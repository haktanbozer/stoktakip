<?php
require 'db.php';
girisKontrol();

// 1. Tüketim Analizi Sorgusu (Son 90 gün)
$params = [];
$cityWhere = "";

if (isset($_SESSION['aktif_sehir_id'])) {
    $cityWhere = " AND l.city_id = ? ";
    $params[] = $_SESSION['aktif_sehir_id'];
}

$sql = "
SELECT
    p.id,
    p.name,
    p.brand,
    p.quantity,
    p.min_quantity,
    p.unit,
    p.expiry_date,
    SUM(ch.amount) AS total_consumed_90,
    COUNT(ch.id) AS consumption_count,
    GREATEST(DATEDIFF(NOW(), MIN(ch.consumed_at)), 1) AS active_days
FROM products p
JOIN consumption_history ch ON p.id = ch.product_id
LEFT JOIN cabinets c ON p.cabinet_id = c.id
LEFT JOIN rooms r ON c.room_id = r.id
LEFT JOIN locations l ON r.location_id = l.id
WHERE ch.consumed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
$cityWhere
GROUP BY p.id, p.name, p.brand, p.quantity, p.min_quantity, p.unit, p.expiry_date
ORDER BY p.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$analizler = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tahminEdilenler = [];
foreach ($analizler as $a) {
    $activeDays = (int)$a['active_days'];
    $totalConsumed = (float)$a['total_consumed_90'];
    $currentQty = (float)$a['quantity'];

    // Günlük Tüketim Hızı (DCR)
    $dcr = $totalConsumed / $activeDays;

    if ($dcr > 0) {
        $a['dcr'] = round($dcr, 3);

        if ($currentQty <= 0) {
            $a['days_remaining'] = 0;
            $a['run_out_date'] = date('Y-m-d');
            $a['status'] = 'depleted';
        } else {
            $daysRemaining = ceil($currentQty / $dcr);
            $a['days_remaining'] = $daysRemaining;
            $a['run_out_date'] = date('Y-m-d', strtotime("+{$daysRemaining} days"));
            $a['status'] = ($daysRemaining <= 15) ? 'critical' : (($daysRemaining <= 30) ? 'warning' : 'safe');
        }

        $tahminEdilenler[] = $a;
    }
}

// Bitiş gününe göre en acilden en uzağa sırala
usort($tahminEdilenler, function($a, $b) {
    return $a['days_remaining'] <=> $b['days_remaining'];
});

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">
                📅 Tüketim Hızı & Stok Tahmini (Son 90 Gün)
            </h2>
            <?php if(isset($_SESSION['aktif_sehir_ad'])): ?>
                <span class="text-xs bg-blue-100 text-blue-800 px-3 py-1 rounded-full dark:bg-blue-900 dark:text-blue-300 font-bold">
                    <?= htmlspecialchars($_SESSION['aktif_sehir_ad']) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4 border-b dark:border-slate-700 pb-3">
                Bu sayfa, son 90 gündeki kullanım verilerinize göre mutfaktaki bakliyat ve gıdaların <strong>tahmini ne zaman tükeneceğini</strong> hesaplar.
            </p>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-bold border-b dark:border-slate-700">
                        <tr>
                            <th class="p-3">Ürün</th>
                            <th class="p-3 text-center">Günlük Tüketim (Ort.)</th>
                            <th class="p-3 text-center">Mevcut Stok</th>
                            <th class="p-3">Tahmini Bitiş Tarihi</th>
                            <th class="p-3 text-center">Kalan Süre</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <?php if (empty($tahminEdilenler)): ?>
                            <tr>
                                <td colspan="5" class="p-8 text-center text-slate-400 dark:text-slate-500">
                                    <div class="text-3xl mb-2">📊</div>
                                    Henüz analiz yapacak kadar tüketim kaydı oluşmadı. Ürün tükettikçe burası otomatik hesaplanacaktır.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tahminEdilenler as $t): 
                                if ($t['status'] === 'depleted') {
                                    $badge = 'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300';
                                    $metin = 'Tükendi (0 Stok)';
                                } elseif ($t['status'] === 'critical') {
                                    $badge = 'bg-orange-100 dark:bg-orange-900/50 text-orange-700 dark:text-orange-300';
                                    $metin = $t['days_remaining'] . ' Gün Kaldı';
                                } elseif ($t['status'] === 'warning') {
                                    $badge = 'bg-yellow-100 dark:bg-yellow-900/50 text-yellow-700 dark:text-yellow-300';
                                    $metin = $t['days_remaining'] . ' Gün Kaldı';
                                } else {
                                    $badge = 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300';
                                    $metin = $t['days_remaining'] . ' Gün';
                                }
                            ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                                <td class="p-3 font-medium text-slate-800 dark:text-slate-200">
                                    <b><?= htmlspecialchars($t['name']) ?></b>
                                    <?php if(!empty($t['brand'])): ?>
                                        <span class="text-xs text-slate-400 block"><?= htmlspecialchars($t['brand']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-center text-slate-600 dark:text-slate-300 font-mono">
                                    <?= $t['dcr'] ?> <?= $t['unit'] ?>/gün
                                </td>
                                <td class="p-3 text-center text-slate-600 dark:text-slate-300 font-bold">
                                    <?= (float)$t['quantity'] ?> <?= $t['unit'] ?>
                                </td>
                                <td class="p-3 text-slate-700 dark:text-slate-200 font-medium">
                                    <?= ($t['status'] === 'depleted') ? '<span class="text-red-600 font-bold">Tükendi</span>' : date('d.m.Y', strtotime($t['run_out_date'])) ?>
                                </td>
                                <td class="p-3 text-center">
                                    <span class="<?= $badge ?> px-3 py-1 rounded text-xs font-bold inline-block">
                                        <?= $metin ?>
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
</body>
</html>