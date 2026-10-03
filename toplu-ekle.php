<?php
// toplu-ekle.php - Marketten Dönünce Hızlı Toplu Ürün Ekleme (Modernize Edilmiş)
require 'db.php';
girisKontrol();

if (!isset($cspNonce)) { $cspNonce = ''; }

if (!function_exists('turkceSirala')) {
    function turkceSirala(&$array) {
        $harfler = ['ç'=>'c', 'ğ'=>'g', 'ı'=>'i', 'i'=>'ia', 'ö'=>'o', 'ş'=>'s', 'ü'=>'u', 'Ç'=>'C', 'Ğ'=>'G', 'İ'=>'I', 'Ö'=>'O', 'Ş'=>'S', 'Ü'=>'U'];
        usort($array, function($a, $b) use ($harfler) {
            $aTr = strtr($a, $harfler);
            $bTr = strtr($b, $harfler);
            return strcasecmp($aTr, $bTr);
        });
    }
}

if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    $stmtSehir = $pdo->prepare("SELECT c.* FROM cities c JOIN user_city_assignments uca ON c.id = uca.city_id WHERE uca.user_id = ? ORDER BY c.name ASC");
    $stmtSehir->execute([$_SESSION['user_id']]);
    $sehirler = $stmtSehir->fetchAll();
} else {
    $sehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
}

$kategoriler = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$aktifSehirId = $_SESSION['aktif_sehir_id'] ?? '';

// Kategori -> Alt kategoriler eşleme tablosu (JavaScript için hızlı önbellek)
$katHaritasi = [];
foreach ($kategoriler as $kat) {
    $subs = [];
    if (!empty($kat['sub_categories'])) {
        $subs = array_filter(array_map('trim', explode(',', $kat['sub_categories'])));
        turkceSirala($subs);
    }
    $katHaritasi[$kat['name']] = array_values($subs);
}

// Ürün tipleri (Cinsler) haritası (Birim ve kritik eşik bilgileri)
$stmtTipler = $pdo->query("SELECT id, name, category, default_unit, min_threshold FROM product_types");
$mevcutTipler = $stmtTipler->fetchAll(PDO::FETCH_ASSOC);

$tipHaritasi = [];
foreach ($mevcutTipler as $mt) {
    $tipHaritasi[$mt['name']] = [
        'id'            => $mt['id'],
        'category'      => $mt['category'],
        'default_unit'  => $mt['default_unit'],
        'min_threshold' => (float)$mt['min_threshold']
    ];
}

$sonuc = ['basarili' => 0, 'hatali' => 0, 'hatalar' => []];
$islemYapildi = false;

// --- POST: TOPLU KAYIT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toplu_kaydet'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');

    $urunler       = $_POST['urunler']       ?? [];
    $cabinet_ids   = $_POST['cabinet_id']    ?? [];
    $kategoris     = $_POST['category']      ?? [];
    $alt_kategoris = $_POST['sub_category']  ?? [];
    $miktarlar     = $_POST['quantity']      ?? [];
    $birimler      = $_POST['unit']          ?? [];
    $sktler        = $_POST['expiry_date']   ?? [];
    $markalar      = $_POST['brand']         ?? [];
    $barkodlar     = $_POST['barcode']       ?? [];
    $suresizler    = $_POST['is_suresiz']     ?? [];
    
    // Global alım tarihi (tüm toplu eklenenlere uygulanır)
    $genel_alim = !empty($_POST['g_purchase_date']) ? $_POST['g_purchase_date'] : date('Y-m-d');
    $varsayilanDolap = $_POST['g_cab'] ?? '';

    $count = count($urunler);
    $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';

    for ($i = 0; $i < $count; $i++) {
        $ad = trim($urunler[$i] ?? '');
        if ($ad === '') continue; // Boş satırları atla

        $cabId   = !empty($cabinet_ids[$i]) ? $cabinet_ids[$i] : $varsayilanDolap;
        $kat     = trim($kategoris[$i] ?? 'Gıda');
        $altKat  = trim($alt_kategoris[$i] ?? '');
        $pType   = $altKat; // Cins = Alt Kategori
        $miktar  = (float)($miktarlar[$i] ?? 1);
        if ($miktar <= 0) $miktar = 1.0;
        $birim   = trim($birimler[$i] ?? 'Adet');

        // SKT kontrolü: is_suresiz seçilmişse veya tarih boşsa NULL
        $isSuresiz = !empty($suresizler[$i]) && $suresizler[$i] === '1';
        $skt     = (!$isSuresiz && !empty($sktler[$i])) ? $sktler[$i] : null;

        $marka   = trim($markalar[$i] ?? '');
        $barkod  = trim($barkodlar[$i] ?? '');

        // Ürün tipi eşleştirmesi / otomatik oluşturma
        $pTypeId = null;
        $minQty  = 1.0;

        if (!empty($altKat)) {
            if (isset($tipHaritasi[$altKat])) {
                $pTypeId = $tipHaritasi[$altKat]['id'];
                $minQty  = $tipHaritasi[$altKat]['min_threshold'];
            } else {
                // Veritabanında ara
                $stmtTip = $pdo->prepare("SELECT id, min_threshold, default_unit FROM product_types WHERE (name = ? OR sub_category = ?) LIMIT 1");
                $stmtTip->execute([$altKat, $altKat]);
                $tipRow = $stmtTip->fetch();

                if ($tipRow) {
                    $pTypeId = $tipRow['id'];
                    $minQty  = (float)$tipRow['min_threshold'];
                } else {
                    $pTypeId = 'pt_' . uniqid();
                    $stmtInsTip = $pdo->prepare("INSERT INTO product_types (id, name, category, sub_category, default_unit, min_threshold) VALUES (?,?,?,?,?,?)");
                    $stmtInsTip->execute([$pTypeId, $altKat, $kat, $altKat, $birim, 1.00]);
                    $minQty  = 1.00;
                    $tipHaritasi[$altKat] = ['id' => $pTypeId, 'category' => $kat, 'default_unit' => $birim, 'min_threshold' => 1.00];
                }
            }
        }

        if (empty($cabId)) {
            $sonuc['hatali']++;
            $sonuc['hatalar'][] = "$ad — Dolap seçilmedi.";
            continue;
        }

        // IDOR Koruması: cabinet_id'nin kullanıcının yetkili şehrine ait olduğunu doğrula
        if (!$isAdmin) {
            $stmtCabCheck = $pdo->prepare("SELECT COUNT(*) FROM cabinets cab
                JOIN rooms r ON cab.room_id = r.id
                JOIN locations l ON r.location_id = l.id
                JOIN user_city_assignments uca ON l.city_id = uca.city_id AND uca.user_id = ?
                WHERE cab.id = ?");
            $stmtCabCheck->execute([$_SESSION['user_id'], $cabId]);
            if ($stmtCabCheck->fetchColumn() == 0) {
                $sonuc['hatali']++;
                $sonuc['hatalar'][] = "$ad — Bu dolaba ekleme yetkiniz yok.";
                sistemLogla("IDOR Girişimi - Yetkisiz dolaba ürün ekleme: user={$_SESSION['user_id']}, cabinet=$cabId", 'SECURITY');
                continue;
            }
        }

        try {
            $id = uniqid('prod_');
            $stmt = $pdo->prepare("INSERT INTO products
                (id, name, barcode, brand, product_type, product_type_id, category, sub_category, quantity, min_quantity, unit,
                 cabinet_id, purchase_date, expiry_date, is_opened, added_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
            $stmt->execute([
                $id, $ad, $barkod ?: null, $marka ?: null, $pType ?: null, $pTypeId, $kat, $altKat, $miktar, $minQty, $birim,
                $cabId, $genel_alim, $skt, $_SESSION['user_id']
            ]);

            auditLog('EKLEME', "$ad ($miktar $birim) toplu ekleme ile sisteme eklendi.");
            $sonuc['basarili']++;
        } catch (PDOException $e) {
            $sonuc['hatali']++;
            sistemLogla("Toplu Ekleme Hatası ($ad): " . $e->getMessage(), 'ERROR');
            $sonuc['hatalar'][] = "$ad — Kaydedilirken bir veritabanı hatası oluştu.";
        }
    }

    if ($sonuc['basarili'] > 0 && function_exists('bildirimleriGuncelle')) {
        bildirimleriGuncelle($pdo);
    }

    $islemYapildi = true;
}

require 'header.php';
?>

<div class="max-w-7xl mx-auto pb-16">

    <!-- ÜST BAŞLIK -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-3">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white flex items-center gap-2">
                📋 Toplu Ürün Ekleme
            </h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Market veya pazar alışverişinden dönünce birden fazla ürünü tek seferde kilerinize ekleyin.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="urun-ekle.php" class="text-sm bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 px-4 py-2 rounded-lg font-bold flex items-center gap-1.5 transition">
                ← Tekli Ürün Ekle
            </a>
            <a href="envanter.php" class="text-sm bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-800 px-4 py-2 rounded-lg font-bold flex items-center gap-1.5 transition">
                📦 Envantere Git
            </a>
        </div>
    </div>

    <!-- İŞLEM SONUÇ BİLDİRİMİ -->
    <?php if ($islemYapildi): ?>
        <div class="mb-6 p-4 rounded-xl border <?= $sonuc['hatali'] === 0 ? 'bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-800' : 'bg-amber-50 border-amber-200 dark:bg-amber-900/20 dark:border-amber-800' ?>">
            <p class="font-bold text-base <?= $sonuc['hatali'] === 0 ? 'text-green-700 dark:text-green-300' : 'text-amber-700 dark:text-amber-300' ?>">
                ✅ <?= $sonuc['basarili'] ?> ürün başarıyla eklendi.
                <?= $sonuc['hatali'] > 0 ? '⚠️ ' . $sonuc['hatali'] . ' ürün eklenemedi.' : '' ?>
            </p>
            <?php if (!empty($sonuc['hatalar'])): ?>
                <ul class="mt-2 text-xs text-red-600 dark:text-red-400 space-y-1">
                    <?php foreach ($sonuc['hatalar'] as $h): ?>
                        <li>• <?= htmlspecialchars($h) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- 📍 VARSAYILAN KONUM VE ORTAK AYARLAR -->
    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 mb-5 shadow-sm">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center pb-3 mb-4 border-b border-slate-100 dark:border-slate-700 gap-2">
            <h3 class="text-sm font-bold text-blue-600 dark:text-blue-400 flex items-center gap-2">
                📍 Varsayılan Konum ve Alım Tarihi
                <span class="text-xs font-normal text-slate-400">(Her satırda bağımsız değiştirilebilir)</span>
            </h3>
            <span id="aktifDolapDurumu" class="text-xs px-2.5 py-1 rounded bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 font-semibold">
                Dolap Seçilmedi
            </span>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div>
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Şehir</label>
                <select id="g_city" class="w-full p-2 border rounded-lg text-sm bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition focus:ring-2 focus:ring-blue-500 outline-none">
                    <option value="">Seçiniz...</option>
                    <?php foreach ($sehirler as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($aktifSehirId == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Mekan</label>
                <select id="g_loc" class="w-full p-2 border rounded-lg text-sm bg-slate-50 dark:bg-slate-700/50 dark:border-slate-600 dark:text-white transition focus:ring-2 focus:ring-blue-500 outline-none" disabled>
                    <option value="">Önce şehir</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Oda</label>
                <select id="g_room" class="w-full p-2 border rounded-lg text-sm bg-slate-50 dark:bg-slate-700/50 dark:border-slate-600 dark:text-white transition focus:ring-2 focus:ring-blue-500 outline-none" disabled>
                    <option value="">Önce mekan</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Dolap</label>
                <select id="g_cab" class="w-full p-2 border rounded-lg text-sm bg-slate-50 dark:bg-slate-700/50 dark:border-slate-600 dark:text-white transition focus:ring-2 focus:ring-blue-500 outline-none" disabled>
                    <option value="">Önce oda</option>
                </select>
            </div>
            <div class="col-span-2 md:col-span-1">
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Toplu Alım Tarihi</label>
                <input type="date" form="topluForm" name="g_purchase_date" value="<?= date('Y-m-d') ?>" class="w-full p-2 border rounded-lg text-sm bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 mt-4 pt-3 border-t border-slate-100 dark:border-slate-700/60">
            <button type="button" id="btnHepsineUygula"
                class="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-lg text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-sm">
                ↓ Seçilen Dolabı Tüm Satırlara Uygula
            </button>

            <!-- Hızlı Satır İşlemleri -->
            <div class="flex items-center gap-1.5 flex-wrap justify-between sm:justify-end">
                <span class="text-xs text-slate-400 font-bold hidden sm:inline">Hızlı Satır:</span>
                <button type="button" onclick="satirEkle()" class="px-2.5 py-1.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-xs font-bold rounded-md transition">+1 Satır</button>
                <button type="button" onclick="topluSatirEkle(5)" class="px-2.5 py-1.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-xs font-bold rounded-md transition">+5 Satır</button>
                <button type="button" onclick="topluSatirEkle(10)" class="px-2.5 py-1.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-xs font-bold rounded-md transition">+10 Satır</button>
                <button type="button" onclick="bosSatirlariTemizle()" class="px-2.5 py-1.5 text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-900/20 text-xs font-bold rounded-md transition" title="İsmi boş olan satırları sil">🧹 Boşları Sil</button>
            </div>
        </div>
    </div>

    <!-- ── FORM VE TABLO ── -->
    <form method="POST" id="topluForm">
        <?php echo csrfAlaniniEkle(); ?>
        <input type="hidden" name="toplu_kaydet" value="1">

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
            <!-- Mobil Kaydırma İpucu -->
            <div class="sm:hidden px-3 py-2 flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400 font-medium border-b border-slate-100 dark:border-slate-700/60 bg-slate-50/70 dark:bg-slate-700/30">
                <span class="flex items-center gap-1.5">👉 <strong>İpucu:</strong> Tabloyu sağa kaydırarak tüm alanları doldurabilirsiniz</span>
                <span class="text-xs text-slate-400">⇄</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs min-w-[1080px]">
                    <thead class="bg-slate-50 dark:bg-slate-700/60 text-[11px] font-bold uppercase text-slate-500 dark:text-slate-400 border-b dark:border-slate-700">
                        <tr>
                            <th class="p-3 w-10 text-center">#</th>
                            <th class="p-3 w-48">Kategori & Cins</th>
                            <th class="p-3 w-52">Barkod</th>
                            <th class="p-3 w-36">Marka</th>
                            <th class="p-3 min-w-[190px]">Ürün Adı</th>
                            <th class="p-3 w-32">Miktar / Birim</th>
                            <th class="p-3 w-44">Son Kullanma (SKT)</th>
                            <th class="p-3 w-44">Hedef Dolap</th>
                            <th class="p-3 w-10 text-center">Sil</th>
                        </tr>
                    </thead>
                    <tbody id="satirListesi" class="divide-y divide-slate-100 dark:divide-slate-700/60 text-slate-700 dark:text-slate-200">
                        <!-- JS ile satırlar dinamik basılacak -->
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row gap-4 mt-6 items-center justify-between">
            <div class="flex items-center gap-2">
                <button type="button" id="btnSatirEkle"
                    class="flex items-center gap-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 px-5 py-2.5 rounded-lg font-bold text-sm transition">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Yeni Satır Ekle
                </button>
                <span id="toplamSatirSayisi" class="text-xs text-slate-400 font-bold ml-2">1 satır</span>
            </div>

            <button type="submit" id="btnKaydet"
                class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white px-10 py-3 rounded-xl font-bold text-sm transition shadow-lg shadow-green-600/30 flex items-center justify-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Tümünü Kaydet ve Bitir
            </button>
        </div>
    </form>
</div>

<!-- ── BARKOD KAMERA MODAL ── -->
<div id="barkodModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 backdrop-blur-sm p-4">
    <div class="bg-white dark:bg-slate-800 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden border border-slate-200 dark:border-slate-700">
        <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="12" y1="8" x2="12" y2="16"/></svg>
                Barkod Tarayıcı
            </h3>
            <button type="button" onclick="barkodModalKapat()" class="text-slate-400 hover:text-red-500 transition-colors p-1">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="p-6 flex flex-col items-center">
            <div id="barkodReader" class="w-full max-w-[300px] min-h-[250px] bg-black rounded-xl overflow-hidden relative shadow-inner"></div>
            <p id="barkodSonucMetni" class="mt-4 text-sm font-bold text-slate-600 dark:text-slate-300 text-center"></p>
        </div>
    </div>
</div>

<script nonce="<?= $cspNonce ?>">
// Veritabanı Haritaları
const KATEGORI_HARITASI = <?= json_encode($katHaritasi, JSON_UNESCAPED_UNICODE) ?>;
const TIP_HARITASI      = <?= json_encode($tipHaritasi, JSON_UNESCAPED_UNICODE) ?>;
const BIRIMLER          = ['Adet', 'Paket', 'Kg', 'Litre'];

let satirSayaci       = 0;
let currentDolaplar   = []; // Odadaki dolaplar listesi [{id, name}]
let currentGlobalCab  = '';
let currentGlobalCabName = '';

// ── YENİ SATIR EKLEME FONKSİYONU ──
function satirEkle(varsayilanlar = {}) {
    satirSayaci++;
    const n = satirSayaci;

    const ad       = varsayilanlar.ad || '';
    const marka    = varsayilanlar.marka || '';
    const miktar   = varsayilanlar.miktar || 1;
    const birim    = varsayilanlar.birim || 'Adet';
    const skt      = varsayilanlar.skt || '';
    const barkod   = varsayilanlar.barkod || '';
    const kat      = varsayilanlar.kategori || '';
    const altKat   = varsayilanlar.alt_kategori || '';
    const cabId    = varsayilanlar.dolap_id || currentGlobalCab || '';

    // Kategori seçenekleri
    const katKeys = Object.keys(KATEGORI_HARITASI);
    const katOpts = katKeys.map(k => `<option value="${k}" ${k === kat ? 'selected' : ''}>${k}</option>`).join('');

    // Birim seçenekleri
    const birimOpts = BIRIMLER.map(b => `<option value="${b}" ${b === birim ? 'selected' : ''}>${b}</option>`).join('');

    // Dolap seçenekleri
    let dolapOpts = '<option value="">Dolap Seç...</option>';
    if (currentDolaplar.length > 0) {
        dolapOpts = currentDolaplar.map(d => `<option value="${d.id}" ${d.id === cabId ? 'selected' : ''}>${d.name}</option>`).join('');
    } else if (cabId) {
        dolapOpts = `<option value="${cabId}" selected>${currentGlobalCabName || 'Seçili Dolap'}</option>`;
    }

    const tr = document.createElement('tr');
    tr.id = `satir_${n}`;
    tr.className = 'hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group';
    tr.innerHTML = `
        <td class="p-3 text-center font-bold text-slate-400">
            ${n}
        </td>

        <td class="p-3 space-y-1.5">
            <select id="kat_${n}" name="category[]" data-satir="${n}" class="kat-select w-full p-1.5 border rounded-lg text-xs bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-blue-500 outline-none transition">
                <option value="">Kategori Seç...</option>
                ${katOpts}
            </select>
            <select id="alt_kat_${n}" name="sub_category[]" data-satir="${n}" class="alt-kat-select w-full p-1.5 border rounded-lg text-xs bg-slate-50 dark:bg-slate-700/60 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-blue-500 outline-none transition">
                <option value="">Cins / Alt Kategori</option>
            </select>
        </td>

        <td class="p-3 space-y-1.5">
            <input type="text" id="barkod_input_${n}" name="barcode[]" value="${escHtml(barkod)}" placeholder="Barkod girin..." 
                class="w-full p-1.5 border rounded-lg text-xs dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-blue-500 outline-none transition"
                data-satir="${n}">
            <div class="flex items-center gap-1.5">
                <button type="button" class="btn-kamera-ac flex-1 py-1 px-2 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-md transition text-[11px] font-bold flex items-center justify-center gap-1 shadow-sm border border-indigo-200 dark:border-indigo-800" 
                    data-satir="${n}" title="Kamera ile Barkod Tara">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="12" y1="8" x2="12" y2="16"/></svg>
                    <span>Kamera</span>
                </button>
                <button type="button" class="btn-barkod-sorgula flex-1 py-1 px-2 bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/50 rounded-md transition text-[11px] font-bold flex items-center justify-center gap-1 shadow-sm border border-blue-200 dark:border-blue-800" 
                    data-satir="${n}" title="Barkodu Sorgula">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <span>Sorgula</span>
                </button>
            </div>
            <div id="barkod_msg_${n}" class="text-[10px] font-bold hidden truncate"></div>
        </td>

        <td class="p-3">
            <div class="flex items-center gap-1">
                <input type="text" id="marka_${n}" name="brand[]" value="${escHtml(marka)}" placeholder="Marka..."
                    class="w-full p-1.5 border rounded-lg text-xs dark:bg-slate-700 dark:border-slate-600 dark:text-slate-300 focus:ring-1 focus:ring-blue-500 outline-none transition">
                <button type="button" onclick="document.getElementById('marka_${n}').value='Açık / Markasız'"
                    class="text-[10px] bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 px-1.5 py-1.5 rounded border dark:border-slate-600 transition whitespace-nowrap" title="Açık / Markasız Yap">
                    Açık
                </button>
            </div>
        </td>

        <td class="p-3">
            <input type="text" id="urun_ad_${n}" name="urunler[]" value="${escHtml(ad)}" placeholder="Ürün adı (Örn: Kaşar 400g) *" required
                class="w-full p-1.5 border rounded-lg text-xs font-semibold dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-blue-500 outline-none transition">
        </td>

        <td class="p-3 space-y-1.5">
            <input type="number" name="quantity[]" value="${miktar}" min="0.01" step="0.01" required
                class="w-full p-1.5 border rounded-lg text-xs text-center font-bold dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-blue-500 outline-none transition">
            <select id="birim_${n}" name="unit[]" class="w-full p-1.5 border rounded-lg text-xs bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                ${birimOpts}
            </select>
        </td>

        <td class="p-3 space-y-1.5">
            <input type="date" id="skt_${n}" name="expiry_date[]" value="${skt}"
                class="w-full p-1.5 border border-red-200 dark:border-red-900/40 rounded-lg text-xs dark:bg-slate-700 dark:text-white focus:ring-1 focus:ring-red-400 outline-none transition min-w-[130px]">
            <input type="hidden" id="is_suresiz_${n}" name="is_suresiz[]" value="0">
            <label class="flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400 font-semibold cursor-pointer">
                <input type="checkbox" id="no_skt_${n}" data-satir="${n}" class="no-skt-checkbox rounded text-blue-600 w-3.5 h-3.5">
                <span>Süresiz / SKT Yok</span>
            </label>
        </td>

        <td class="p-3">
            <select id="cab_${n}" name="cabinet_id[]" required class="w-full p-1.5 border rounded-lg text-xs bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                ${dolapOpts}
            </select>
        </td>

        <td class="p-3 text-center">
            <button type="button" class="btn-satir-sil text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 p-1.5 rounded-lg transition" data-id="${n}" title="Satırı Sil">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
        </td>
    `;

    document.getElementById('satirListesi').appendChild(tr);

    // Kategori varsa alt kategorileri doldur
    if (kat) {
        kategoriDegisti(n, altKat);
    }

    satirSayisiniGuncelle();
}

// ── ÇOKLU SATIR EKLEME ──
function topluSatirEkle(adet = 5) {
    for (let i = 0; i < adet; i++) {
        satirEkle();
    }
}

// ── BOŞ SATIRLARI TEMİZLE ──
function bosSatirlariTemizle() {
    const satirlar = document.querySelectorAll('#satirListesi tr');
    let silinen = 0;
    satirlar.forEach(tr => {
        const input = tr.querySelector('[name="urunler[]"]');
        if (input && !input.value.trim() && satirlar.length - silinen > 1) {
            tr.remove();
            silinen++;
        }
    });
    satirSayisiniGuncelle();
}

function satirSayisiniGuncelle() {
    const sayi = document.querySelectorAll('#satirListesi tr').length;
    const badge = document.getElementById('toplamSatirSayisi');
    if (badge) badge.textContent = sayi + ' satır';
}

function satirSil(n) {
    const el = document.getElementById(`satir_${n}`);
    if (el) {
        const total = document.querySelectorAll('#satirListesi tr').length;
        if (total > 1) {
            el.remove();
        } else {
            // Tek satır kaldıysa içeriğini temizle
            el.querySelectorAll('input').forEach(i => i.value = '');
        }
        satirSayisiniGuncelle();
    }
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── KATEGORİ DEĞİŞİNCE ALT KATEGORİLERİ DOLDUR ──
function kategoriDegisti(n, seciliAltKat = '') {
    const katSelect = document.getElementById(`kat_${n}`);
    const altKatSelect = document.getElementById(`alt_kat_${n}`);
    if (!katSelect || !altKatSelect) return;

    const kat = katSelect.value;
    altKatSelect.innerHTML = '<option value="">Cins / Alt Kategori</option>';

    if (kat && KATEGORI_HARITASI[kat]) {
        KATEGORI_HARITASI[kat].forEach(sub => {
            const opt = document.createElement('option');
            opt.value = sub;
            opt.textContent = sub;
            if (seciliAltKat && seciliAltKat.toLowerCase() === sub.toLowerCase()) {
                opt.selected = true;
            }
            altKatSelect.appendChild(opt);
        });
    }

    if (seciliAltKat) {
        altKategoriDegisti(n);
    }
}

// ── CİNS / ALT KATEGORİ DEĞİŞİNCE BİRİMİ OTOMATİK SEÇ ──
function altKategoriDegisti(n) {
    const altKatSelect = document.getElementById(`alt_kat_${n}`);
    const birimSelect   = document.getElementById(`birim_${n}`);
    const urunAdInput   = document.getElementById(`urun_ad_${n}`);
    if (!altKatSelect) return;

    const sub = altKatSelect.value.trim();
    if (urunAdInput && !urunAdInput.value && sub) {
        urunAdInput.placeholder = "Örn: " + sub;
    }

    if (!birimSelect) return;

    if (sub && TIP_HARITASI[sub]) {
        birimSelect.value = TIP_HARITASI[sub].default_unit || 'Adet';
    } else if (sub) {
        // Akıllı varsayım
        if (/(Makarna|Bisküvi|Cips|Çikolata|Gofret|Kraker|Tablet|Mendil|Bez|Poşet|Streç|Folyo|Kağıt|Pil|Bant|Yufka|Lavaş)/i.test(sub)) {
            birimSelect.value = 'Paket';
        } else if (/(Et|Kıyma|Kuşbaşı|Biftek|Tavuk|Hindi|Somon|Levrek|Peynir|Kaşar|Pirinç|Bulgur|Mercimek|Nohut|Fasulye|Un|Şeker|Tuz|Patates|Soğan|Meyve|Sebze)/i.test(sub)) {
            birimSelect.value = 'Kg';
        } else if (/(Damacana|Teneke Yağ|Ayçiçek|Zeytinyağı|Mısır Özü)/i.test(sub)) {
            birimSelect.value = 'Litre';
        } else {
            birimSelect.value = 'Adet';
        }
    }
}

// ── SÜRESİZ ÜRÜN TOGGLE ──
function suresizToggle(n) {
    const cb = document.getElementById(`no_skt_${n}`);
    const sktInput = document.getElementById(`skt_${n}`);
    const hiddenSuresiz = document.getElementById(`is_suresiz_${n}`);
    if (!cb || !sktInput) return;

    if (cb.checked) {
        sktInput.value = '';
        sktInput.readOnly = true;
        sktInput.classList.add('opacity-40', 'bg-slate-100', 'dark:bg-slate-900', 'cursor-not-allowed');
        if (hiddenSuresiz) hiddenSuresiz.value = '1';
    } else {
        sktInput.readOnly = false;
        sktInput.classList.remove('opacity-40', 'bg-slate-100', 'dark:bg-slate-900', 'cursor-not-allowed');
        if (hiddenSuresiz) hiddenSuresiz.value = '0';
    }
}

// ── BARKOD SORGULA (AKILLI ÜRÜN BULMA & OPEN FOOD FACTS) ──
async function barkodSorgula(n) {
    const barkodInput = document.getElementById(`barkod_input_${n}`);
    if (!barkodInput) return;
    const val = barkodInput.value.trim();
    if (!val || val.length < 3) return;

    const msgDiv = document.getElementById(`barkod_msg_${n}`);
    if (msgDiv) {
        msgDiv.className = 'text-[10px] text-blue-600 dark:text-blue-400 font-bold block mt-1';
        msgDiv.textContent = '🔍 Sistemde aranıyor...';
        msgDiv.classList.remove('hidden');
    }

    try {
        // 1. Önce Kendi Veritabanımıza Bak
        const localRes = await fetch(`ajax.php?islem=barkod_getir&barkod=${encodeURIComponent(val)}`);
        const localData = await localRes.json();

        if (localData && !localData.bulunamadi && localData.name) {
            const adInput = document.getElementById(`urun_ad_${n}`);
            const markaInput = document.getElementById(`marka_${n}`);
            const katSelect = document.getElementById(`kat_${n}`);
            const birimSelect = document.getElementById(`birim_${n}`);

            if (adInput) adInput.value = localData.name;
            if (markaInput && localData.brand) markaInput.value = localData.brand;
            if (birimSelect && localData.unit) birimSelect.value = localData.unit;

            if (katSelect && localData.category) {
                katSelect.value = localData.category;
                kategoriDegisti(n, localData.sub_category || localData.product_type || '');
            }

            if (msgDiv) {
                msgDiv.className = 'text-[10px] text-emerald-600 dark:text-emerald-400 font-bold block mt-1';
                msgDiv.textContent = `⚡ Sistemden: ${localData.name} ${localData.brand ? '— ' + localData.brand : ''}`;
                setTimeout(() => { if (msgDiv.textContent.includes('Sistemden')) msgDiv.classList.add('hidden'); }, 3500);
            }
            return;
        }

        // 2. Kendi sistemimizde yoksa Open Food Facts'e sor
        if (msgDiv) {
            msgDiv.className = 'text-[10px] text-indigo-600 dark:text-indigo-400 font-bold block mt-1';
            msgDiv.textContent = '🌍 Global veri (Open Food Facts) aranıyor...';
        }

        const res = await fetch(`https://world.openfoodfacts.org/api/v0/product/${encodeURIComponent(val)}.json`);
        const data = await res.json();

        if (data && data.status === 1 && data.product) {
            const p = data.product;
            const ad = p.product_name_tr || p.product_name || '';
            const marka = p.brands ? p.brands.split(',')[0].trim() : '';

            const adInput = document.getElementById(`urun_ad_${n}`);
            const markaInput = document.getElementById(`marka_${n}`);

            if (ad && adInput && !adInput.value) adInput.value = ad;
            if (marka && markaInput && !markaInput.value) markaInput.value = marka;

            if (msgDiv) {
                msgDiv.className = 'text-[10px] text-emerald-600 dark:text-emerald-400 font-bold block mt-1';
                msgDiv.textContent = `🌍 İnternetten: ${ad || marka}`;
                setTimeout(() => { if (msgDiv.textContent.includes('İnternetten')) msgDiv.classList.add('hidden'); }, 3500);
            }
        } else {
            if (msgDiv) {
                msgDiv.className = 'text-[10px] text-amber-600 dark:text-amber-400 font-bold block mt-1';
                msgDiv.textContent = '⚠️ Sistemde ve internette bulunamadı (manuel girin)';
                setTimeout(() => { if (msgDiv.textContent.includes('bulunamadı')) msgDiv.classList.add('hidden'); }, 3500);
            }
        }
    } catch (e) {
        if (msgDiv) {
            msgDiv.className = 'text-[10px] text-red-500 font-medium block mt-1';
            msgDiv.textContent = '❌ Bağlantı hatası oluştu';
            setTimeout(() => msgDiv.classList.add('hidden'), 2500);
        }
    }
}

// ── GLOBAL KONUM CASCADE ──
async function globalMekanYukle() {
    const cityId = document.getElementById('g_city').value;
    const locSel = document.getElementById('g_loc');
    locSel.innerHTML = '<option value="">Yükleniyor...</option>';
    locSel.disabled = true;
    document.getElementById('g_room').innerHTML = '<option value="">Önce mekan</option>';
    document.getElementById('g_room').disabled = true;
    document.getElementById('g_cab').innerHTML  = '<option value="">Önce oda</option>';
    document.getElementById('g_cab').disabled   = true;

    if (!cityId) { locSel.innerHTML = '<option value="">Önce şehir</option>'; return; }

    const data = await fetch(`ajax.php?islem=get_mekanlar&id=${cityId}`).then(r => r.json()).catch(() => []);
    locSel.innerHTML = '<option value="">Seçiniz...</option>';
    data.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.name;
        locSel.appendChild(opt);
    });
    locSel.disabled = false;
    if (data.length === 1) { locSel.value = data[0].id; globalOdaYukle(); }
}

async function globalOdaYukle() {
    const locId = document.getElementById('g_loc').value;
    const roomSel = document.getElementById('g_room');
    roomSel.innerHTML = '<option value="">Yükleniyor...</option>';
    roomSel.disabled  = true;
    document.getElementById('g_cab').innerHTML = '<option value="">Önce oda</option>';
    document.getElementById('g_cab').disabled  = true;

    if (!locId) { roomSel.innerHTML = '<option value="">Önce mekan</option>'; return; }

    const data = await fetch(`ajax.php?islem=get_odalar&id=${locId}`).then(r => r.json()).catch(() => []);
    roomSel.innerHTML = '<option value="">Seçiniz...</option>';
    data.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.name;
        roomSel.appendChild(opt);
    });
    roomSel.disabled = false;
    if (data.length === 1) { roomSel.value = data[0].id; globalDolapYukle(); }
}

async function globalDolapYukle() {
    const roomId = document.getElementById('g_room').value;
    const cabSel = document.getElementById('g_cab');
    cabSel.innerHTML = '<option value="">Yükleniyor...</option>';
    cabSel.disabled  = true;

    if (!roomId) { cabSel.innerHTML = '<option value="">Önce oda</option>'; return; }

    const data = await fetch(`ajax.php?islem=get_dolaplar&id=${roomId}`).then(r => r.json()).catch(() => []);
    currentDolaplar = data || [];

    cabSel.innerHTML = '<option value="">Dolap Seçiniz...</option>';
    currentDolaplar.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.name;
        cabSel.appendChild(opt);
    });
    cabSel.disabled = false;

    // Satırlardaki dolap listelerini de güncelle
    satirDolaplariniGuncelle();

    if (currentDolaplar.length > 0) {
        cabSel.value = currentDolaplar[0].id;
        globalDolapSecildi();
    }
}

function globalDolapSecildi() {
    const cabSel = document.getElementById('g_cab');
    currentGlobalCab = cabSel.value;
    currentGlobalCabName = cabSel.options[cabSel.selectedIndex]?.text || '';
    const badge = document.getElementById('aktifDolapDurumu');
    if (badge) {
        badge.textContent = currentGlobalCabName ? `📍 ${currentGlobalCabName}` : 'Dolap Seçilmedi';
        badge.className = currentGlobalCabName 
            ? 'text-xs px-2.5 py-1 rounded bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 font-bold'
            : 'text-xs px-2.5 py-1 rounded bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 font-semibold';
    }
}

function satirDolaplariniGuncelle() {
    if (!currentDolaplar.length) return;
    document.querySelectorAll('[id^="cab_"]').forEach(sel => {
        const val = sel.value;
        sel.innerHTML = currentDolaplar.map(d => `<option value="${d.id}" ${d.id === (val || currentGlobalCab) ? 'selected' : ''}>${d.name}</option>`).join('');
    });
}

function hepsineUygula() {
    const cabId = document.getElementById('g_cab').value;
    if (!cabId) { 
        alert('Lütfen önce yukarıdaki konumdan bir dolap seçin!'); 
        return; 
    }

    currentGlobalCab = cabId;
    currentGlobalCabName = document.getElementById('g_cab').options[document.getElementById('g_cab').selectedIndex]?.text || '';

    document.querySelectorAll('[id^="cab_"]').forEach(el => {
        el.value = cabId;
    });

    const btn = document.getElementById('btnHepsineUygula');
    btn.textContent = `✅ "${currentGlobalCabName}" Tüm Satırlara Uygulandı`;
    btn.classList.replace('bg-blue-600', 'bg-green-600');
    setTimeout(() => {
        btn.textContent = '↓ Seçilen Dolabı Tüm Satırlara Uygula';
        btn.classList.replace('bg-green-600', 'bg-blue-600');
    }, 2000);
}

// ── EVENT DELEGATION VE BAŞLANGIÇ ──
document.addEventListener('DOMContentLoaded', () => {
    // İlk 3 satırı başlat
    satirEkle();
    satirEkle();
    satirEkle();

    // Satır Ekle Butonları
    const btnSatirEkle = document.getElementById('btnSatirEkle');
    if (btnSatirEkle) btnSatirEkle.addEventListener('click', () => satirEkle());

    const btnHepsine = document.getElementById('btnHepsineUygula');
    if (btnHepsine) btnHepsine.addEventListener('click', hepsineUygula);

    // Cascade Selectler
    const gCity = document.getElementById('g_city');
    const gLoc  = document.getElementById('g_loc');
    const gRoom = document.getElementById('g_room');
    const gCab  = document.getElementById('g_cab');

    if (gCity) gCity.addEventListener('change', globalMekanYukle);
    if (gLoc)  gLoc.addEventListener('change', globalOdaYukle);
    if (gRoom) gRoom.addEventListener('change', globalDolapYukle);
    if (gCab)  gCab.addEventListener('change', globalDolapSecildi);

    // Tablo İçi Olay Delegasyonu (Delegated Events)
    const satirListesi = document.getElementById('satirListesi');
    
    satirListesi.addEventListener('click', function(e) {
        const btnSil = e.target.closest('.btn-satir-sil');
        if (btnSil) {
            satirSil(btnSil.getAttribute('data-id'));
            return;
        }

        const btnKamera = e.target.closest('.btn-kamera-ac');
        if (btnKamera) {
            satirKameraAc(btnKamera.getAttribute('data-satir'));
            return;
        }

        const btnSorgula = e.target.closest('.btn-barkod-sorgula');
        if (btnSorgula) {
            const satir = btnSorgula.getAttribute('data-satir');
            if (satir) barkodSorgula(satir);
            return;
        }
    });

    satirListesi.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target && e.target.id && e.target.id.startsWith('barkod_input_')) {
            e.preventDefault();
            const satir = e.target.getAttribute('data-satir');
            if (satir) barkodSorgula(satir);
        }
    });

    satirListesi.addEventListener('change', function(e) {
        const target = e.target;
        const satir = target.getAttribute('data-satir');
        if (!satir) return;

        if (target.classList.contains('kat-select')) {
            kategoriDegisti(satir);
        } else if (target.classList.contains('alt-kat-select')) {
            altKategoriDegisti(satir);
        } else if (target.classList.contains('no-skt-checkbox')) {
            suresizToggle(satir);
        } else if (target.id && target.id.startsWith('barkod_input_')) {
            barkodSorgula(satir);
        }
    });

    // Barkod Modal Dışına ve Escape Tuşuna Tıklama ile Kapatma
    const bModal = document.getElementById('barkodModal');
    if (bModal) {
        bModal.addEventListener('click', function(e) {
            if (e.target === this) barkodModalKapat();
        });
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') barkodModalKapat();
    });

    // Otomatik konum cascade başlat
    if (gCity && gCity.value) {
        globalMekanYukle();
    }
});

// Form gönderilirken en az 1 satırın dolu olduğunu doğrula
document.getElementById('topluForm').addEventListener('submit', function(e) {
    const satirlar = document.querySelectorAll('[name="urunler[]"]');
    let dolu = 0;
    satirlar.forEach(s => { if (s.value.trim()) dolu++; });
    if (dolu === 0) {
        e.preventDefault();
        alert('Lütfen en az bir ürün adı girin!');
    }
});

// ── BARKOD KAMERA FONKSİYONLARI ──
let html5QrCode = null;
let currentBarkodRow = null;

function satirKameraAc(n) {
    currentBarkodRow = n;
    const modal = document.getElementById('barkodModal');
    if (modal) modal.classList.remove('hidden');
    
    document.getElementById('barkodSonucMetni').innerHTML = 'Kameralar taranıyor... <br><span class="text-[10px]">(Tarayıcı izin penceresi çıkarsa onaylayın)</span>';
    
    if (typeof Html5Qrcode === 'undefined') {
        document.getElementById('barkodSonucMetni').textContent = 'Kamera kütüphanesi yüklenemedi.';
        return;
    }

    if (html5QrCode) {
        try { html5QrCode.stop().catch(() => {}); } catch(e) {}
    }

    html5QrCode = new Html5Qrcode("barkodReader");
    const scanConfig = { fps: 10, qrbox: { width: 250, height: 150 } };
    const onScanSuccess = (decodedText) => {
        barkodModalKapat();
        const input = document.getElementById('barkod_input_' + currentBarkodRow);
        if (input) {
            input.value = decodedText;
            barkodSorgula(currentBarkodRow);
        }
    };

    // 1. Mobilde doğrudan arka kamerayı (environment) açmayı dene
    html5QrCode.start({ facingMode: "environment" }, scanConfig, onScanSuccess, () => {})
        .catch(() => {
            // 2. Olmazsa cihaz kameralarını listeleyip arka veya ilk kamerayı seç
            return Html5Qrcode.getCameras().then(devices => {
                if (devices && devices.length) {
                    let cameraId = devices[0].id;
                    for (let i = 0; i < devices.length; i++) {
                        const lbl = (devices[i].label || '').toLowerCase();
                        if (lbl.includes('back') || lbl.includes('arka')) {
                            cameraId = devices[i].id;
                            break;
                        }
                    }
                    return html5QrCode.start(cameraId, scanConfig, onScanSuccess, () => {});
                }
                throw new Error('Kamera bulunamadı!');
            });
        })
        .catch(err => {
            const sp = document.createElement('span');
            sp.className = 'text-red-500 font-bold';
            sp.textContent = 'Kamera Başlatılamadı: ' + String(err.message || err);
            document.getElementById('barkodSonucMetni').replaceChildren(sp);
        });
}

function barkodModalKapat() {
    if (html5QrCode) {
        try { html5QrCode.stop().then(() => { html5QrCode.clear(); }).catch(e => {}); } catch(e) {}
    }
    const modal = document.getElementById('barkodModal');
    if (modal) modal.classList.add('hidden');
}
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js" nonce="<?= $cspNonce ?>"></script>
</body>
</html>
