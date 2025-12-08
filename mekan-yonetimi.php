<?php
require 'db.php';
girisKontrol();

// KRİTİK GÜVENLİK DÜZELTMESİ: Sadece ADMIN rolüne sahip kullanıcıların erişimi
if ($_SESSION['role'] !== 'ADMIN') die("Bu sayfaya erişim yetkiniz yok. Yönetici izni gereklidir.");

$mesaj = '';

// --- POST İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // KRİTİK GÜVENLİK DÜZELTMESİ: CSRF token kontrolü
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    try {
        if (isset($_POST['islem'])) {
            $islem = $_POST['islem'];
            $id = uniqid('loc_');
            $logDetay = '';

            if ($islem === 'sehir_ekle') {
                $stmt = $pdo->prepare("INSERT INTO cities (id, name) VALUES (?, ?)");
                $stmt->execute([$id, $_POST['name']]);
                $mesaj = "✅ Şehir eklendi.";
                $logDetay = "Şehir eklendi: " . $_POST['name'];
            }
            elseif ($islem === 'mekan_ekle') {
                $stmt = $pdo->prepare("INSERT INTO locations (id, city_id, name, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id, $_POST['city_id'], $_POST['name'], 'Ev']); 
                $mesaj = "✅ Mekan eklendi.";
                $logDetay = "Mekan eklendi: " . $_POST['name'];
            }
            elseif ($islem === 'oda_ekle') {
                $stmt = $pdo->prepare("INSERT INTO rooms (id, location_id, name) VALUES (?, ?, ?)");
                $stmt->execute([$id, $_POST['location_id'], $_POST['name']]);
                $mesaj = "✅ Oda eklendi.";
                $logDetay = "Oda eklendi: " . $_POST['name'];
            }
            elseif ($islem === 'dolap_ekle') {
                $stmt = $pdo->prepare("INSERT INTO cabinets (id, room_id, name, height, width, depth, shelf_count, door_count, drawer_count, cooler_volume, freezer_volume, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $type = $_POST['type'] ?? 'Dolap';
                $coolerVol = !empty($_POST['cooler_volume']) ? $_POST['cooler_volume'] : null;
                $freezerVol = !empty($_POST['freezer_volume']) ? $_POST['freezer_volume'] : null;

                $stmt->execute([
                    $id, 
                    $_POST['room_id'], 
                    $_POST['name'],
                    $_POST['height'],
                    $_POST['width'],
                    $_POST['depth'],
                    $_POST['shelf_count'],
                    $_POST['door_count'],
                    $_POST['drawer_count'] ?? 0, 
                    $coolerVol,
                    $freezerVol,
                    $type
                ]);
                $mesaj = "✅ Dolap/Buzdolabı tanımlandı.";
                $logDetay = "Dolap eklendi: " . $_POST['name'];
            }
            
            // Audit Log (Ekleme)
            if(function_exists('auditLog') && !empty($logDetay)) auditLog('EKLEME', $logDetay);
        }

        if (isset($_POST['sil_id']) && isset($_POST['tablo'])) {
            $tablo = $_POST['tablo'];
            $silId = $_POST['sil_id'];
            $izinliTablolar = ['cities', 'locations', 'rooms', 'cabinets'];
            if (in_array($tablo, $izinliTablolar)) {
                // Silinen öğenin adını al (Log için)
                $nameCol = "name";
                $stmtCheck = $pdo->prepare("SELECT $nameCol FROM $tablo WHERE id = ?");
                $stmtCheck->execute([$silId]);
                $itemName = $stmtCheck->fetchColumn() ?? 'Bilinmeyen Öğe';

                $stmt = $pdo->prepare("DELETE FROM $tablo WHERE id = ?");
                $stmt->execute([$silId]);
                
                // Audit Log (Silme)
                if(function_exists('auditLog')) auditLog('SİLME', "$tablo tablosundan '$itemName' silindi.");
                
                $mesaj = "🗑️ Kayıt silindi.";
            }
        }

    } catch (PDOException $e) {
        $mesaj = "❌ Hata: " . $e->getMessage();
    }
}

// --- LİSTELER ---
$sehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
$mekanlar = $pdo->query("SELECT l.*, c.name as city_name FROM locations l JOIN cities c ON l.city_id = c.id ORDER BY l.name ASC")->fetchAll();
$odalar   = $pdo->query("SELECT r.*, l.name as loc_name, c.name as city_name FROM rooms r JOIN locations l ON r.location_id = l.id JOIN cities c ON l.city_id = c.id ORDER BY r.name ASC")->fetchAll();

$dolaplar = $pdo->query("SELECT cab.*, r.name as room_name, l.name as loc_name, c.name as city_name 
                         FROM cabinets cab 
                         JOIN rooms r ON cab.room_id = r.id 
                         JOIN locations l ON r.location_id = l.id 
                         JOIN cities c ON l.city_id = c.id 
                         ORDER BY cab.name ASC")->fetchAll();

$dolapTipleri = $pdo->query("SELECT * FROM cabinet_types ORDER BY name ASC")->fetchAll();

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">

        <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-6 transition-colors">Mekan ve Dolap Yapılandırması</h2>

        <?php if($mesaj): ?>
            <div class="bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200 p-3 rounded mb-6 border border-blue-200 dark:border-blue-800 transition-colors"><?= $mesaj ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            
            <div class="space-y-4">
                <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-lg mb-4 text-blue-600 dark:text-blue-400 border-b dark:border-slate-700 pb-2">1. Şehir Yönetimi</h3>
                    <form method="POST" class="flex gap-2 mb-6">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="hidden" name="islem" value="sehir_ekle">
                        <input type="text" name="name" placeholder="Örn: İstanbul" required class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm transition-colors">Ekle</button>
                    </form>
                    <div class="max-h-48 overflow-y-auto border dark:border-slate-700 rounded bg-slate-50 dark:bg-slate-700/30 custom-scrollbar">
                        <table class="w-full text-sm text-left">
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                <?php foreach($sehirler as $s): ?>
                                <tr class="group hover:bg-white dark:hover:bg-slate-700 transition-colors">
                                    <td class="p-3 font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($s['name']) ?></td>
                                    <td class="p-3 text-right">
                                        <form method="POST" onsubmit="confirmDelete(event, 'Şehir', 'Şehri silerseniz bağlı TÜM veriler silinir!')" class="inline">
                                            <?php echo csrfAlaniniEkle(); ?>
                                            <input type="hidden" name="tablo" value="cities">
                                            <input type="hidden" name="sil_id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="text-red-400 hover:text-red-600 dark:hover:text-red-300 p-1 transition-colors">✕</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($sehirler)) echo '<tr><td class="p-3 text-slate-400 dark:text-slate-500 text-center">Henüz şehir yok.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-lg mb-4 text-blue-600 dark:text-blue-400 border-b dark:border-slate-700 pb-2">2. Mekan Yönetimi</h3>
                    <form method="POST" class="space-y-3 mb-6">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="hidden" name="islem" value="mekan_ekle">
                        <div class="flex gap-2">
                            <select name="city_id" required class="w-1/3 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                                <option value="">Şehir Seç...</option>
                                <?php foreach($sehirler as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="name" placeholder="Örn: Yazlık Ev" required class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm transition-colors">Ekle</button>
                        </div>
                    </form>
                    <div class="max-h-48 overflow-y-auto border dark:border-slate-700 rounded bg-slate-50 dark:bg-slate-700/30 custom-scrollbar">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-100 dark:bg-slate-700 text-xs text-slate-500 dark:text-slate-400 font-bold">
                                <tr><th class="p-2">Mekan</th><th class="p-2">Şehir</th><th class="p-2 text-right"></th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                <?php foreach($mekanlar as $m): ?>
                                <tr class="group hover:bg-white dark:hover:bg-slate-700 transition-colors">
                                    <td class="p-2 font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($m['name']) ?></td>
                                    <td class="p-2 text-xs text-blue-500 dark:text-blue-400"><?= htmlspecialchars($m['city_name']) ?></td>
                                    <td class="p-2 text-right">
                                        <form method="POST" onsubmit="confirmDelete(event, 'Mekan')" class="inline">
                                            <?php echo csrfAlaniniEkle(); ?>
                                            <input type="hidden" name="tablo" value="locations">
                                            <input type="hidden" name="sil_id" value="<?= $m['id'] ?>">
                                            <button type="submit" class="text-red-400 hover:text-red-600 dark:hover:text-red-300 p-1 transition-colors">✕</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($mekanlar)) echo '<tr><td colspan="3" class="p-3 text-slate-400 dark:text-slate-500 text-center">Henüz mekan yok.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-lg mb-4 text-blue-600 dark:text-blue-400 border-b dark:border-slate-700 pb-2">3. Oda Yönetimi</h3>
                    <form method="POST" class="space-y-3 mb-6">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="hidden" name="islem" value="oda_ekle">
                        <div class="flex gap-2">
                            <select name="location_id" required class="w-1/2 p-2 border rounded text-sm text-ellipsis overflow-hidden dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                                <option value="">Mekan Seç...</option>
                                <?php foreach($mekanlar as $m): ?>
                                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?> (<?= $m['city_name'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="name" placeholder="Örn: Mutfak" required class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm transition-colors">Ekle</button>
                        </div>
                    </form>
                    <div class="max-h-48 overflow-y-auto border dark:border-slate-700 rounded bg-slate-50 dark:bg-slate-700/30 custom-scrollbar">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-100 dark:bg-slate-700 text-xs text-slate-500 dark:text-slate-400 font-bold">
                                <tr><th class="p-2">Oda</th><th class="p-2">Konum</th><th class="p-2 text-right"></th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                <?php foreach($odalar as $o): ?>
                                <tr class="group hover:bg-white dark:hover:bg-slate-700 transition-colors">
                                    <td class="p-2 font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($o['name']) ?></td>
                                    <td class="p-2 text-slate-500 dark:text-slate-400 text-xs">
                                        <?= htmlspecialchars($o['loc_name']) ?> <span class="text-blue-500 dark:text-blue-400">(<?= htmlspecialchars($o['city_name']) ?>)</span>
                                    </td>
                                    <td class="p-2 text-right">
                                        <form method="POST" onsubmit="confirmDelete(event, 'Oda')" class="inline">
                                            <?php echo csrfAlaniniEkle(); ?>
                                            <input type="hidden" name="tablo" value="rooms">
                                            <input type="hidden" name="sil_id" value="<?= $o['id'] ?>">
                                            <button type="submit" class="text-red-500 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-medium text-xs transition">Sil</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($odalar)) echo '<tr><td colspan="3" class="p-3 text-slate-400 dark:text-slate-500 text-center">Henüz oda yok.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-lg mb-4 text-blue-600 dark:text-blue-400 border-b dark:border-slate-700 pb-2">4. Dolap & Raf Yönetimi</h3>
                    
                    <form method="POST" class="space-y-4 mb-6" id="dolapForm">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="hidden" name="islem" value="dolap_ekle">
                        
                        <div class="flex gap-2">
                            <select name="room_id" required class="w-1/2 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                                <option value="">Oda Seç...</option>
                                <?php foreach($odalar as $o): ?>
                                    <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?> (<?= $o['loc_name'] ?> - <?= $o['city_name'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="name" placeholder="Dolap Adı" required class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Dolap Tipi</label>
                            <select name="type" id="typeSelect" class="w-full p-2 border rounded text-sm bg-slate-50 dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors" onchange="updateFields()">
                                <option value="" data-fields="">Standart (Tip Seçiniz)</option>
                                <?php foreach($dolapTipleri as $dt): ?>
                                    <option value="<?= $dt['name'] ?>" data-fields="<?= $dt['active_fields'] ?>">
                                        <?= htmlspecialchars($dt['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-3" id="dynamicFields">
                            <div data-field="height" class="hidden"><label class="block text-[10px] uppercase text-slate-500 dark:text-slate-400">Yükseklik</label><input type="number" name="height" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white"></div>
                            <div data-field="width" class="hidden"><label class="block text-[10px] uppercase text-slate-500 dark:text-slate-400">Genişlik</label><input type="number" name="width" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white"></div>
                            <div data-field="depth" class="hidden"><label class="block text-[10px] uppercase text-slate-500 dark:text-slate-400">Derinlik</label><input type="number" name="depth" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white"></div>
                            <div data-field="shelf_count" class="hidden"><label class="block text-[10px] uppercase text-slate-500 dark:text-slate-400">Raf Sayısı</label><input type="number" name="shelf_count" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white"></div>
                            <div data-field="door_count" class="hidden"><label class="block text-[10px] uppercase text-slate-500 dark:text-slate-400">Kapak Sayısı</label><input type="number" name="door_count" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white"></div>
                            
                            <div data-field="drawer_count" class="hidden"><label class="block text-[10px] uppercase text-slate-500 dark:text-slate-400">Çekmece Sayısı</label><input type="number" name="drawer_count" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white"></div>
                            
                            <div data-field="cooler_volume" class="hidden"><label class="block text-[10px] uppercase text-slate-500 dark:text-slate-400">Soğutucu Hacim (Lt)</label><input type="number" name="cooler_volume" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white"></div>
                            <div data-field="freezer_volume" class="hidden"><label class="block text-[10px] uppercase text-slate-500 dark:text-slate-400">Dondurucu Hacim (Lt)</label><input type="number" name="freezer_volume" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white"></div>
                        </div>

                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-bold transition-colors">Dolabı Kaydet</button>
                    </form>

                    <div class="max-h-64 overflow-y-auto border dark:border-slate-700 rounded bg-slate-50 dark:bg-slate-700/30 custom-scrollbar">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-100 dark:bg-slate-700 text-xs text-slate-500 dark:text-slate-400 font-bold">
                                <tr><th class="p-2">Dolap</th><th class="p-2">Tip</th><th class="p-2">Konum</th><th class="p-2 text-right"></th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                <?php foreach($dolaplar as $d): ?>
                                <tr class="group hover:bg-white dark:hover:bg-slate-700 transition-colors">
                                    <td class="p-2 font-medium text-slate-700 dark:text-slate-300">
                                        <?= htmlspecialchars($d['name']) ?>
                                        <div class="text-[10px] text-slate-400 dark:text-slate-500">
                                            <?php 
                                                $detaylar = [];
                                                if($d['height']) $detaylar[] = "{$d['height']}x{$d['width']}x{$d['depth']} cm";
                                                if($d['shelf_count']) $detaylar[] = "{$d['shelf_count']} Raf";
                                                if($d['drawer_count']) $detaylar[] = "{$d['drawer_count']} Çekmece";
                                                if($d['cooler_volume']) $detaylar[] = "❄️ {$d['cooler_volume']}L / {$d['freezer_volume']}L";
                                                
                                                echo implode(' | ', $detaylar);
                                            ?>
                                        </div>
                                    </td>
                                    <td class="p-2 text-xs">
                                        <?php if(stripos($d['type'] ?? '', 'Buzdolabı') !== false): ?>
                                            <span class="bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded">❄️ Buzdolabı</span>
                                        <?php else: ?>
                                            <span class="bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-1.5 py-0.5 rounded"><?= htmlspecialchars($d['type'] ?? 'Genel') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-2 text-slate-500 dark:text-slate-400 text-xs">
                                        <?= htmlspecialchars($d['room_name']) ?><br>
                                        <span class="text-[10px] opacity-75">
                                            <?= htmlspecialchars($d['loc_name']) ?> &rsaquo; 
                                            <b class="text-blue-500 dark:text-blue-400"><?= htmlspecialchars($d['city_name']) ?></b>
                                        </span>
                                    </td>
                                    <td class="p-2 text-right">
                                        <form method="POST" onsubmit="confirmDelete(event, 'Dolap', 'İçindeki tüm ürünler de silinecektir!')" class="inline">
                                            <?php echo csrfAlaniniEkle(); ?>
                                            <input type="hidden" name="tablo" value="cabinets">
                                            <input type="hidden" name="sil_id" value="<?= $d['id'] ?>">
                                            <button type="submit" class="text-red-400 hover:text-red-600 dark:hover:text-red-300 p-1 transition-colors">✕</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($dolaplar)) echo '<tr><td colspan="4" class="p-3 text-slate-400 dark:text-slate-500 text-center">Henüz dolap yok.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function updateFields() {
    const select = document.getElementById('typeSelect');
    const selectedOption = select.options[select.selectedIndex];
    const fields = selectedOption.getAttribute('data-fields') ? select.options[select.selectedIndex].getAttribute('data-fields').split(',') : [];
    
    document.querySelectorAll('#dynamicFields > div').forEach(div => div.classList.add('hidden'));
    
    fields.forEach(field => {
        let normalizedField = field.trim();
        // Veritabanı ve HTML name uyumsuzluklarını düzelt
        if(normalizedField === 'coolerVolume') normalizedField = 'cooler_volume';
        if(normalizedField === 'freezerVolume') normalizedField = 'freezer_volume';
        if(normalizedField === 'drawerCount') normalizedField = 'drawer_count';
        if(normalizedField === 'shelfCount') normalizedField = 'shelf_count';
        if(normalizedField === 'doorCount') normalizedField = 'door_count';
        
        const div = document.querySelector(`div[data-field="${normalizedField}"]`);
        if(div) div.classList.remove('hidden');
    });
}

// SweetAlert2 Silme Onayı
function confirmDelete(event, itemType, warningText = 'Bu işlem geri alınamaz!') {
    event.preventDefault();
    const form = event.target;
    
    Swal.fire({
        title: itemType + ' Silinecek!',
        text: warningText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: 'Evet, Sil',
        cancelButtonText: 'İptal',
        background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
        color: document.documentElement.classList.contains('dark') ? '#fff' : '#0f172a'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
}
</script>
</body>
</html>
