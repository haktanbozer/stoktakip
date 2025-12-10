<?php
require 'db.php';
girisKontrol();

// Güvenlik: CSP Nonce Kontrolü
if (!isset($cspNonce)) { $cspNonce = ''; }

$sehirler    = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
$kategoriler = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$aktifSehirId = $_SESSION['aktif_sehir_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    try {
        $id         = uniqid('prod_');
        $expiryDate = (!empty($_POST['expiry_date'])) ? $_POST['expiry_date'] : null;

        $sql = "INSERT INTO products (
                    id, name, brand, category, sub_category,
                    quantity, unit, cabinet_id, shelf_location,
                    purchase_date, expiry_date, added_by_user_id
                ) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $id,
            $_POST['name'],
            $_POST['brand'],
            $_POST['category'],
            $_POST['sub_category'] ?? '',
            $_POST['quantity'],
            $_POST['unit'],
            $_POST['cabinet_id'],
            $_POST['shelf_location'] ?? null,
            $_POST['purchase_date'],
            $expiryDate,
            $_SESSION['user_id']
        ]);

        // Loglama
        $stmtCab = $pdo->prepare("SELECT name FROM cabinets WHERE id = ?");
        $stmtCab->execute([$_POST['cabinet_id']]);
        $dolapAdi = $stmtCab->fetchColumn() ?? 'Bilinmeyen Dolap';

        auditLog('EKLEME', "{$_POST['name']} ({$_POST['quantity']} {$_POST['unit']}) sisteme eklendi. Dolap: $dolapAdi");

        header("Location: envanter.php?durum=basarili");
        exit;
    } catch (PDOException $e) {
        $error = "Kayıt Hatası: " . $e->getMessage();
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
                        <?php foreach($sehirler as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($aktifSehirId == $s['id']) ? 'selected' : '' ?>>
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

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Ürün Adı</label>
                <input type="text" name="name" required class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Marka</label>
                <input type="text" name="brand" class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Kategori</label>
                <select name="category" id="category" required class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors" onchange="fetchSubCategories()">
                    <option value="">Seçiniz...</option>
                    <?php foreach($kategoriler as $kat): ?>
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

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="col-span-2 md:col-span-1">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Miktar</label>
                <input type="number" step="0.01" name="quantity" required class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
            </div>
            <div class="col-span-2 md:col-span-1">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Birim</label>
                <select name="unit" class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                    <?php foreach(['Adet','Paket','Kutu','Şişe','Kavanoz','Kg','Gram','Lt','Mililitre'] as $b): ?>
                        <option value="<?= $b ?>"><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-span-2 md:col-span-1">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alım Tarihi</label>
                <input type="date" name="purchase_date" value="<?= date('Y-m-d') ?>" class="w-full p-2 border rounded text-slate-600 dark:text-slate-300 dark:bg-slate-700 dark:border-slate-600 transition-colors">
            </div>
            
            <div class="col-span-2 md:col-span-1 relative">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Son Kullanma</label>
                <input type="date" name="expiry_date" id="expiry_date" class="w-full p-2 border rounded border-red-200 bg-red-50 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300 focus:ring-2 focus:ring-red-500 outline-none transition disabled:opacity-50 disabled:bg-slate-100 dark:disabled:bg-slate-900 disabled:border-slate-200 dark:disabled:border-slate-700 disabled:text-slate-400 dark:disabled:text-slate-500">
                
                <label class="flex items-center gap-2 mt-2 cursor-pointer">
                    <input type="checkbox" id="no_skt" class="w-4 h-4 text-blue-600 dark:bg-slate-700 dark:border-slate-600 rounded" onchange="toggleSKT()">
                    <span class="text-xs text-slate-500 dark:text-slate-400 font-bold">SKT Yok / Süresiz Ürün</span>
                </label>
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
// 1. SKT Toggle Fonksiyonu
function toggleSKT() {
    const checkbox = document.getElementById('no_skt');
    const input    = document.getElementById('expiry_date');
    if (checkbox.checked) {
        input.value   = '';
        input.disabled = true;
        input.required = false;
    } else {
        input.disabled = false;
        input.required = true;
    }
}

// 2. Sayfa Yüklendiğinde Otomatik Çalışma + EVENT BIND
document.addEventListener('DOMContentLoaded', () => { 
    const citySelect    = document.getElementById('city');
    const locSelect     = document.getElementById('location');
    const roomSelect    = document.getElementById('room');
    const cabSelect     = document.getElementById('cabinet');
    const catSelect     = document.getElementById('category');

    // Şehir zaten seçiliyse mekanları otomatik yükle
    if (citySelect && citySelect.value) {
        console.log('Şehir seçili, mekanlar yükleniyor:', citySelect.value);
        fetchMekanlar(); 
    }

    // INLINE onchange yerine / yanına güvenli event listener’lar
    if (citySelect)  citySelect.addEventListener('change',  fetchMekanlar);
    if (locSelect)   locSelect.addEventListener('change',   fetchOdalar);
    if (roomSelect)  roomSelect.addEventListener('change',  fetchDolaplar);
    if (cabSelect)   cabSelect.addEventListener('change',   checkCabinetType);
    if (catSelect)   catSelect.addEventListener('change',   fetchSubCategories);
});

// 3. Genel AJAX Veri Çekme Fonksiyonu
async function fetchData(action, param = '') { 
    try {
        const qs = (action === 'get_alt_kategoriler')
            ? `name=${encodeURIComponent(param)}`
            : `id=${encodeURIComponent(param)}`;
        
        const res = await fetch(`ajax.php?islem=${encodeURIComponent(action)}&${qs}`);
        
        if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
        }
        
        const data = await res.json();
        console.log(`${action} response:`, data);
        return data;
    } catch (error) {
        console.error(`${action} hatası:`, error);
        return [];
    }
}

// 4. Mekanları Getir
async function fetchMekanlar() { 
    const cityId = document.getElementById('city').value; 
    const loc    = document.getElementById('location');
    
    console.log('fetchMekanlar çağrıldı, cityId:', cityId);
    
    // Sıfırla
    loc.innerHTML = '<option>Yükleniyor...</option>'; 
    loc.disabled  = true;
    
    // Alt seviyeleri de sıfırla
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
        console.log(`${data.length} mekan yüklendi`);
    } else {
        loc.innerHTML = '<option value="">Bu şehirde mekan yok</option>';
        console.warn('Mekan bulunamadı');
    }
}

// 5. Odaları Getir
async function fetchOdalar() {
    const locId = document.getElementById('location').value; 
    const room  = document.getElementById('room');
    
    console.log('fetchOdalar çağrıldı, locId:', locId);
    
    room.innerHTML = '<option>Yükleniyor...</option>'; 
    room.disabled  = true;
    
    // Alt seviyeyi sıfırla
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
        console.log(`${data.length} oda yüklendi`);
    } else {
        room.innerHTML = '<option value="">Bu mekanda oda yok</option>';
        console.warn('Oda bulunamadı');
    }
}

// 6. Dolapları Getir
async function fetchDolaplar() {
    const roomId = document.getElementById('room').value; 
    const cab    = document.getElementById('cabinet');
    
    console.log('fetchDolaplar çağrıldı, roomId:', roomId);
    
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
        console.log(`${data.length} dolap yüklendi`);
    } else {
        cab.innerHTML = '<option value="">Bu odada dolap yok</option>';
        console.warn('Dolap bulunamadı');
    }
}

// 7. Alt Kategorileri Getir
async function fetchSubCategories() {
    const cat = document.getElementById('category').value; 
    const sub = document.getElementById('sub_category');
    
    console.log('fetchSubCategories çağrıldı, cat:', cat);
    
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
        console.log(`${data.length} alt kategori yüklendi`);
    } else { 
        sub.innerHTML = '<option value="">Alt Kategori Yok</option>'; 
        sub.disabled = true; 
        console.warn('Alt kategori bulunamadı');
    }
}

// 8. Dolap Tipine Göre Raf Seçeneklerini Göster
async function checkCabinetType() {
    const cabId = document.getElementById('cabinet').value; 
    const con   = document.getElementById('shelf_container'); 
    const sel   = document.getElementById('shelf_location'); 
    const lbl   = document.getElementById('shelf_label');
    
    console.log('checkCabinetType çağrıldı, cabId:', cabId);
    
    if(!cabId) { 
        con.classList.add('hidden'); 
        return; 
    }
    
    try {
        const data = await fetchData('get_dolap_detay', cabId);
        
        if (!data) {
            console.error('Dolap detayı alınamadı');
            return;
        }
        
        console.log('Dolap detayı:', data);
        
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
        
        console.log('Raf seçenekleri yüklendi');
    } catch(error) {
        console.error('checkCabinetType hatası:', error);
    }
}
</script>

</body>
</html>
