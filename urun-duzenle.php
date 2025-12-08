<?php
require 'db.php';
girisKontrol();

if (!isset($_GET['id'])) { header("Location: envanter.php"); exit; }
$id = $_GET['id'];
$error = '';

// Verileri Çek (Sorgular aynı kalıyor)
$sql = "SELECT p.*, c.room_id, r.location_id, l.city_id FROM products p
        LEFT JOIN cabinets c ON p.cabinet_id = c.id
        LEFT JOIN rooms r ON c.room_id = r.id
        LEFT JOIN locations l ON r.location_id = l.id
        WHERE p.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$urun = $stmt->fetch();
if (!$urun) die("Ürün bulunamadı.");

// Listeleri Hazırla
$sehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
$kategoriler = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

// Alt Listeler
$mekanlar = $odalar = $dolaplar = $altKategoriler = [];
if ($urun['city_id']) { $stmt = $pdo->prepare("SELECT * FROM locations WHERE city_id = ?"); $stmt->execute([$urun['city_id']]); $mekanlar = $stmt->fetchAll(); }
if ($urun['location_id']) { $stmt = $pdo->prepare("SELECT * FROM rooms WHERE location_id = ?"); $stmt->execute([$urun['location_id']]); $odalar = $stmt->fetchAll(); }
if ($urun['room_id']) { $stmt = $pdo->prepare("SELECT * FROM cabinets WHERE room_id = ?"); $stmt->execute([$urun['room_id']]); $dolaplar = $stmt->fetchAll(); }
if ($urun['category']) { $stmt = $pdo->prepare("SELECT sub_categories FROM categories WHERE name = ?"); $stmt->execute([$urun['category']]); $cat = $stmt->fetch(); if($cat) $altKategoriler = array_filter(array_map('trim', explode(',', $cat['sub_categories']))); }

// Kaydetme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // KRİTİK GÜVENLİK DÜZELTMESİ: CSRF token kontrolü
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    try {
        $expiryDate = (!empty($_POST['expiry_date'])) ? $_POST['expiry_date'] : null;
        
        $sql = "UPDATE products SET name=?, brand=?, category=?, sub_category=?, quantity=?, unit=?, cabinet_id=?, shelf_location=?, purchase_date=?, expiry_date=? WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['name'], $_POST['brand'], $_POST['category'], $_POST['sub_category'] ?? '', 
            $_POST['quantity'], $_POST['unit'], $_POST['cabinet_id'], $_POST['shelf_location'] ?? null, 
            $_POST['purchase_date'], $expiryDate, $id
        ]);

        // ** AUDIT LOG: ÜRÜN GÜNCELLENDİ **
        // Detaylı loglama (Hangi ürünün güncellendiği)
        auditLog('GÜNCELLEME', "{$urun['name']} ({$urun['quantity']} {$urun['unit']}) ürününün bilgileri güncellendi. Yeni Adı: {$_POST['name']}, Yeni Miktarı: {$_POST['quantity']}");

        header("Location: envanter.php?durum=basarili"); exit;
    } catch (PDOException $e) { $error = "Hata: " . $e->getMessage(); }
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
            <?= $error ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <?php echo csrfAlaniniEkle(); ?>
        <div class="bg-slate-50 dark:bg-slate-700/30 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
            <h3 class="font-bold text-blue-600 dark:text-blue-400 mb-3">📍 Konum</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Şehir</label>
                    <select id="city" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="fetchMekanlar()">
                        <?php foreach($sehirler as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id']==$urun['city_id']?'selected':'' ?>><?= $s['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Mekan</label>
                    <select id="location" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="fetchOdalar()">
                        <?php foreach($mekanlar as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $m['id']==$urun['location_id']?'selected':'' ?>><?= $m['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Oda</label>
                    <select id="room" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="fetchDolaplar()">
                        <?php foreach($odalar as $o): ?>
                            <option value="<?= $o['id'] ?>" <?= $o['id']==$urun['room_id']?'selected':'' ?>><?= $o['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Dolap</label>
                    <select name="cabinet_id" id="cabinet" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="checkCabinetType()">
                        <?php foreach($dolaplar as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id']==$urun['cabinet_id']?'selected':'' ?>><?= $c['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div id="shelf_container" class="mt-4">
                <label class="block text-xs font-bold text-green-600 dark:text-green-400 mb-1">Raf</label>
                <select name="shelf_location" id="shelf_location" class="w-full p-2 border rounded bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300">
                    <option value="<?= $urun['shelf_location'] ?>" selected><?= $urun['shelf_location'] ?></option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Ürün Adı</label>
                <input type="text" name="name" value="<?= htmlspecialchars($urun['name']) ?>" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Marka</label>
                <input type="text" name="brand" value="<?= htmlspecialchars($urun['brand']) ?>" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Kategori</label>
                <select name="category" id="category" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="fetchSubCategories()">
                    <?php foreach($kategoriler as $k): ?>
                        <option value="<?= $k['name'] ?>" <?= $k['name']==$urun['category']?'selected':'' ?>><?= $k['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alt Kategori</label>
                <select name="sub_category" id="sub_category" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                    <?php foreach($altKategoriler as $a): ?>
                        <option value="<?= $a ?>" <?= $a==$urun['sub_category']?'selected':'' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-4 gap-4">
            <div class="col-span-1">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Miktar</label>
                <input type="number" step="0.01" name="quantity" value="<?= $urun['quantity'] ?>" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
            <div class="col-span-1">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Birim</label>
                <select name="unit" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                    <?php foreach(['Adet','Paket','Kutu','Şişe','Kavanoz','Kg','Gram','Lt','Mililitre'] as $b): ?>
                        <option value="<?= $b ?>" <?= $b==$urun['unit']?'selected':'' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-span-1">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alım Tar.</label>
                <input type="date" name="purchase_date" value="<?= $urun['purchase_date'] ?>" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
            
            <div class="col-span-1 relative">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Son Kullanma</label>
                <input type="date" name="expiry_date" id="expiry_date" value="<?= $urun['expiry_date'] ?>" class="w-full p-2 border rounded border-red-200 bg-red-50 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300 disabled:opacity-50 disabled:bg-slate-100 dark:disabled:bg-slate-800" <?= empty($urun['expiry_date']) ? 'disabled' : '' ?>>
                <label class="flex items-center gap-2 mt-2 cursor-pointer">
                    <input type="checkbox" id="no_skt" class="w-4 h-4 text-blue-600 dark:bg-slate-700 dark:border-slate-600" onchange="toggleSKT()" <?= empty($urun['expiry_date']) ? 'checked' : '' ?>>
                    <span class="text-xs text-slate-500 dark:text-slate-400 font-bold">SKT Yok</span>
                </label>
            </div>
        </div>

        <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white py-3 rounded font-bold transition shadow-lg shadow-orange-500/30">
            Güncelle
        </button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => { checkCabinetType('<?= $urun['shelf_location'] ?>'); });
function toggleSKT() { const c=document.getElementById('no_skt'); const i=document.getElementById('expiry_date'); if(c.checked){i.value='';i.disabled=true;}else{i.disabled=false;} }
async function fetchData(action, param = '') { const p = action === 'get_alt_kategoriler' ? `name=${param}` : `id=${param}`; const res = await fetch(`ajax.php?islem=${action}&${p}`); return await res.json(); }
async function fetchMekanlar() { const c=document.getElementById('city').value; const l=document.getElementById('location'); l.innerHTML='<option>Yükleniyor...</option>'; const d=await fetchData('get_mekanlar',c); l.innerHTML='<option>Seçiniz...</option>'; d.forEach(i=>l.innerHTML+=`<option value="${i.id}">${i.name}</option>`); }
async function fetchOdalar() { const c=document.getElementById('location').value; const r=document.getElementById('room'); r.innerHTML='<option>Yükleniyor...</option>'; const d=await fetchData('get_odalar',c); r.innerHTML='<option>Seçiniz...</option>'; d.forEach(i=>r.innerHTML+=`<option value="${i.id}">${i.name}</option>`); }
async function fetchDolaplar() { const c=document.getElementById('room').value; const cb=document.getElementById('cabinet'); cb.innerHTML='<option>Yükleniyor...</option>'; const d=await fetchData('get_dolaplar',c); cb.innerHTML='<option>Seçiniz...</option>'; d.forEach(i=>cb.innerHTML+=`<option value="${i.id}">${i.name}</option>`); }
async function fetchSubCategories() { const c=document.getElementById('category').value; const s=document.getElementById('sub_category'); s.innerHTML='<option>Yükleniyor...</option>'; const d=await fetchData('get_alt_kategoriler',c); s.innerHTML='<option>Seçiniz...</option>'; d.forEach(i=>s.innerHTML+=`<option value="${i}">${i}</option>`); }
async function checkCabinetType(cur=null) {
    const cid=document.getElementById('cabinet').value; if(!cid)return;
    const data=await fetchData('get_dolap_detay',cid);
    const sel=document.getElementById('shelf_location'); document.getElementById('shelf_container').classList.remove('hidden'); sel.innerHTML='';
    if(cur) sel.innerHTML+=`<option value="${cur}" selected>${cur}</option><option disabled>---</option>`;
    if(data.type&&data.type.includes('Buzdolabı')) { ['Soğutucu','Dondurucu','Kahvaltılık'].forEach(o=>{if(o!=cur)sel.innerHTML+=`<option value="${o}">${o}</option>`}); }
    else { const r=parseInt(data.shelf_count)||0; for(let i=1;i<=r;i++) if(`${i}. Raf`!=cur) sel.innerHTML+=`<option value="${i}. Raf">${i}. Raf</option>`; sel.innerHTML+='<option value="Genel">Genel</option>'; }
}
</script>
</body>
</html>
