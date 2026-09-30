<?php
// excel-import.php - Excel'den (CSV) Toplu Ürün Yükleme
require 'db.php';
girisKontrol();

if (!isset($cspNonce)) { $cspNonce = ''; }

$sehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
$aktifSehirId = $_SESSION['aktif_sehir_id'] ?? '';

$sonuc = ['basarili' => 0, 'hatali' => 0, 'hatalar' => []];
$islemYapildi = false;

// Şablon İndirme İsteği
if (isset($_GET['sablon_indir'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="StokTakip_Sablon.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Barkod', 'Ürün Adı', 'Marka', 'Kategori', 'Alt Kategori', 'Miktar', 'Birim', 'Min Miktar', 'SKT'], ';');
    fputcsv($out, ['8690504000000', 'Örnek Ürün', 'MarkaAdı', 'Gıda', 'Atıştırmalık', '5', 'Adet', '1', '2027-12-31'], ';');
    fclose($out);
    exit;
}

// Dosya Yükleme İşlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_excel'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');
    $islemYapildi = true;

    $cabId = $_POST['cabinet_id'] ?? '';
    if (empty($cabId)) {
        $sonuc['hatalar'][] = "Lütfen ürünlerin ekleneceği hedef dolabı seçin.";
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $sonuc['hatalar'][] = "Dosya yüklenirken bir hata oluştu veya dosya seçilmedi.";
    } else {
        $fileTmp = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($ext !== 'csv') {
            $sonuc['hatalar'][] = "Sadece .csv uzantılı Excel dosyaları yüklenebilir.";
        } else {
            // Dosyayı aç ve oku
            if (($handle = fopen($fileTmp, "r")) !== FALSE) {
                // İlk satırı (başlıkları) atla
                $header = fgetcsv($handle, 1000, ";"); 
                
                // BOM temizliği
                if (isset($header[0])) {
                    $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);
                }

                $satirNo = 1;
                while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                    $satirNo++;
                    if (empty(array_filter($data))) continue; // Boş satırı atla

                    // CSV Sütunları: Barkod[0], Ürün Adı[1], Marka[2], Kategori[3], Alt Kategori[4], Miktar[5], Birim[6], Min Miktar[7], SKT[8]
                    $barkod   = trim($data[0] ?? '');
                    $ad       = trim($data[1] ?? '');
                    $marka    = trim($data[2] ?? '');
                    $kategori = trim($data[3] ?? 'Genel');
                    $altKat   = trim($data[4] ?? '');
                    $miktar   = (float)str_replace(',', '.', ($data[5] ?? 1));
                    $birim    = trim($data[6] ?? 'Adet');
                    $minQty   = (float)str_replace(',', '.', ($data[7] ?? 1));
                    $skt      = trim($data[8] ?? '');
                    
                    if (empty($skt)) $skt = null;
                    if (empty($ad)) {
                        $sonuc['hatali']++;
                        $sonuc['hatalar'][] = "Satır $satirNo: Ürün adı boş olamaz.";
                        continue;
                    }

                    try {
                        $id = uniqid('prod_');
                        $stmt = $pdo->prepare("INSERT INTO products
                            (id, name, barcode, brand, category, sub_category, quantity, min_quantity, unit,
                             cabinet_id, purchase_date, expiry_date, is_opened, added_by_user_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
                        $stmt->execute([
                            $id, $ad, $barkod ?: null, $marka ?: null, $kategori, $altKat, $miktar, $minQty, $birim,
                            $cabId, date('Y-m-d'), $skt, $_SESSION['user_id']
                        ]);
                        $sonuc['basarili']++;
                    } catch (PDOException $e) {
                        $sonuc['hatali']++;
                        $sonuc['hatalar'][] = "Satır $satirNo ($ad): Veritabanı hatası.";
                    }
                }
                fclose($handle);

                if (function_exists('auditLog') && $sonuc['basarili'] > 0) {
                    auditLog('EKLEME', "Excel'den {$sonuc['basarili']} adet ürün toplu içe aktarıldı.");
                }
            } else {
                $sonuc['hatalar'][] = "Dosya okunamadı.";
            }
        }
    }
}

require 'header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-3">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white flex items-center gap-2">
                ⬆️ Excel'den Ürün Yükle
            </h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Hazırladığınız CSV dosyasını sisteme tek seferde aktarın.</p>
        </div>
        <a href="envanter.php" class="text-sm text-blue-600 hover:underline flex items-center gap-1 dark:text-blue-400">
            ← Envantere Dön
        </a>
    </div>

    <?php if ($islemYapildi): ?>
        <div class="mb-6 p-4 rounded-xl border <?= $sonuc['hatali'] === 0 && empty($sonuc['hatalar']) ? 'bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-800' : 'bg-amber-50 border-amber-200 dark:bg-amber-900/20 dark:border-amber-800' ?>">
            <p class="font-bold <?= $sonuc['hatali'] === 0 && empty($sonuc['hatalar']) ? 'text-green-700 dark:text-green-300' : 'text-amber-700 dark:text-amber-300' ?>">
                ✅ <?= $sonuc['basarili'] ?> ürün başarıyla aktarıldı.
                <?= $sonuc['hatali'] > 0 ? '⚠️ ' . $sonuc['hatali'] . ' ürün aktarılamadı.' : '' ?>
            </p>
            <?php if (!empty($sonuc['hatalar'])): ?>
                <ul class="mt-2 text-xs text-red-600 dark:text-red-400 space-y-1 bg-red-50 dark:bg-red-900/10 p-3 rounded border border-red-100 dark:border-red-800 h-32 overflow-y-auto">
                    <?php foreach ($sonuc['hatalar'] as $h): ?>
                        <li>• <?= htmlspecialchars($h) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Sol: Talimatlar -->
        <div class="col-span-1 bg-white dark:bg-slate-800 p-5 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
            <h3 class="font-bold text-slate-800 dark:text-white mb-3">Nasıl Yüklenir?</h3>
            <ol class="text-sm text-slate-600 dark:text-slate-400 space-y-3 list-decimal list-inside">
                <li>Öncelikle örnek şablonu bilgisayarınıza indirin.</li>
                <li>Dosyayı Excel'de açıp ürünlerinizi alt alta listelerek kaydedin.</li>
                <li>Kaydederken formatın <b>CSV (Virgülle Ayrılmış)</b> olarak kaldığına emin olun (Excel otomatik yapar).</li>
                <li>Yüklemeden önce sağ taraftan ürünlerin konulacağı hedef dolabı seçin ve yükleyin.</li>
            </ol>
            <a href="?sablon_indir=1" class="mt-5 block text-center bg-blue-100 hover:bg-blue-200 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50 py-2 px-4 rounded-lg font-bold text-sm transition">
                📥 Örnek Şablonu İndir
            </a>
        </div>

        <!-- Sağ: Yükleme Formu -->
        <div class="col-span-1 md:col-span-2 bg-white dark:bg-slate-800 p-5 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
            <form method="POST" enctype="multipart/form-data" class="space-y-5">
                <?php echo csrfAlaniniEkle(); ?>
                <input type="hidden" name="import_excel" value="1">

                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border border-blue-100 dark:border-blue-800">
                    <h4 class="text-sm font-bold text-blue-800 dark:text-blue-300 mb-2">1. Hedef Konumu Seçin</h4>
                    <p class="text-xs text-blue-600 dark:text-blue-400 mb-3">Excel'deki tüm ürünler seçtiğiniz bu dolaba eklenecektir.</p>
                    
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Şehir</label>
                            <select id="g_city" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="globalMekanYukle()">
                                <option value="">Seçiniz</option>
                                <?php foreach ($sehirler as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= ($aktifSehirId == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Mekan</label>
                            <select id="g_loc" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="globalOdaYukle()" disabled>
                                <option value="">Önce şehir</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Oda</label>
                            <select id="g_room" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="globalDolapYukle()" disabled>
                                <option value="">Önce mekan</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Dolap *</label>
                            <select name="cabinet_id" id="g_cab" required class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" disabled>
                                <option value="">Önce oda</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-bold text-slate-800 dark:text-white mb-2">2. CSV Dosyasını Seçin</h4>
                    <input type="file" name="csv_file" accept=".csv" required 
                        class="w-full text-sm text-slate-500 dark:text-slate-400
                        file:mr-4 file:py-2 file:px-4
                        file:rounded-lg file:border-0
                        file:text-sm file:font-semibold
                        file:bg-indigo-50 file:text-indigo-700
                        hover:file:bg-indigo-100
                        dark:file:bg-indigo-900/30 dark:file:text-indigo-400
                        border border-slate-200 dark:border-slate-600 rounded-lg p-2 bg-slate-50 dark:bg-slate-700/50">
                </div>

                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-xl shadow-lg shadow-green-500/30 transition flex items-center justify-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Dosyayı Yükle ve Kaydet
                </button>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= $cspNonce ?>">
// ===== CASCADE LOKASYON YÜKLEME =====
async function globalMekanYukle() {
    const cityId = document.getElementById('g_city').value;
    const locSel = document.getElementById('g_loc');
    locSel.innerHTML = '<option value="">Yükleniyor...</option>'; locSel.disabled = true;
    document.getElementById('g_room').innerHTML = '<option value="">Önce mekan</option>'; document.getElementById('g_room').disabled = true;
    document.getElementById('g_cab').innerHTML  = '<option value="">Önce oda</option>'; document.getElementById('g_cab').disabled   = true;

    if (!cityId) { locSel.innerHTML = '<option value="">Önce şehir</option>'; return; }

    const data = await fetch(`ajax.php?islem=get_mekanlar&id=${cityId}`).then(r => r.json()).catch(() => []);
    locSel.innerHTML = '<option value="">Seçiniz</option>';
    data.forEach(d => locSel.innerHTML += `<option value="${d.id}">${d.name}</option>`);
    locSel.disabled = false;
}

async function globalOdaYukle() {
    const locId = document.getElementById('g_loc').value;
    const roomSel = document.getElementById('g_room');
    roomSel.innerHTML = '<option value="">Yükleniyor...</option>'; roomSel.disabled  = true;
    document.getElementById('g_cab').innerHTML = '<option value="">Önce oda</option>'; document.getElementById('g_cab').disabled  = true;

    if (!locId) { roomSel.innerHTML = '<option value="">Önce mekan</option>'; return; }

    const data = await fetch(`ajax.php?islem=get_odalar&id=${locId}`).then(r => r.json()).catch(() => []);
    roomSel.innerHTML = '<option value="">Seçiniz</option>';
    data.forEach(d => roomSel.innerHTML += `<option value="${d.id}">${d.name}</option>`);
    roomSel.disabled = false;
}

async function globalDolapYukle() {
    const roomId = document.getElementById('g_room').value;
    const cabSel = document.getElementById('g_cab');
    cabSel.innerHTML = '<option value="">Yükleniyor...</option>'; cabSel.disabled  = true;

    if (!roomId) { cabSel.innerHTML = '<option value="">Önce oda</option>'; return; }

    const data = await fetch(`ajax.php?islem=get_dolaplar&id=${roomId}`).then(r => r.json()).catch(() => []);
    cabSel.innerHTML = '<option value="">Seçiniz</option>';
    data.forEach(d => cabSel.innerHTML += `<option value="${d.id}">${d.name}</option>`);
    cabSel.disabled = false;
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('g_city').value) globalMekanYukle();
});
</script>
</body>
</html>
