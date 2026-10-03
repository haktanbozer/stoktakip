<?php
require 'db.php';
girisKontrol();

// Güvenlik: CSP Nonce Kontrolü
if (!isset($cspNonce)) {$cspNonce = ''; }

if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    $stmtSehir = $pdo->prepare("SELECT c.* FROM cities c JOIN user_city_assignments uca ON c.id = uca.city_id WHERE uca.user_id = ? ORDER BY c.name ASC");
    $stmtSehir->execute([$_SESSION['user_id']]);
    $sehirler = $stmtSehir->fetchAll();
} else {
    $sehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
}
$kategoriler =$pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$aktifSehirId = $_POST['city_id'] ?? ($_SESSION['aktif_sehir_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    try {
        $cabId      = $_POST['cabinet_id'] ?? '';
        if (empty($cabId)) {
            throw new Exception("Lütfen geçerli bir dolap seçin.");
        }

        // IDOR Koruması: Dolabın kullanıcının yetkili şehrine ait olduğunu doğrula
        if (($_SESSION['role'] ?? '') !== 'ADMIN') {
            $stmtCabCheck = $pdo->prepare("SELECT COUNT(*) FROM cabinets cab
                JOIN rooms r ON cab.room_id = r.id
                JOIN locations l ON r.location_id = l.id
                JOIN user_city_assignments uca ON l.city_id = uca.city_id AND uca.user_id = ?
                WHERE cab.id = ?");
            $stmtCabCheck->execute([$_SESSION['user_id'], $cabId]);
            if ($stmtCabCheck->fetchColumn() == 0) {
                sistemLogla("IDOR Girişimi - Yetkisiz dolaba ürün ekleme: user={$_SESSION['user_id']}, cabinet=$cabId", 'SECURITY');
                throw new Exception("Bu dolaba ürün ekleme yetkiniz bulunmamaktadır.");
            }
        }

        $id         = uniqid('prod_');
        $expiryDate = (!empty($_POST['expiry_date'])) ? $_POST['expiry_date'] : null;
        $barcode    = !empty($_POST['barcode']) ? trim($_POST['barcode']) : null;
        $subCat     = !empty($_POST['sub_category']) ? trim($_POST['sub_category']) : '';
        $productType   = $subCat; // Ürün tipi doğrudan alt kategori
        $productTypeId = !empty($_POST['product_type_id']) ? trim($_POST['product_type_id']) : null;
        $minQty     = isset($_POST['min_quantity']) ? (float)$_POST['min_quantity'] : 1.00;
        $isOpened   = isset($_POST['is_opened']) ? 1 : 0;
        $openedAt   = ($isOpened && !empty($_POST['opened_at'])) ? $_POST['opened_at'] : ($isOpened ? date('Y-m-d') : null);

        // Alt kategoriye göre kritik eşiği ve tipi doğrula
        if (!empty($subCat)) {
            $stmtTip = $pdo->prepare("SELECT id, min_threshold FROM product_types WHERE category = ? AND (name = ? OR sub_category = ?) LIMIT 1");
            $stmtTip->execute([$_POST['category'] ?? '', $subCat, $subCat]);
            $tipRow = $stmtTip->fetch();
            if (!$tipRow) {
                // Kategori adı varyasyonu durumunda doğrudan alt kategori adıyla dene
                $stmtTipFallback = $pdo->prepare("SELECT id, min_threshold FROM product_types WHERE (name = ? OR sub_category = ?) LIMIT 1");
                $stmtTipFallback->execute([$subCat, $subCat]);
                $tipRow = $stmtTipFallback->fetch();
            }
            if ($tipRow) { 
                $productTypeId = $tipRow['id'];
                $minQty = (float)$tipRow['min_threshold']; 
            }
        }

        $sql = "INSERT INTO products (
                    id, name, barcode, brand, product_type, product_type_id,
                    category, sub_category,
                    quantity, min_quantity, unit, cabinet_id, shelf_location,
                    purchase_date, expiry_date, is_opened, opened_at, added_by_user_id
                ) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $id, $_POST['name'],
            $barcode, $_POST['brand'] ?? null,
            $productType, $productTypeId,
            $_POST['category'],
            $_POST['sub_category'] ?? '', $_POST['quantity'],
            $minQty, $_POST['unit'],

            $cabId, $_POST['shelf_location'] ?? null,
            $_POST['purchase_date'],
            $expiryDate, $isOpened,
            $openedAt, $_SESSION['user_id']
        ]);

        // Loglama
        $stmtCab = $pdo->prepare("SELECT name FROM cabinets WHERE id = ?");
        $stmtCab->execute([$cabId]);
        $dolapAdi = $stmtCab->fetchColumn() ?? 'Bilinmeyen Dolap';

        auditLog('EKLEME', "{$_POST['name']} ({$_POST['quantity']} {$_POST['unit']}) sisteme eklendi. Dolap: $dolapAdi");

        header("Location: envanter.php?durum=basarili");
        exit;
    } catch (PDOException $e) {
        sistemLogla("Ürün Ekleme PDO Hatası: " . $e->getMessage(), 'ERROR');
        $error = "Ürün kaydedilirken bir veritabanı hatası oluştu. Lütfen tekrar deneyin.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

require 'header.php';
?>

<div class="max-w-4xl mx-auto bg-white dark:bg-slate-800 p-8 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
    <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-6">Akıllı Ürün Ekleme</h2>
    
    <?php if(isset($error)): ?>
        <div class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 p-3 rounded mb-4 border border-red-200 dark:border-red-800">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6" id="kayitFormu">
        <?php echo csrfAlaniniEkle(); ?>
        
        <div id="konumKarti" class="bg-slate-50 dark:bg-slate-700/30 p-4 rounded-lg border border-slate-200 dark:border-slate-600 transition-all duration-200">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-blue-600 dark:text-blue-400 flex items-center gap-2">📍 Konum Bilgisi</h3>
                <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800">
                    * Tüm adımlar zorunludur
                </span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">
                        Şehir <span class="text-rose-500 font-bold">*</span>
                    </label>
                    <select name="city_id" id="city" class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                        <option value="">Seçiniz...</option>
                        <?php foreach($sehirler as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($aktifSehirId == $s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">
                        Mekan (Ev/Depo) <span class="text-rose-500 font-bold">*</span>
                    </label>
                    <select name="location_id" id="location" class="w-full p-2 border rounded bg-slate-100 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" disabled>
                        <option value="">Önce Şehir Seçin</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">
                        Oda <span class="text-rose-500 font-bold">*</span>
                    </label>
                    <select name="room_id" id="room" class="w-full p-2 border rounded bg-slate-100 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" disabled>
                        <option value="">Önce Mekan Seçin</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">
                        Dolap <span class="text-rose-500 font-bold">*</span>
                    </label>
                    <select name="cabinet_id" id="cabinet" class="w-full p-2 border rounded bg-slate-100 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" disabled>
                        <option value="">Önce Oda Seçin</option>
                    </select>
                </div>
            </div>

            <div id="shelf_container" class="mt-4 hidden animate-pulse">
                <label class="block text-xs font-bold text-green-600 dark:text-green-400 mb-1" id="shelf_label">Raf/Bölüm Seçimi</label>
                <select name="shelf_location" id="shelf_location" class="w-full p-2 border-2 border-green-200 dark:border-green-800 rounded bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300 transition-colors"></select>
            </div>
        </div>

        <!-- ── ADIM 1: KATEGORİ & ALT KATEGORİ ─────────────────────────── -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Kategori</label>
                <select name="category" id="category" required
                    class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                    <option value="">Seçiniz...</option>
                    <?php foreach($kategoriler as $kat): ?>
                        <option value="<?= htmlspecialchars($kat['name']) ?>" <?= (($_POST['category'] ?? '') === $kat['name']) ? 'selected' : '' ?>><?= htmlspecialchars($kat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alt Kategori</label>
                <select name="sub_category" id="sub_category"
                    class="w-full p-2 border rounded bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" disabled>
                    <option value="">Önce Kategori Seçin</option>
                </select>
                <div id="altKatKritikBadge" class="hidden mt-2 p-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded text-xs flex items-center justify-between text-amber-800 dark:text-amber-300 font-semibold">
                    <span>🔔 Bu alt kategori için kritik stok eşiği: <strong id="altKatEsikMiktar">1</strong> <span id="altKatEsikBirim">Adet</span></span>
                </div>
                <input type="hidden" name="product_type" id="productTypeHidden" value="<?= htmlspecialchars($_POST['product_type'] ?? '') ?>">
                <input type="hidden" name="product_type_id" id="productTypeId" value="<?= htmlspecialchars($_POST['product_type_id'] ?? '') ?>">
                <input type="hidden" name="min_quantity" id="minQuantityInput" value="<?= htmlspecialchars($_POST['min_quantity'] ?? '1.00') ?>">
            </div>
        </div>

        <!-- ── BARKOD EKLEME ────────────────────────────────────────────── -->
        <div class="bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-lg border border-indigo-200 dark:border-indigo-800">
            <label class="block text-sm font-bold text-indigo-700 dark:text-indigo-400 mb-1 flex items-center gap-2">
                🏷️ Barkod <span class="text-xs font-normal text-indigo-500">(opsiyonel - hızlı arama sağlar)</span>
            </label>
            <div class="flex flex-col sm:flex-row gap-2">
                <input type="text" name="barcode" id="barcodeInput"
                    placeholder="Örn: 8690504... (yazıp Enter'a veya Sorgula'ya basın)"
                    value="<?= htmlspecialchars($_POST['barcode'] ?? '') ?>"
                    class="flex-1 min-w-0 p-2.5 border rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors text-sm">
                
                <div class="flex gap-2 shrink-0">
                    <button type="button" id="btnBarkodAc" title="Kamera ile Barkod Tara"
                        class="flex-1 sm:flex-none bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white px-3.5 py-2.5 rounded-lg transition flex items-center justify-center gap-1.5 text-sm font-bold shadow-sm whitespace-nowrap">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="12" y1="8" x2="12" y2="16"/></svg>
                        <span>Kamera</span>
                    </button>
                    <button type="button" id="btnBarkodSorgula" title="Barkodu Sorgula"
                        class="flex-1 sm:flex-none bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white px-3.5 py-2.5 rounded-lg transition flex items-center justify-center gap-1.5 text-sm font-bold shadow-sm whitespace-nowrap">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        <span>Sorgula</span>
                    </button>
                </div>
            </div>
            <p id="barkodDurumu" class="text-xs mt-1.5 hidden"></p>
        </div>

        <!-- ── ADIM 2: MARKA & ÜRÜN ADI ─────────────────────────────────── -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Marka <span class="text-slate-400 font-normal text-xs">(opsiyonel)</span></label>
                <div class="flex gap-2">
                    <input type="text" name="brand" id="marka"
                        placeholder="Örn: Sütaş, Duru, Tat..."
                        value="<?= htmlspecialchars($_POST['brand'] ?? '') ?>"
                        class="flex-1 p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                    <button type="button" onclick="document.getElementById('marka').value='Açık / Markasız'"
                        class="text-xs bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 px-2 py-1 rounded border dark:border-slate-600 transition whitespace-nowrap">
                        Açık
                    </button>
                </div>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Ürün Adı</label>
                <input type="text" name="name" id="urunAdi" required
                    placeholder="Örn: Taze Kaşar 400g, Basmati Pirinç 1kg..."
                    value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                    class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
            </div>
        </div>

        <!-- ── ADIM 4: MİKTAR, BİRİM, KRİTİK EŞİK ──────────────────────── -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Miktar</label>
                <input type="number" step="0.01" name="quantity" required
                    value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>"
                    class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Birim</label>
                <select name="unit" id="birimSelect"
                    class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                    <?php foreach(['Adet', 'Paket', 'Kg', 'Litre'] as $b): ?>
                        <option value="<?= $b ?>" <?= (($_POST['unit'] ?? '') === $b) ? 'selected' : '' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alım Tarihi</label>
                <input type="date" name="purchase_date" value="<?= htmlspecialchars($_POST['purchase_date'] ?? date('Y-m-d')) ?>"
                    class="w-full p-2 border rounded text-slate-600 dark:text-slate-300 dark:bg-slate-700 dark:border-slate-600 transition-colors">
            </div>
        </div>

        <!-- ── ADIM 5: SKT & PAKET DURUMU ────────────────────────────────── -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 dark:bg-slate-700/30 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Son Kullanma Tarihi</label>
                <input type="date" name="expiry_date" id="expiry_date"
                    value="<?= htmlspecialchars($_POST['expiry_date'] ?? '') ?>"
                    class="w-full p-2 border rounded border-red-200 bg-red-50 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300 focus:ring-2 focus:ring-red-500 outline-none transition disabled:opacity-50 disabled:bg-slate-100 dark:disabled:bg-slate-900 disabled:border-slate-200 dark:disabled:border-slate-700 disabled:text-slate-400 dark:disabled:text-slate-500">
                <label class="flex items-center gap-2 mt-2 cursor-pointer">
                    <input type="checkbox" id="no_skt" class="w-4 h-4 text-blue-600 dark:bg-slate-700 dark:border-slate-600 rounded">
                    <span class="text-xs text-slate-500 dark:text-slate-400 font-bold">SKT Yok / Süresiz Ürün</span>
                </label>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Paket Durumu</label>
                <label class="flex items-center gap-2 mt-2 cursor-pointer">
                    <input type="checkbox" name="is_opened" id="is_opened" value="1" <?= (!empty($_POST['is_opened'])) ? 'checked' : '' ?> class="w-4 h-4 text-amber-600 dark:bg-slate-700 dark:border-slate-600">
                    <span class="text-xs text-amber-600 dark:text-amber-400 font-bold">⚠️ Paket Açıldı Olarak Başlat</span>
                </label>
                <div id="opened_date_container" class="mt-2 <?= (!empty($_POST['is_opened'])) ? '' : 'hidden' ?>">
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Açıldığı Tarih</label>
                    <input type="date" name="opened_at" id="opened_at" value="<?= htmlspecialchars($_POST['opened_at'] ?? date('Y-m-d')) ?>"
                        class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                </div>
            </div>
        </div>



        <div class="pt-4">
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg font-bold text-lg shadow-lg shadow-blue-500/30 transition-colors">
                Kayıt Defterine İşle
            </button>
        </div>

    </form>
</div>


<div class="h-20 md:h-0"></div>

<script nonce="<?= $cspNonce ?>">
function toggleSKT() {
    const checkbox = document.getElementById('no_skt');
    const input    = document.getElementById('expiry_date');
    if (checkbox.checked) {
        input.value    = '';
        input.disabled = true;
        input.required = false;
    } else {
        input.disabled = false;
        input.required = true;
    }
}

function toggleOpenedDate() {
    const c = document.getElementById('is_opened');
    const box = document.getElementById('opened_date_container');
    const input = document.getElementById('opened_at');
    if (c.checked) {
        box.classList.remove('hidden');
        if (!input.value) {
            input.value = new Date().toISOString().split('T')[0];
        }
    } else {
        box.classList.add('hidden');
        input.value = '';
    }
}

let targetLocId = '<?= htmlspecialchars($_POST['location_id'] ?? '') ?>';
let targetRoomId = '<?= htmlspecialchars($_POST['room_id'] ?? '') ?>';
let targetCabId = '<?= htmlspecialchars($_POST['cabinet_id'] ?? '') ?>';

document.addEventListener('DOMContentLoaded', () => { 
    const citySelect = document.getElementById('city');
    const locSelect  = document.getElementById('location');
    const roomSelect = document.getElementById('room');
    const cabSelect  = document.getElementById('cabinet');
    const catSelect  = document.getElementById('category');

    if (citySelect && citySelect.value) {
        fetchMekanlar(); 
    }

    if (citySelect)  citySelect.addEventListener('change', fetchMekanlar);
    if (locSelect)   locSelect.addEventListener('change',  fetchOdalar);
    if (roomSelect)  roomSelect.addEventListener('change', fetchDolaplar);
    if (cabSelect)   cabSelect.addEventListener('change',  checkCabinetType);
    if (catSelect)   catSelect.addEventListener('change',  fetchSubCategories);

    // Form Gönderiminde Konum Doğrulaması (Client-side validation)
    const kayitFormu = document.getElementById('kayitFormu');
    if (kayitFormu) {
        kayitFormu.addEventListener('submit', function(e) {
            const cityEl = document.getElementById('city');
            const locEl  = document.getElementById('location');
            const roomEl = document.getElementById('room');
            const cabEl  = document.getElementById('cabinet');

            // Hata stillerini temizle
            [cityEl, locEl, roomEl, cabEl].forEach(el => {
                if (el) el.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
            });

            let missingEl = null;
            let missingName = '';

            if (!cityEl || !cityEl.value) {
                missingEl = cityEl;
                missingName = 'Şehir';
            } else if (!locEl || !locEl.value || locEl.disabled) {
                missingEl = locEl;
                missingName = 'Mekan (Ev/Depo)';
            } else if (!roomEl || !roomEl.value || roomEl.disabled) {
                missingEl = roomEl;
                missingName = 'Oda';
            } else if (!cabEl || !cabEl.value || cabEl.disabled) {
                missingEl = cabEl;
                missingName = 'Dolap';
            }

            if (missingEl) {
                e.preventDefault();
                e.stopPropagation();

                // Hatalı alanı görsel olarak belirginleştir
                missingEl.classList.add('ring-2', 'ring-rose-500', '!border-rose-500');

                // Konum kartına yumuşak kaydır
                const konumKarti = document.getElementById('konumKarti');
                if (konumKarti) {
                    konumKarti.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }

                if (!missingEl.disabled) {
                    missingEl.focus();
                }

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Konum Bilgisi Eksik!',
                        html: `Ürünü kaydetmeden önce lütfen <b>${missingName}</b> seçimini yapın.<br><span class="text-xs text-slate-500 dark:text-slate-400 mt-2 block">Stok ve son kullanma tarihi takibinin doğru çalışabilmesi için konum adımları (Şehir, Mekan, Oda, Dolap) zorunludur.</span>`,
                        confirmButtonColor: '#4f46e5',
                        confirmButtonText: 'Tamam, Seçeceğim'
                    });
                } else {
                    alert(`Lütfen geçerli bir ${missingName} seçin.`);
                }
                return false;
            }
        });

        // Kullanıcı seçim yaptıkça kırmızı çerçeveyi otomatik kaldır
        [citySelect, locSelect, roomSelect, cabSelect].forEach(el => {
            if (el) {
                el.addEventListener('change', function() {
                    this.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
                });
            }
        });
    }

    // Checkbox olayları (CSP uyumlu)
    const chkSkt = document.getElementById('no_skt');
    if (chkSkt) chkSkt.addEventListener('change', toggleSKT);

    const chkOpened = document.getElementById('is_opened');
    if (chkOpened) chkOpened.addEventListener('change', toggleOpenedDate);

    // Manuel barkod sorgula (CSP uyumlu)
    const btnManuel = document.getElementById('btnManuelBarkodSorgula');
    if (btnManuel) {
        btnManuel.addEventListener('click', function(e) {
            e.preventDefault();
            barkodSorgu(document.getElementById('manuelBarkod').value);
        });
    }

    // Modal kapatma butonu
    const btnKapat = document.getElementById('btnBarkodKapat');
    if (btnKapat) {
        btnKapat.addEventListener('click', function(e) {
            e.preventDefault();
            barkodModalKapat();
        });
    }
});

async function fetchData(action, param = '') { 
    try {
        const qs = (action === 'get_alt_kategoriler')
            ? `name=${encodeURIComponent(param)}`
            : `id=${encodeURIComponent(param)}`;
        
        const res = await fetch(`ajax.php?islem=${encodeURIComponent(action)}&${qs}`);
        if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
        return await res.json();
    } catch (error) {
        console.error(`${action} hatası:`, error);
        return [];
    }
}

async function fetchMekanlar() { 
    const cityId = document.getElementById('city').value; 
    const loc    = document.getElementById('location');
    
    loc.innerHTML = '<option>Yükleniyor...</option>'; 
    loc.disabled  = true;
    loc.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
    
    const room    = document.getElementById('room');
    const cabinet = document.getElementById('cabinet');
    
    room.innerHTML    = '<option value="">Önce Mekan Seçin</option>'; 
    room.disabled     = true;
    room.classList.add('bg-slate-100', 'dark:bg-slate-900');
    room.classList.remove('bg-white', 'dark:bg-slate-700', 'ring-2', 'ring-rose-500', '!border-rose-500');
    
    cabinet.innerHTML = '<option value="">Önce Oda Seçin</option>'; 
    cabinet.disabled  = true;
    cabinet.classList.add('bg-slate-100', 'dark:bg-slate-900');
    cabinet.classList.remove('bg-white', 'dark:bg-slate-700', 'ring-2', 'ring-rose-500', '!border-rose-500');
    
    document.getElementById('shelf_container').classList.add('hidden');

    if(!cityId) {
        loc.innerHTML = '<option value="">Önce Şehir Seçin</option>';
        return;
    }
    
    const data = await fetchData('get_mekanlar', cityId);
    loc.innerHTML = '<option value="">Seçiniz...</option>'; 
    
    if (data && data.length > 0) {
        data.forEach(i => { const opt = document.createElement('option'); opt.value = i.id; opt.textContent = i.name; loc.appendChild(opt); });
        loc.disabled = false;
        loc.classList.remove('bg-slate-100', 'dark:bg-slate-900', 'dark:text-slate-400');
        loc.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');

        if (targetLocId && data.some(d => String(d.id) === String(targetLocId))) {
            loc.value = targetLocId;
            targetLocId = '';
            await fetchOdalar();
        } else if (data.length === 1) {
            loc.value = data[0].id;
            await fetchOdalar();
        }
    } else {
        loc.innerHTML = '<option value="">Bu şehirde mekan yok</option>';
    }
}

async function fetchOdalar() {
    const locId = document.getElementById('location').value; 
    const room  = document.getElementById('room');
    
    room.innerHTML = '<option>Yükleniyor...</option>'; 
    room.disabled  = true;
    room.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
    
    const cabinet = document.getElementById('cabinet');
    cabinet.innerHTML = '<option value="">Önce Oda Seçin</option>'; 
    cabinet.disabled  = true;
    cabinet.classList.add('bg-slate-100', 'dark:bg-slate-900');
    cabinet.classList.remove('bg-white', 'dark:bg-slate-700', 'ring-2', 'ring-rose-500', '!border-rose-500');
    
    document.getElementById('shelf_container').classList.add('hidden');
    
    if(!locId) {
        room.innerHTML = '<option value="">Önce Mekan Seçin</option>';
        return;
    }
    
    const data = await fetchData('get_odalar', locId);
    room.innerHTML = '<option value="">Seçiniz...</option>'; 
    
    if (data && data.length > 0) {
        data.forEach(i => { const opt = document.createElement('option'); opt.value = i.id; opt.textContent = i.name; room.appendChild(opt); });
        room.disabled = false;
        room.classList.remove('bg-slate-100', 'dark:bg-slate-900', 'dark:text-slate-400');
        room.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');

        if (targetRoomId && data.some(d => String(d.id) === String(targetRoomId))) {
            room.value = targetRoomId;
            targetRoomId = '';
            await fetchDolaplar();
        } else if (data.length === 1) {
            room.value = data[0].id;
            await fetchDolaplar();
        }
    } else {
        room.innerHTML = '<option value="">Bu mekanda oda yok</option>';
    }
}

async function fetchDolaplar() {
    const roomId = document.getElementById('room').value; 
    const cab    = document.getElementById('cabinet');
    
    cab.innerHTML = '<option>Yükleniyor...</option>'; 
    cab.disabled  = true;
    cab.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
    document.getElementById('shelf_container').classList.add('hidden');
    
    if(!roomId) {
        cab.innerHTML = '<option value="">Önce Oda Seçin</option>';
        return;
    }
    
    const data = await fetchData('get_dolaplar', roomId);
    cab.innerHTML = '<option value="">Seçiniz...</option>'; 
    
    if (data && data.length > 0) {
        data.forEach(i => { const opt = document.createElement('option'); opt.value = i.id; opt.textContent = i.name; cab.appendChild(opt); });
        cab.disabled = false;
        cab.classList.remove('bg-slate-100', 'dark:bg-slate-900', 'dark:text-slate-400');
        cab.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');

        if (targetCabId && data.some(d => String(d.id) === String(targetCabId))) {
            cab.value = targetCabId;
            targetCabId = '';
            await checkCabinetType();
        } else if (data.length === 1) {
            cab.value = data[0].id;
            await checkCabinetType();
        }
    } else {
        cab.innerHTML = '<option value="">Bu odada dolap yok</option>';
    }
}

async function fetchSubCategories() {
    const cat = document.getElementById('category').value; 
    const sub = document.getElementById('sub_category');
    
    sub.innerHTML = '<option>Yükleniyor...</option>'; 
    sub.disabled  = true;
    
    if(!cat) {
        sub.innerHTML = '<option value="">Önce Kategori Seçin</option>';
        return;
    }
    
    const data = await fetchData('get_alt_kategoriler', cat);
    if(data && data.length > 0) { 
        sub.innerHTML = '<option value="">Seçiniz...</option>'; 
        data.forEach(i => { const opt = document.createElement('option'); opt.value = i; opt.textContent = i; sub.appendChild(opt); });
        sub.disabled = false; 
        sub.classList.remove('bg-slate-50', 'dark:bg-slate-900', 'dark:text-slate-400');
        sub.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');
    } else { 
        sub.innerHTML = '<option value="">Alt Kategori Yok</option>'; 
        sub.disabled = true; 
    }

    sub.addEventListener('change', onAltKategoriSecildi, { once: false });
    if (data && data.length === 1) { sub.value = data[0]; onAltKategoriSecildi(); }
}

// ── ALT KATEGORİ KRİTİK EŞİK BAĞLANTISI ─────────────────────────────────

async function onAltKategoriSecildi() {
    const cat = document.getElementById('category').value;
    const sub = document.getElementById('sub_category').value;
    const badge = document.getElementById('altKatKritikBadge');
    const hiddenType = document.getElementById('productTypeHidden');
    const hiddenId = document.getElementById('productTypeId');
    const hiddenMin = document.getElementById('minQuantityInput');
    const birimSel = document.getElementById('birimSelect');
    const urunAdi = document.getElementById('urunAdi');

    if (!sub) {
        if (badge) badge.classList.add('hidden');
        if (hiddenType) hiddenType.value = '';
        if (hiddenId) hiddenId.value = '';
        return;
    }

    if (hiddenType) hiddenType.value = sub;
    if (urunAdi && !urunAdi.value) {
        urunAdi.placeholder = "Örn: " + sub;
    }

    try {
        const res = await fetch(`ajax.php?islem=get_alt_kategori_bilgi&kategori=${encodeURIComponent(cat)}&alt_kategori=${encodeURIComponent(sub)}`);
        const data = await res.json();
        if (data) {
            if (hiddenId) hiddenId.value = data.id || '';
            if (hiddenMin) hiddenMin.value = data.min_threshold || 1.00;
            const esikMiktarEl = document.getElementById('altKatEsikMiktar');
            const esikBirimEl = document.getElementById('altKatEsikBirim');
            if (esikMiktarEl) esikMiktarEl.textContent = data.min_threshold || 1.00;
            if (esikBirimEl) esikBirimEl.textContent = data.default_unit || 'Adet';
            if (badge) badge.classList.remove('hidden');

            // Birim seçimini de otomatik eşitle
            if (data.default_unit && birimSel) {
                birimSel.value = data.default_unit;
            }
        }
    } catch (e) {
        console.error('Alt kategori eşik bilgisi alınamadı:', e);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Barkod toggle
    const btnBarkodToggle = document.getElementById('btnBarkodToggle');
    if (btnBarkodToggle) {
        btnBarkodToggle.addEventListener('click', () => {
            const blok = document.getElementById('barkodBlok');
            const ikon = document.getElementById('barkodToggleIkon');
            blok.classList.toggle('hidden');
            ikon.style.transform = blok.classList.contains('hidden') ? '' : 'rotate(180deg)';
        });
    }
});



    async function checkCabinetType() {
    const cabId = document.getElementById('cabinet').value; 
    const con   = document.getElementById('shelf_container'); 
    const sel   = document.getElementById('shelf_location'); 
    const lbl   = document.getElementById('shelf_label');
    
    if(!cabId) { 
        con.classList.add('hidden'); 
        return; 
    }
    
    try {
        const data = await fetchData('get_dolap_detay', cabId);
        if (!data) return;
        
        con.classList.remove('hidden'); 
        sel.innerHTML = '';
        
        if(data.type && data.type.includes('Buzdolabı')) {
            con.classList.remove('hidden');
            lbl.innerText = 'Bölme'; 
            ['Soğutucu','Dondurucu'].forEach(o => { const opt = document.createElement('option'); opt.value = o; opt.textContent = o; sel.appendChild(opt); });
        } else {
            con.classList.add('hidden');
            sel.innerHTML = '<option value="">Genel</option>';
        }
    } catch(error) {
        console.error('checkCabinetType hatası:', error);
    }
}

</script>

<!-- Barkod Okuyucu Kütüphanesi -->
<!-- ── BARKOD KAMERA MODAL ── -->
<div id="barkodModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 backdrop-blur-sm p-4">
    <div class="bg-white dark:bg-slate-800 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden border border-slate-200 dark:border-slate-700">
        <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="12" y1="8" x2="12" y2="16"/></svg>
                Barkod Tarayıcı
            </h3>
            <button type="button" id="btnBarkodKapat" class="text-slate-400 hover:text-red-500 transition-colors p-1">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="p-6 flex flex-col items-center">
            <div id="barkodReader" class="w-full max-w-[300px] min-h-[250px] bg-black rounded-xl overflow-hidden relative shadow-inner"></div>
            <p id="barkodSonucMetni" class="mt-4 text-sm font-bold text-slate-600 dark:text-slate-300 text-center"></p>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js" nonce="<?= $cspNonce ?>"></script>
<script nonce="<?= $cspNonce ?>">
// ===== BARKOD OKUYUCU =====
let html5QrCode = null;

function barkodModalAc() {
    try {
        const modal = document.getElementById('barkodModal');
        if (modal) modal.classList.remove('hidden');
        
        document.getElementById('barkodSonucMetni').innerHTML = 'Kameralar taranıyor... <br><span class="text-[10px]">(Tarayıcı izin penceresi çıkarsa onaylayın)</span>';

        if (typeof Html5Qrcode === 'undefined') {
            document.getElementById('barkodSonucMetni').textContent = 'Kamera kütüphanesi yüklenemedi. Lütfen sayfayı yenileyin.';
            return;
        }

        Html5Qrcode.getCameras().then(devices => {
            if (devices && devices.length) {
                let cameraId = devices[0].id;
                // Arka kamerayı bulmaya çalış
                for (let i = 0; i < devices.length; i++) {
                    if (devices[i].label.toLowerCase().includes('back') || devices[i].label.toLowerCase().includes('arka')) {
                        cameraId = devices[i].id;
                        break;
                    }
                }
                
                html5QrCode = new Html5Qrcode("barkodReader");
                html5QrCode.start(
                    cameraId,
                    { fps: 10, qrbox: { width: 250, height: 150 } },
                    (decodedText) => {
                        barkodModalKapat();
                        barkodSorgu(decodedText);
                    },
                    () => {}
                ).catch(err => {
                    document.getElementById('barkodSonucMetni').innerHTML = `<span class="text-red-500">Kamera Başlatılamadı: ${err}</span>`;
                });
            } else {
                document.getElementById('barkodSonucMetni').innerHTML = '<span class="text-red-500">Cihazda hiçbir kamera bulunamadı!</span>';
            }
        }).catch(err => {
            const errStr = (err && err.message) ? err.message : String(err);
            document.getElementById('barkodSonucMetni').innerHTML = `
                <span class="text-red-500 font-bold block mb-1">Erişim Reddedildi!</span>
                <span class="text-xs text-slate-500">Tarayıcı veya telefon ayarlarına girip kameraya İZİN VERMENİZ (Allow) gerekiyor.<br><br>Hata: ${errStr}</span>
            `;
            console.warn('Kamera izin hatası:', err);
        });

    } catch (e) {
        alert("Kamera modülü yüklenirken bir hata oluştu: " + e.message);
    }
}

function barkodModalKapat() {
    if (html5QrCode) {
        try {
            html5QrCode.stop().catch(() => {}).finally(() => { html5QrCode = null; });
        } catch(e) {}
    }
    document.getElementById('barkodModal').classList.add('hidden');
}

let sonSorgulananBarkod = '';

async function barkodSorgu(barkod) {
    if (!barkod || barkod.trim() === '') return;
    barkod = barkod.trim();

    const barcodeInput = document.getElementById('barcodeInput');
    if (barcodeInput && barcodeInput.value !== barkod) {
        barcodeInput.value = barkod;
    }

    const durum = document.getElementById('barkodDurumu');
    durum.className = 'text-xs mt-1 text-blue-500 dark:text-blue-400 font-medium';
    durum.textContent = '🔍 Sistemimizde aranıyor...';
    durum.classList.remove('hidden');

    try {
        // 1. Önce Kendi Veritabanımıza Bak
        const localRes = await fetch(`ajax.php?islem=barkod_getir&barkod=${encodeURIComponent(barkod)}`);
        const localData = await localRes.json();
        
        if (!localData.bulunamadi) {
            sonSorgulananBarkod = barkod;
            if (localData.name) document.getElementById('urunAdi').value = localData.name;
            if (localData.brand) document.getElementById('marka').value = localData.brand;
            
            // Birim seçimi
            if (localData.unit) {
                const birimSel = document.getElementById('birimSelect');
                if (birimSel) birimSel.value = localData.unit;
            }
            
            // Kategori seçimi (Eğer varsa)
            if (localData.category) {
                const catSel = document.getElementById('category');
                for (let i = 0; i < catSel.options.length; i++) {
                    if (catSel.options[i].value === localData.category) {
                        catSel.selectedIndex = i;
                        await fetchSubCategories();
                        break;
                    }
                }
                
                // Alt Kategori seçimi ve eşik bağlantısı
                if (localData.sub_category) {
                    const subSel = document.getElementById('sub_category');
                    for (let i = 0; i < subSel.options.length; i++) {
                        if (subSel.options[i].value === localData.sub_category) {
                            subSel.selectedIndex = i;
                            await onAltKategoriSecildi();
                            break;
                        }
                    }
                }
            }
            
            durum.className = 'text-xs mt-1 text-green-600 dark:text-green-400 font-bold';
            durum.textContent = `⚡ (Sistemden Getirildi): ${localData.name} ${localData.brand ? '— ' + localData.brand : ''}`;
            const mB = document.getElementById('manuelBarkod');
            if (mB) mB.value = '';
            return; // Bulunduğu için işlemi bitir
        }

        // 2. Kendi sistemimizde yoksa Open Food Facts'e sor
        durum.textContent = '🔍 Global API (Open Food Facts) aranıyor...';
        const res = await fetch(`https://world.openfoodfacts.org/api/v0/product/${encodeURIComponent(barkod)}.json`);
        const data = await res.json();

        if (data.status === 1 && data.product) {
            sonSorgulananBarkod = barkod;
            const p = data.product;
            const ad = p.product_name_tr || p.product_name || '';
            const marka = p.brands || '';

            if (ad) document.getElementById('urunAdi').value = ad;
            if (marka) document.getElementById('marka').value = marka.split(',')[0].trim();

            durum.className = 'text-xs mt-1 text-emerald-600 dark:text-emerald-400 font-bold';
            durum.textContent = `🌍 (İnternetten Bulundu): ${ad || 'İsim yok'} ${marka ? '— ' + marka : ''}`;
        } else {
            durum.className = 'text-xs mt-1 text-amber-600 dark:text-amber-400 font-bold';
            durum.textContent = '⚠️ Ürün veritabanında ve internette bulunamadı. Lütfen bilgileri manuel giriniz (kaydedildiğinde sistem öğrenecektir).';
        }
    } catch (e) {
        durum.className = 'text-xs mt-1 text-red-500 font-medium';
        durum.textContent = '❌ Bağlantı hatası oluştu. Barkod yine de kaydedildi.';
    }

    const mB2 = document.getElementById('manuelBarkod');
    if (mB2) mB2.value = '';
}

// Barkod Input Dinleyicileri (Elle Giriş, Barkod Okuyucu Tabanca, Enter)
const bInput = document.getElementById('barcodeInput');
if (bInput) {
    bInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const val = this.value.trim();
            if (val) barkodSorgu(val);
        }
    });

    bInput.addEventListener('change', function() {
        const val = this.value.trim();
        if (val && val !== sonSorgulananBarkod) {
            barkodSorgu(val);
        }
    });
}

// "Sorgula" Butonu
const btnBarkodSorgula = document.getElementById('btnBarkodSorgula');
if (btnBarkodSorgula) {
    btnBarkodSorgula.addEventListener('click', function(e) {
        e.preventDefault();
        const val = document.getElementById('barcodeInput').value.trim();
        if (val) {
            barkodSorgu(val);
        } else {
            document.getElementById('barcodeInput').focus();
        }
    });
}

// Modal dışına tıklayınca kapat
const bModal = document.getElementById('barkodModal');
if (bModal) {
    bModal.addEventListener('click', function(e) {
        if (e.target === this) barkodModalKapat();
    });
}

// Buton ile Modalı Açma (CSP Uyumluluğu İçin)
const btnBarkod = document.getElementById('btnBarkodAc');
if (btnBarkod) {
    btnBarkod.addEventListener('click', function(e) {
        e.preventDefault();
        barkodModalAc();
    });
}
</script>

</body>
</html>