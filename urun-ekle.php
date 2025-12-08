<?php
require 'db.php';
girisKontrol();

$sehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
$kategoriler = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$aktifSehirId = $_SESSION['aktif_sehir_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = uniqid('prod_');
        
        // SKT Kontrolü: Checkbox işaretliyse veya tarih boşsa NULL yap
        $expiryDate = (!empty($_POST['expiry_date'])) ? $_POST['expiry_date'] : null;

        $sql = "INSERT INTO products (id, name, brand, category, sub_category, quantity, unit, cabinet_id, shelf_location, purchase_date, expiry_date, added_by_user_id) 
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

        header("Location: envanter.php?durum=basarili");
        exit;
    } catch (PDOException $e) {
        $error = "Hata: " . $e->getMessage();
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

    <form method="POST" class="space-y-6">
        
        <div class="bg-slate-50 dark:bg-slate-700/30 p-4 rounded-lg border border-slate-200 dark:border-slate-600 transition-colors">
            <h3 class="font-bold text-blue-600 dark:text-blue-400 mb-3 flex items-center gap-2">📍 Konum Bilgisi</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Şehir</label>
                    <select id="city" class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors" onchange="fetchMekanlar()">
                        <option value="">Seçiniz...</option>
                        <?php foreach($sehirler as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($aktifSehirId == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Mekan (Ev/Depo)</label>
                    <select id="location" class="w-full p-2 border rounded bg-slate-100 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" disabled onchange="fetchOdalar()">
                        <option value="">Önce Şehir Seçin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Oda</label>
                    <select id="room" class="w-full p-2 border rounded bg-slate-100 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 transition-colors" disabled onchange="fetchDolaplar()">
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
                    <?php foreach($kategoriler as $kat): ?><option value="<?= htmlspecialchars($kat['name']) ?>"><?= htmlspecialchars($kat['name']) ?></option><?php endforeach; ?>
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
                    <?php foreach(['Adet','Paket','Kutu','Şişe','Kavanoz','Kg','Gram','Lt','Mililitre'] as $b): ?><option value="<?= $b ?>"><?= $b ?></option><?php endforeach; ?>
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

        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg font-bold text-lg shadow-lg shadow-blue-500/30 transition-colors">
            Kayıt Defterine İşle
        </button>
    </form>
</div>

<script>
// SKT Toggle Fonksiyonu
function toggleSKT() {
    const checkbox = document.getElementById('no_skt');
    const input = document.getElementById('expiry_date');
    if (checkbox.checked) {
        input.value = '';
        input.disabled = true;
        input.required = false;
    } else {
        input.disabled = false;
        input.required = true;
    }
}

// Diğer JS fonksiyonları (Mekanizmalarda değişiklik yok, sadece classlar backendde değişti)
document.addEventListener('DOMContentLoaded', () => { if(document.getElementById('city').value) fetchMekanlar(); });
async function fetchData(action, param = '') { const p = action === 'get_alt_kategoriler' ? `name=${param}` : `id=${param}`; const res = await fetch(`ajax.php?islem=${action}&${p}`); return await res.json(); }

// fetchMekanlar, fetchOdalar vb. fonksiyonlarda oluşturulan HTML stringleri JS içinde olduğundan 
// ve Tailwind class'ları PHP tarafında değil JS stringlerinde olmadığından (JS sadece <option> döndürüyor),
// select elementinin kendisine verdiğimiz dark mode classları yeterli olacaktır.
// Ancak 'disabled' durumunda select kutularının rengini değiştiren JS kodlarına dark mode eklemeliyiz:

async function fetchMekanlar() { 
    const cityId = document.getElementById('city').value; const loc = document.getElementById('location');
    loc.innerHTML = '<option>Yükleniyor...</option>'; loc.disabled=true;
    if(!cityId) return;
    const data = await fetchData('get_mekanlar', cityId);
    loc.innerHTML = '<option value="">Seçiniz...</option>'; data.forEach(i => loc.innerHTML += `<option value="${i.id}">${i.name}</option>`);
    loc.disabled=false;
    // Renk Güncellemesi (Aktif olunca beyaz/koyu yap)
    loc.classList.remove('bg-slate-100', 'dark:bg-slate-900');
    loc.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');
}

async function fetchOdalar() {
    const locId = document.getElementById('location').value; const room = document.getElementById('room');
    room.innerHTML = '<option>Yükleniyor...</option>'; room.disabled=true;
    if(!locId) return;
    const data = await fetchData('get_odalar', locId);
    room.innerHTML = '<option value="">Seçiniz...</option>'; data.forEach(i => room.innerHTML += `<option value="${i.id}">${i.name}</option>`);
    room.disabled=false;
    room.classList.remove('bg-slate-100', 'dark:bg-slate-900');
    room.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');
}

async function fetchDolaplar() {
    const roomId = document.getElementById('room').value; const cab = document.getElementById('cabinet');
    cab.innerHTML = '<option>Yükleniyor...</option>'; cab.disabled=true;
    if(!roomId) return;
    const data = await fetchData('get_dolaplar', roomId);
    cab.innerHTML = '<option value="">Seçiniz...</option>'; data.forEach(i => cab.innerHTML += `<option value="${i.id}">${i.name}</option>`);
    cab.disabled=false;
    cab.classList.remove('bg-slate-100', 'dark:bg-slate-900');
    cab.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');
}

async function fetchSubCategories() {
    const cat = document.getElementById('category').value; const sub = document.getElementById('sub_category');
    sub.innerHTML = '<option>Yükleniyor...</option>'; sub.disabled=true;
    if(!cat) return;
    const data = await fetchData('get_alt_kategoriler', cat);
    if(data.length>0) { 
        sub.innerHTML='<option value="">Seçiniz...</option>'; 
        data.forEach(i=>sub.innerHTML+=`<option value="${i}">${i}</option>`); 
        sub.disabled=false; 
        sub.classList.remove('bg-slate-50', 'dark:bg-slate-900');
        sub.classList.add('bg-white', 'dark:bg-slate-700', 'dark:text-white');
    } else { 
        sub.innerHTML='<option value="">Alt Kategori Yok</option>'; 
        sub.disabled=true; 
    }
}

async function checkCabinetType() {
    const cabId = document.getElementById('cabinet').value; const con = document.getElementById('shelf_container'); const sel = document.getElementById('shelf_location'); const lbl = document.getElementById('shelf_label');
    if(!cabId) { con.classList.add('hidden'); return; }
    const data = await fetchData('get_dolap_detay', cabId);
    con.classList.remove('hidden'); sel.innerHTML='';
    if(data.type && data.type.includes('Buzdolabı')) {
        lbl.innerText='Saklama Bölümü'; ['Soğutucu','Dondurucu','Kahvaltılık','Sebzelik','Kapak İçi'].forEach(o=>sel.innerHTML+=`<option value="${o}">${o}</option>`);
    } else {
        lbl.innerText='Raf/Çekmece'; const r=parseInt(data.shelf_count)||0; const c=parseInt(data.drawer_count)||0;
        if(r>0){ sel.innerHTML+='<optgroup label="Raflar">'; for(let i=1;i<=r;i++) sel.innerHTML+=`<option value="${i}. Raf">${i}. Raf</option>`; sel.innerHTML+='</optgroup>'; }
        if(c>0){ sel.innerHTML+='<optgroup label="Çekmeceler">'; for(let i=1;i<=c;i++) sel.innerHTML+=`<option value="${i}. Çekmece">${i}. Çekmece</option>`; sel.innerHTML+='</optgroup>'; }
        sel.innerHTML+='<option value="Genel">Genel Alan</option>';
    }
}
</script>
</body>
</html>