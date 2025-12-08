<?php
require 'db.php';
girisKontrol();

// --- ŞEHİR FİLTRESİ HAZIRLIĞI ---
$joinSQL = "";
$whereSQL = "";
$params = [];

// PDF Raporu için ham veri sorgusu
$pdfJoinSQL = "JOIN cabinets c ON p.cabinet_id = c.id 
               JOIN rooms r ON c.room_id = r.id 
               JOIN locations l ON r.location_id = l.id";
$pdfWhereSQL = "1=1";
$pdfParams = [];

if (isset($_SESSION['aktif_sehir_id'])) {
    $joinSQL = "JOIN cabinets c ON p.cabinet_id = c.id 
                JOIN rooms r ON c.room_id = r.id 
                JOIN locations l ON r.location_id = l.id";
    $whereSQL = "AND l.city_id = ?";
    $params[] = $_SESSION['aktif_sehir_id'];

    $pdfWhereSQL .= " AND l.city_id = ?";
    $pdfParams[] = $_SESSION['aktif_sehir_id'];
}

// 1. TEMEL İSTATİSTİKLER
$toplamUrun = $pdo->prepare("SELECT count(*) FROM products p $joinSQL WHERE 1=1 $whereSQL");
$toplamUrun->execute($params);
$toplamUrun = $toplamUrun->fetchColumn();

// Kritik ürün (Sadece tarihi olanlar)
$kritikUrun = $pdo->prepare("SELECT count(*) FROM products p $joinSQL WHERE p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 90 DAY) $whereSQL");
$kritikUrun->execute($params);
$kritikUrun = $kritikUrun->fetchColumn();

// 2. GRAFİK VERİLERİ
$stmt = $pdo->prepare("SELECT category, count(*) as sayi FROM products p $joinSQL WHERE 1=1 $whereSQL GROUP BY category");
$stmt->execute($params);
$kategoriVerileri = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->prepare("SELECT purchase_date FROM products p $joinSQL WHERE 1=1 $whereSQL");
$stmt->execute($params);
$alimTarihleri = $stmt->fetchAll(PDO::FETCH_COLUMN);

$yasGruplari = ['0-30 Gün' => 0, '1-3 Ay' => 0, '3-6 Ay' => 0, '> 6 Ay' => 0];
$bugun = time();
foreach($alimTarihleri as $tarih) {
    if(!$tarih) continue;
    $gecenGun = ceil(abs($bugun - strtotime($tarih)) / (60 * 60 * 24));
    if ($gecenGun <= 30) $yasGruplari['0-30 Gün']++;
    elseif ($gecenGun <= 90) $yasGruplari['1-3 Ay']++;
    elseif ($gecenGun <= 180) $yasGruplari['3-6 Ay']++;
    else $yasGruplari['> 6 Ay']++;
}

// 3. ODA VE DOLAP VERİLERİ
$odaSQL = "SELECT r.name as oda_adi, count(c.id) as dolap_sayisi 
           FROM rooms r 
           JOIN locations l ON r.location_id = l.id 
           LEFT JOIN cabinets c ON c.room_id = r.id 
           WHERE 1=1 " . (isset($_SESSION['aktif_sehir_id']) ? "AND l.city_id = ?" : "") . "
           GROUP BY r.id ORDER BY dolap_sayisi DESC";
$stmt = $pdo->prepare($odaSQL);
if(isset($_SESSION['aktif_sehir_id'])) $stmt->execute([$_SESSION['aktif_sehir_id']]);
else $stmt->execute();
$odaVerileri = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$dolapSQL = "SELECT c.name as dolap_adi, count(p.id) as urun_sayisi 
             FROM cabinets c
             JOIN rooms r ON c.room_id = r.id
             JOIN locations l ON r.location_id = l.id
             LEFT JOIN products p ON p.cabinet_id = c.id
             WHERE 1=1 " . (isset($_SESSION['aktif_sehir_id']) ? "AND l.city_id = ?" : "") . "
             GROUP BY c.id ORDER BY urun_sayisi DESC LIMIT 10";
$stmt = $pdo->prepare($dolapSQL);
if(isset($_SESSION['aktif_sehir_id'])) $stmt->execute([$_SESSION['aktif_sehir_id']]);
else $stmt->execute();
$dolapVerileri = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. PDF VERİSİ
// Order by expiry_date ASC, NULLs last
$stmt = $pdo->prepare("SELECT p.* FROM products p $pdfJoinSQL WHERE $pdfWhereSQL ORDER BY (p.expiry_date IS NULL), p.expiry_date ASC");
$stmt->execute($pdfParams);
$tumUrunlerPDF = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. YAKLAŞANLAR (Sadece tarihi olanlar)
$stmt = $pdo->prepare("SELECT p.* FROM products p $joinSQL WHERE p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 90 DAY) $whereSQL ORDER BY p.expiry_date ASC LIMIT 10");
$stmt->execute($params);
$yaklasanlar = $stmt->fetchAll();

require 'header.php';
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">
        Genel Bakış
        <span class="text-sm font-normal text-slate-500 dark:text-slate-400 ml-2">
            (<?= $_SESSION['aktif_sehir_ad'] ?? 'Tüm Şehirler' ?>)
        </span>
    </h2>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    
    <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 flex items-center gap-4 transition-colors">
        <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22v-9.99"/></svg>
        </div>
        <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">Toplam Ürün</p>
            <h3 class="text-2xl font-bold text-slate-800 dark:text-white"><?= $toplamUrun ?></h3>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 flex items-center gap-4 transition-colors">
        <div class="p-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg">
           <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
        </div>
        <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">Kritik (3 Ay Altı)</p>
            <h3 class="text-2xl font-bold text-slate-800 dark:text-white"><?= $kritikUrun ?></h3>
        </div>
    </div>
    
    <div onclick="generatePDF()" class="bg-slate-800 dark:bg-slate-700 p-6 rounded-xl shadow-lg border border-slate-700 dark:border-slate-600 flex items-center gap-4 cursor-pointer hover:bg-slate-700 dark:hover:bg-slate-600 transition group relative overflow-hidden">
        <div class="absolute right-[-10px] bottom-[-10px] text-slate-700 dark:text-slate-500 opacity-20 group-hover:opacity-30 group-hover:scale-110 transition">
            <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </div>
        
        <div class="p-3 bg-slate-700 dark:bg-slate-600 text-white rounded-lg group-hover:bg-slate-600 dark:group-hover:bg-slate-500 transition">
           <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </div>
        <div>
            <p class="text-sm text-slate-400">Detaylı Rapor</p>
            <h3 class="text-xl font-bold text-white">PDF İndir</h3>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    <div class="bg-white dark:bg-slate-800 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
        <h4 class="font-bold text-slate-700 dark:text-slate-200 mb-4 text-sm uppercase tracking-wider flex items-center gap-2">📊 Kategori Dağılımı</h4>
        <div class="h-60 relative"><canvas id="catChart"></canvas></div>
    </div>
    <div class="bg-white dark:bg-slate-800 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
        <h4 class="font-bold text-slate-700 dark:text-slate-200 mb-4 text-sm uppercase tracking-wider flex items-center gap-2">⏳ Stok Yaş Analizi</h4>
        <div class="h-60 relative"><canvas id="ageChart"></canvas></div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
    <div class="bg-white dark:bg-slate-800 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
        <h4 class="font-bold text-slate-700 dark:text-slate-200 mb-4 text-sm uppercase tracking-wider flex items-center gap-2">🏠 Odalardaki Dolap Sayısı</h4>
        <div class="h-60 relative"><canvas id="odaChart"></canvas></div>
    </div>
    <div class="bg-white dark:bg-slate-800 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
        <h4 class="font-bold text-slate-700 dark:text-slate-200 mb-4 text-sm uppercase tracking-wider flex items-center gap-2">📦 Dolap Doluluk Oranları (Top 10)</h4>
        <div class="h-60 relative"><canvas id="dolapChart"></canvas></div>
    </div>
</div>

<div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
    <div class="p-4 border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-700/50">
        <h3 class="font-semibold text-slate-700 dark:text-slate-200">Son Kullanma Tarihi Yaklaşanlar</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-slate-600 dark:text-slate-300">
            <thead class="text-xs text-slate-400 dark:text-slate-500 uppercase bg-slate-50 dark:bg-slate-700">
                <tr>
                    <th class="px-6 py-3">Ürün</th>
                    <th class="px-6 py-3">Kategori</th>
                    <th class="px-6 py-3">Tarih</th>
                    <th class="px-6 py-3">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                <?php if (empty($yaklasanlar)): ?>
                    <tr><td colspan="4" class="px-6 py-4 text-center text-slate-400">Riskli ürün yok.</td></tr>
                <?php else: ?>
                    <?php foreach($yaklasanlar as $urun): 
                        if (!empty($urun['expiry_date'])) {
                            $kalanGun = ceil((strtotime($urun['expiry_date']) - time()) / (60 * 60 * 24));
                            $renk = $kalanGun < 7 ? 'text-red-600 dark:text-red-400 font-bold' : 'text-orange-500 dark:text-orange-400';
                            $tarihGoster = date('d.m.Y', strtotime($urun['expiry_date']));
                            $durumGoster = $kalanGun . ' Gün';
                        } else {
                            $kalanGun = 9999;
                            $renk = 'text-slate-500 dark:text-slate-400';
                            $tarihGoster = '-';
                            $durumGoster = 'Süresiz';
                        }
                    ?>
                    <tr class="border-b dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                        <td class="px-6 py-4 font-medium text-slate-800 dark:text-slate-200">
                            <?= htmlspecialchars($urun['name']) ?>
                            <span class="text-xs text-slate-400 block"><?= htmlspecialchars($urun['brand']) ?></span>
                        </td>
                        <td class="px-6 py-4"><?= htmlspecialchars($urun['category']) ?></td>
                        <td class="px-6 py-4"><?= $tarihGoster ?></td>
                        <td class="px-6 py-4 <?= $renk ?>"><?= $durumGoster ?></td>
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

<script>
const catData = <?= json_encode($kategoriVerileri) ?>;
const ageData = <?= json_encode($yasGruplari) ?>;
const odaData = <?= json_encode($odaVerileri) ?>;
const dolapData = <?= json_encode($dolapVerileri) ?>;
const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#6366f1', '#14b8a6'];

// Chart.js Default Font Color for Dark Mode
Chart.defaults.color = document.documentElement.classList.contains('dark') ? '#94a3b8' : '#64748b';

new Chart(document.getElementById('catChart').getContext('2d'), {
    type: 'doughnut',
    data: { labels: Object.keys(catData), datasets: [{ data: Object.values(catData), backgroundColor: colors, borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 10 } } } }
});

new Chart(document.getElementById('ageChart').getContext('2d'), {
    type: 'bar',
    data: { labels: Object.keys(ageData), datasets: [{ label: 'Ürün', data: Object.values(ageData), backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'], borderRadius: 4 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('odaChart').getContext('2d'), {
    type: 'bar',
    data: { labels: Object.keys(odaData), datasets: [{ label: 'Dolap Sayısı', data: Object.values(odaData), backgroundColor: '#8b5cf6', borderRadius: 4 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, indexAxis: 'y' }
});

new Chart(document.getElementById('dolapChart').getContext('2d'), {
    type: 'bar',
    data: { labels: Object.keys(dolapData), datasets: [{ label: 'Ürün Sayısı', data: Object.values(dolapData), backgroundColor: '#f59e0b', borderRadius: 4 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});

function tr(str) { if(!str) return ""; return str.replace(/Ğ/g, "G").replace(/ğ/g, "g").replace(/Ü/g, "U").replace(/ü/g, "u").replace(/Ş/g, "S").replace(/ş/g, "s").replace(/İ/g, "I").replace(/ı/g, "i").replace(/Ö/g, "O").replace(/ö/g, "o").replace(/Ç/g, "C").replace(/ç/g, "c"); }
const pdfData = <?= json_encode($tumUrunlerPDF) ?>;

function generatePDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    const today = new Date().toLocaleDateString('tr-TR');

    doc.setFontSize(20); doc.setTextColor(41, 128, 185);
    doc.text("StokTakip - Envanter Raporu", 14, 20);
    
    doc.setFontSize(10); doc.setTextColor(100);
    doc.text("Tarih: " + today, 14, 28);
    doc.text("Lokasyon: <?= htmlspecialchars($_SESSION['aktif_sehir_ad'] ?? 'Tum Sehirler') ?>", 14, 33);

    const rows = pdfData.map(p => {
        let sktStr = "-";
        let kalanStr = "Suresiz";

        if (p.expiry_date) {
            const skt = new Date(p.expiry_date);
            const bugun = new Date();
            const fark = Math.ceil((skt - bugun) / (1000 * 60 * 60 * 24));
            sktStr = p.expiry_date; 
            kalanStr = fark + ' Gun';
        }

        return [tr(p.name), tr(p.brand), tr(p.category), p.quantity + ' ' + p.unit, sktStr, kalanStr];
    });

    doc.autoTable({
        startY: 40,
        head: [['Urun', 'Marka', 'Kategori', 'Miktar', 'SKT', 'Kalan']],
        body: rows,
        theme: 'striped',
        headStyles: { fillColor: [44, 62, 80] }
    });

    doc.save(`StokTakip_Rapor_${new Date().toISOString().slice(0,10)}.pdf`);
}
</script>
</body>
</html>