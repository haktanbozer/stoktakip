<?php
// odalar.php - Oda, Dolap ve Ürün Hiyerarşisi
require 'db.php';
girisKontrol();

if (!isset($cspNonce)) { $cspNonce = ''; }

$where = "WHERE 1=1";
$params = [];

// Şehir Filtresi
if (isset($_SESSION['aktif_sehir_id'])) {
    $where .= " AND l.city_id = ?";
    $params[] = $_SESSION['aktif_sehir_id'];
}

// Arama Filtresi
if (!empty($_GET['q'])) {
    $where .= " AND (r.name LIKE ? OR l.name LIKE ?)";
    $q = "%" . trim($_GET['q']) . "%";
    $params[] = $q; 
    $params[] = $q;
}

// 1. TEK SORGUDA TÜM HİYERARŞİYİ ÇEK (N+1 Sorgu Çözümü)
$sql = "SELECT 
            r.id AS room_id,
            r.name AS room_name,
            l.name AS loc_name,
            c.name AS city_name,
            cab.id AS cab_id,
            cab.name AS cab_name,
            p.id AS prod_id,
            p.name AS prod_name,
            p.brand,
            p.quantity,
            p.unit,
            p.expiry_date,
            p.is_opened,
            p.min_quantity
        FROM rooms r
        JOIN locations l ON r.location_id = l.id 
        JOIN cities c ON l.city_id = c.id 
        LEFT JOIN cabinets cab ON cab.room_id = r.id
        LEFT JOIN products p ON p.cabinet_id = cab.id
        $where
        ORDER BY l.name ASC, r.name ASC, cab.name ASC, p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. HAFIZADA GRUPLAMA (Oda -> Dolap -> Ürün)
$odalar = [];
foreach ($rows as $row) {
    $rid = $row['room_id'];
    if (!isset($odalar[$rid])) {
        $odalar[$rid] = [
            'id'          => $rid,
            'name'        => $row['room_name'],
            'loc_name'    => $row['loc_name'],
            'city_name'   => $row['city_name'],
            'dolaplar'    => [],
            'toplam_urun' => 0
        ];
    }

    if (!empty($row['cab_id'])) {
        $cid = $row['cab_id'];
        if (!isset($odalar[$rid]['dolaplar'][$cid])) {
            $odalar[$rid]['dolaplar'][$cid] = [
                'name'    => $row['cab_name'],
                'urunler' => []
            ];
        }

        if (!empty($row['prod_id'])) {
            $odalar[$rid]['dolaplar'][$cid]['urunler'][] = [
                'id'           => $row['prod_id'],
                'name'         => $row['prod_name'],
                'brand'        => $row['brand'],
                'quantity'     => $row['quantity'],
                'unit'         => $row['unit'],
                'expiry_date'  => $row['expiry_date'],
                'is_opened'    => $row['is_opened'],
                'min_quantity' => $row['min_quantity']
            ];
            $odalar[$rid]['toplam_urun']++;
        }
    }
}

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">
                    Oda & Dolap Düzeni
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Konum: <?= htmlspecialchars($_SESSION['aktif_sehir_ad'] ?? 'Tüm Şehirler') ?>
                </p>
            </div>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN'): ?>
                <a href="mekan-yonetimi.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition font-medium shadow-md shadow-blue-500/20">
                    + Mekan & Dolap Ayarları
                </a>
            <?php endif; ?>
        </div>

        <!-- Arama Kutusu -->
        <div class="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-6 transition-colors">
            <form method="GET" class="flex gap-2">
                <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Oda adı veya mekan ara..." class="w-full p-2 border rounded-lg outline-none focus:border-blue-500 dark:bg-slate-900 dark:border-slate-600 dark:text-white dark:focus:border-blue-400 text-sm transition-colors">
                <button type="submit" class="bg-slate-800 dark:bg-slate-700 text-white px-5 rounded-lg text-sm font-medium hover:bg-slate-700 dark:hover:bg-slate-600 transition">Ara</button>
                <?php if(!empty($_GET['q'])): ?>
                    <a href="odalar.php" class="bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-3 py-2 rounded-lg text-sm hover:bg-slate-200 transition">Temizle</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tablo Listesi -->
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-bold border-b dark:border-slate-700 text-xs uppercase">
                        <tr>
                            <th class="p-4">Oda Adı</th>
                            <th class="p-4">Mekan</th>
                            <th class="p-4">Şehir</th>
                            <th class="p-4">Dolap Sayısı</th>
                            <th class="p-4">Kayıtlı Ürün</th>
                            <th class="p-4 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <?php if(empty($odalar)): ?>
                            <tr>
                                <td colspan="6" class="p-6 text-center text-slate-400 dark:text-slate-500">
                                    Arama kriterine uygun oda bulunamadı.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($odalar as $o): 
                                $dolapSayisi = count($o['dolaplar']);
                            ?>
                                <!-- ANA SATIR -->
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors cursor-pointer room-row group" data-room-id="<?= $o['id'] ?>">
                                    <td class="p-4 font-bold text-slate-800 dark:text-slate-200">
                                        <?= htmlspecialchars($o['name']) ?>
                                    </td>
                                    <td class="p-4 text-slate-600 dark:text-slate-400">
                                        <?= htmlspecialchars($o['loc_name']) ?>
                                    </td>
                                    <td class="p-4">
                                        <span class="bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-2 py-0.5 rounded text-xs border border-blue-100 dark:border-blue-800 font-medium">
                                            <?= htmlspecialchars($o['city_name']) ?>
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <?php if($dolapSayisi > 0): ?>
                                            <span class="font-bold text-slate-700 dark:text-slate-300"><?= $dolapSayisi ?></span> Dolap
                                        <?php else: ?>
                                            <span class="text-slate-400 text-xs italic">Dolap Yok</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4">
                                        <?php if($o['toplam_urun'] > 0): ?>
                                            <span class="font-bold text-emerald-600 dark:text-emerald-400"><?= $o['toplam_urun'] ?></span> Ürün
                                        <?php else: ?>
                                            <span class="text-slate-400 text-xs italic">Ürün Yok</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 text-right">
                                        <svg id="arrow-<?= $o['id'] ?>" class="w-5 h-5 text-slate-400 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-transform duration-200 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </td>
                                </tr>

                                <!-- DETAY SATIRI -->
                                <tr id="room-detail-<?= $o['id'] ?>" class="hidden bg-slate-50/70 dark:bg-slate-900/40">
                                    <td colspan="6" class="p-4">
                                        <?php if (empty($o['dolaplar'])): ?>
                                            <div class="text-xs text-slate-400 dark:text-slate-500 py-2">
                                                Bu odada kayıtlı dolap bulunmuyor.
                                            </div>
                                        <?php else: ?>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <?php foreach ($o['dolaplar'] as $dolap): ?>
                                                    <div class="border border-slate-200 dark:border-slate-700 rounded-lg p-3 bg-white dark:bg-slate-800 shadow-sm">
                                                        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-2 mb-2">
                                                            <span class="font-bold text-xs uppercase text-slate-700 dark:text-slate-200">
                                                                🗄️ <?= htmlspecialchars($dolap['name']) ?>
                                                            </span>
                                                            <span class="text-[11px] bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded-full font-medium">
                                                                <?= count($dolap['urunler']) ?> ürün
                                                            </span>
                                                        </div>

                                                        <?php if (empty($dolap['urunler'])): ?>
                                                            <div class="text-[11px] text-slate-400 dark:text-slate-500 italic py-1">
                                                                Bu dolapta kayıtlı ürün yok.
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="overflow-x-auto max-h-48 custom-scrollbar">
                                                                <table class="w-full text-xs">
                                                                    <thead class="text-[10px] uppercase text-slate-400 dark:text-slate-500 border-b dark:border-slate-700">
                                                                        <tr>
                                                                            <th class="py-1 text-left">Ürün</th>
                                                                            <th class="py-1 text-right">Miktar</th>
                                                                            <th class="py-1 text-right">SKT</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                                                                        <?php foreach ($dolap['urunler'] as $p): ?>
                                                                            <tr>
                                                                                <td class="py-1.5 text-slate-700 dark:text-slate-200 pr-2">
                                                                                    <span class="font-medium"><?= htmlspecialchars($p['name']) ?></span>
                                                                                    <?php if(!empty($p['is_opened'])): ?>
                                                                                        <span class="text-[9px] bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 px-1 rounded font-bold">Açık</span>
                                                                                    <?php endif; ?>
                                                                                    <?php if(!empty($p['brand'])): ?>
                                                                                        <span class="text-[10px] text-slate-400 block"><?= htmlspecialchars($p['brand']) ?></span>
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                                <td class="py-1.5 text-right font-medium whitespace-nowrap <?= ((float)$p['quantity'] <= (float)$p['min_quantity']) ? 'text-red-500 font-bold' : 'text-slate-600 dark:text-slate-300' ?>">
                                                                                    <?= (float)$p['quantity'] . ' ' . htmlspecialchars($p['unit']) ?>
                                                                                </td>
                                                                                <td class="py-1.5 text-right whitespace-nowrap text-[11px] text-slate-500 dark:text-slate-400">
                                                                                    <?php if ($p['expiry_date']): ?>
                                                                                        <?= date('d.m.Y', strtotime($p['expiry_date'])) ?>
                                                                                    <?php else: ?>
                                                                                        <span class="text-slate-400">Süresiz</span>
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
</div>

<script nonce="<?= $cspNonce ?>">
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.room-row').forEach(function (row) {
        row.addEventListener('click', function () {
            const id = this.dataset.roomId;
            const detail = document.getElementById('room-detail-' + id);
            const arrow = document.getElementById('arrow-' + id);
            if (!detail) return;

            detail.classList.toggle('hidden');
            if (arrow) {
                arrow.classList.toggle('rotate-180');
            }
        });
    });
});
</script>
</body>
</html>