<?php
require 'db.php';
girisKontrol();

// CSP Nonce Kontrolü
$cspNonce =$cspNonce ?? '';

// Verileri Çek
$joinSQL = "LEFT JOIN cabinets c ON p.cabinet_id = c.id 
            LEFT JOIN rooms r ON c.room_id = r.id 
            LEFT JOIN locations l ON r.location_id = l.id";
$whereSQL = "WHERE 1=1";
$params = [];

if (isset($_SESSION['aktif_sehir_id'])) {$whereSQL .= " AND l.city_id = ?";
    $params[] =$_SESSION['aktif_sehir_id'];
}

$sql = "SELECT p.*, l.name as loc_name, r.name as room_name, c.name as cab_name 
        FROM products p $joinSQL$whereSQL 
        ORDER BY (p.expiry_date IS NULL) ASC, p.expiry_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$urunler =$stmt->fetchAll(PDO::FETCH_ASSOC);

// --- İSTATİSTİK HESAPLAMA ---
$toplamUrun = count($urunler);$bugun = strtotime('today');

$riskStats = ['expired' => 0, 'critical' => 0, 'warning' => 0, 'safe' => 0];$yasStats  = ['new' => 0, 'stable' => 0, 'old' => 0];
$catStats  = [];$azalanStokSayisi = 0;

foreach ($urunler as $u) {$miktar  = (float)$u['quantity'];$minStok = isset($u['min_quantity']) ? (float)$u['min_quantity'] : 1.0;

    if ($miktar <= $minStok) {$azalanStokSayisi++;
    }

    // Risk Analizi
    if (empty($u['expiry_date'])) {$riskStats['safe']++;
    } else {
        $skt = strtotime($u['expiry_date']);$kalanGun = (int)round(($skt -$bugun) / 86400);
        
        if ($kalanGun < 0)$riskStats['expired']++;
        elseif ($kalanGun <= 7)$riskStats['critical']++;
        elseif ($kalanGun <= 30)$riskStats['warning']++;
        else $riskStats['safe']++;
    }

    // Stok Yaşı
    if (!empty($u['purchase_date'])) {$alim = strtotime($u['purchase_date']);$stokGun = (int)round(abs($bugun -$alim) / 86400);
        if ($stokGun <= 30)$yasStats['new']++;
        elseif ($stokGun <= 90)$yasStats['stable']++;
        else $yasStats['old']++;
    }

    // Kategori
    $cat =$u['category'] ?? 'Diğer';
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
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Konum: <?= htmlspecialchars($_SESSION['aktif_sehir_ad'] ?? 'Tüm Lokasyonlar') ?>
                </p>
            </div>
            <button onclick="generatePDF()" class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg transition flex items-center gap-2 shadow-lg shadow-red-500/30 font-bold text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                PDF İndir
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6" id="report-preview">
            
            <!-- 1. Risk Analizi -->
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-lg text-slate-700 dark:text-white mb-4">1. Son Kullanma Tarihi Durumu</h3>
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
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-orange-500"></span> Kritik (≤7 Gün): <b><?= $riskStats['critical'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-yellow-400"></span> Yaklaşan (≤30 Gün): <b><?= $riskStats['warning'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-green-500"></span> Güvenli: <b><?= $riskStats['safe'] ?></b></div>
                </div>
            </div>

            <!-- 2. Stok Devir Hızı -->
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-lg text-slate-700 dark:text-white mb-4">2. Bekleme Süresi (Stok Yaşı)</h3>
                <div class="flex h-4 rounded-full overflow-hidden mb-4 bg-slate-100 dark:bg-slate-700">
                    <?php if($toplamUrun > 0): ?>
                        <div style="width: <?= ($yasStats['new']/$toplamUrun)*100 ?>%" class="bg-blue-400" title="Yeni (0-30 Gün)"></div>
                        <div style="width: <?= ($yasStats['stable']/$toplamUrun)*100 ?>%" class="bg-purple-400" title="Orta (1-3 Ay)"></div>
                        <div style="width: <?= ($yasStats['old']/$toplamUrun)*100 ?>%" class="bg-slate-400" title="Eski (>3 Ay)"></div>
                    <?php endif; ?>
                </div>
                <div class="grid grid-cols-2 gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-blue-400"></span> Yeni (0-30 Gün): <b><?= $yasStats['new'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-purple-400"></span> Orta (1-3 Ay): <b><?= $yasStats['stable'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-slate-400"></span> Uzun Süreli (>3 Ay): <b><?= $yasStats['old'] ?></b></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-rose-500"></span> Azalan/Biten: <b><?= $azalanStokSayisi ?></b></div>
                </div>
            </div>

            <!-- 3. Kategori Dağılımı -->
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-lg text-slate-700 dark:text-white mb-4">3. Kategori Dağılımı</h3>
                <div class="max-h-60 overflow-y-auto">
                    <table class="w-full text-sm text-slate-600 dark:text-slate-300">
                        <thead class="bg-slate-50 dark:bg-slate-700 text-left font-bold text-slate-500 dark:text-slate-400 sticky top-0">
                            <tr><th class="p-2">Kategori</th><th class="p-2">Adet</th><th class="p-2">Oran</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <?php foreach($catStats as $cat =>$count): ?>
                            <tr>
                                <td class="p-2 font-medium"><?= htmlspecialchars($cat) ?></td>
                                <td class="p-2 font-bold"><?= $count ?></td>
                                <td class="p-2 text-slate-400">%<?= $toplamUrun > 0 ? number_format(($count/$toplamUrun)*100, 1) : 0 ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 4. Acil Tüketilmesi Gerekenler -->
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-lg text-red-600 dark:text-red-400 mb-4">4. Acil Tüketilmesi Gerekenler (≤30 Gün)</h3>
                <ul class="text-sm space-y-2 max-h-60 overflow-y-auto">
                    <?php 
                    $sayac = 0;
                    foreach($urunler as$u): 
                        if(empty($u['expiry_date'])) continue;
                        
                        $kalan = (int)round((strtotime($u['expiry_date']) -$bugun) / 86400);
                        if($kalan > 30) continue; 
                        $sayac++;
                    ?>
                    <li class="flex justify-between border-b dark:border-slate-700 pb-1 text-slate-700 dark:text-slate-300">
                        <span>
                            <b><?= htmlspecialchars($u['name']) ?></b>
                            <?php if(!empty($u['is_opened'])): ?>
                                <span class="text-[10px] bg-amber-100 text-amber-800 px-1 py-0.5 rounded font-bold">Açık</span>
                            <?php endif; ?>
                        </span>
                        <span class="<?= $kalan < 0 ? 'text-red-600 font-bold' : ($kalan <= 7 ? 'text-orange-500 font-bold' : 'text-yellow-600') ?>">
                            <?= $kalan < 0 ? abs($kalan) . ' Gün Geçti' : $kalan . ' Gün Kaldı' ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                    <?php if($sayac == 0): ?>
                        <li class="text-slate-400 dark:text-slate-500">Riskli ürün bulunmuyor.</li>
                    <?php endif; ?>
                </ul>
            </div>

        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<script nonce="<?= $cspNonce ?>">
const stats = {
    total: <?= (int)$toplamUrun ?>,
    risk: <?= json_encode($riskStats) ?>,
    age: <?= json_encode($yasStats) ?>,
    cats: <?= json_encode($catStats) ?>,
    products: <?= json_encode($urunler) ?>
};

function tr(str) {
    if (!str) return "";
    str = String(str);
    str = str.replace(/ç/g,"c").replace(/ğ/g,"g").replace(/ı/g,"i").replace(/ö/g,"o").replace(/ş/g,"s").replace(/ü/g,"u");
    str = str.replace(/Ç/g,"C").replace(/Ğ/g,"G").replace(/İ/g,"I").replace(/Ö/g,"O").replace(/Ş/g,"S").replace(/Ü/g,"U");
    return str.replace(/&amp;/g,"&").replace(/&lt;/g,"<").replace(/&gt;/g,">").replace(/&quot;/g,'"');
}

function generatePDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    const today = new Date().toLocaleDateString('tr-TR');
    const locationName = "<?= addslashes($_SESSION['aktif_sehir_ad'] ?? 'Tum Lokasyonlar') ?>";

    doc.setFontSize(18); 
    doc.setTextColor(41, 128, 185);
    doc.text(tr("StokTakip - Kapsamli Envanter Raporu"), 14, 20);
    
    doc.setFontSize(9); 
    doc.setTextColor(100);
    doc.text(tr("Tarih: ") + today, 14, 27);
    doc.text(tr("Lokasyon: ") + tr(locationName), 14, 32);
    doc.text(tr("Toplam Urun: ") + stats.total, 14, 37);

    let yPos = 46;

    // --- 1. RİSK ANALİZİ GRAFİK ÇUBUĞU ---
    doc.setFontSize(12); 
    doc.setTextColor(0);
    doc.text(tr("1. Son Kullanma Tarihi Risk Analizi"), 14, yPos);
    yPos += 6;

    if (stats.total > 0) {
        const barW      = 180;
        const expiredW  = (stats.risk.expired  / stats.total) * barW;
        const criticalW = (stats.risk.critical / stats.total) * barW;
        const warningW  = (stats.risk.warning  / stats.total) * barW;
        const safeW     = (stats.risk.safe     / stats.total) * barW;

        let currentX = 14;
        const barH   = 5;

        if (expiredW  > 0) { doc.setFillColor(239, 68, 68);  doc.rect(currentX, yPos, expiredW,  barH, 'F'); currentX += expiredW; }
        if (criticalW > 0) { doc.setFillColor(249, 115, 22); doc.rect(currentX, yPos, criticalW, barH, 'F'); currentX += criticalW; }
        if (warningW  > 0) { doc.setFillColor(250, 204, 21); doc.rect(currentX, yPos, warningW,  barH, 'F'); currentX += warningW; }
        if (safeW     > 0) { doc.setFillColor(34, 197, 94);  doc.rect(currentX, yPos, safeW,     barH, 'F'); }
    }

    yPos += 9;
    doc.setFontSize(8.5); 
    doc.setTextColor(80);
    doc.text(tr(`Gecmis: ${stats.risk.expired} | Kritik (<=7 Gun): ${stats.risk.critical} | Yaklasan (<=30 Gun): ${stats.risk.warning} | Guvenli: ${stats.risk.safe}`), 14, yPos);
    
    // --- 2. KATEGORİ DAĞILIMI TABLOSU ---
    yPos += 10;
    doc.setFontSize(12); 
    doc.setTextColor(0);
    doc.text(tr("2. Kategori Ozeti"), 14, yPos);

    const catRows = Object.entries(stats.cats).map(([name, count]) => {
        let percent = stats.total > 0 ? ((count / stats.total) * 100).toFixed(1) : 0;
        return [tr(name), count, `%${percent}`];
    });

    doc.autoTable({
        startY: yPos + 4,
        head: [[tr('Kategori'), tr('Urun Adedi'), tr('Oran')]],
        body: catRows,
        theme: 'striped',
        headStyles: { fillColor: [44, 62, 80], font: 'helvetica' },
        styles: { fontSize: 8, font: 'helvetica' },
        margin: { left: 14, right: 14 }
    });

    // --- 3. TÜM ÜRÜNLER LİSTESİ ---
    let finalY = doc.lastAutoTable.finalY + 12;
    doc.setFontSize(12);
    doc.setTextColor(0);
    doc.text(tr("3. Detayli Envanter Listesi"), 14, finalY);

    const productRows = stats.products.map(p => {
        let sktStr   = "-";
        let durumStr = tr("Suresiz");

        if (p.expiry_date) {
            const skt   = new Date(p.expiry_date);
            const bugun = new Date();
            const fark  = Math.ceil((skt - bugun) / 86400000);
            
            const gun = String(skt.getDate()).padStart(2, '0');
            const ay  = String(skt.getMonth() + 1).padStart(2, '0');
            const yil = skt.getFullYear();
            sktStr    = `${gun}.${ay}.${yil}`;
            
            if (fark < 0) {
                durumStr = tr("GECMIS (") + Math.abs(fark) + tr(" gun)");
            } else {
                durumStr = fark + tr(" Gun");
            }
        }

        let paketDurumu = (p.is_opened && p.is_opened == 1) ? tr(" [Acik]") : "";
        let urunAdi = tr(p.name) + paketDurumu;

        return [
            urunAdi, 
            tr(p.brand || '-'), 
            tr(p.category), 
            (p.quantity ?? 0) + ' ' + tr(p.unit), 
            sktStr, 
            durumStr
        ];
    });

    doc.autoTable({
        startY: finalY + 4,
        head: [[tr('Urun'), tr('Marka'), tr('Kategori'), tr('Miktar'), tr('SKT'), tr('Durum')]],
        body: productRows,
        headStyles: { fillColor: [41, 128, 185], font: 'helvetica' },
        alternateRowStyles: { fillColor: [248, 250, 252] },
        styles: { fontSize: 7.5, font: 'helvetica' },
        margin: { left: 14, right: 14 }
    });

    doc.save(`StokTakip_Rapor_${new Date().toISOString().slice(0,10)}.pdf`);
}
</script>
</body>
</html>