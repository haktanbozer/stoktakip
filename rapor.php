<?php
require 'db.php';
girisKontrol();

// Verileri Çek
$joinSQL = "";
$whereSQL = "WHERE 1=1";
$params = [];

if (isset($_SESSION['aktif_sehir_id'])) {
    $joinSQL = "JOIN cabinets c ON p.cabinet_id = c.id 
                JOIN rooms r ON c.room_id = r.id 
                JOIN locations l ON r.location_id = l.id";
    $whereSQL .= " AND l.city_id = ?";
    $params[] = $_SESSION['aktif_sehir_id'];
}

$sql = "SELECT p.* FROM products p $joinSQL $whereSQL ORDER BY (p.expiry_date IS NULL), p.expiry_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$urunler = $stmt->fetchAll();

// --- İSTATİSTİK HESAPLAMA (PHP Tarafı) ---
$toplamUrun = count($urunler);
$bugun = time();

// 1. Risk Analizi
$riskStats = ['expired' => 0, 'critical' => 0, 'warning' => 0, 'safe' => 0];
// 2. Stok Yaşı (Turnover)
$yasStats = ['new' => 0, 'stable' => 0, 'old' => 0];
// 3. Kategori Dağılımı
$catStats = [];

foreach ($urunler as $u) {
    // Risk Analizi
    if (empty($u['expiry_date'])) {
        // SKT yoksa "Güvenli" kabul ediyoruz
        $riskStats['safe']++;
    } else {
        $skt = strtotime($u['expiry_date']);
        $kalanGun = ceil(($skt - $bugun) / (60 * 60 * 24));
        
        if ($kalanGun < 0) $riskStats['expired']++;
        elseif ($kalanGun <= 7) $riskStats['critical']++;
        elseif ($kalanGun <= 30) $riskStats['warning']++;
        else $riskStats['safe']++;
    }

    // Stok Yaşı (Alım tarihine göre)
    if ($u['purchase_date']) {
        $alim = strtotime($u['purchase_date']);
        $stokGun = ceil(abs($bugun - $alim) / (60 * 60 * 24));
        if ($stokGun <= 30) $yasStats['new']++;
        elseif ($stokGun <= 90) $yasStats['stable']++;
        else $yasStats['old']++;
    }

    // Kategori
    $cat = $u['category'];
    if (!isset($catStats[$cat])) $catStats[$cat] = 0;
    $catStats[$cat]++;
}

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">Envanter Raporu</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">Mevcut stok durumunun detaylı analizi</p>
            </div>
            <button onclick="generatePDF()" class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg transition flex items-center gap-2 shadow-lg shadow-red-500/30 font-bold">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                PDF İndir
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6" id="report-preview">
            
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-lg text-slate-700 dark:text-white mb-4">1. Risk Analizi (SKT)</h3>
                <div class="flex h-4 rounded-full overflow-hidden mb-4 bg-slate-100 dark:bg-slate-700">
                    <?php if($toplamUrun > 0): ?>
                        <div style="width: <?= ($riskStats['expired']/$toplamUrun)*100 ?>%" class="bg-red-500" title="Süresi Geçmiş"></div>
                        <div style="width: <?= ($riskStats['critical']/$toplamUrun)*100 ?>%" class="bg-orange-500" title="Kritik (7 Gün)"></div>
                        <div style="width: <?= ($riskStats['warning']/$toplamUrun)*100 ?>%" class="bg-yellow-400" title="Yaklaşan (30 Gün)"></div>
                        <div style="width: <?= ($riskStats['safe']/$toplamUrun)*100 ?>%" class="bg-green-500" title="Güvenli"></div>
                    <?php endif; ?>
                </div>
                <div class="grid grid-cols-2 gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-red-500"></span> Geçmiş: <b><?= $riskStats['expired'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-orange-500"></span> Kritik: <b><?= $riskStats['critical'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-yellow-400"></span> Yaklaşan: <b><?= $riskStats['warning'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-green-500"></span> Güvenli (Süresiz Dahil): <b><?= $riskStats['safe'] ?></b></div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-lg text-slate-700 dark:text-white mb-4">2. Stok Devir Hızı</h3>
                <div class="flex h-4 rounded-full overflow-hidden mb-4 bg-slate-100 dark:bg-slate-700">
                    <?php if($toplamUrun > 0): ?>
                        <div style="width: <?= ($yasStats['new']/$toplamUrun)*100 ?>%" class="bg-blue-400" title="Yeni (0-30 Gün)"></div>
                        <div style="width: <?= ($yasStats['stable']/$toplamUrun)*100 ?>%" class="bg-purple-400" title="Orta (1-3 Ay)"></div>
                        <div style="width: <?= ($yasStats['old']/$toplamUrun)*100 ?>%" class="bg-slate-400" title="Eski (>3 Ay)"></div>
                    <?php endif; ?>
                </div>
                <div class="grid grid-cols-2 gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-blue-400"></span> Yeni: <b><?= $yasStats['new'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-purple-400"></span> Orta: <b><?= $yasStats['stable'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-slate-400"></span> Yavaş: <b><?= $yasStats['old'] ?></b></div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-lg text-slate-700 dark:text-white mb-4">3. Kategori Dağılımı</h3>
                <div class="max-h-60 overflow-y-auto custom-scrollbar">
                    <table class="w-full text-sm text-slate-600 dark:text-slate-300">
                        <thead class="bg-slate-50 dark:bg-slate-700 text-left font-bold text-slate-500 dark:text-slate-400 sticky top-0">
                            <tr><th class="p-2">Kategori</th><th class="p-2">Adet</th><th class="p-2">Oran</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <?php foreach($catStats as $cat => $count): ?>
                            <tr>
                                <td class="p-2"><?= $cat ?></td>
                                <td class="p-2 font-bold"><?= $count ?></td>
                                <td class="p-2 text-slate-400">%<?= $toplamUrun > 0 ? number_format(($count/$toplamUrun)*100, 1) : 0 ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-lg text-red-600 dark:text-red-400 mb-4">4. Acil Tüketilmesi Gerekenler</h3>
                <ul class="text-sm space-y-2 max-h-60 overflow-y-auto custom-scrollbar">
                    <?php 
                    $sayac = 0;
                    foreach($urunler as $u): 
                        if(empty($u['expiry_date'])) continue; // Süresizleri atla
                        
                        $kalan = ceil((strtotime($u['expiry_date']) - time()) / (60*60*24));
                        if($kalan > 30) continue; 
                        if($sayac++ >= 8) break; 
                    ?>
                    <li class="flex justify-between border-b dark:border-slate-700 pb-1 text-slate-700 dark:text-slate-300">
                        <span><?= htmlspecialchars($u['name']) ?></span>
                        <span class="<?= $kalan < 7 ? 'text-red-600 dark:text-red-400 font-bold' : 'text-orange-500 dark:text-orange-400' ?>">
                            <?= $kalan < 0 ? 'GEÇMİŞ' : $kalan . ' Gün' ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                    <?php if($sayac == 0) echo "<li class='text-slate-400 dark:text-slate-500'>Riskli ürün bulunmuyor.</li>"; ?>
                </ul>
            </div>

        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<script>
// PHP verilerini JS'ye aktar
const stats = {
    total: <?= $toplamUrun ?>,
    risk: <?= json_encode($riskStats) ?>,
    age: <?= json_encode($yasStats) ?>,
    cats: <?= json_encode($catStats) ?>,
    products: <?= json_encode($urunler) ?>
};

// Gelişmiş Türkçe Karakter Çeviricisi
function tr(str) {
    if(!str) return "";
    str = String(str);
    // Küçük harf karakterleri
    str = str.replace(/ç/g, "c").replace(/ğ/g, "g").replace(/ı/g, "i").replace(/ö/g, "o").replace(/ş/g, "s").replace(/ü/g, "u");
    // Büyük harf karakterleri
    str = str.replace(/Ç/g, "C").replace(/Ğ/g, "G").replace(/İ/g, "I").replace(/Ö/g, "O").replace(/Ş/g, "S").replace(/Ü/g, "U");
    
    // HTML varlıklarını temizle
    return str.replace(/&amp;/g, "&").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&quot;/g, '"');
}

function generatePDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    const today = new Date().toLocaleDateString('tr-TR');
    
    const locationName = "<?= $_SESSION['aktif_sehir_ad'] ?? 'Tüm Lokasyonlar' ?>";

    doc.setFontSize(20); 
    doc.setTextColor(41, 128, 185);
    doc.text(tr("StokTakip - Envanter Raporu"), 14, 20); // Başlığı çevir
    
    doc.setFontSize(10); 
    doc.setTextColor(100);
    doc.text(tr("Tarih: ") + today, 14, 28);
    doc.text(tr("Lokasyon: ") + tr(locationName), 14, 33); // Lokasyon adını çevir
    doc.text(tr("Toplam Ürün: ") + stats.total, 14, 38);

    let yPos = 50;

    // --- 1. RİSK ANALİZİ GRAFİĞİ (Görsel Ekleme) ---
    doc.setFontSize(14); 
    doc.setTextColor(0);
    doc.text(tr("1. Son Kullanma Tarihi Risk Analizi"), 14, yPos);
    yPos += 8;

    if(stats.total > 0) {
        const barW = 180;
        const expiredW = (stats.risk.expired / stats.total) * barW;
        const criticalW = (stats.risk.critical / stats.total) * barW;
        const warningW = (stats.risk.warning / stats.total) * barW;
        const safeW = (stats.risk.safe / stats.total) * barW;

        let currentX = 14;
        const barH = 5;

        // Bar Çizimi
        if(expiredW > 0) { doc.setFillColor(239, 68, 68); doc.rect(currentX, yPos, expiredW, barH, 'F'); currentX += expiredW; }
        if(criticalW > 0) { doc.setFillColor(249, 115, 22); doc.rect(currentX, yPos, criticalW, barH, 'F'); currentX += criticalW; }
        if(warningW > 0) { doc.setFillColor(250, 204, 21); doc.rect(currentX, yPos, warningW, barH, 'F'); currentX += warningW; }
        if(safeW > 0) { doc.setFillColor(34, 197, 94); doc.rect(currentX, yPos, safeW, barH, 'F'); }
    }

    yPos += 10;
    doc.setFontSize(9); 
    doc.setTextColor(80);
    doc.text(tr(`Geçmiş: ${stats.risk.expired} | Kritik (7 Gün): ${stats.risk.critical} | Yaklaşan (30 Gün): ${stats.risk.warning} | Güvenli: ${stats.risk.safe}`), 14, yPos);
    
    // --- 2. KATEGORİ TABLOSU ---
    yPos += 15;
    doc.setFontSize(14); 
    doc.setTextColor(0);
    doc.text(tr("2. Kategori Dağılımı"), 14, yPos);

    const catRows = Object.entries(stats.cats).map(([name, count]) => {
        let percent = stats.total > 0 ? ((count/stats.total)*100).toFixed(1) : 0;
        return [tr(name), count, `%${percent}`];
    });

    doc.autoTable({
        startY: yPos + 5,
        head: [[tr('Kategori'), tr('Adet'), tr('Oran')]],
        body: catRows,
        theme: 'striped',
        headStyles: { fillColor: [44, 62, 80], font: 'helvetica' },
        styles: { font: 'helvetica' }
    });

    // --- 3. TÜM ÜRÜNLER LİSTESİ ---
    let finalY = doc.lastAutoTable.finalY + 15;
    doc.text(tr("3. Ürün Listesi"), 14, finalY);

    const productRows = stats.products.map(p => {
        let sktStr = "-";
        let kalanStr = tr("Süresiz"); // Süresiz kelimesini de çevir

        if (p.expiry_date) {
            const skt = new Date(p.expiry_date);
            const bugun = new Date();
            const fark = Math.ceil((skt - bugun) / (1000 * 60 * 60 * 24));
            
            const gun = String(skt.getDate()).padStart(2, '0');
            const ay = String(skt.getMonth() + 1).padStart(2, '0');
            const yil = skt.getFullYear();
            sktStr = `${gun}.${ay}.${yil}`;
            
            kalanStr = fark + tr(" Gün");
            if(fark < 0) kalanStr = tr("GEÇMİŞ"); // Geçmiş kelimesini çevir
        }

        // Tüm metinleri tr() fonksiyonundan geçiriyoruz
        return [tr(p.name), tr(p.brand), tr(p.category), p.quantity + ' ' + tr(p.unit), sktStr, kalanStr];
    });

    doc.autoTable({
        startY: finalY + 5,
        head: [[tr('Ürün'), tr('Marka'), tr('Kategori'), tr('Miktar'), tr('SKT'), tr('Durum')]],
        body: productRows,
        headStyles: { fillColor: [220, 38, 38], font: 'helvetica' },
        alternateRowStyles: { fillColor: [254, 242, 242] },
        styles: { fontSize: 8, font: 'helvetica' }
    });

    doc.save(`StokTakip_Rapor_${new Date().toISOString().slice(0,10)}.pdf`);
}
</script>
</body>
</html>