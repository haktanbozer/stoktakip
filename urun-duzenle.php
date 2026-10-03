<?php
require 'db.php';
girisKontrol();

if (!isset($_GET['id'])) { header("Location: envanter.php"); exit; }
$id = $_GET['id']; $error = '';

// 1. MEVCUT ÜRÜN VERİLERİNİ ÇEK
// B6: Admin olmayanlar sadece kendi şehirlerindeki ürünleri düzenleyebilir
$sql = "SELECT p.*, c.room_id, r.location_id, l.city_id FROM products p
        LEFT JOIN cabinets c ON p.cabinet_id = c.id
        LEFT JOIN rooms r ON c.room_id = r.id
        LEFT JOIN locations l ON r.location_id = l.id
        WHERE p.id = ?";
$params = [$id];

if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    if (!empty($_SESSION['aktif_sehir_id'])) {
        $sql .= " AND l.city_id = ? AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)";
        $params[] = $_SESSION['aktif_sehir_id'];
        $params[] = $_SESSION['user_id'];
    } else {
        $sql .= " AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)";
        $params[] = $_SESSION['user_id'];
    }
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$urun = $stmt->fetch();
if (!$urun) { header("Location: envanter.php?hata=yetki"); exit; }

// 2. LİSTELERİ HAZIRLA (Şehir Filtreli)
if (isset($_SESSION['aktif_sehir_id'])) {
    $stmt =$pdo->prepare("SELECT * FROM cities WHERE id = ? ORDER BY name ASC");
    $stmt->execute([$_SESSION['aktif_sehir_id']]);
    $sehirler =$stmt->fetchAll();
} else {
    $sehirler =$pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
}

$kategoriler =$pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

// 3. ALT LİSTELERİ DOLDUR
$mekanlar = [];$odalar = [];
$dolaplar = [];$altKategoriler = [];

$seciliSehir =$urun['city_id'] ? $urun['city_id'] : ($_SESSION['aktif_sehir_id'] ?? null);
if ($seciliSehir) {
    $stmt =$pdo->prepare("SELECT * FROM locations WHERE city_id = ? ORDER BY name ASC");
    $stmt->execute([$seciliSehir]);
    $mekanlar =$stmt->fetchAll();
}

if ($urun['location_id']) {
    $stmt =$pdo->prepare("SELECT * FROM rooms WHERE location_id = ? ORDER BY name ASC");
    $stmt->execute([$urun['location_id']]);
    $odalar =$stmt->fetchAll();
}

if ($urun['room_id']) {
    $stmt =$pdo->prepare("SELECT * FROM cabinets WHERE room_id = ? ORDER BY name ASC");
    $stmt->execute([$urun['room_id']]);
    $dolaplar =$stmt->fetchAll();
}

if ($urun['category']) {
    $stmt =$pdo->prepare("SELECT sub_categories FROM categories WHERE name = ?");
    $stmt->execute([$urun['category']]);
    $cat =$stmt->fetch();
    if($cat && !empty($cat['sub_categories'])) {
        $altKategoriler = array_filter(array_map('trim', explode(',',$cat['sub_categories'])));
        sort($altKategoriler);
    }
}

// 4. KAYDETME İŞLEMİ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    try {
        $newCabId = $_POST['cabinet_id'] ?? '';
        if (empty($newCabId)) {
            throw new Exception("Lütfen geçerli bir dolap seçin.");
        }

        // IDOR Koruması: Hedef dolabın kullanıcının yetkili şehrine ait olduğunu doğrula
        if (($_SESSION['role'] ?? '') !== 'ADMIN') {
            $stmtCabCheck = $pdo->prepare("SELECT COUNT(*) FROM cabinets cab
                JOIN rooms r ON cab.room_id = r.id
                JOIN locations l ON r.location_id = l.id
                JOIN user_city_assignments uca ON l.city_id = uca.city_id AND uca.user_id = ?
                WHERE cab.id = ?");
            $stmtCabCheck->execute([$_SESSION['user_id'], $newCabId]);
            if ($stmtCabCheck->fetchColumn() == 0) {
                sistemLogla("IDOR Girişimi - Yetkisiz dolaba ürün taşıma: user={$_SESSION['user_id']}, cabinet=$newCabId", 'SECURITY');
                throw new Exception("Bu dolaba ürün taşıma yetkiniz bulunmamaktadır.");
            }
        }

        $expiryDate = (!empty($_POST['expiry_date'])) ? $_POST['expiry_date'] : null;
        $barcode    = !empty($_POST['barcode']) ? trim($_POST['barcode']) : null;
        $subCat     = !empty($_POST['sub_category']) ? trim($_POST['sub_category']) : '';
        $productType   = $subCat;
        $productTypeId = !empty($_POST['product_type_id']) ? trim($_POST['product_type_id']) : null;
        $minQty     = isset($_POST['min_quantity']) ? (float)$_POST['min_quantity'] : 1.00;
        $isOpened   = isset($_POST['is_opened']) ? 1 : 0;
        $openedAt   = ($isOpened && !empty($_POST['opened_at'])) ? $_POST['opened_at'] : ($isOpened ? date('Y-m-d') : null);

        // Alt kategoriye ait kritik eşiği al
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

        $sql = "UPDATE products SET 
                    name = ?, 
                    barcode = ?,
                    brand = ?, 
                    product_type = ?,
                    product_type_id = ?,
                    category = ?, 
                    sub_category = ?, 
                    quantity = ?, 
                    min_quantity = ?,
                    unit = ?, 
                    cabinet_id = ?, 
                    shelf_location = ?, 
                    purchase_date = ?, 
                    expiry_date = ?,
                    is_opened = ?,
                    opened_at = ?
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['name'], $barcode,
            $_POST['brand'] ?? null, 
            $productType, $productTypeId,
            $_POST['category'], 
            $_POST['sub_category'] ?? '', $_POST['quantity'], 
            $minQty, $_POST['unit'], 
            $newCabId, $_POST['shelf_location'] ?? null, 
            $_POST['purchase_date'], 
            $expiryDate, $isOpened,
            $openedAt, $id
        ]);

        if (function_exists('auditLog')) {
            auditLog('GÜNCELLEME', "{$urun['name']} ürün bilgileri güncellendi. (ID: $id)");
        }

        header("Location: envanter.php?durum=basarili");
        exit;
    } catch (PDOException $e) {
        sistemLogla("Ürün Güncelleme PDO Hatası: " . $e->getMessage(), 'ERROR');
        $error = "Ürün güncellenirken bir veritabanı hatası oluştu. Lütfen tekrar deneyin.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

require 'header.php';
?>

<div class="max-w-4xl mx-auto bg-white dark:bg-slate-800 p-8 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white">Ürün Düzenle</h2>
        <a href="envanter.php" class="text-blue-600 dark:text-blue-400 hover:underline">← Geri</a>
    </div>
    
    <?php if($error): ?>
        <div class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 p-3 rounded mb-4 border border-red-200 dark:border-red-800">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6" id="duzenleFormu">
        <?php echo csrfAlaniniEkle(); ?>
        
        <div id="konumKarti" class="bg-slate-50 dark:bg-slate-700/30 p-4 rounded-lg border border-slate-200 dark:border-slate-600 transition-all duration-200">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-blue-600 dark:text-blue-400 flex items-center gap-2">📍 Konum</h3>
                <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800">
                    * Tüm adımlar zorunludur
                </span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">
                        Şehir <span class="text-rose-500 font-bold">*</span>
                    </label>
                    <select id="city" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="fetchMekanlar()">
                        <?php foreach($sehirler as$s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id']==$seciliSehir ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">
                        Mekan <span class="text-rose-500 font-bold">*</span>
                    </label>
                    <select id="location" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="fetchOdalar()">
                        <option value="">Seçiniz...</option>
                        <?php foreach($mekanlar as$m): ?>
                            <option value="<?= $m['id'] ?>" <?= $m['id']==$urun['location_id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">
                        Oda <span class="text-rose-500 font-bold">*</span>
                    </label>
                    <select id="room" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="fetchDolaplar()">
                        <option value="">Seçiniz...</option>
                        <?php foreach($odalar as$o): ?>
                            <option value="<?= $o['id'] ?>" <?= $o['id']==$urun['room_id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">
                        Dolap <span class="text-rose-500 font-bold">*</span>
                    </label>
                    <select name="cabinet_id" id="cabinet" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="checkCabinetType()">
                        <option value="">Seçiniz...</option>
                        <?php foreach($dolaplar as$c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id']==$urun['cabinet_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div id="shelf_container" class="mt-4 <?= empty($urun['shelf_location']) ? 'hidden' : '' ?>">
                <label class="block text-xs font-bold text-green-600 dark:text-green-400 mb-1">Raf / Bölüm</label>
                <select name="shelf_location" id="shelf_location" class="w-full p-2 border rounded bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300">
                    <option value="<?= htmlspecialchars($urun['shelf_location']) ?>" selected><?= htmlspecialchars($urun['shelf_location']) ?></option>
                </select>
            </div>
        </div>

        <!-- ── ADIM 1: KATEGORİ & ALT KATEGORİ ─────────────────────────── -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Kategori</label>
                <select name="category" id="category" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="fetchSubCategories()">
                    <?php foreach($kategoriler as $k): ?>
                        <option value="<?= htmlspecialchars($k['name']) ?>" <?= $k['name']==$urun['category'] ? 'selected' : '' ?>><?= htmlspecialchars($k['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alt Kategori</label>
                <select name="sub_category" id="sub_category" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="onAltKategoriSecildi()">
                    <?php foreach($altKategoriler as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $a==$urun['sub_category'] ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="altKatKritikBadge" class="mt-2 p-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded text-xs flex items-center justify-between text-amber-800 dark:text-amber-300 font-semibold">
                    <span>🔔 Bu alt kategori için kritik stok eşiği: <strong id="altKatEsikMiktar"><?= (float)($urun['min_quantity'] ?? 1.00) ?></strong> <span id="altKatEsikBirim"><?= htmlspecialchars($urun['unit'] ?? 'Adet') ?></span></span>
                </div>
                <input type="hidden" name="product_type" id="productTypeHidden" value="<?= htmlspecialchars($urun['sub_category'] ?? '') ?>">
                <input type="hidden" name="product_type_id" id="productTypeId" value="<?= htmlspecialchars($urun['product_type_id'] ?? '') ?>">
                <input type="hidden" name="min_quantity" id="minQuantityInput" value="<?= htmlspecialchars($urun['min_quantity'] ?? '1.00') ?>">
            </div>
        </div>

        <!-- ── ADIM 2: MARKA, ÜRÜN ADI, BARKOD ─────────────────────────── -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Marka <span class="text-slate-400 font-normal text-xs">(opsiyonel)</span></label>
                <div class="flex gap-2">
                    <input type="text" name="brand" id="marka" value="<?= htmlspecialchars($urun['brand'] ?? '') ?>"
                        placeholder="Örn: Sütaş, Duru, Tat..."
                        class="flex-1 p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                    <button type="button" onclick="document.getElementById('marka').value='Açık / Markasız'"
                        class="text-xs bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 px-2 py-1 rounded border dark:border-slate-600 transition whitespace-nowrap">
                        Açık
                    </button>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Ürün Adı</label>
                <input type="text" name="name" value="<?= htmlspecialchars($urun['name']) ?>" required
                    class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Barkod <span class="text-slate-400 font-normal text-xs">(opsiyonel)</span></label>
                <input type="text" name="barcode" value="<?= htmlspecialchars($urun['barcode'] ?? '') ?>"
                    placeholder="Örn: 8690504..." class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Mevcut Miktar</label>
                <input type="number" step="0.01" name="quantity" value="<?= $urun['quantity'] ?>" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Kritik Eşik (Min)</label>
                <input type="number" step="0.01" name="min_quantity" value="<?= $urun['min_quantity'] ?? 1.00 ?>" required class="w-full p-2 border rounded border-amber-300 dark:border-amber-600 dark:bg-slate-700 dark:text-white" title="Stok bu miktarın altına indiğinde uyarı verir">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Birim</label>
                <select name="unit" id="birimSelect" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                    <?php foreach(['Adet', 'Paket', 'Kg', 'Litre'] as $b): ?>
                        <option value="<?= $b ?>" <?= $b==$urun['unit'] ? 'selected' : '' ?>><?=$b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alım Tarihi</label>
                <input type="date" name="purchase_date" value="<?= $urun['purchase_date'] ?>" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 dark:bg-slate-700/30 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Son Kullanma Tarihi</label>
                <input type="date" name="expiry_date" id="expiry_date" value="<?= $urun['expiry_date'] ?>" class="w-full p-2 border rounded border-red-200 bg-red-50 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300 disabled:opacity-50 disabled:bg-slate-100 dark:disabled:bg-slate-800" <?= empty($urun['expiry_date']) ? 'disabled' : '' ?>>
                <label class="flex items-center gap-2 mt-2 cursor-pointer">
                    <input type="checkbox" id="no_skt" class="w-4 h-4 text-blue-600 dark:bg-slate-700 dark:border-slate-600" onchange="toggleSKT()" <?= empty($urun['expiry_date']) ? 'checked' : '' ?>>
                    <span class="text-xs text-slate-500 dark:text-slate-400 font-bold">SKT Yok</span>
                </label>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Paket Durumu</label>
                <label class="flex items-center gap-2 mt-2 cursor-pointer">
                    <input type="checkbox" name="is_opened" id="is_opened" value="1" class="w-4 h-4 text-amber-600 dark:bg-slate-700 dark:border-slate-600" onchange="toggleOpenedDate()" <?= !empty($urun['is_opened']) ? 'checked' : '' ?>>
                    <span class="text-xs text-amber-600 dark:text-amber-400 font-bold">⚠️ Paket Açıldı</span>
                </label>
                <div id="opened_date_container" class="mt-2 <?= empty($urun['is_opened']) ? 'hidden' : '' ?>">
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Açıldığı Tarih</label>
                    <input type="date" name="opened_at" id="opened_at" value="<?= $urun['opened_at'] ?? '' ?>" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                </div>
            </div>
        </div>

        <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white py-3 rounded font-bold transition shadow-lg shadow-orange-500/30">
            Güncelle
        </button>
    </form>
</div>

<script nonce="<?= $cspNonce ?>">
document.addEventListener('DOMContentLoaded', () => { 
    const currentShelf = '<?= htmlspecialchars($urun['shelf_location'] ?? '') ?>';
    if(document.getElementById('cabinet').value) {
        checkCabinetType(currentShelf);
    }
});

function toggleSKT() { 
    const c = document.getElementById('no_skt'); 
    const i = document.getElementById('expiry_date'); 
    if(c.checked){ i.value = ''; i.disabled = true; } else { i.disabled = false; } 
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

async function fetchData(action, param = '') { 
    const p = action === 'get_alt_kategoriler' ? `name=${param}` : `id=${param}`; 
    try {
        const res = await fetch(`ajax.php?islem=${action}&${p}`); 
        return await res.json(); 
    } catch(e) {
        console.error("Veri çekme hatası:", e);
        return [];
    }
}

async function fetchMekanlar() { 
    const c = document.getElementById('city').value; 
    const l = document.getElementById('location'); 
    l.innerHTML = '<option>Yükleniyor...</option>'; 
    l.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
    
    const r = document.getElementById('room');
    const cb = document.getElementById('cabinet');
    r.innerHTML = '<option value="">Önce Mekan Seçiniz</option>';
    r.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
    cb.innerHTML = '<option value="">Önce Oda Seçiniz</option>';
    cb.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
    
    const d = await fetchData('get_mekanlar', c); 
    l.innerHTML = '<option value="">Seçiniz...</option>'; 
    d.forEach(i => { const opt = document.createElement('option'); opt.value = i.id; opt.textContent = i.name; l.appendChild(opt); }); 
    if (d && d.length === 1) {
        l.value = d[0].id;
        await fetchOdalar();
    }
}

async function fetchOdalar() { 
    const c = document.getElementById('location').value; 
    const r = document.getElementById('room'); 
    r.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
    
    if(!c) {
        r.innerHTML = '<option value="">Önce Mekan Seçiniz</option>';
        return;
    }

    r.innerHTML = '<option>Yükleniyor...</option>'; 
    const cb = document.getElementById('cabinet');
    cb.innerHTML = '<option value="">Önce Oda Seçiniz</option>';
    cb.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
    
    const d = await fetchData('get_odalar', c); 
    r.innerHTML = '<option value="">Seçiniz...</option>'; 
    d.forEach(i => { const opt = document.createElement('option'); opt.value = i.id; opt.textContent = i.name; r.appendChild(opt); }); 
    if (d && d.length === 1) {
        r.value = d[0].id;
        await fetchDolaplar();
    }
}

async function fetchDolaplar() { 
    const c = document.getElementById('room').value; 
    const cb = document.getElementById('cabinet'); 
    cb.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
    
    if(!c) {
        cb.innerHTML = '<option value="">Önce Oda Seçiniz</option>';
        return;
    }

    cb.innerHTML = '<option>Yükleniyor...</option>'; 
    const d = await fetchData('get_dolaplar', c); 
    cb.innerHTML = '<option value="">Seçiniz...</option>'; 
    d.forEach(i => { const opt = document.createElement('option'); opt.value = i.id; opt.textContent = i.name; cb.appendChild(opt); }); 
    if (d && d.length === 1) {
        cb.value = d[0].id;
        await checkCabinetType();
    }
}

async function fetchSubCategories() { 
    const c = document.getElementById('category').value; 
    const s = document.getElementById('sub_category'); 
    s.innerHTML = '<option>Yükleniyor...</option>'; 
    const d = await fetchData('get_alt_kategoriler', c); 
    s.innerHTML = '<option value="">Seçiniz...</option>'; 
    d.forEach(i => { const opt = document.createElement('option'); opt.value = i; opt.textContent = i; s.appendChild(opt); }); 
    onAltKategoriSecildi();
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

    if (!sub) {
        if (badge) badge.classList.add('hidden');
        if (hiddenType) hiddenType.value = '';
        if (hiddenId) hiddenId.value = '';
        return;
    }

    if (hiddenType) hiddenType.value = sub;

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

            if (data.default_unit && birimSel && !birimSel.value) {
                birimSel.value = data.default_unit;
            }
        }
    } catch (e) {
        console.error('Alt kategori eşik bilgisi alınamadı:', e);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Sayfa açıldığında mevcut alt kategorinin eşiğini yükle
    onAltKategoriSecildi();

    // Form Gönderiminde Konum Doğrulaması (Client-side validation)
    const duzenleFormu = document.getElementById('duzenleFormu');
    if (duzenleFormu) {
        duzenleFormu.addEventListener('submit', function(e) {
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

        // Seçim yapıldığında kırmızı çerçeveyi temizle
        const cityEl = document.getElementById('city');
        const locEl  = document.getElementById('location');
        const roomEl = document.getElementById('room');
        const cabEl  = document.getElementById('cabinet');
        [cityEl, locEl, roomEl, cabEl].forEach(el => {
            if (el) {
                el.addEventListener('change', function() {
                    this.classList.remove('ring-2', 'ring-rose-500', '!border-rose-500');
                });
            }
        });
    }
});

async function checkCabinetType(cur = null) {
    const cid = document.getElementById('cabinet').value; 
    const container = document.getElementById('shelf_container');
    const sel = document.getElementById('shelf_location'); 
    
    if(!cid) {
        container.classList.add('hidden');
        return;
    }
    
    const data = await fetchData('get_dolap_detay', cid);
    
    container.classList.remove('hidden'); 
    sel.innerHTML = '';
    
    if(cur) sel.innerHTML += `<option value="${cur}" selected>${cur}</option><option disabled>---</option>`;
    
    if(data.type && data.type.includes('Buzdolabı')) { 
        container.classList.remove('hidden');
        ['Soğutucu','Dondurucu'].forEach(o => {
            if(o != cur) { const opt = document.createElement('option'); opt.value = o; opt.textContent = o; sel.appendChild(opt); }
        }); 
    } else { 
        container.classList.add('hidden');
        if('Genel' != cur && '' != cur && null != cur) { 
            const opt = document.createElement('option'); opt.value = cur; opt.textContent = cur; sel.appendChild(opt); 
        } else {
            sel.innerHTML = '<option value="">Genel</option>';
        }
    }
}
</script>
</body>
</html>