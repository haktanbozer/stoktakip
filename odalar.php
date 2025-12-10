<?php
require 'db.php';
girisKontrol();

// Odaları Çek (Şehir filtresine duyarlı)
$where = "WHERE 1=1";
$params = [];

if (isset($_SESSION['aktif_sehir_id'])) {
    $where .= " AND l.city_id = ?";
    $params[] = $_SESSION['aktif_sehir_id'];
}

// Arama
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $where .= " AND (r.name LIKE ? OR l.name LIKE ?)";
    $q = "%" . $_GET['q'] . "%";
    $params[] = $q; 
    $params[] = $q;
}

// Oda başına dolap sayısı + ürün sayısı
$sql = "SELECT 
            r.*, 
            l.name  AS loc_name, 
            c.name  AS city_name, 
            COUNT(DISTINCT cab.id) AS dolap_sayisi,
            COALESCE(SUM(caburun.urun_sayisi),0) AS urun_sayisi
        FROM rooms r
        JOIN locations l ON r.location_id = l.id 
        JOIN cities   c ON l.city_id = c.id 
        LEFT JOIN cabinets cab ON cab.room_id = r.id
        LEFT JOIN (
            SELECT cabinet_id, COUNT(*) AS urun_sayisi
            FROM products
            GROUP BY cabinet_id
        ) caburun ON caburun.cabinet_id = cab.id
        $where 
        GROUP BY r.id 
        ORDER BY l.name ASC, r.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$odalar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// DETAY satırları için: dolap + ürün sorgusu
$detayStmt = $pdo->prepare("
    SELECT 
        cab.id   AS cab_id,
        cab.name AS cab_name,
        p.id     AS prod_id,
        p.name   AS prod_name,
        p.brand,
        p.quantity,
        p.unit,
        p.expiry_date
    FROM cabinets cab
    LEFT JOIN products p ON p.cabinet_id = cab.id
    WHERE cab.room_id = ?
    ORDER BY cab.name ASC, p.name ASC
");

require 'header.php';
?>

<div class="w-full">
    
    <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">
            Oda Listesi
            <span class="text-sm font-normal text-slate-500 dark:text-slate-400 ml-2">
                (<?= $_SESSION['aktif_sehir_ad'] ?? 'Tüm Şehirler' ?>)
            </span>
        </h2>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN'): ?>
            <a href="mekan-yonetimi.php" class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 transition shadow-lg shadow-blue-500/30">
                + Yeni Oda Ekle
            </a>
        <?php endif; ?>
    </div>

    <div class="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-6 transition-colors">
        <form method="GET" class="flex gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Oda adı veya mekan ara..." class="w-full p-2 border rounded outline-none focus:border-blue-500 dark:bg-slate-900 dark:border-slate-600 dark:text-white dark:focus:border-blue-400 transition-colors">
            <button type="submit" class="bg-slate-800 dark:bg-slate-700 text-white px-6 rounded hover:bg-slate-700 dark:hover:bg-slate-600 transition">Ara</button>
        </form>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-bold border-b dark:border-slate-700">
                    <tr>
                        <th class="p-4">Oda Adı</th>
                        <th class="p-4">Mekan (Konum)</th>
                        <th class="p-4">Şehir</th>
                        <th class="p-4">Dolap Sayısı</th>
                        <th class="p-4">Ürün Sayısı</th> <!-- Sil yok artık, son kolon bu -->
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    <?php if(empty($odalar)): ?>
                        <tr>
                            <td colspan="5" class="p-6 text-center text-slate-400 dark:text-slate-500">
                                Kayıtlı oda bulunamadı.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($odalar as $o): ?>
                            <?php
                            // Bu odanın dolap+ürün detaylarını çek
                            $detayStmt->execute([$o['id']]);
                            $detayRows = $detayStmt->fetchAll(PDO::FETCH_ASSOC);

                            // Dolaplara göre grupla
                            $dolaplar = [];
                            foreach ($detayRows as $row) {
                                $cid = $row['cab_id'];
                                if (!isset($dolaplar[$cid])) {
                                    $dolaplar[$cid] = [
                                        'ad'      => $row['cab_name'],
                                        'urunler' => []
                                    ];
                                }
                                if (!empty($row['prod_id'])) {
                                    $dolaplar[$cid]['urunler'][] = $row;
                                }
                            }
                            ?>
                            <!-- ANA SATIR (tıklanabilir) -->
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors cursor-pointer room-row"
                                data-room-id="<?= $o['id'] ?>">
                                <td class="p-4 font-bold text-slate-800 dark:text-slate-200">
                                    <?= htmlspecialchars($o['name']) ?>
                                    <div class="text-[11px] text-slate-400 dark:text-slate-500">
                                        Detay için tıklayın
                                    </div>
                                </td>
                                <td class="p-4 text-slate-600 dark:text-slate-400">
                                    <?= htmlspecialchars($o['loc_name']) ?>
                                </td>
                                <td class="p-4 text-slate-500 dark:text-slate-400">
                                    <span class="bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-2 py-1 rounded text-xs border border-blue-100 dark:border-blue-800">
                                        <?= htmlspecialchars($o['city_name']) ?>
                                    </span>
                                </td>
                                <td class="p-4">
                                    <?php if($o['dolap_sayisi'] > 0): ?>
                                        <span class="font-bold text-slate-700 dark:text-slate-300"><?= $o['dolap_sayisi'] ?></span>
                                        <span class="text-slate-500 dark:text-slate-500">Adet</span>
                                    <?php else: ?>
                                        <span class="text-slate-300 dark:text-slate-600 text-xs italic">Boş</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4">
                                    <?php if($o['urun_sayisi'] > 0): ?>
                                        <span class="font-bold text-emerald-600 dark:text-emerald-400">
                                            <?= (int)$o['urun_sayisi'] ?>
                                        </span>
                                        <span class="text-slate-500 dark:text-slate-500">Ürün</span>
                                    <?php else: ?>
                                        <span class="text-slate-300 dark:text-slate-600 text-xs italic">Ürün yok</span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- DETAY SATIRI -->
                            <tr id="room-detail-<?= $o['id'] ?>" class="hidden bg-slate-50 dark:bg-slate-900/40">
                                <td colspan="5" class="p-4">
                                    <?php if (empty($dolaplar)): ?>
                                        <div class="text-sm text-slate-400 dark:text-slate-500">
                                            Bu odada kayıtlı dolap veya ürün bulunmuyor.
                                        </div>
                                    <?php else: ?>
                                        <div class="space-y-4">
                                            <?php foreach ($dolaplar as $dolap): ?>
                                                <div class="border border-slate-200 dark:border-slate-700 rounded-lg p-3 bg-white/70 dark:bg-slate-800/60">
                                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-2">
                                                        <span class="font-semibold text-slate-700 dark:text-slate-200">
                                                            <?= htmlspecialchars($dolap['ad']) ?>
                                                        </span>
                                                        <span class="text-[11px] text-slate-400 dark:text-slate-500">
                                                            <?= count($dolap['urunler']) ?> ürün
                                                        </span>
                                                    </div>

                                                    <?php if (empty($dolap['urunler'])): ?>
                                                        <div class="text-xs text-slate-400 dark:text-slate-500">
                                                            Bu dolapta henüz ürün yok.
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="overflow-x-auto">
                                                            <table class="w-full text-xs">
                                                                <thead class="text-[11px] uppercase text-slate-400 dark:text-slate-500 border-b border-slate-100 dark:border-slate-700">
                                                                    <tr>
                                                                        <th class="py-1 pr-2 text-left">Ürün</th>
                                                                        <th class="py-1 pr-2 text-left">Marka</th>
                                                                        <th class="py-1 pr-2 text-right">Miktar</th>
                                                                        <th class="py-1 pr-2 text-left">SKT</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($dolap['urunler'] as $p): ?>
                                                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                                                            <td class="py-1 pr-2 text-slate-700 dark:text-slate-200">
                                                                                <?= htmlspecialchars($p['prod_name']) ?>
                                                                            </td>
                                                                            <td class="py-1 pr-2 text-slate-500 dark:text-slate-400">
                                                                                <?= htmlspecialchars($p['brand']) ?>
                                                                            </td>
                                                                            <td class="py-1 pr-2 text-right text-slate-700 dark:text-slate-200 whitespace-nowrap">
                                                                                <?= (float)$p['quantity'] . ' ' . htmlspecialchars($p['unit']) ?>
                                                                            </td>
                                                                            <td class="py-1 pr-2 text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                                                                <?php if ($p['expiry_date']): ?>
                                                                                    <?= date('d.m.Y', strtotime($p['expiry_date'])) ?>
                                                                                <?php else: ?>
                                                                                    <span class="text-[11px] italic text-slate-400 dark:text-slate-500">Yok</span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script nonce="<?= $cspNonce ?? '' ?>">
// Oda satırına tıklayınca altında detay aç / kapa
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.room-row').forEach(function (row) {
        row.addEventListener('click', function () {
            const id = this.dataset.roomId;
            const detail = document.getElementById('room-detail-' + id);
            if (!detail) return;
            detail.classList.toggle('hidden');
        });
    });
});
</script>

</body>
</html>
