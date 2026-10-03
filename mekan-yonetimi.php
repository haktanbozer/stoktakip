<?php
require 'db.php';
girisKontrol();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    if (function_exists('sistemLogla')) {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user = $_SESSION['username'] ?? 'Bilinmeyen';
        sistemLogla("Yetkisiz Sayfa Erişimi Engellendi: mekan-yonetimi.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    }
    header("Location: index.php?hata=yetkisiz");
    exit;
}

if (!isset($cspNonce)) { $cspNonce = ''; }

$mesaj = '';
$mesajTuru = 'info';

// --- POST İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    try {
        if (isset($_POST['islem'])) {
            $islem = $_POST['islem'];
            $isim  = trim($_POST['name'] ?? '');

            if (empty($isim)) {
                throw new Exception("İsim alanı boş bırakılamaz.");
            }

            if (mb_strlen($isim, 'UTF-8') > 100) {
                throw new Exception("İsim çok uzun! Maksimum 100 karakter kullanabilirsiniz.");
            }

            // Nokta, parantez, kesme, tire ve rakam destekli esnek validasyon
            if (!preg_match('/^[a-zA-Z0-9ğüşıöçĞÜŞİÖÇ\s\-_().,\/]+$/u', $isim)) {
                throw new Exception("İsimde geçersiz özel karakterler var.");
            }

            $id = uniqid('loc_');
            $logDetay = '';

            // 1. Şehir Ekleme
            if ($islem === 'sehir_ekle') {
                $check = $pdo->prepare("SELECT COUNT(*) FROM cities WHERE name = ?");
                $check->execute([$isim]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Bu isimde bir şehir zaten mevcut.");
                }

                $stmt = $pdo->prepare("INSERT INTO cities (id, name) VALUES (?, ?)");
                $stmt->execute([$id, $isim]);
                $mesaj = "Şehir başarıyla eklendi: $isim";
                $mesajTuru = 'success';
                $logDetay = "Şehir eklendi: $isim";
            }
            // 2. Mekan Ekleme
            elseif ($islem === 'mekan_ekle') {
                $cityId = $_POST['city_id'] ?? '';
                if (empty($cityId)) throw new Exception("Lütfen bir şehir seçin.");

                $check = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE city_id = ? AND name = ?");
                $check->execute([$cityId, $isim]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Bu şehirde aynı isimde bir mekan zaten tanımlı.");
                }

                $stmt = $pdo->prepare("INSERT INTO locations (id, city_id, name, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id, $cityId, $isim, 'Ev']); 
                $mesaj = "Mekan başarıyla eklendi: $isim";
                $mesajTuru = 'success';
                $logDetay = "Mekan eklendi: $isim";
            }
            // 3. Oda Ekleme
            elseif ($islem === 'oda_ekle') {
                $locId = $_POST['location_id'] ?? '';
                if (empty($locId)) throw new Exception("Lütfen bir mekan seçin.");

                $check = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE location_id = ? AND name = ?");
                $check->execute([$locId, $isim]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Bu mekanda aynı isimde bir oda zaten tanımlı.");
                }

                $stmt = $pdo->prepare("INSERT INTO rooms (id, location_id, name) VALUES (?, ?, ?)");
                $stmt->execute([$id, $locId, $isim]);
                $mesaj = "Oda başarıyla eklendi: $isim";
                $mesajTuru = 'success';
                $logDetay = "Oda eklendi: $isim";
            }
            // 4. Dolap Ekleme
            elseif ($islem === 'dolap_ekle') {
                $roomId = $_POST['room_id'] ?? '';
                if (empty($roomId)) throw new Exception("Lütfen bir oda seçin.");

                $type = !empty($_POST['type']) ? trim($_POST['type']) : 'Genel';

                $stmt = $pdo->prepare("INSERT INTO cabinets (id, room_id, name, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $id, 
                    $roomId, 
                    $isim,
                    $type
                ]);
                $mesaj = "Dolap/Depolama alanı tanımlandı: $isim";
                $mesajTuru = 'success';
                $logDetay = "Dolap eklendi: $isim ($type)";
            }
            
            if (function_exists('auditLog') && !empty($logDetay)) {
                auditLog('EKLEME', $logDetay);
            }
        }

        // SİLME İŞLEMİ (Kademeli Kontrol)
        if (isset($_POST['sil_id']) && isset($_POST['tablo'])) {
            $tablo = $_POST['tablo'];
            $silId = $_POST['sil_id'];
            $izinliTablolar = ['cities', 'locations', 'rooms', 'cabinets'];
            
            if (in_array($tablo, $izinliTablolar)) {
                $stmtCheck = $pdo->prepare("SELECT name FROM $tablo WHERE id = ?");
                $stmtCheck->execute([$silId]);
                $itemName = $stmtCheck->fetchColumn() ?? 'Bilinmeyen Öğe';

                // Kademeli Ürün Kontrolü (Silme işlemlerinde ürün kaybını önle)
                if ($tablo === 'cabinets') {
                    $stmtProd = $pdo->prepare("SELECT COUNT(*) FROM products WHERE cabinet_id = ?");
                    $stmtProd->execute([$silId]);
                    $urunSayisi = $stmtProd->fetchColumn();
                    if ($urunSayisi > 0) {
                        throw new Exception("Bu dolap silinemez! İçinde kayıtlı $urunSayisi adet ürün var. Önce ürünleri başka bir dolaba taşıyın.");
                    }
                } elseif ($tablo === 'rooms') {
                    $stmtProd = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN cabinets cab ON p.cabinet_id = cab.id WHERE cab.room_id = ?");
                    $stmtProd->execute([$silId]);
                    $urunSayisi = $stmtProd->fetchColumn();
                    if ($urunSayisi > 0) {
                        throw new Exception("Bu oda silinemez! Odadaki dolaplarda kayıtlı $urunSayisi adet ürün var. Önce ürünleri başka bir odaya taşıyın.");
                    }
                } elseif ($tablo === 'locations') {
                    $stmtProd = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN cabinets cab ON p.cabinet_id = cab.id JOIN rooms r ON cab.room_id = r.id WHERE r.location_id = ?");
                    $stmtProd->execute([$silId]);
                    $urunSayisi = $stmtProd->fetchColumn();
                    if ($urunSayisi > 0) {
                        throw new Exception("Bu mekan silinemez! Mekandaki dolaplarda kayıtlı $urunSayisi adet ürün var. Önce ürünleri başka bir mekana taşıyın.");
                    }
                } elseif ($tablo === 'cities') {
                    $stmtProd = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN cabinets cab ON p.cabinet_id = cab.id JOIN rooms r ON cab.room_id = r.id JOIN locations l ON r.location_id = l.id WHERE l.city_id = ?");
                    $stmtProd->execute([$silId]);
                    $urunSayisi = $stmtProd->fetchColumn();
                    if ($urunSayisi > 0) {
                        throw new Exception("Bu şehir silinemez! Şehirdeki dolaplarda kayıtlı $urunSayisi adet ürün var. Önce ürünleri başka bir şehre taşıyın.");
                    }
                }

                $stmt = $pdo->prepare("DELETE FROM $tablo WHERE id = ?");
                $stmt->execute([$silId]);
                
                if (function_exists('auditLog')) {
                    auditLog('SİLME', "$tablo tablosundan '$itemName' silindi.");
                }
                
                $mesaj = "'$itemName' başarıyla silindi.";
                $mesajTuru = 'success';
            }
        }

    } catch (Exception $e) {
        $mesaj = $e->getMessage();
        $mesajTuru = 'error';
    }
}

// --- LİSTELERİ ÇEK ---
$sehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$mekanlar = $pdo->query("SELECT l.*, c.name as city_name FROM locations l JOIN cities c ON l.city_id = c.id ORDER BY l.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$odalar   = $pdo->query("SELECT r.*, l.name as loc_name, c.name as city_name FROM rooms r JOIN locations l ON r.location_id = l.id JOIN cities c ON l.city_id = c.id ORDER BY r.name ASC")->fetchAll(PDO::FETCH_ASSOC);

$dolaplar = $pdo->query("SELECT cab.*, r.name as room_name, l.name as loc_name, c.name as city_name,
                         (SELECT COUNT(*) FROM products WHERE cabinet_id = cab.id) as urun_sayisi
                         FROM cabinets cab 
                         JOIN rooms r ON cab.room_id = r.id 
                         JOIN locations l ON r.location_id = l.id 
                         JOIN cities c ON l.city_id = c.id 
                         ORDER BY cab.name ASC")->fetchAll(PDO::FETCH_ASSOC);

$dolapTipleri = $pdo->query("SELECT * FROM cabinet_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">

        <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-6 transition-colors">Mekan ve Dolap Yapılandırması</h2>

        <?php if($mesaj): ?>
            <?php 
                $alertRenk = $mesajTuru === 'success' 
                    ? 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 border-green-500' 
                    : ($mesajTuru === 'error' ? 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 border-red-500' : 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200 border-blue-500');
            ?>
            <div class="<?= $alertRenk ?> p-3 rounded mb-6 border-l-4 transition-colors font-medium">
                <?= htmlspecialchars($mesaj) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            
            <!-- 1. ŞEHİR YÖNETİMİ -->
            <div class="space-y-4">
                <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-lg mb-4 text-blue-600 dark:text-blue-400 border-b dark:border-slate-700 pb-2">1. Şehir Yönetimi</h3>
                    <form method="POST" class="flex gap-2 mb-6">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="hidden" name="islem" value="sehir_ekle">
                        <input type="text" name="name" placeholder="Örn: İstanbul" required class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-bold transition-colors">Ekle</button>
                    </form>
                    <div class="max-h-48 overflow-y-auto border dark:border-slate-700 rounded bg-slate-50 dark:bg-slate-700/30">
                        <table class="w-full text-sm text-left">
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                <?php foreach($sehirler as $s): ?>
                                <tr class="group hover:bg-white dark:hover:bg-slate-700 transition-colors">
                                    <td class="p-3 font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($s['name']) ?></td>
                                    <td class="p-3 text-right">
                                        <form method="POST" onsubmit="confirmDelete(event, 'Şehir', 'Şehri silerseniz bağlı mekanlar ve odalar etkilenecektir!')" class="inline">
                                            <?php echo csrfAlaniniEkle(); ?>
                                            <input type="hidden" name="tablo" value="cities">
                                            <input type="hidden" name="sil_id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="text-red-400 hover:text-red-600 dark:hover:text-red-300 p-1 font-bold">✕</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($sehirler)) echo '<tr><td class="p-3 text-slate-400 text-center">Henüz şehir yok.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 2. MEKAN YÖNETİMİ -->
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
                            <input type="text" name="name" placeholder="Örn: Ev / Depo" required class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm font-bold transition-colors">Ekle</button>
                        </div>
                    </form>
                    <div class="max-h-48 overflow-y-auto border dark:border-slate-700 rounded bg-slate-50 dark:bg-slate-700/30">
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
                                            <button type="submit" class="text-red-400 hover:text-red-600 dark:hover:text-red-300 p-1 font-bold">✕</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($mekanlar)) echo '<tr><td colspan="3" class="p-3 text-slate-400 text-center">Henüz mekan yok.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 3. ODA YÖNETİMİ -->
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
                            <input type="text" name="name" placeholder="Örn: Mutfak / Kiler" required class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm font-bold transition-colors">Ekle</button>
                        </div>
                    </form>
                    <div class="max-h-48 overflow-y-auto border dark:border-slate-700 rounded bg-slate-50 dark:bg-slate-700/30">
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
                                            <button type="submit" class="text-red-500 dark:text-red-400 hover:text-red-700 font-bold text-xs">Sil</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($odalar)) echo '<tr><td colspan="3" class="p-3 text-slate-400 text-center">Henüz oda yok.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 4. DOLAP VE RAF YÖNETİMİ -->
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
                            <select name="type" id="typeSelect" class="w-full p-2 border rounded text-sm bg-slate-50 dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                                <option value="" data-fields="">Standart (Tip Seçiniz)</option>
                                <?php foreach($dolapTipleri as $dt): ?>
                                    <option value="<?= htmlspecialchars($dt['name']) ?>">
                                        <?= htmlspecialchars($dt['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        </div>

                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded text-sm font-bold transition-colors shadow">Dolabı Kaydet</button>
                    </form>

                    <div class="max-h-64 overflow-y-auto border dark:border-slate-700 rounded bg-slate-50 dark:bg-slate-700/30">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-100 dark:bg-slate-700 text-xs text-slate-500 dark:text-slate-400 font-bold">
                                <tr><th class="p-2">Dolap</th><th class="p-2">Tip</th><th class="p-2">Konum</th><th class="p-2 text-right"></th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                <?php foreach($dolaplar as $d): ?>
                                <tr class="group hover:bg-white dark:hover:bg-slate-700 transition-colors">
                                    <td class="p-2 font-medium text-slate-700 dark:text-slate-300">
                                        <?= htmlspecialchars($d['name']) ?>
                                        <span class="text-[10px] text-slate-500 font-normal ml-1">(<?= (int)$d['urun_sayisi'] ?> ürün)</span>
                                    </td>
                                    <td class="p-2 text-xs">
                                        <?php if(stripos($d['type'] ?? '', 'Buzdolabı') !== false): ?>
                                            <span class="bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded font-medium">❄️ Buzdolabı</span>
                                        <?php else: ?>
                                            <span class="bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-1.5 py-0.5 rounded font-medium"><?= htmlspecialchars($d['type'] ?? 'Genel') ?></span>
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
                                        <form method="POST" onsubmit="confirmDelete(event, 'Dolap', '<?= (int)$d['urun_sayisi'] > 0 ? "Dikkat: Bu dolapta {$d['urun_sayisi']} adet ürün var!" : "Bu işlem geri alınamaz!" ?>')" class="inline">
                                            <?php echo csrfAlaniniEkle(); ?>
                                            <input type="hidden" name="tablo" value="cabinets">
                                            <input type="hidden" name="sil_id" value="<?= $d['id'] ?>">
                                            <button type="submit" class="text-red-400 hover:text-red-600 dark:hover:text-red-300 p-1 font-bold">✕</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($dolaplar)) echo '<tr><td colspan="4" class="p-3 text-slate-400 text-center">Henüz dolap yok.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- SweetAlert2 CDN Eklendi -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script nonce="<?= $cspNonce ?>">


function confirmDelete(event, itemType, warningText = 'Bu işlem geri alınamaz!') {
    event.preventDefault();
    const form = event.target;
    
    Swal.fire({
        title: itemType + ' Silinecek!',
        text: warningText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
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