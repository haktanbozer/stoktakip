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
    $params[] = $q; $params[] = $q;
}

// Sorgu (Dolap sayısını da alıyoruz)
$sql = "SELECT r.*, l.name as loc_name, c.name as city_name, COUNT(cab.id) as dolap_sayisi 
        FROM rooms r 
        JOIN locations l ON r.location_id = l.id 
        JOIN cities c ON l.city_id = c.id 
        LEFT JOIN cabinets cab ON cab.room_id = r.id
        $where 
        GROUP BY r.id 
        ORDER BY l.name ASC, r.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$odalar = $stmt->fetchAll();

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
        <a href="mekan-yonetimi.php" class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 transition shadow-lg shadow-blue-500/30">
            + Yeni Oda Ekle
        </a>
    </div>

    <div class="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-6 transition-colors">
        <form method="GET" class="flex gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Oda adı veya mekan ara..." class="w-full p-2 border rounded outline-none focus:border-blue-500 dark:bg-slate-900 dark:border-slate-600 dark:text-white dark:focus:border-blue-400 transition-colors">
            <button type="submit" class="bg-slate-800 dark:bg-slate-700 text-white px-6 rounded hover:bg-slate-700 dark:hover:bg-slate-600 transition">Ara</button>
        </form>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-bold border-b dark:border-slate-700">
                <tr>
                    <th class="p-4">Oda Adı</th>
                    <th class="p-4">Mekan (Konum)</th>
                    <th class="p-4">Şehir</th>
                    <th class="p-4">Dolap Sayısı</th>
                    <th class="p-4 text-right">İşlem</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                <?php if(empty($odalar)): ?>
                    <tr><td colspan="5" class="p-6 text-center text-slate-400 dark:text-slate-500">Kayıtlı oda bulunamadı.</td></tr>
                <?php else: ?>
                    <?php foreach($odalar as $o): ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                        <td class="p-4 font-bold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($o['name']) ?></td>
                        <td class="p-4 text-slate-600 dark:text-slate-400"><?= htmlspecialchars($o['loc_name']) ?></td>
                        <td class="p-4 text-slate-500 dark:text-slate-400">
                            <span class="bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-2 py-1 rounded text-xs border border-blue-100 dark:border-blue-800">
                                <?= htmlspecialchars($o['city_name']) ?>
                            </span>
                        </td>
                        <td class="p-4">
                            <?php if($o['dolap_sayisi'] > 0): ?>
                                <span class="font-bold text-slate-700 dark:text-slate-300"><?= $o['dolap_sayisi'] ?></span> <span class="text-slate-500 dark:text-slate-500">Adet</span>
                            <?php else: ?>
                                <span class="text-slate-300 dark:text-slate-600 text-xs italic">Boş</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-4 text-right">
                            <form method="POST" action="mekan-yonetimi.php" onsubmit="return confirm('Odayı silerseniz içindeki dolaplar da silinir! Emin misiniz?')" class="inline">
                                <input type="hidden" name="tablo" value="rooms">
                                <input type="hidden" name="sil_id" value="<?= $o['id'] ?>">
                                <button type="submit" class="text-red-500 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-medium text-xs transition">Sil</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>