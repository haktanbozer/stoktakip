<?php
require 'db.php';
girisKontrol();

// Güvenlik: CSP Nonce Kontrolü
if (!isset($cspNonce)) {$cspNonce = ''; }

$sehirler    =$pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
$kategoriler =$pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$aktifSehirId =$_SESSION['aktif_sehir_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    try {
        $id         = uniqid('prod_');$expiryDate = (!empty($_POST['expiry_date'])) ?$_POST['expiry_date'] : null;
        $barcode    = !empty($_POST['barcode']) ? trim($_POST['barcode']) : null;
        $minQty     = isset($_POST['min_quantity']) ? (float)$_POST['min_quantity'] : 1.00;
        $isOpened   = isset($_POST['is_opened']) ? 1 : 0;
        $openedAt   = ($isOpened && !empty($_POST['opened_at'])) ? $_POST['opened_at'] : ($isOpened ? date('Y-m-d') : null);

        $sql = "INSERT INTO products (
                    id, name, barcode, brand, category, sub_category,
                    quantity, min_quantity, unit, cabinet_id, shelf_location,
                    purchase_date, expiry_date, is_opened, opened_at, added_by_user_id
                ) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt =$pdo->prepare($sql);$stmt->execute([
            $id,$_POST['name'],
            $barcode,$_POST['brand'] ?? null,
            $_POST['category'],
            $_POST['sub_category'] ?? '',$_POST['quantity'],
            $minQty,$_POST['unit'],
            $_POST['cabinet_id'],$_POST['shelf_location'] ?? null,
            $_POST['purchase_date'],
            $expiryDate,$isOpened,
            $openedAt,$_SESSION['user_id']
        ]);

        // Loglama
        $stmtCab =$pdo->prepare("SELECT name FROM cabinets WHERE id = ?");
        $stmtCab->execute([$_POST['cabinet_id']]);
        $dolapAdi =$stmtCab->fetchColumn() ?? 'Bilinmeyen Dolap';

        auditLog('EKLEME', "{$_POST['name']} ({$_POST['quantity']} {$_POST['unit']}) sisteme eklendi. Dolap: $dolapAdi");

        header("Location: envanter.php?durum=basarili");
        exit;
    } catch (PDOException $e) {$error = "Kayıt Hatası: " . $e->getMessage();
    }
}

require 'header.php';
?>

<div class="max-w-4xl mx-auto bg-white dark:bg-slate-800 p-8 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
    <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-6">Akıllı Ürün Ekleme</h2>
    
    <?php if(isset($error)): ?>
        <div class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 p-3 rounded mb-4 border border-red-200 dark:border-red-800">
            <?= $error ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6" id="kayitFormu">
        <?php echo csrfAlaniniEkle(); ?>
        
        <div class="bg-slate-50 dark:bg-slate-700/30 p-4 rounded-lg border border-slate-200 dark:border-slate-600 transition-colors">
            <h3 class="font-bold text-blue-600 dark:text-blue-400 mb-3 flex items-center gap-2">📍 Konum Bilgisi</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Şehir</label>
                    <select name="city_id" id="city" class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors" onchange="fetchMekanlar()">
                        <option value="">Seçiniz...</option>
                        <?php foreach($sehirler as$s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($aktifSehirId ==$s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Mekan (Ev/Depo)</label>
                    <select name="location_id" id="location" class="w-full p-2 border rounded bg-slate-100 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" disabled onchange="fetchOdalar()">
                        <option value="">Önce Şehir Seçin</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Oda</label>
                    <select name="room_id" id="room" class="w-full p-2 border rounded bg-slate-100 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" disabled onchange="fetchDolaplar()">
                        <option value="">Önce Mekan Seçin</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Dolap</label>
                    <select name="cabinet_id" id="cabinet" class="w-full p-2 border rounded bg-slate-100 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" required disabled onchange="checkCabinetType()">
                        <option value="">Önce Oda Seçin</option>
                    </select>
                </div>
            </div>

            <div id="shelf_container" class="mt-4 hidden animate-pulse">
                <label class="block text-xs font-bold text-green-600 dark:text-green-400 mb-1" id="shelf_label">Raf/Bölüm Seçimi</label>
                <select name="shelf_location" id="shelf_location" class="w-full p-2 border-2 border-green-200 dark:border-green-800 rounded bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300 transition-colors"></select>
            </div>
        </div>

        <!-- Barkod Okuyucu Satırı -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Barkod</label>
                <div class="flex gap-2">
                    <input type="text" name="barcode" id="barcodeInput" placeholder="Örn: 8690504..." class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                    <button type="button" id="btnBarkodAc" title="Kamera ile Barkod Tara"
                        class="flex-shrink-0 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg transition flex items-center gap-1 text-sm font-bold shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="12" y1="8" x2="12" y2="16"/></svg>
                        Tara
                    </button>
                </div>
                <p id="barkodDurumu" class="text-xs mt-1 hidden"></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Ürün Adı</label>
                <input type="text" name="name" id="urunAdi" required class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Marka</label>
                <input type="text" name="brand" id="marka" class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
            </div>
        </div>

        <!-- Barkod Okuyucu Modalı -->
        <div id="barkodModal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm border border-slate-200 dark:border-slate-700">
                <div class="p-4 border-b dark:border-slate-700 flex justify-between items-center">
                    <h3 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Barkod / QR Tara
                    </h3>
                    <button type="button" id="btnBarkodKapat" class="text-slate-400 hover:text-red-500 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="p-4">
                    <div id="barkodReader" class="rounded-lg overflow-hidden border-2 border-indigo-200 dark:border-indigo-700"></div>
                    <p id="barkodSonucMetni" class="text-center text-sm text-slate-500 dark:text-slate-400 mt-3">Kamera açılıyor...</p>
                    <div class="mt-3">
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Ya da manuel gir:</label>
                        <div class="flex gap-2">
                            <input type="text" id="manuelBarkod" placeholder="Barkod numarası..." class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                            <button type="button" id="btnManuelBarkodSorgula"
                                class="bg-indigo-600 text-white px-3 py-2 rounded text-sm font-bold hover:bg-indigo-700 transition">Ara</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Kategori</label>
                <select name="category" id="category" required class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                    <option value="">Seçiniz...</option>
                    <?php foreach($kategoriler as$kat): ?>
                        <option value="<?= htmlspecialchars($kat['name']) ?>"><?= htmlspecialchars($kat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alt Kategori</label>
                <select name="sub_category" id="sub_category" class="w-full p-2 border rounded bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" disabled>
                    <option value="">Önce Kategori Seçin</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Miktar</label>
                <input type="number" step="0.01" name="quantity" required class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Kritik Eşik (Min)</label>
                <input type="number" step="0.01" name="min_quantity" value="1.00" required class="w-full p-2 border rounded border-amber-300 dark:border-amber-600 dark:bg-slate-700 dark:text-white" title="Stok bu miktarın altına indiğinde uyarı verir">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Birim</label>
                <select name="unit" class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                    <?php foreach(['Adet','Paket','Kutu','Şişe','Kavanoz','Kg','Gram','Lt','Mililitre'] as $b): ?>
                        <option value="<?= $b ?>"><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alım Tarihi</label>
                <input type="date" name="purchase_date" value="<?= date('Y-m-d') ?>" class="w-full p-2 border rounded text-slate-600 dark:text-slate-300 dark:bg-slate-700 dark:border-slate-600 transition-colors">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 dark:bg-slate-700/30 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Son Kullanma Tarihi</label>
                <input type="date" name="expiry_date" id="expiry_date" class="w-full p-2 border rounded border-red-200 bg-red-50 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300 focus:ring-2 focus:ring-red-500 outline-none transition disabled:opacity-50 disabled:bg-slate-100 dark:disabled:bg-slate-900 disabled:border-slate-200 dark:disabled:border-slate-700 disabled:text-slate-400 dark:disabled:text-slate-500">
                <label class="flex items-center gap-2 mt-2 cursor-pointer">
                    <input type="checkbox" id="no_skt" class="w-4 h-4 text-blue-600 dark:bg-slate-700 dark:border-slate-600 rounded">
                    <span class="text-xs text-slate-500 dark:text-slate-400 font-bold">SKT Yok / Süresiz Ürün</span>
                </label>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Paket Durumu</label>
                <label class="flex items-center gap-2 mt-2 cursor-pointer">
                    <input type="checkbox" name="is_opened" id="is_opened" value="1" class="w-4 h-4 text-amber-600 dark:bg-slate-700 dark:border-slate-600">
                    <span class="text-xs text-amber-600 dark:text-amber-400 font-bold">⚠️ Paket Açıldı Olarak Başlat</span>
                </label>
                <div id="opened_date_container" class="mt-2 hidden">
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Açıldığı Tarih</label>
                    <input type="date" name="opened_at" id="opened_at" value="<?= date('Y-m-d') ?>" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                </div>
            </div>
        </div>

        <div class="pt-6 border-t dark:border-slate-700 mt-4">
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
    
    const room    = document.getElementById('room');
    const cabinet = document.getElementById('cabinet');
    
    room.innerHTML    = '<option value="">Önce Mekan Seçin</option>'; 
    room.disabled     = true;
    room.classList.add('bg-slate-100', 'dark:bg-slate-900');
    room.classList.remove('bg-white', 'dark:bg-slate-700');
    
    cabinet.innerHTML = '<option value="">Önce Oda Seçin</option>'; 
    cabinet.disabled  = true;
    cabinet.classList.add('bg-slate-100', 'dark:bg-slate-900');
    cabinet.classList.remove('bg-white', 'dark:bg-slate-700');
    
    document.getElementById('shelf_container').classList.add('hidden');

    if(!cityId) {
        loc.innerHTML = '<option value="">Önce Şehir Seçin</option>';
        return;
    }
    
    const data = await fetchData('get_mekanlar', cityId);
    loc.innerHTML = '<option value="">Seçiniz...</option>'; 
    
    if (data && data.length > 0) {
        data.forEach(i => loc.innerHTML += `<option value="${i.id}">${i.name}</option>`);
        loc.disabled = false;
        loc.classList.remove('bg-slate-100', 'dark:bg-slate-900', 'dark:text-slate-400');
        loc.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');
    } else {
        loc.innerHTML = '<option value="">Bu şehirde mekan yok</option>';
    }
}

async function fetchOdalar() {
    const locId = document.getElementById('location').value; 
    const room  = document.getElementById('room');
    
    room.innerHTML = '<option>Yükleniyor...</option>'; 
    room.disabled  = true;
    
    const cabinet = document.getElementById('cabinet');
    cabinet.innerHTML = '<option value="">Önce Oda Seçin</option>'; 
    cabinet.disabled  = true;
    cabinet.classList.add('bg-slate-100', 'dark:bg-slate-900');
    cabinet.classList.remove('bg-white', 'dark:bg-slate-700');
    
    document.getElementById('shelf_container').classList.add('hidden');
    
    if(!locId) {
        room.innerHTML = '<option value="">Önce Mekan Seçin</option>';
        return;
    }
    
    const data = await fetchData('get_odalar', locId);
    room.innerHTML = '<option value="">Seçiniz...</option>'; 
    
    if (data && data.length > 0) {
        data.forEach(i => room.innerHTML += `<option value="${i.id}">${i.name}</option>`);
        room.disabled = false;
        room.classList.remove('bg-slate-100', 'dark:bg-slate-900', 'dark:text-slate-400');
        room.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');
    } else {
        room.innerHTML = '<option value="">Bu mekanda oda yok</option>';
    }
}

async function fetchDolaplar() {
    const roomId = document.getElementById('room').value; 
    const cab    = document.getElementById('cabinet');
    
    cab.innerHTML = '<option>Yükleniyor...</option>'; 
    cab.disabled  = true;
    document.getElementById('shelf_container').classList.add('hidden');
    
    if(!roomId) {
        cab.innerHTML = '<option value="">Önce Oda Seçin</option>';
        return;
    }
    
    const data = await fetchData('get_dolaplar', roomId);
    cab.innerHTML = '<option value="">Seçiniz...</option>'; 
    
    if (data && data.length > 0) {
        data.forEach(i => cab.innerHTML += `<option value="${i.id}">${i.name}</option>`);
        cab.disabled = false;
        cab.classList.remove('bg-slate-100', 'dark:bg-slate-900', 'dark:text-slate-400');
        cab.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');
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
        data.forEach(i => sub.innerHTML += `<option value="${i}">${i}</option>`); 
        sub.disabled = false; 
        sub.classList.remove('bg-slate-50', 'dark:bg-slate-900', 'dark:text-slate-400');
        sub.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');
    } else { 
        sub.innerHTML = '<option value="">Alt Kategori Yok</option>'; 
        sub.disabled = true; 
    }
}

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
            lbl.innerText = 'Saklama Bölümü'; 
            ['Soğutucu','Dondurucu','Kahvaltılık','Sebzelik','Kapak İçi'].forEach(o => 
                sel.innerHTML += `<option value="${o}">${o}</option>`
            );
        } else {
            lbl.innerText = 'Raf/Çekmece'; 
            const r = parseInt(data.shelf_count)  || 0; 
            const c = parseInt(data.drawer_count) || 0;
            
            if(r > 0) { 
                sel.innerHTML += '<optgroup label="Raflar">'; 
                for(let i = 1; i <= r; i++) 
                    sel.innerHTML += `<option value="${i}. Raf">${i}. Raf</option>`; 
                sel.innerHTML += '</optgroup>'; 
            }
            
            if(c > 0) { 
                sel.innerHTML += '<optgroup label="Çekmeceler">'; 
                for(let i = 1; i <= c; i++) 
                    sel.innerHTML += `<option value="${i}. Çekmece">${i}. Çekmece</option>`; 
                sel.innerHTML += '</optgroup>'; 
            }
            
            sel.innerHTML += '<option value="Genel">Genel Alan</option>';
        }
    } catch(error) {
        console.error('checkCabinetType hatası:', error);
    }
}

</script>

<!-- Barkod Okuyucu Kütüphanesi -->
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

async function barkodSorgu(barkod) {
    if (!barkod || barkod.trim() === '') return;
    barkod = barkod.trim();

    document.getElementById('barcodeInput').value = barkod;
    const durum = document.getElementById('barkodDurumu');
    durum.className = 'text-xs mt-1 text-blue-500 dark:text-blue-400';
    durum.textContent = '🔍 Sistemimizde aranıyor...';
    durum.classList.remove('hidden');

    try {
        // 1. Önce Kendi Veritabanımıza Bak
        const localRes = await fetch(`ajax.php?islem=barkod_getir&barkod=${encodeURIComponent(barkod)}`);
        const localData = await localRes.json();
        
        if (!localData.bulunamadi) {
            document.getElementById('urunAdi').value = localData.name || '';
            document.getElementById('marka').value = localData.brand || '';
            
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
                
                // Alt Kategori seçimi (Eğer varsa)
                if (localData.sub_category) {
                    const subSel = document.getElementById('sub_category');
                    for (let i = 0; i < subSel.options.length; i++) {
                        if (subSel.options[i].value === localData.sub_category) {
                            subSel.selectedIndex = i;
                            break;
                        }
                    }
                }
            }
            
            durum.className = 'text-xs mt-1 text-green-600 dark:text-green-400 font-bold';
            durum.textContent = `⚡ (Sistemden Bulundu): ${localData.name}`;
            document.getElementById('manuelBarkod').value = '';
            return; // Bulunduğu için işlemi bitir
        }

        // 2. Kendi sistemimizde yoksa Open Food Facts'e sor
        durum.textContent = '🔍 Global API (Open Food Facts) aranıyor...';
        const res = await fetch(`https://world.openfoodfacts.org/api/v0/product/${encodeURIComponent(barkod)}.json`);
        const data = await res.json();

        if (data.status === 1 && data.product) {
            const p = data.product;
            const ad = p.product_name_tr || p.product_name || '';
            const marka = p.brands || '';

            if (ad) document.getElementById('urunAdi').value = ad;
            if (marka) document.getElementById('marka').value = marka.split(',')[0].trim();

            durum.className = 'text-xs mt-1 text-emerald-600 dark:text-emerald-400 font-bold';
            durum.textContent = `🌍 (İnternetten Bulundu): ${ad || 'İsim yok'} ${marka ? '— ' + marka : ''}`;
        } else {
            durum.className = 'text-xs mt-1 text-amber-600 dark:text-amber-400 font-bold';
            durum.textContent = '⚠️ Ürün bulunamadı. (İlk kez eklediğinizde sistem öğrenecektir)';
        }
    } catch (e) {
        durum.className = 'text-xs mt-1 text-red-500';
        durum.textContent = '❌ Bağlantı hatası. Barkod yine de kaydedildi.';
    }

    document.getElementById('manuelBarkod').value = '';
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