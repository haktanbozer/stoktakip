<?php
require 'db.php';
girisKontrol();

// --- 1. SİLME İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sil_urun_id'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    $silId   = $_POST['sil_urun_id'];
    $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
    
    if ($isAdmin) {
        // Admin: Herhangi bir ürünü silebilir
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$silId]);
    } else {
        // Normal kullanıcı: Yalnızca yetkili olduğu şehirdeki ürünleri silebilir (IDOR koruması)
        $stmt = $pdo->prepare("DELETE p FROM products p
            JOIN cabinets cab ON p.cabinet_id = cab.id
            JOIN rooms r ON cab.room_id = r.id
            JOIN locations l ON r.location_id = l.id
            JOIN user_city_assignments uca ON l.city_id = uca.city_id AND uca.user_id = ?
            WHERE p.id = ?");
        $stmt->execute([$_SESSION['user_id'], $silId]);
        
        if ($stmt->rowCount() === 0) {
            sistemLogla("IDOR Girişimi - Yetkisiz ürün silme: user={$_SESSION['user_id']}, product=$silId", 'SECURITY');
        }
    }
    
    header("Location: envanter.php?silindi=1");
    exit;
}

// --- 2. VERİ ÇEKME & FİLTRELEME ---
$params = [];
$sql = "SELECT 
            p.*, 
            l.name as loc_name, 
            r.name as room_name, 
            c.name as cab_name,
            l.city_id, 
            r.location_id,      
            c.room_id  
        FROM products p 
        LEFT JOIN cabinets c ON p.cabinet_id = c.id 
        LEFT JOIN rooms r ON c.room_id = r.id 
        LEFT JOIN locations l ON r.location_id = l.id 
        WHERE 1=1";

$isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
$userId  = $_SESSION['user_id'] ?? '';
$cityParam = $_SESSION['aktif_sehir_id'] ?? null;

if (!$isAdmin) {
    if (!empty($cityParam)) {
        $sql .= " AND l.city_id = ? AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)";
        $params[] = $cityParam;
        $params[] = $userId;
    } else {
        $sql .= " AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)";
        $params[] = $userId;
    }
} elseif (!empty($cityParam)) {
    $sql .= " AND l.city_id = ?";
    $params[] = $cityParam;
}

// Filtreler
if (!empty($_GET['q'])) {
    $sql .= " AND (p.name LIKE ? OR p.brand LIKE ? OR p.product_type LIKE ?)";
    $term = "%" . $_GET['q'] . "%";
    $params[] = $term; $params[] = $term; $params[] = $term;
}
if (!empty($_GET['cat'])) { $sql .= " AND p.category = ?"; $params[] = $_GET['cat']; }
if (!empty($_GET['sub_cat'])) { 
    $sql .= " AND (p.sub_category = ? OR p.product_type = ?)"; 
    $params[] = $_GET['sub_cat']; 
    $params[] = $_GET['sub_cat']; 
}
if (!empty($_GET['filter_location_id'])) { $sql .= " AND l.id = ?"; $params[] = $_GET['filter_location_id']; }
if (!empty($_GET['filter_room_id'])) { $sql .= " AND r.id = ?"; $params[] = $_GET['filter_room_id']; }
if (!empty($_GET['filter_cabinet_id'])) { $sql .= " AND c.id = ?"; $params[] = $_GET['filter_cabinet_id']; }

// Önce tarihi olanlar (en acil/en yakın olanlar en üstte), en sona süresizler (NULL)
$sql .= " ORDER BY CASE WHEN p.expiry_date IS NULL THEN 1 ELSE 0 END ASC, p.expiry_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tumUrunler = $stmt->fetchAll();

// --- 3. SEÇENEKLER ---
$kategoriler = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

// Alt Kategori Filtre Seçenekleri
$seciliKategori = $_GET['cat'] ?? '';
$seciliAltKategori = $_GET['sub_cat'] ?? '';
$altKategoriler = [];

if (!empty($seciliKategori)) {
    $stmtAlt = $pdo->prepare("SELECT DISTINCT name FROM product_types WHERE category = ? ORDER BY name ASC");
    $stmtAlt->execute([$seciliKategori]);
    $altKategoriler = $stmtAlt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($altKategoriler)) {
        $stmtAlt2 = $pdo->prepare("SELECT DISTINCT sub_category FROM products WHERE category = ? AND sub_category IS NOT NULL AND sub_category != '' ORDER BY sub_category ASC");
        $stmtAlt2->execute([$seciliKategori]);
        $altKategoriler = $stmtAlt2->fetchAll(PDO::FETCH_COLUMN);
    }
} else {
    $altKategoriler = $pdo->query("SELECT DISTINCT sub_category FROM products WHERE sub_category IS NOT NULL AND sub_category != '' ORDER BY sub_category ASC")->fetchAll(PDO::FETCH_COLUMN);
}

// Transfer Modal Verileri & Şehir İzolasyonu
if (!$isAdmin) {
    $stmtSehir = $pdo->prepare("SELECT c.id, c.name FROM cities c JOIN user_city_assignments uca ON c.id = uca.city_id WHERE uca.user_id = ? ORDER BY c.name ASC");
    $stmtSehir->execute([$userId]);
    $sehirler_tr = $stmtSehir->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($cityParam)) {
        $stmtL = $pdo->prepare("SELECT l.id, l.name, l.city_id FROM locations l WHERE l.city_id = ? AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?) ORDER BY l.name ASC");
        $stmtL->execute([$cityParam, $userId]);
        $mekanlar_tr = $stmtL->fetchAll(PDO::FETCH_ASSOC);

        $stmtR = $pdo->prepare("SELECT r.id, r.name, r.location_id FROM rooms r JOIN locations l ON r.location_id = l.id WHERE l.city_id = ? AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?) ORDER BY r.name ASC");
        $stmtR->execute([$cityParam, $userId]);
        $odalar_tr = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        $stmtC = $pdo->prepare("SELECT c.id, c.name, c.room_id FROM cabinets c JOIN rooms r ON c.room_id = r.id JOIN locations l ON r.location_id = l.id WHERE l.city_id = ? AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?) ORDER BY c.name ASC");
        $stmtC->execute([$cityParam, $userId]);
        $dolaplar_tr = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $cinsSQL = "SELECT pt.id, pt.name, pt.category, pt.sub_category, pt.default_unit, pt.min_threshold,
                    COALESCE(SUM(p.quantity), 0) as toplam_stok,
                    COUNT(p.id) as paket_sayisi
                    FROM product_types pt
                    LEFT JOIN (
                        SELECT prod.id, prod.product_type_id, prod.quantity
                        FROM products prod
                        JOIN cabinets c ON prod.cabinet_id = c.id
                        JOIN rooms r ON c.room_id = r.id
                        JOIN locations l ON r.location_id = l.id
                        WHERE l.city_id = ? AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)
                    ) p ON p.product_type_id = pt.id
                    GROUP BY pt.id ORDER BY pt.category ASC, pt.name ASC";
        $cinsParams = [$cityParam, $userId];
    } else {
        $stmtL = $pdo->prepare("SELECT l.id, l.name, l.city_id FROM locations l WHERE l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?) ORDER BY l.name ASC");
        $stmtL->execute([$userId]);
        $mekanlar_tr = $stmtL->fetchAll(PDO::FETCH_ASSOC);

        $stmtR = $pdo->prepare("SELECT r.id, r.name, r.location_id FROM rooms r JOIN locations l ON r.location_id = l.id WHERE l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?) ORDER BY r.name ASC");
        $stmtR->execute([$userId]);
        $odalar_tr = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        $stmtC = $pdo->prepare("SELECT c.id, c.name, c.room_id FROM cabinets c JOIN rooms r ON c.room_id = r.id JOIN locations l ON r.location_id = l.id WHERE l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?) ORDER BY c.name ASC");
        $stmtC->execute([$userId]);
        $dolaplar_tr = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $cinsSQL = "SELECT pt.id, pt.name, pt.category, pt.sub_category, pt.default_unit, pt.min_threshold,
                    COALESCE(SUM(p.quantity), 0) as toplam_stok,
                    COUNT(p.id) as paket_sayisi
                    FROM product_types pt
                    LEFT JOIN (
                        SELECT prod.id, prod.product_type_id, prod.quantity
                        FROM products prod
                        JOIN cabinets c ON prod.cabinet_id = c.id
                        JOIN rooms r ON c.room_id = r.id
                        JOIN locations l ON r.location_id = l.id
                        WHERE l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)
                    ) p ON p.product_type_id = pt.id
                    GROUP BY pt.id ORDER BY pt.category ASC, pt.name ASC";
        $cinsParams = [$userId];
    }
} else {
    // Admin
    $sehirler_tr = $pdo->query("SELECT id, name FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($cityParam)) {
        $stmtL = $pdo->prepare("SELECT l.id, l.name, l.city_id FROM locations l LEFT JOIN cities c ON l.city_id = c.id WHERE l.city_id = ? ORDER BY l.name ASC");
        $stmtL->execute([$cityParam]);
        $mekanlar_tr = $stmtL->fetchAll(PDO::FETCH_ASSOC);

        $stmtR = $pdo->prepare("SELECT r.id, r.name, r.location_id FROM rooms r JOIN locations l ON r.location_id = l.id WHERE l.city_id = ? ORDER BY r.name ASC");
        $stmtR->execute([$cityParam]);
        $odalar_tr = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        $stmtC = $pdo->prepare("SELECT c.id, c.name, c.room_id FROM cabinets c JOIN rooms r ON c.room_id = r.id JOIN locations l ON r.location_id = l.id WHERE l.city_id = ? ORDER BY c.name ASC");
        $stmtC->execute([$cityParam]);
        $dolaplar_tr = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $cinsSQL = "SELECT pt.id, pt.name, pt.category, pt.sub_category, pt.default_unit, pt.min_threshold,
                    COALESCE(SUM(p.quantity), 0) as toplam_stok,
                    COUNT(p.id) as paket_sayisi
                    FROM product_types pt
                    LEFT JOIN (
                        SELECT prod.id, prod.product_type_id, prod.quantity
                        FROM products prod
                        JOIN cabinets c ON prod.cabinet_id = c.id
                        JOIN rooms r ON c.room_id = r.id
                        JOIN locations l ON r.location_id = l.id
                        WHERE l.city_id = ?
                    ) p ON p.product_type_id = pt.id
                    GROUP BY pt.id ORDER BY pt.category ASC, pt.name ASC";
        $cinsParams = [$cityParam];
    } else {
        $mekanlar_tr = $pdo->query("SELECT l.id, l.name, l.city_id FROM locations l LEFT JOIN cities c ON l.city_id = c.id ORDER BY l.name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $odalar_tr   = $pdo->query("SELECT r.id, r.name, r.location_id FROM rooms r JOIN locations l ON r.location_id = l.id ORDER BY r.name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $dolaplar_tr = $pdo->query("SELECT c.id, c.name, c.room_id FROM cabinets c JOIN rooms r ON c.room_id = r.id JOIN locations l ON r.location_id = l.id ORDER BY c.name ASC")->fetchAll(PDO::FETCH_ASSOC);

        $cinsSQL = "SELECT pt.id, pt.name, pt.category, pt.sub_category, pt.default_unit, pt.min_threshold,
                    COALESCE(SUM(p.quantity), 0) as toplam_stok,
                    COUNT(p.id) as paket_sayisi
                    FROM product_types pt
                    LEFT JOIN products p ON p.product_type_id = pt.id
                    GROUP BY pt.id ORDER BY pt.category ASC, pt.name ASC";
        $cinsParams = [];
    }
}
$stmtCins = $pdo->prepare($cinsSQL);
$stmtCins->execute($cinsParams);
$cinsOzetleri = $stmtCins->fetchAll(PDO::FETCH_ASSOC);

require 'header.php';

?>

<a href="urun-ekle.php" class="md:hidden fixed bottom-6 right-6 bg-blue-600 text-white w-14 h-14 rounded-full shadow-2xl flex items-center justify-center z-40 hover:scale-110 transition border-2 border-white dark:border-slate-800">
    <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
</a>

<div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
    <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
        Stok Envanteri 
        <?php if(isset($_SESSION['aktif_sehir_ad'])): ?>
            <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full dark:bg-blue-900 dark:text-blue-300 font-normal">
                <?= htmlspecialchars($_SESSION['aktif_sehir_ad']) ?>
            </span>
        <?php endif; ?>
    </h2>
    <div class="flex items-center gap-2 flex-wrap justify-end">
        <button type="button" id="btnCinsOzetiAc" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 dark:hover:bg-indigo-900/50 px-3 py-2 rounded-lg transition items-center gap-2 flex text-sm font-bold border border-indigo-200 dark:border-indigo-800 shadow-sm" title="Evdeki Tüm Ürün Cinslerinin Toplam Stok Durumu">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            <span>🏷️ Cins Özeti</span>
        </button>
        <a href="excel-export.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600 px-3 py-2 rounded-lg transition items-center gap-2 flex text-sm font-bold border border-slate-200 dark:border-slate-600 shadow-sm" title="Envanteri İndir (CSV)">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span class="hidden md:inline">İndir</span>
        </a>
        <a href="excel-import.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600 px-3 py-2 rounded-lg transition items-center gap-2 flex text-sm font-bold border border-slate-200 dark:border-slate-600 shadow-sm" title="Excel'den Yükle">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <span class="hidden md:inline">Yükle</span>
        </a>
        <a href="urun-ekle.php" class="hidden md:flex bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition items-center gap-2 shadow-lg shadow-blue-500/30 text-sm font-bold">
            + Yeni Ürün
        </a>
        <a href="toplu-ekle.php" class="hidden md:flex bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition items-center gap-2 shadow-lg shadow-green-500/30 text-sm font-bold">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Toplu Ekle
        </a>
    </div>
</div>

<div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-6 transition-colors overflow-hidden">
    <details class="group">
        <summary class="flex justify-between items-center p-4 cursor-pointer bg-slate-50 dark:bg-slate-700/50">
            <span class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                Arama ve Filtreleme
            </span>
            <span class="transition group-open:rotate-180">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
        </summary>
        
        <div class="p-5 border-t border-slate-200 dark:border-slate-700">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="sm:col-span-2 md:col-span-3 lg:col-span-6 relative">
                    <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Ürün adı veya marka ara..." class="w-full pl-10 p-3 border rounded-lg focus:ring-2 focus:ring-blue-500 text-sm dark:bg-slate-900 dark:border-slate-600 dark:text-white transition-colors">
                    <svg class="absolute left-3 top-3.5 text-slate-400 w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Kategori</label>
                    <select name="cat" id="filter_cat" class="w-full p-2.5 border rounded-lg text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                        <option value="">Tümü</option>
                        <?php foreach($kategoriler as $k): ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= ($seciliKategori == $k) ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Alt Kategori (Cins)</label>
                    <select name="sub_cat" id="filter_sub_cat" class="w-full p-2.5 border rounded-lg text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                        <option value="">Tümü</option>
                        <?php foreach($altKategoriler as $ak): ?>
                            <option value="<?= htmlspecialchars($ak) ?>" <?= ($seciliAltKategori == $ak) ? 'selected' : '' ?>><?= htmlspecialchars($ak) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Mekan</label>
                    <select name="filter_location_id" id="filter_location" class="w-full p-2.5 border rounded-lg text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                        <option value="">Tümü</option>
                        <?php foreach($mekanlar_tr as $m): ?>
                            <option value="<?= htmlspecialchars($m['id']) ?>" <?= (isset($_GET['filter_location_id']) && $_GET['filter_location_id'] == $m['id']) ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Oda</label>
                    <select name="filter_room_id" id="filter_room" class="w-full p-2.5 border rounded-lg text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                        <option value="">Tümü</option>
                        <?php 
                        $cur_loc = $_GET['filter_location_id'] ?? null;
                        $filt_rooms = $cur_loc ? array_filter($odalar_tr, fn($r)=>$r['location_id']==$cur_loc) : $odalar_tr;
                        foreach($filt_rooms as $o): ?>
                            <option value="<?= htmlspecialchars($o['id']) ?>" <?= (isset($_GET['filter_room_id']) && $_GET['filter_room_id'] == $o['id']) ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Dolap</label>
                    <select name="filter_cabinet_id" id="filter_cabinet" class="w-full p-2.5 border rounded-lg text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                        <option value="">Tümü</option>
                        <?php 
                        $cur_room = $_GET['filter_room_id'] ?? null;
                        $filt_cabs = $cur_room ? array_filter($dolaplar_tr, fn($c)=>$c['room_id']==$cur_room) : $dolaplar_tr;
                        foreach($filt_cabs as $c): ?>
                            <option value="<?= htmlspecialchars($c['id']) ?>" <?= (isset($_GET['filter_cabinet_id']) && $_GET['filter_cabinet_id'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" class="bg-slate-800 dark:bg-slate-600 text-white px-6 py-2.5 rounded-lg hover:bg-slate-700 dark:hover:bg-slate-500 text-sm font-bold w-full transition shadow-md">
                        Sonuçları Getir
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>

<div class="hidden md:block bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors p-2">
    <table id="urunTablosu" class="w-full text-sm text-left text-slate-600 dark:text-slate-300">
        <thead class="text-xs text-slate-400 dark:text-slate-500 uppercase bg-slate-50 dark:bg-slate-700/50">
            <tr>
                <th class="px-4 py-3">Durum</th>
                <th class="px-4 py-3">Ürün</th>
                <th class="px-4 py-3">Konum</th>
                <th class="px-4 py-3">Miktar</th>
                <th class="px-4 py-3">SKT</th>
                <th class="px-4 py-3 text-right">İşlem</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
        <?php foreach($tumUrunler as$urun): 
            $durumHtml = hesaplaDurum($urun);
            // Sıralama için sayısal değer: Süresizler en sona (2147483647), olanlar timestamp
            $orderTimestamp = !empty($urun['expiry_date']) ? strtotime($urun['expiry_date']) : 2147483647;
        ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                <td class="px-4 py-3"><?= $durumHtml['badge'] ?></td>
                <td class="px-4 py-3">
                    <div class="font-bold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($urun['name']) ?></div>
                    <div class="text-xs text-slate-400"><?= htmlspecialchars($urun['brand'] ?? '') ?></div>
                    <div class="flex items-center gap-1.5 flex-wrap mt-1">
                        <span class="text-[10px] text-blue-500 dark:text-blue-400">
                            <?= htmlspecialchars($urun['category']) ?><?= !empty($urun['sub_category']) ? ' &rsaquo; ' . htmlspecialchars($urun['sub_category']) : '' ?>
                        </span>
                        <?php if(!empty($urun['product_type']) && $urun['product_type'] !== $urun['sub_category']): ?>
                            <span class="inline-flex items-center text-[10px] font-semibold bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 px-1.5 py-0.5 rounded">
                                🏷️ <?= htmlspecialchars($urun['product_type']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="px-4 py-3 text-xs">
                    <div class="font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($urun['loc_name']) ?> &rsaquo; <?= htmlspecialchars($urun['room_name']) ?></div>
                    <div class="text-slate-500"><?= htmlspecialchars($urun['cab_name']) ?></div>
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        <button type="button" class="btn-tuket w-6 h-6 rounded bg-red-100 text-red-600 hover:bg-red-500 hover:text-white flex items-center justify-center font-bold" data-id="<?= $urun['id'] ?>">-</button>
                        <span id="qty_desk_<?= $urun['id'] ?>" class="font-bold"><?= (float)$urun['quantity'] . ' ' . htmlspecialchars($urun['unit']) ?></span>
                        <button type="button" class="btn-transfer w-6 h-6 rounded bg-blue-100 text-blue-600 hover:bg-blue-500 hover:text-white flex items-center justify-center font-bold" data-json="<?= htmlspecialchars(json_encode($urun, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">⇄</button>
                    </div>
                </td>
                <td class="px-4 py-3" data-order="<?= $orderTimestamp ?>">
                    <?= $durumHtml['tarih'] ?>
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="urun-duzenle.php?id=<?= $urun['id'] ?>" class="text-blue-600 hover:underline text-xs mr-2 font-bold">DÜZENLE</a>
                    <form method="POST" class="inline delete-form">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="hidden" name="sil_urun_id" value="<?= $urun['id'] ?>">
                        <button class="text-red-500 hover:underline text-xs font-bold">SİL</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="md:hidden space-y-4 pb-20"> 
    <?php if(empty($tumUrunler)): ?>
        <div class="text-center p-8 text-slate-400 dark:text-slate-500">
            <div class="text-4xl mb-2">📦</div>
            Ürün bulunamadı.
        </div>
    <?php else: ?>
        <?php foreach($tumUrunler as $urun):$durumHtml = hesaplaDurum($urun);$cardBorder = $durumHtml['risk'] == 'expired' ? 'border-l-4 border-l-red-500' : 
                          ($durumHtml['risk'] == 'critical' ? 'border-l-4 border-l-orange-500' : 'border-l-4 border-l-green-500');
        ?>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-4 relative <?= $cardBorder ?>">
            
            <div class="flex justify-between items-start mb-2">
                <div>
                    <h3 class="font-bold text-slate-800 dark:text-white text-lg leading-tight">
                        <?= htmlspecialchars($urun['name']) ?>
                    </h3>
                    <?php if(!empty($urun['brand'])): ?>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium"><?= htmlspecialchars($urun['brand']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="text-right">
                    <span id="qty_mob_<?= $urun['id'] ?>" class="block font-bold text-lg text-slate-700 dark:text-slate-200">
                        <?= (float)$urun['quantity'] ?> <span class="text-xs font-normal"><?= $urun['unit'] ?></span>
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 text-xs mb-4">
                <span class="bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-1 rounded flex items-center gap-1">
                    🏠 <?= htmlspecialchars($urun['room_name']) ?> &rsaquo; <?= htmlspecialchars($urun['cab_name']) ?>
                </span>
                <span class="bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-300 px-2 py-1 rounded">
                    <?= htmlspecialchars($urun['category']) ?><?= !empty($urun['sub_category']) ? ' &rsaquo; ' . htmlspecialchars($urun['sub_category']) : '' ?>
                </span>
                <?php if(!empty($urun['product_type']) && $urun['product_type'] !== $urun['sub_category']): ?>
                    <span class="bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 px-2 py-1 rounded font-medium">
                        🏷️ <?= htmlspecialchars($urun['product_type']) ?>
                    </span>
                <?php endif; ?>
                <?= $durumHtml['badge'] ?>
            </div>

            <div class="flex items-center justify-between border-t border-slate-100 dark:border-slate-700 pt-3 mt-2">
                <div class="text-xs text-slate-500 dark:text-slate-400">
                    <span class="block text-[10px] uppercase tracking-wide opacity-70">Son Kullanma</span>
                    <?= $durumHtml['tarih_kisa'] ?>
                </div>

                <div class="flex gap-1">
                    <button type="button" class="btn-tuket h-9 w-9 flex items-center justify-center rounded-lg bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400 hover:bg-red-100 border border-red-200 dark:border-red-800 transition" data-id="<?= $urun['id'] ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>
                    </button>
                    
                    <button type="button" class="btn-transfer h-9 w-9 flex items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400 hover:bg-blue-100 border border-blue-200 dark:border-blue-800 transition" 
                            data-json="<?= htmlspecialchars(json_encode($urun, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 4v16M3 8h10M3 16h10m4-8 4 4-4 4"/></svg>
                    </button>

                    <a href="urun-duzenle.php?id=<?= $urun['id'] ?>" class="h-9 w-9 flex items-center justify-center rounded-lg bg-orange-50 text-orange-600 dark:bg-orange-900/20 dark:text-orange-400 hover:bg-orange-100 border border-orange-200 dark:border-orange-800 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                    </a>

                    <form method="POST" class="delete-form inline">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="hidden" name="sil_urun_id" value="<?= $urun['id'] ?>">
                        <button type="submit" class="h-9 w-9 flex items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-400 hover:bg-slate-200 border border-slate-200 dark:border-slate-600 transition">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div id="transferModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-75 backdrop-blur-sm transition-opacity duration-300">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md transform transition-all border border-slate-200 dark:border-slate-700">
            <div class="p-6">
                <h3 class="text-xl font-bold text-blue-600 dark:text-blue-400 mb-4 border-b dark:border-slate-700 pb-2 flex items-center gap-2">
                    <span>⇄</span> <span id="modalProductName">Ürün Transferi</span>
                </h3>
                <form id="transferForm" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" id="modalProductId" name="id">

                    <div class="bg-slate-50 dark:bg-slate-700/50 p-3 rounded text-sm text-slate-700 dark:text-slate-300 flex items-center gap-2">
                        <span class="text-lg">📍</span>
                        <span id="currentLocationText" class="font-medium"></span>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Transfer Miktarı (<span id="maxQtyText"></span>)</label>
                        <input type="number" id="transferAmount" name="amount" step="0.01" required min="0.01" class="w-full p-3 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white font-bold text-lg">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="text-[10px] uppercase font-bold text-slate-400">Hedef Şehir</label><select id="targetCity" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white text-sm"></select></div>
                        <div><label class="text-[10px] uppercase font-bold text-slate-400">Hedef Mekan</label><select id="targetLocation" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white text-sm"></select></div>
                        <div><label class="text-[10px] uppercase font-bold text-slate-400">Hedef Oda</label><select id="targetRoom" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white text-sm"></select></div>
                        <div><label class="text-[10px] uppercase font-bold text-slate-400">Hedef Dolap</label><select id="targetCabinet" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white text-sm"></select></div>
                    </div>

                    <div id="targetShelfContainer" class="mt-2 hidden">
                        <label class="block text-xs font-bold text-green-600 dark:text-green-400 mb-1">Raf/Bölüm</label>
                        <select id="targetShelf" class="w-full p-2 border-2 rounded border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300"></select>
                    </div>

                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" id="btnTransferCancel" class="px-4 py-2.5 text-sm rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 transition font-medium">İptal</button>
                        <button type="button" id="btnTransferSubmit" class="px-6 py-2.5 text-sm rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-bold shadow-lg shadow-blue-500/30 transition">Onayla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<!-- ── CİNS BAZLI STOK ÖZETİ MODALI ───────────────────────────────────── -->
<div id="cinsOzetModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-75 backdrop-blur-sm transition-opacity duration-300">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-2xl transform transition-all border border-slate-200 dark:border-slate-700">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4 pb-3 border-b dark:border-slate-700">
                    <h3 class="text-xl font-bold text-slate-800 dark:text-white flex items-center gap-2">
                        🏷️ Ürün Cinsi Stok Durumu (Ev Geneli)
                    </h3>
                    <button type="button" id="btnCinsModalKapat" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-2xl font-bold">&times;</button>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                    Farklı marka ve dolaplardaki aynı cins ürünler (örn: Basmati Pirinç, Taze Kaşar) burada ev geneli olarak toplanır.
                </p>

                <?php if(empty($cinsOzetleri)): ?>
                    <div class="text-center py-8 text-slate-400">
                        Henüz tanımlanmış ürün cinsi bulunmuyor. Ürün eklerken "Ürün Tipi" seçerek oluşturabilirsiniz.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto max-h-96 overflow-y-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-700/50 text-xs font-bold uppercase text-slate-500 dark:text-slate-400 sticky top-0">
                                <tr>
                                    <th class="p-3">Cins Adı</th>
                                    <th class="p-3">Kategori</th>
                                    <th class="p-3 text-center">Toplam Stok</th>
                                    <th class="p-3 text-center">Eşik</th>
                                    <th class="p-3 text-center">Durum</th>
                                    <th class="p-3 text-right">İşlem</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                                <?php foreach($cinsOzetleri as $co): 
                                    $toplam = (float)$co['toplam_stok'];
                                    $esik   = (float)$co['min_threshold'];
                                    $durum = ($toplam <= 0) 
                                        ? '<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300">TÜKENDİ</span>' 
                                        : (($toplam <= $esik) 
                                            ? '<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">AZALDI</span>' 
                                            : '<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300">YETERLİ</span>');
                                ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                        <td class="p-3 font-bold text-slate-800 dark:text-slate-200">
                                            <?= htmlspecialchars($co['name']) ?>
                                            <span class="block text-[11px] font-normal text-slate-400"><?= $co['paket_sayisi'] ?> paket</span>
                                        </td>
                                        <td class="p-3 text-xs text-slate-500">
                                            <?= htmlspecialchars($co['category']) ?>
                                            <?php if($co['sub_category']): ?>
                                                <span class="text-slate-400">&rsaquo; <?= htmlspecialchars($co['sub_category']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 text-center font-bold text-slate-700 dark:text-slate-200">
                                            <?= $toplam ?> <?= htmlspecialchars($co['default_unit']) ?>
                                        </td>
                                        <td class="p-3 text-center text-xs text-slate-400">
                                            Min: <?= $esik ?> <?= htmlspecialchars($co['default_unit']) ?>
                                        </td>
                                        <td class="p-3 text-center">
                                            <?= $durum ?>
                                        </td>
                                        <td class="p-3 text-right">
                                            <a href="envanter.php?q=<?= urlencode($co['name']) ?>" class="text-xs text-blue-600 dark:text-blue-400 font-bold hover:underline">
                                                Filtrele →
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="mt-4 pt-3 border-t dark:border-slate-700 flex justify-between items-center text-xs">
                    <span class="text-slate-400">Yeni ürün eklerken ürün tipleri otomatik listelenir.</span>
                    <button type="button" onclick="document.getElementById('cinsOzetModal').classList.add('hidden')" class="px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 rounded font-medium hover:bg-slate-200">
                        Kapat
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
function hesaplaDurum($urun) {
    if (empty($urun['expiry_date'])) {
        return [
            'risk' => 'safe',
            'badge' => '<span class="bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 px-2 py-0.5 rounded text-[10px] font-bold uppercase">Süresiz</span>',
            'tarih' => '<span class="text-slate-400 text-xs italic">SKT Yok</span>',
            'tarih_kisa' => 'Süresiz'
        ];
    }
    
    $bugun = strtotime('today');$skt = strtotime($urun['expiry_date']);$fark = (int)round(($skt -$bugun) / 86400);
    $tarihYazi = date('d.m.Y',$skt);
    
    if ($fark < 0) {$badge = '<span class="bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300 px-2 py-0.5 rounded text-[10px] font-bold uppercase">Geçmiş</span>';
        $kisa = "<span class='text-red-600 font-bold'>$tarihYazi (" . abs($fark) . " gün geçti)</span>";
        $risk = 'expired';
    } elseif ($fark <= 7) {$badge = '<span class="bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-300 px-2 py-0.5 rounded text-[10px] font-bold uppercase">Kritik</span>';
        $kisa = "<span class='text-orange-600 font-bold'>$tarihYazi ($fark gün kaldı)</span>";
        $risk = 'critical';
    } elseif ($fark <= 30) {$badge = '<span class="bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300 px-2 py-0.5 rounded text-[10px] font-bold uppercase">Yakın</span>';
        $kisa = "<span class='text-yellow-600 font-medium'>$tarihYazi ($fark gün)</span>";
        $risk = 'warning';
    } else {
        $badge = '<span class="bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300 px-2 py-0.5 rounded text-[10px] font-bold uppercase">Güvenli</span>';
        $kisa = "<span class='text-slate-600 dark:text-slate-400'>$tarihYazi</span>";
        $risk = 'safe';
    }
    
    return ['risk'=>$risk, 'badge'=>$badge, 'tarih'=>$kisa, 'tarih_kisa'=>$kisa];
}
?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.tailwindcss.min.js"></script>

<script nonce="<?= $cspNonce ?>">
const DATA_CITIES = <?= json_encode($sehirler_tr) ?>;
const DATA_LOCS   = <?= json_encode($mekanlar_tr) ?>;
const DATA_ROOMS  = <?= json_encode($odalar_tr) ?>;
const DATA_CABS   = <?= json_encode($dolaplar_tr) ?>;
const CSRF_TOKEN  = '<?= $_SESSION['csrf_token'] ?? '' ?>';

$(document).ready(function() {
    // Sadece masaüstünde DataTables başlat
    if (window.innerWidth >= 768) {
        $('#urunTablosu').DataTable({
            "language": { "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" },
            "pageLength": 25,
            "responsive": true,
            // 4. indeks (SKT sütununa) göre küçükten büyüğe otomatik sırala
            "order": [[4, 'asc']],
            "columnDefs": [
                { "orderable": false, "targets": 5 }
            ]
        });
    }

    // Kategori değiştiğinde alt kategorileri dinamik getir
    $('#filter_cat').change(async function() {
        const cat = this.value;
        const subEl = $('#filter_sub_cat');
        subEl.html('<option value="">Yükleniyor...</option>');
        
        if (!cat) {
            subEl.html('<option value="">Tümü</option>');
            return;
        }

        try {
            const res = await fetch(`ajax.php?islem=get_alt_kategoriler&name=${encodeURIComponent(cat)}`);
            const data = await res.json();
            subEl.html('<option value="">Tümü</option>');
            if (data && data.length > 0) {
                data.forEach(item => {
                    subEl.append(`<option value="${item}">${item}</option>`);
                });
            }
        } catch(e) {
            console.error('Alt kategori yükleme hatası:', e);
            subEl.html('<option value="">Tümü</option>');
        }
    });

    $('#filter_location').change(function(){ updateFilters(this.value, 'room'); });
    $('#filter_room').change(function(){ updateFilters(this.value, 'cabinet'); });

    $(document).on('click', '.btn-tuket', function() { hizliTuket(this, $(this).data('id')); });$(document).on('click', '.btn-transfer', function() {
        const u = $(this).data('json'); 
        transferDialog(u.id, u.quantity, u.unit, u.name, u.city_id, u.location_id, u.room_id, u.cabinet_id);
    });

    $(document).on('submit', '.delete-form', function(e) {
        e.preventDefault();
        const form = this;
        Swal.fire({
            title: 'Silinsin mi?', text: "Bu işlem geri alınamaz!", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Evet, Sil', cancelButtonText: 'İptal',
            background: $('html').hasClass('dark') ? '#1e293b' : '#fff', color: $('html').hasClass('dark') ? '#fff' : '#000'
        }).then((res) => { if(res.isConfirmed) form.submit(); });
    });

    $('#btnTransferCancel').click(closeModal);
    $('#btnTransferSubmit').click(submitTransfer);
    $('#targetCabinet').change(function(){ loadTargetShelves(this.value); });
});

function updateFilters(parentId, targetType) {
    let targetEl = targetType === 'room' ? $('#filter_room') : $('#filter_cabinet');
    let sourceData = targetType === 'room' ? DATA_ROOMS : DATA_CABS;
    let matchKey = targetType === 'room' ? 'location_id' : 'room_id';
    
    targetEl.html('<option value="">Tümü</option>');
    if(targetType === 'room') $('#filter_cabinet').html('<option value="">Tümü</option>');

    if(parentId) {
        let filtered = sourceData.filter(x => x[matchKey] == parentId);
        filtered.forEach(x => targetEl.append(`<option value="${x.id}">${x.name}</option>`));
    } else {
        sourceData.forEach(x => targetEl.append(`<option value="${x.id}">${x.name}</option>`));
    }
}

async function hizliTuket(btn, id) {
    const { value: adet } = await Swal.fire({
        title: 'Hızlı Tüketim', input: 'number', inputLabel: 'Miktar', inputValue: 1,
        showCancelButton: true, confirmButtonText: 'Düş',
        inputAttributes: { min: 0.01, step: 0.01 },
        background: $('html').hasClass('dark') ? '#1e293b' : '#fff', color: $('html').hasClass('dark') ? '#fff' : '#000'
    });

    if (adet) {
        try {
            const res = await fetch(`ajax.php?islem=hizli_tuket&id=${id}&adet=${adet}&csrf_token=${CSRF_TOKEN}`);
            const data = await res.json();
            if (data.success) {
                $(`#qty_desk_${id}`).text(`${parseFloat(data.yeni_miktar)} ${data.birim}`);
                $(`#qty_mob_${id}`).html(`${parseFloat(data.yeni_miktar)} <span class="text-xs font-normal">${data.birim}</span>`);
                
                Swal.fire({ icon: 'success', title: 'Güncellendi', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
                if(data.yeni_miktar <= 0) window.location.reload();
            } else {
                Swal.fire('Hata', data.error, 'error');
            }
        } catch(e) { console.error(e); }
    }
}

function transferDialog(pid, qty, unit, pname, city, loc, room, cab) {
    $('#modalProductId').val(pid);
    $('#modalProductName').text(pname);
    $('#transferAmount').val(qty).attr('max', qty);
    $('#maxQtyText').text(`${qty} ${unit}`);
    
    let cName = (DATA_CITIES.find(x=>x.id==city)||{}).name || '-';
    let lName = (DATA_LOCS.find(x=>x.id==loc)||{}).name || '-';
    let rName = (DATA_ROOMS.find(x=>x.id==room)||{}).name || '-';
    $('#currentLocationText').html(`${cName} &rsaquo; ${lName} &rsaquo; ${rName}`);

    fillSelect('targetCity', DATA_CITIES, city);
    updateModalSelects(city, loc, room);

    $('#targetCity').off('change').on('change', function(){ updateModalSelects(this.value, null, null); });
    $('#targetLocation').off('change').on('change', function(){ updateModalSelects($('#targetCity').val(), this.value, null); });
    $('#targetRoom').off('change').on('change', function(){ updateModalSelects($('#targetCity').val(), $('#targetLocation').val(), this.value); });

    $('#targetShelfContainer').addClass('hidden');
    $('#transferModal').removeClass('hidden');
}

function fillSelect(id, data, selectedVal=null) {
    let el = $(`#${id}`);
    el.html('<option value="">Seçiniz...</option>');
    data.forEach(x => {
        el.append(`<option value="${x.id}" ${x.id==selectedVal?'selected':''}>${x.name}</option>`);
    });
}

function updateModalSelects(cityId, locId, roomId) {
    if(!cityId) return;
    let locs = DATA_LOCS.filter(x => x.city_id == cityId);
    fillSelect('targetLocation', locs, locId);

    if(!locId) { $('#targetRoom, #targetCabinet').html(''); return; }
    let rooms = DATA_ROOMS.filter(x => x.location_id == locId);
    fillSelect('targetRoom', rooms, roomId);

    if(!roomId) { $('#targetCabinet').html(''); return; }
    let cabs = DATA_CABS.filter(x => x.room_id == roomId);
    fillSelect('targetCabinet', cabs, null);
}

function closeModal() { $('#transferModal').addClass('hidden'); }

async function submitTransfer() {
    let pid = $('#modalProductId').val();
    let amt = $('#transferAmount').val();
    let newCab = $('#targetCabinet').val();
    let shelf = $('#targetShelf').val();

    if(!newCab) { Swal.fire('Hata', 'Hedef dolap seçilmedi!', 'warning'); return; }

    try {
        let url = `ajax.php?islem=hizli_transfer&id=${pid}&amount=${amt}&new_cab_id=${newCab}&shelf_location=${shelf}&csrf_token=${CSRF_TOKEN}`;
        let res = await fetch(url);
        let data = await res.json();
        if(data.success) {
            closeModal();
            await Swal.fire('Başarılı', 'Transfer tamamlandı.', 'success');
            window.location.reload();
        } else { Swal.fire('Hata', data.error, 'error'); }
    } catch(e) { console.error(e); }
}

async function loadTargetShelves(cabId) {
    if(!cabId) { $('#targetShelfContainer').addClass('hidden'); return; }
    try {
        let res = await fetch(`ajax.php?islem=get_dolap_detay&id=${cabId}`);
        let data = await res.json();
        let sel = $('#targetShelf').html('');
        if(data.type && data.type.includes('Buzdolabı')) {
            $('#targetShelfContainer').removeClass('hidden');
            ['Soğutucu','Dondurucu'].forEach(x => sel.append(`<option>${x}</option>`));
        } else {
            $('#targetShelfContainer').addClass('hidden');
            sel.append('<option value="">Genel</option>');
        }
    } catch(e){}
}

// Cins Bazlı Stok Özeti Modal Kontrolleri
$('#btnCinsOzetiAc').on('click', function() {
    $('#cinsOzetModal').removeClass('hidden');
});
$('#btnCinsModalKapat').on('click', function() {
    $('#cinsOzetModal').addClass('hidden');
});
</script>
</body>
</html>