<?php
require 'db.php';
girisKontrol();

// CSP Nonce Kontrolü
$cspNonce = $cspNonce ?? '';

// --- VERİ HAZIRLIĞI ---
$joinSQL = "LEFT JOIN cabinets c ON p.cabinet_id = c.id 
            LEFT JOIN rooms r ON c.room_id = r.id 
            LEFT JOIN locations l ON r.location_id = l.id";
$whereSQL = "WHERE 1=1";
$params = [];

// Şehir Filtresi
if (isset($_SESSION['aktif_sehir_id'])) {
    $whereSQL .= " AND l.city_id = ?";
    $params[] = $_SESSION['aktif_sehir_id'];
}

// 1. TÜM ÜRÜNLERİ ÇEK
$sql = "SELECT p.*, l.name as loc_name, r.name as room_name, c.name as cab_name 
        FROM products p $joinSQL $whereSQL 
        ORDER BY (p.expiry_date IS NULL), p.expiry_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tumUrunler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. İSTATİSTİKLER
$toplamUrun       = count($tumUrunler);
$bugun            = time();
$riskStats        = ['expired' => 0, 'critical' => 0, 'warning' => 0, 'safe' => 0];
$catStats         = [];
$yasGruplari      = ['0-30 Gün' => 0, '1-3 Ay' => 0, '3-6 Ay' => 0, '> 6 Ay' => 0];
$kritikUrunSayisi = 0;
$yaklasanlar      = [];

foreach ($tumUrunler as $urun) {
    // A. Risk Analizi
    if (empty($urun['expiry_date'])) {
        $riskStats['safe']++;
    } else {
        $skt      = strtotime($urun['expiry_date']);
        $kalanGun = ceil(($skt - $bugun) / 86400);

        if      ($kalanGun < 0)   $riskStats['expired']++;
        elseif  ($kalanGun <= 7)  $riskStats['critical']++;
        elseif  ($kalanGun <= 30) $riskStats['warning']++;
        else                      $riskStats['safe']++;

        if ($kalanGun <= 90) {
            $kritikUrunSayisi++;
            if (count($yaklasanlar) < 10) {
                $yaklasanlar[] = $urun;
            }
        }
    }

    // B. Kategori Analizi
    $cat = $urun['category'] ?? 'Diğer';
    if (!isset($catStats[$cat])) $catStats[$cat] = 0;
    $catStats[$cat]++;

    // C. Stok Yaşı
    if (!empty($urun['purchase_date'])) {
        $alim     = strtotime($urun['purchase_date']);
        $gecenGun = ceil(abs($bugun - $alim) / 86400);
        if      ($gecenGun <= 30)  $yasGruplari['0-30 Gün']++;
        elseif  ($gecenGun <= 90)  $yasGruplari['1-3 Ay']++;
        elseif  ($gecenGun <= 180) $yasGruplari['3-6 Ay']++;
        else                       $yasGruplari['> 6 Ay']++;
    }
}

// 3. ODA VE DOLAP VERİLERİ
$filterPart   = isset($_SESSION['aktif_sehir_id']) ? "AND l.city_id = ?" : "";
$filterParams = isset($_SESSION['aktif_sehir_id']) ? [$_SESSION['aktif_sehir_id']] : [];

$odaSQL = "SELECT r.name as oda_adi, COUNT(c.id) as dolap_sayisi 
           FROM rooms r 
           JOIN locations l ON r.location_id = l.id 
           LEFT JOIN cabinets c ON c.room_id = r.id 
           WHERE 1=1 $filterPart
           GROUP BY r.id 
           ORDER BY dolap_sayisi DESC";
$stmt = $pdo->prepare($odaSQL);
$stmt->execute($filterParams);
$odaVerileri = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$dolapSQL = "SELECT c.name as dolap_adi, COUNT(p.id) as urun_sayisi 
             FROM cabinets c
             JOIN rooms r ON c.room_id = r.id
             JOIN locations l ON r.location_id = l.id
             LEFT JOIN products p ON p.cabinet_id = c.id
             WHERE 1=1 $filterPart
             GROUP BY c.id 
             ORDER BY urun_sayisi DESC 
             LIMIT 10";
$stmt = $pdo->prepare($dolapSQL);
$stmt->execute($filterParams);
$dolapVerileri = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

require 'header.php';
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">
        Genel Bakış
        <span class="text-sm font-normal text-slate-500 dark:text-slate-400 ml-2">
            (<?= htmlspecialchars($_SESSION['aktif_sehir_ad'] ?? 'Tüm Şehirler') ?>)
        </span>
    </h2>
</div>

<!-- ÜST KARTLAR (Mobilde 1 sütun, md ve üstü 3 sütun) -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 mb-8">
    <!-- Toplam ürün -->
    <div class="bg-white dark:bg-slate-800 p-4 md:p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 flex items-center gap-4 transition-colors">
        <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="m7.5 4.27 9 5.15"/>
                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                <path d="m3.3 7 8.7 5 8.7-5"/>
                <path d="M12 22v-9.99"/>
            </svg>
        </div>
        <div class="min-w-0">
            <p class="text-xs md:text-sm text-slate-500 dark:text-slate-400 truncate">Toplam Ürün</p>
            <h3 class="text-xl md:text-2xl font-bold text-slate-800 dark:text-white"><?= $toplamUrun ?></h3>
        </div>
    </div>

    <!-- Kritik ürün -->
    <div class="bg-white dark:bg-slate-800 p-4 md:p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 flex items-center gap-4 transition-colors">
        <div class="p-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg shrink-0">
           <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
               <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
               <path d="M12 9v4"/>
               <path d="M12 17h.01"/>
           </svg>
        </div>
        <div class="min-w-0">
            <p class="text-xs md:text-sm text-slate-500 dark:text-slate-400 truncate">Kritik (3 Ay Altı)</p>
            <h3 class="text-xl md:text-2xl font-bold text-slate-800 dark:text-white"><?= $kritikUrunSayisi ?></h3>
        </div>
    </div>
    
    <!-- PDF kartı: light temada açık, dark temada koyu -->
    <div id="pdfCard"
         class="bg-white dark:bg-slate-700 p-4 md:p-6 rounded-xl shadow-lg border border-slate-200 dark:border-slate-600 
                flex items-center gap-4 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-600 transition 
                group relative overflow-hidden text-slate-800 dark:text-white">
        
        <div class="absolute right-[-6px] bottom-[-6px] text-slate-200 dark:text-slate-500 opacity-40 
                    group-hover:opacity-60 group-hover:scale-110 transition">
            <svg xmlns="http://www.w3.org/2000/svg" width="70" height="70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
                <polyline points="10 9 9 9 8 9"/>
            </svg>
        </div>
        
        <div class="p-3 bg-slate-100 dark:bg-slate-600 text-slate-700 dark:text-white rounded-lg 
                    group-hover:bg-slate-200 dark:group-hover:bg-slate-500 transition shrink-0">
           <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
               <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
               <polyline points="7 10 12 15 17 10"/>
               <line x1="12" y1="15" x2="12" y2="3"/>
           </svg>
        </div>
        <div class="min-w-0">
            <p class="text-xs md:text-sm text-slate-500 dark:text-slate-300">Detaylı Rapor</p>
            <h3 class="text-lg md:text-xl font-bold">PDF İndir</h3>
        </div>
    </div>
</div>

<!-- GRAFİKLER (stack → md'de 2 sütun) -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
    <div class="bg-white dark:bg-slate-800 p-4 md:p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
        <h4 class="font-bold text-slate-700 dark:text-slate-200 mb-3 md:mb-4 text-xs md:text-sm uppercase tracking-wider flex items-center gap-2">
            📊 Kategori Dağılımı
        </h4>
        <div class="h-56 md:h-60 relative"><canvas id="catChart"></canvas></div>
    </div>
    <div class="bg-white dark:bg-slate-800 p-4 md:p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
        <h4 class="font-bold text-slate-700 dark:text-slate-200 mb-3 md:mb-4 text-xs md:text-sm uppercase tracking-wider flex items-center gap-2">
            ⏳ Stok Yaş Analizi
        </h4>
        <div class="h-56 md:h-60 relative"><canvas id="ageChart"></canvas></div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-8">
    <div class="bg-white dark:bg-slate-800 p-4 md:p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
        <h4 class="font-bold text-slate-700 dark:text-slate-200 mb-3 md:mb-4 text-xs md:text-sm uppercase tracking-wider flex items-center gap-2">
            🏠 Odalardaki Dolap Sayısı
        </h4>
        <div class="h-56 md:h-60 relative"><canvas id="odaChart"></canvas></div>
    </div>
    <div class="bg-white dark:bg-slate-800 p-4 md:p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
        <h4 class="font-bold text-slate-700 dark:text-slate-200 mb-3 md:mb-4 text-xs md:text-sm uppercase tracking-wider flex items-center gap-2">
            📦 Dolap Doluluk Oranları (Top 10)
        </h4>
        <div class="h-56 md:h-60 relative"><canvas id="dolapChart"></canvas></div>
    </div>
</div>

<!-- Yaklaşan SKT Tablosu (mobilde yatay scroll) -->
<div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
    <div class="p-4 border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-700/50">
        <h3 class="font-semibold text-slate-700 dark:text-slate-200 text-sm md:text-base">
            Son Kullanma Tarihi Yaklaşanlar (İlk 10)
        </h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[600px] text-sm text-left text-slate-600 dark:text-slate-300">
            <thead class="text-xs text-slate-400 dark:text-slate-500 uppercase bg-slate-50 dark:bg-slate-700">
                <tr>
                    <th class="px-4 md:px-6 py-3">Ürün</th>
                    <th class="px-4 md:px-6 py-3">Kategori</th>
                    <th class="px-4 md:px-6 py-3">Tarih</th>
                    <th class="px-4 md:px-6 py-3">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                <?php if (empty($yaklasanlar)): ?>
                    <tr>
                        <td colspan="4" class="px-4 md:px-6 py-4 text-center text-slate-400 dark:text-slate-500">
                            Riskli (3 aydan az kalan) ürün yok.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach($yaklasanlar as $urun): 
                        if (empty($urun['expiry_date'])) continue;

                        $kalanGun     = ceil((strtotime($urun['expiry_date']) - time()) / 86400);
                        $renk        = $kalanGun < 7 ? 'text-red-600 dark:text-red-400 font-bold' : 'text-orange-500 dark:text-orange-400';
                        $tarihGoster = date('d.m.Y', strtotime($urun['expiry_date']));
                        $durumGoster = $kalanGun < 0 ? 'Süresi Geçmiş' : $kalanGun . ' Gün Kaldı';
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                        <td class="px-4 md:px-6 py-4 font-medium text-slate-800 dark:text-slate-200">
                            <?= htmlspecialchars($urun['name']) ?>
                            <span class="text-xs text-slate-400 dark:text-slate-500 block">
                                <?= htmlspecialchars($urun['brand']) ?>
                            </span>
                        </td>
                        <td class="px-4 md:px-6 py-4"><?= htmlspecialchars($urun['category']) ?></td>
                        <td class="px-4 md:px-6 py-4"><?= $tarihGoster ?></td>
                        <td class="px-4 md:px-6 py-4 <?= $renk ?>"><?= $durumGoster ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<script nonce="<?= $cspNonce ?>">
const catData   = <?= json_encode($catStats) ?>;
const ageData   = <?= json_encode($yasGruplari) ?>;
const odaData   = <?= json_encode($odaVerileri) ?>;
const dolapData = <?= json_encode($dolapVerileri) ?>;

// PDF verisi
const pdfData = <?= json_encode($tumUrunler) ?>;
const stats   = {
    total: <?= (int)$toplamUrun ?>,
    risk: <?= json_encode($riskStats) ?>
};

// Tema algısı
const isDark = document.documentElement.classList.contains('dark');

// Chart genel renkler
Chart.defaults.color       = isDark ? '#cbd5f5' : '#64748b';
Chart.defaults.borderColor = isDark ? 'rgba(148,163,184,0.35)' : 'rgba(148,163,184,0.4)';

const tooltipBg   = isDark ? '#020617' : '#f9fafb';
const tooltipText = isDark ? '#e5e7eb' : '#0f172a';

const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#6366f1', '#14b8a6'];

// 1. Kategori Chart
new Chart(document.getElementById('catChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: Object.keys(catData),
        datasets: [{
            data: Object.values(catData),
            backgroundColor: colors,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth: 10, color: Chart.defaults.color }
            },
            tooltip: {
                backgroundColor: tooltipBg,
                titleColor: tooltipText,
                bodyColor: tooltipText
            }
        }
    }
});

// 2. Yaş Chart
new Chart(document.getElementById('ageChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: Object.keys(ageData),
        datasets: [{
            label: 'Ürün',
            data: Object.values(ageData),
            backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: tooltipBg,
                titleColor: tooltipText,
                bodyColor: tooltipText
            }
        },
        scales: {
            x: { ticks: { color: Chart.defaults.color } },
            y: { ticks: { color: Chart.defaults.color } }
        }
    }
});

// 3. Oda Chart
new Chart(document.getElementById('odaChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: Object.keys(odaData),
        datasets: [{
            label: 'Dolap Sayısı',
            data: Object.values(odaData),
            backgroundColor: '#8b5cf6',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: tooltipBg,
                titleColor: tooltipText,
                bodyColor: tooltipText
            }
        },
        scales: {
            x: { ticks: { color: Chart.defaults.color } },
            y: { ticks: { color: Chart.defaults.color } }
        }
    }
});

// 4. Dolap Chart
new Chart(document.getElementById('dolapChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: Object.keys(dolapData),
        datasets: [{
            label: 'Ürün Sayısı',
            data: Object.values(dolapData),
            backgroundColor: '#f59e0b',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: tooltipBg,
                titleColor: tooltipText,
                bodyColor: tooltipText
            }
        },
        scales: {
            x: { ticks: { color: Chart.defaults.color, maxRotation: 40, minRotation: 0 } },
            y: { ticks: { color: Chart.defaults.color } }
        }
    }
});

// PDF yardımcı
function tr(str) {
    if (!str) return "";
    str = String(str);
    str = str.replace(/ç/g,"c").replace(/ğ/g,"g").replace(/ı/g,"i").replace(/ö/g,"o").replace(/ş/g,"s").replace(/ü/g,"u");
    str = str.replace(/Ç/g,"C").replace(/Ğ/g,"G").replace(/İ/g,"I").replace(/Ö/g,"O").replace(/Ş/g,"S").replace(/Ü/g,"U");
    return str.replace(/&amp;/g,"&").replace(/&lt;/g,"<").replace(/&gt;/g,">").replace(/&quot;/g,'"');
}

function generatePDF() {
    const { jsPDF } = window.jspdf;
    const doc          = new jsPDF();
    const today        = new Date().toLocaleDateString('tr-TR');
    const locationName = "<?= addslashes($_SESSION['aktif_sehir_ad'] ?? 'Tüm Lokasyonlar') ?>";

    doc.setFontSize(20);
    doc.setTextColor(41,128,185);
    doc.text(tr("StokTakip - Genel Rapor"), 14, 20);
    
    doc.setFontSize(10);
    doc.setTextColor(100);
    doc.text(tr("Tarih: ") + today, 14, 28);
    doc.text(tr("Lokasyon: ") + tr(locationName), 14, 33);
    doc.text(tr("Toplam Ürün: ") + stats.total, 14, 38);

    let yPos = 50;

    // Risk barı
    doc.setFontSize(14);
    doc.setTextColor(0);
    doc.text(tr("1. Risk Analizi"), 14, yPos);
    yPos += 8;

    if (stats.total > 0) {
        const barW      = 180;
        const expiredW  = (stats.risk.expired  / stats.total) * barW;
        const criticalW = (stats.risk.critical / stats.total) * barW;
        const warningW  = (stats.risk.warning  / stats.total) * barW;
        const safeW     = (stats.risk.safe     / stats.total) * barW;

        let currentX = 14;
        const barH   = 5;

        if (expiredW  > 0) { doc.setFillColor(239,68,68);  doc.rect(currentX, yPos, expiredW,  barH, 'F'); currentX += expiredW; }
        if (criticalW > 0) { doc.setFillColor(249,115,22); doc.rect(currentX, yPos, criticalW, barH, 'F'); currentX += criticalW; }
        if (warningW  > 0) { doc.setFillColor(250,204,21); doc.rect(currentX, yPos, warningW,  barH, 'F'); currentX += warningW; }
        if (safeW     > 0) { doc.setFillColor(34,197,94);  doc.rect(currentX, yPos, safeW,     barH, 'F'); }
    }

    yPos += 10;
    doc.setFontSize(9);
    doc.setTextColor(80);
    doc.text(
        tr(`Geçmiş: ${stats.risk.expired} | Kritik: ${stats.risk.critical} | Yaklaşan: ${stats.risk.warning} | Güvenli: ${stats.risk.safe}`),
        14,
        yPos
    );

    let finalY = yPos + 15;
    doc.setFontSize(14);
    doc.setTextColor(0);
    doc.text(tr("2. Ürün Listesi"), 14, finalY);

    const productRows = pdfData.map(p => {
        let sktStr   = "-";
        let kalanStr = tr("Süresiz");

        if (p.expiry_date) {
            const skt   = new Date(p.expiry_date);
            const bugun = new Date();
            const fark  = Math.ceil((skt - bugun) / 86400000);

            const gun = String(skt.getDate()).padStart(2,'0');
            const ay  = String(skt.getMonth()+1).padStart(2,'0');
            const yil = skt.getFullYear();
            sktStr    = `${gun}.${ay}.${yil}`;

            kalanStr = fark + tr(" Gün");
            if (fark < 0) kalanStr = tr("GEÇMİŞ");
        }

        return [
            tr(p.name),
            tr(p.brand),
            tr(p.category),
            (p.quantity ?? 0) + ' ' + tr(p.unit),
            sktStr,
            kalanStr
        ];
    });

    doc.autoTable({
        startY: finalY + 5,
        head: [[tr('Ürün'), tr('Marka'), tr('Kategori'), tr('Miktar'), tr('SKT'), tr('Durum')]],
        body: productRows,
        headStyles: { fillColor: [41,128,185], font: 'helvetica' },
        alternateRowStyles: { fillColor: [245,245,245] },
        styles: { fontSize: 8, font: 'helvetica' }
    });

    doc.save(`StokTakip_Rapor_${new Date().toISOString().slice(0,10)}.pdf`);
}

// PDF kartına tıklama (CSP uyumlu)
document.getElementById('pdfCard')?.addEventListener('click', generatePDF);
</script>
</body>
</html>
