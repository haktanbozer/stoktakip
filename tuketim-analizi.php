<?php
require 'db.php';
girisKontrol();

// Güvenlik: Sadece adminler veya yetkili kullanıcılar görmeli.
// if ($_SESSION['role'] !== 'ADMIN') die("Yetkisiz erişim."); 

// Tüketim Analizi Sorgusu: Son 90 gün baz alınır.
$sql = "
SELECT
    p.id,
    p.name,
    p.quantity,
    p.unit,
    p.expiry_date,
    SUM(ch.amount) AS total_consumed_90,
    DATEDIFF(NOW(), MIN(ch.consumed_at)) AS active_days
FROM products p
JOIN consumption_history ch ON p.id = ch.product_id
WHERE ch.consumed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
GROUP BY p.id
HAVING active_days >= 7 -- Analiz için en az 7 gün veri olsun
ORDER BY p.name ASC
";

$stmt = $pdo->query($sql);
$analizler = $stmt->fetchAll();

$tahminEdilenler = [];
foreach($analizler as $a) {
    // 1. Günlük Tüketim Hızı (DCR) = Toplam Tüketim / Aktif Gün Sayısı
    $dcr = $a['total_consumed_90'] / $a['active_days'];
    
    // 2. Kalan Gün Sayısı = Mevcut Miktar / DCR
    if ($dcr > 0) {
        $daysRemaining = $a['quantity'] / $dcr;
        $a['dcr'] = round($dcr, 3);
        $a['days_remaining'] = ceil($daysRemaining);
        $a['run_out_date'] = date('Y-m-d', strtotime("+$daysRemaining days"));
        $tahminEdilenler[] = $a;
    }
}

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-6 transition-colors">
            📅 Tüketim Hızı Tahminleme (Son 90 Gün)
        </h2>

        <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4 border-b dark:border-slate-700 pb-3">
                Aşağıdaki tablo, son 90 günlük tüketim geçmişine göre ürünlerin tahmini **tükenme tarihlerini** gösterir. (Analiz için en az 7 günlük tüketim verisi olan ürünler listelenir.)
            </p>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-bold border-b dark:border-slate-700">
                        <tr>
                            <th class="p-3">Ürün</th>
                            <th class="p-3 text-center">Günlük Tüketim</th>
                            <th class="p-3 text-center">Mevcut Miktar</th>
                            <th class="p-3">Tahmini Bitiş Tarihi</th>
                            <th class="p-3 text-center">Kalan Gün</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <?php if(empty($tahminEdilenler)): ?>
                            <tr><td colspan="5" class="p-6 text-center text-slate-500 dark:text-slate-400">Yeterli tüketim geçmişi olan ürün bulunamadı.</td></tr>
                        <?php else: ?>
                            <?php foreach($tahminEdilenler as $t): 
                                $riskRenk = $t['days_remaining'] < 30 ? 'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300' : 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300';
                            ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                                <td class="p-3 font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($t['name']) ?></td>
                                <td class="p-3 text-center text-slate-600 dark:text-slate-300"><?= $t['dcr'] ?> <?= $t['unit'] ?></td>
                                <td class="p-3 text-center text-slate-600 dark:text-slate-300"><?= (float)$t['quantity'] ?> <?= $t['unit'] ?></td>
                                <td class="p-3 font-bold text-slate-700 dark:text-slate-200">
                                    <?= date('d.m.Y', strtotime($t['run_out_date'])) ?>
                                </td>
                                <td class="p-3 text-center">
                                    <span class="<?= $riskRenk ?> px-3 py-1 rounded text-xs font-bold">
                                        <?= $t['days_remaining'] ?> Gün
                                    </span>
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

<?php 
// Not: Sidebar linkini eklemedik. Bunu sidebar.php'ye manuel olarak ekleyebilirsin:
/* <a href="tuketim-analizi.php" class="hover:bg-slate-800 px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3">
    ⏳ Tüketim Analizi
</a>
*/
?>
</body>
</html>