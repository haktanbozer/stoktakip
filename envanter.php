<?php
require 'db.php';
girisKontrol();

// Silme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sil_urun_id'])) {
    // KRİTİK GÜVENLİK DÜZELTMESİ: CSRF token kontrolü
    csrfKontrol($_POST['csrf_token'] ?? '');

    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$_POST['sil_urun_id']]);
    header("Location: envanter.php?silindi=1");
    exit;
}
/* Orijinal GET silme bloğu kaldırıldı:
if (isset($_GET['sil'])) {
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$_GET['sil']]);
    header("Location: envanter.php?silindi=1");
    exit;
}
*/

// --- FİLTRELEME & SORGULAMA ---
$params = [];

// 1. Temel Sorgu (Transfer için gerekli ID'ler eklendi)
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

// 2. ŞEHİR FİLTRESİ
if (isset($_SESSION['aktif_sehir_id'])) {
    $sql .= " AND l.city_id = ?";
    $params[] = $_SESSION['aktif_sehir_id'];
}

// --- YENİ KONUM FİLTRELERİ (PHP MANTIĞI) ---
if (!empty($_GET['filter_location_id'])) {
    $sql .= " AND l.id = ?";
    $params[] = $_GET['filter_location_id'];
}
if (!empty($_GET['filter_room_id'])) {
    $sql .= " AND r.id = ?";
    $params[] = $_GET['filter_room_id'];
}
if (!empty($_GET['filter_cabinet_id'])) {
    $sql .= " AND c.id = ?";
    $params[] = $_GET['filter_cabinet_id'];
}
// --- YENİ KONUM FİLTRELERİ BİTİŞ ---


// 3. Diğer Filtreler (Aynı kalır)
if (!empty($_GET['q'])) {
    $sql .= " AND (p.name LIKE ? OR p.brand LIKE ?)";
    $term = "%" . $_GET['q'] . "%";
    $params[] = $term; $params[] = $term;
}
if (!empty($_GET['cat'])) {
    $sql .= " AND p.category = ?";
    $params[] = $_GET['cat'];
}
if (!empty($_GET['sub_cat'])) {
    $sql .= " AND p.sub_category = ?";
    $params[] = $_GET['sub_cat'];
}
if (!empty($_GET['date_start'])) {
    $sql .= " AND p.expiry_date >= ?";
    $params[] = $_GET['date_start'];
}
if (!empty($_GET['date_end'])) {
    $sql .= " AND p.expiry_date <= ?";
    $params[] = $_GET['date_end'];
}
if (!empty($_GET['min_qty'])) {
    $sql .= " AND p.quantity >= ?";
    $params[] = $_GET['min_qty'];
}
if (!empty($_GET['max_qty'])) {
    $sql .= " AND p.quantity <= ?";
    $params[] = $_GET['max_qty'];
}

$sql .= " ORDER BY (p.expiry_date IS NULL), p.expiry_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tumUrunler = $stmt->fetchAll();

// --- GRUPLAMA MANTIĞI (Aynı Kalır) ---
$gruplar = [
    'gecmis' => ['title' => '🚨 Süresi Geçmiş Ürünler', 'items' => [], 'color' => 'bg-red-50 dark:bg-red-900/40 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300'],
    'kritik' => ['title' => '⚠️ Çok Yaklaşanlar (7 Gün)', 'items' => [], 'color' => 'bg-orange-50 dark:bg-orange-900/40 border-orange-200 dark:border-orange-800 text-orange-800 dark:text-orange-300'],
    'yakindan' => ['title' => '📅 Bu Ay Tüketilmeli (30 Gün)', 'items' => [], 'color' => 'bg-yellow-50 dark:bg-yellow-900/40 border-yellow-200 dark:border-yellow-800 text-yellow-800 dark:text-yellow-300'],
    'orta' => ['title' => '🗓️ Orta Vade (1-3 Ay)', 'items' => [], 'color' => 'bg-blue-50 dark:bg-blue-900/40 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300'],
    'uzun' => ['title' => '✅ Uzun Ömürlü (+3 Ay)', 'items' => [], 'color' => 'bg-green-50 dark:bg-green-900/40 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300'],
    'suresiz' => ['title' => '♾️ Süresiz / SKT Yok', 'items' => [], 'color' => 'bg-gray-50 dark:bg-slate-800 border-gray-200 dark:border-slate-700 text-gray-800 dark:text-slate-300'],
];

$bugun = time();
foreach ($tumUrunler as $urun) {
    if (empty($urun['expiry_date'])) {
        $gruplar['suresiz']['items'][] = $urun;
    } else {
        $skt = strtotime($urun['expiry_date']);
        $farkGun = ceil(($skt - $bugun) / (60 * 60 * 24));
        if ($farkGun < 0) $gruplar['gecmis']['items'][] = $urun;
        elseif ($farkGun <= 7) $gruplar['kritik']['items'][] = $urun;
        elseif ($farkGun <= 30) $gruplar['yakindan']['items'][] = $urun;
        elseif ($farkGun <= 90) $gruplar['orta']['items'][] = $urun;
        else $gruplar['uzun']['items'][] = $urun;
    }
}

$kategoriler = $pdo->query("SELECT DISTINCT category FROM products")->fetchAll(PDO::FETCH_COLUMN);

// --- TRANSFER VE FİLTRE İÇİN TÜM KONUM VERİLERİNİ ÇEKME (Düzeltildi) ---

// 1. Şehir Filtresi Durumuna göre WHERE koşulu oluşturulur.
$cityFilterCondition = isset($_SESSION['aktif_sehir_id']) ? "AND l.city_id = '" . $_SESSION['aktif_sehir_id'] . "'" : "";

// 2. Şehirler (Filtresiz)
$sehirler_transfer = $pdo->query("SELECT id, name FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// 3. Mekanlar (Locations)
$mekanlar_transfer_sql = "SELECT l.id, l.name, l.city_id FROM locations l LEFT JOIN cities c ON l.city_id = c.id WHERE 1=1 $cityFilterCondition ORDER BY l.name ASC";
$mekanlar_transfer = $pdo->query($mekanlar_transfer_sql)->fetchAll(PDO::FETCH_ASSOC);

// 4. Odalar (Rooms) - r JOIN l yaparak l.city_id'ye erişim sağlanır.
$odalar_transfer_sql = "SELECT r.id, r.name, r.location_id FROM rooms r JOIN locations l ON r.location_id = l.id WHERE 1=1 $cityFilterCondition ORDER BY r.name ASC";
$odalar_transfer   = $pdo->query($odalar_transfer_sql)->fetchAll(PDO::FETCH_ASSOC);

// 5. Dolaplar (Cabinets) - c JOIN r JOIN l yaparak l.city_id'ye erişim sağlanır.
// Hata burada oluşuyordu, l.city_id'ye erişmek için JOIN yapısı tamamlandı.
$dolaplar_transfer_sql = "SELECT c.id, c.name, c.room_id FROM cabinets c JOIN rooms r ON c.room_id = r.id JOIN locations l ON r.location_id = l.id WHERE 1=1 $cityFilterCondition ORDER BY c.name ASC";
$dolaplar_transfer = $pdo->query($dolaplar_transfer_sql)->fetchAll(PDO::FETCH_ASSOC);


require 'header.php';
?>

<div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
    <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">
        Stok Envanteri 
        <?php if(isset($_SESSION['aktif_sehir_ad'])): ?>
            <span class="text-sm font-normal text-slate-500 dark:text-slate-400 ml-2">(<?= htmlspecialchars($_SESSION['aktif_sehir_ad']) ?>)</span>
        <?php endif; ?>
    </h2>
    <a href="urun-ekle.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center gap-2 no-underline">
        + Yeni Ürün
    </a>
</div>

<div class="bg-white dark:bg-slate-800 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-8 transition-colors">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        
        <div class="md:col-span-5">
            <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Ürün adı, marka ara..." class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 text-sm">
        </div>
        
        <div>
            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Kategori</label>
            <select name="cat" id="filter_cat" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="fetchSubCategories(this.value)">
                <option value="">Tümü</option>
                <?php foreach($kategoriler as $k): ?>
                    <option value="<?= $k ?>" <?= (isset($_GET['cat']) && $_GET['cat'] == $k) ? 'selected' : '' ?>><?= $k ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Mekan</label>
            <select name="filter_location_id" id="filter_location" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="filterRoomsForFilter(this.value)">
                <option value="">Tümü</option>
                <?php foreach($mekanlar_transfer as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= (isset($_GET['filter_location_id']) && $_GET['filter_location_id'] == $m['id']) ? 'selected' : '' ?>><?= $m['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Oda</label>
            <select name="filter_room_id" id="filter_room" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" onchange="filterCabinetsForFilter(this.value)">
                <option value="">Tümü</option>
                <?php 
                $current_location_id = $_GET['filter_location_id'] ?? null;
                $filtered_rooms = $current_location_id ? array_filter($odalar_transfer, fn($r) => $r['location_id'] == $current_location_id) : $odalar_transfer;
                
                foreach($filtered_rooms as $o): 
                ?>
                    <option value="<?= $o['id'] ?>" <?= (isset($_GET['filter_room_id']) && $_GET['filter_room_id'] == $o['id']) ? 'selected' : '' ?>><?= $o['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Dolap</label>
            <select name="filter_cabinet_id" id="filter_cabinet" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                <option value="">Tümü</option>
                <?php 
                $current_room_id = $_GET['filter_room_id'] ?? null;
                $filtered_cabinets = $current_room_id ? array_filter($dolaplar_transfer, fn($c) => $c['room_id'] == $current_room_id) : $dolaplar_transfer;
                
                foreach($filtered_cabinets as $c): 
                ?>
                    <option value="<?= $c['id'] ?>" <?= (isset($_GET['filter_cabinet_id']) && $_GET['filter_cabinet_id'] == $c['id']) ? 'selected' : '' ?>><?= $c['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex items-end">
            <button type="submit" class="bg-slate-800 dark:bg-slate-600 text-white px-6 py-2 rounded hover:bg-slate-700 dark:hover:bg-slate-500 text-sm font-bold w-full">Filtrele</button>
        </div>
    </form>
</div>

<div class="space-y-8">
    <?php foreach($gruplar as $key => $grup): ?>
        <?php if(!empty($grup['items'])): ?>
            <div class="border dark:border-slate-700 rounded-xl overflow-hidden shadow-sm">
                <div class="p-3 font-bold text-sm <?= $grup['color'] ?> flex justify-between items-center transition-colors">
                    <span><?= $grup['title'] ?></span>
                    <span class="bg-white/50 dark:bg-black/20 px-2 py-0.5 rounded text-xs"><?= count($grup['items']) ?> Ürün</span>
                </div>
                <div class="bg-white dark:bg-slate-800 overflow-x-auto transition-colors">
                    <table class="w-full text-sm text-left text-slate-600 dark:text-slate-300">
                        <thead class="text-xs text-slate-400 dark:text-slate-500 uppercase bg-slate-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="px-4 py-2">Ürün</th>
                                <th class="px-4 py-2">Konum</th> 
                                <th class="px-4 py-2">Miktar</th>
                                <th class="px-4 py-2">SKT</th>
                                <th class="px-4 py-2 text-right">İşlem</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <?php foreach($grup['items'] as $urun): 
                                if (!empty($urun['expiry_date'])) {
                                    $kalan = ceil((strtotime($urun['expiry_date']) - time()) / (60*60*24));
                                } else {
                                    $kalan = null;
                                }
                            ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($urun['name']) ?></div>
                                    <div class="text-xs text-slate-400"><?= htmlspecialchars($urun['brand']) ?></div>
                                    <div class="text-[10px] text-blue-500 dark:text-blue-400 mt-1"><?= htmlspecialchars($urun['category']) ?> > <?= htmlspecialchars($urun['sub_category']) ?></div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <div class="font-bold text-slate-700 dark:text-slate-300"><?= htmlspecialchars($urun['loc_name'] ?? '-') ?> &rsaquo; <?= htmlspecialchars($urun['room_name'] ?? '-') ?></div>
                                    <div class="text-slate-500 dark:text-slate-400"><?= htmlspecialchars($urun['cab_name'] ?? '-') ?></div>
                                    <?php if($urun['shelf_location']): ?>
                                        <div class="bg-yellow-50 dark:bg-yellow-900/30 inline-block px-1 mt-1 border border-yellow-100 dark:border-yellow-800 text-[10px] rounded text-yellow-700 dark:text-yellow-400">
                                            <?= htmlspecialchars($urun['shelf_location']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <button onclick="hizliTuket(this, '<?= $urun['id'] ?>', '<?= $_SESSION['csrf_token'] ?>')" class="w-6 h-6 rounded-full bg-red-100 dark:bg-red-900/50 text-red-600 dark:text-red-400 hover:bg-red-500 hover:text-white flex items-center justify-center transition font-bold text-lg leading-none pb-1 shadow-sm border border-red-200 dark:border-red-800" title="Tüket">
                                            -
                                        </button>

                                        <button onclick="transferDialog('<?= $urun['id'] ?>', '<?= (float)$urun['quantity'] ?>', '<?= htmlspecialchars($urun['unit']) ?>', '<?= htmlspecialchars($urun['name']) ?>', '<?= $urun['city_id'] ?>', '<?= $urun['location_id'] ?>', '<?= $urun['room_id'] ?>', '<?= $urun['cabinet_id'] ?>', '<?= $_SESSION['csrf_token'] ?>')" 
                                                class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400 hover:bg-blue-500 hover:text-white flex items-center justify-center transition font-bold text-sm leading-none pb-1 shadow-sm border border-blue-200 dark:border-blue-800" title="Hızlı Transfer">
                                            ⇄
                                        </button>
                                        
                                        <span id="qty_<?= $urun['id'] ?>" class="font-medium text-slate-800 dark:text-slate-200">
                                            <?= (float)$urun['quantity'] . ' ' . htmlspecialchars($urun['unit']) ?>
                                        </span>
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <?php if ($kalan !== null): ?>
                                        <div class="<?= $kalan < 0 ? 'text-red-600 dark:text-red-400 font-bold' : ($kalan < 30 ? 'text-orange-600 dark:text-orange-400 font-bold' : '') ?>">
                                            <?= date('d.m.Y', strtotime($urun['expiry_date'])) ?>
                                        </div>
                                        <div class="text-[10px] text-slate-400"><?= $kalan ?> gün kaldı</div>
                                    <?php else: ?>
                                        <div class="text-slate-500 dark:text-slate-400 font-medium">Süresiz</div>
                                        <div class="text-[10px] text-slate-400 dark:text-slate-500">SKT Yok</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="urun-duzenle.php?id=<?= $urun['id'] ?>" class="text-blue-600 dark:text-blue-400 hover:underline mr-2 text-xs font-bold">DÜZENLE</a>
                                    <form method="POST" onsubmit="return confirm('Silinsin mi?')" class="inline">
                                        <?php echo csrfAlaniniEkle(); ?>
                                        <input type="hidden" name="sil_urun_id" value="<?= $urun['id'] ?>">
                                        <button type="submit" class="text-red-500 dark:text-red-400 hover:underline text-xs bg-transparent border-none p-0 cursor-pointer">SİL</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<div id="transferModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-75 transition-opacity duration-300">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md transform transition-all border border-slate-200 dark:border-slate-700">
            <div class="p-6">
                <h3 class="text-xl font-bold text-blue-600 dark:text-blue-400 mb-4 border-b dark:border-slate-700 pb-2">
                    ⇄ <span id="modalProductName">Ürün Transferi</span>
                </h3>
                <form id="transferForm" onsubmit="event.preventDefault(); submitTransfer();" class="space-y-4">
                    <?php echo csrfAlaniniEkle(); ?>
                    <input type="hidden" id="modalProductId" name="id">
                    
                    <div class="bg-slate-50 dark:bg-slate-700/50 p-3 rounded text-sm text-slate-700 dark:text-slate-300">
                        <span id="currentLocationText" class="font-medium"></span>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Miktar (<span id="maxQtyText"></span>)</label>
                        <input type="number" id="transferAmount" name="amount" step="0.01" required min="0.01" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Hedef Şehir</label>
                            <select id="targetCity" onchange="filterLocations(this.value)" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white"></select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Hedef Mekan</label>
                            <select id="targetLocation" onchange="filterRooms(this.value)" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white"></select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Hedef Oda</label>
                            <select id="targetRoom" onchange="filterCabinets(this.value)" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white"></select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Hedef Dolap</label>
                            <select id="targetCabinet" name="new_cab_id" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white"></select>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition">İptal</button>
                        <button type="submit" class="px-4 py-2 text-sm rounded bg-blue-600 hover:bg-blue-700 text-white font-bold transition">Transferi Tamamla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// PHP'den tüm konum verilerini JS'ye aktar
const ALL_CITIES = <?= json_encode($sehirler_transfer) ?>;
const ALL_LOCATIONS = <?= json_encode($mekanlar_transfer) ?>;
const ALL_ROOMS = <?= json_encode($odalar_transfer) ?>;
const ALL_CABINETS = <?= json_encode($dolaplar_transfer) ?>;
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>'; // CSRF token'ı JS'ye aktarıldı
</script>

<script>
// --- FİLTRELEME MANTIKLARI (GENEL VE MODAL İÇİN) ---

// Mevcut konum filtreleri için veriyi tutar (Filtre formundaki JS için)
const AllLocationsData = <?= json_encode($mekanlar_transfer) ?>;
const AllRoomsData = <?= json_encode($odalar_transfer) ?>;
const AllCabinetsData = <?= json_encode($dolaplar_transfer) ?>;


// Sadece filtreleme formundaki "Oda" dropdownunu doldurur
function filterRoomsForFilter(locationId) {
    const roomSelect = document.getElementById('filter_room');
    roomSelect.innerHTML = '<option value="">Tümü</option>';
    document.getElementById('filter_cabinet').innerHTML = '<option value="">Tümü</option>';

    if (!locationId) {
        // Eğer locationId boşsa, tüm odaları göster (ancak PHP tarafından uygulanan şehir filtresine göre)
        AllRoomsData.forEach(r => {
             // Bu kısım artık gereksiz, çünkü AllRoomsData PHP'de aktif şehre göre zaten filtrelenmiş olarak geliyor
             roomSelect.innerHTML += `<option value="${r.id}">${r.name}</option>`;
        });
        return;
    }
    
    // locationId'ye göre filtrele
    const filteredRooms = AllRoomsData.filter(room => room.location_id === locationId);
    filteredRooms.forEach(room => {
        roomSelect.innerHTML += `<option value="${room.id}">${room.name}</option>`;
    });
}

// Sadece filtreleme formundaki "Dolap" dropdownunu doldurur
function filterCabinetsForFilter(roomId) {
    const cabinetSelect = document.getElementById('filter_cabinet');
    cabinetSelect.innerHTML = '<option value="">Tümü</option>';
    
    if (!roomId) {
        // Oda filtresi kalkarsa, tüm dolapları göster (Mekan filtresine göre zaten PHP'de filtrelenmiş olmalı)
        AllCabinetsData.forEach(c => {
             cabinetSelect.innerHTML += `<option value="${c.id}">${c.name}</option>`;
        });
        return;
    }

    const filteredCabinets = AllCabinetsData.filter(cabinet => cabinet.room_id === roomId);
    filteredCabinets.forEach(cabinet => {
        cabinetSelect.innerHTML += `<option value="${cabinet.id}">${cabinet.name}</option>`;
    });
}


// --- MODAL KONUM FİLTRELEME MANTIKLARI (TRANSFER) ---

function populateSelect(selectId, data, defaultValue) {
    const select = document.getElementById(selectId);
    select.innerHTML = '<option value="">Seçiniz...</option>';
    
    if (data.length === 0) {
        select.disabled = true;
        return;
    }
    select.disabled = false;

    data.forEach(item => {
        const selected = item.id === defaultValue ? 'selected' : '';
        select.innerHTML += `<option value="${item.id}" ${selected}>${item.name}</option>`;
    });

    if (selectId === 'targetCity' && defaultValue) {
        filterLocations(defaultValue, document.getElementById('targetLocation').value);
    } else if (selectId === 'targetLocation' && defaultValue) {
        filterRooms(defaultValue, document.getElementById('targetRoom').value);
    } else if (selectId === 'targetRoom' && defaultValue) {
        filterCabinets(defaultValue, document.getElementById('targetCabinet').value);
    }
}

function filterLocations(cityId, defaultLocationId) {
    const filteredLocations = ALL_LOCATIONS.filter(loc => loc.city_id === cityId);
    populateSelect('targetLocation', filteredLocations, defaultLocationId);
    
    if (!defaultLocationId) {
        document.getElementById('targetRoom').innerHTML = '<option value="">Önce Mekan Seçin</option>';
        document.getElementById('targetCabinet').innerHTML = '<option value="">Önce Oda Seçin</option>';
    }
}

function filterRooms(locationId, defaultRoomId) {
    const filteredRooms = ALL_ROOMS.filter(room => room.location_id === locationId);
    populateSelect('targetRoom', filteredRooms, defaultRoomId);
    
    if (!defaultRoomId) {
        document.getElementById('targetCabinet').innerHTML = '<option value="">Önce Oda Seçin</option>';
    }
}

function filterCabinets(roomId, defaultCabinetId) {
    const filteredCabinets = ALL_CABINETS.filter(cabinet => cabinet.room_id === roomId);
    populateSelect('targetCabinet', filteredCabinets, defaultCabinetId);
}


// --- MODAL YÖNETİMİ (TRANSFER) ---

function openModal() {
    document.getElementById('transferModal').classList.remove('hidden');
    document.body.classList.add('overflow-hidden'); 
}

function closeModal() {
    document.getElementById('transferModal').classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
    document.getElementById('transferForm').reset();
}

/**
 * Transfer diyalogunu açar ve modalı mevcut ürün verileriyle doldurur.
 */
function transferDialog(product_id, current_qty, unit, product_name, current_city_id, current_location_id, current_room_id, current_cabinet_id) {
    
    document.getElementById('modalProductId').value = product_id;
    document.getElementById('modalProductName').innerText = `"${product_name}" Transferi`;
    document.getElementById('transferAmount').max = current_qty;
    document.getElementById('maxQtyText').innerText = `${current_qty} ${unit}`;
    
    const currentCityName = ALL_CITIES.find(c => c.id === current_city_id)?.name || 'Bilinmiyor';
    const currentLocationName = ALL_LOCATIONS.find(l => l.id === current_location_id)?.name || 'Bilinmiyor';
    const currentRoomName = ALL_ROOMS.find(r => r.id === current_room_id)?.name || 'Bilinmiyor';
    const currentCabinetName = ALL_CABINETS.find(c => c.id === current_cabinet_id)?.name || 'Bilinmiyor';
    document.getElementById('currentLocationText').innerHTML = `Mevcut Konum: <b>${currentCityName}</b> &rsaquo; <b>${currentLocationName}</b> &rsaquo; <b>${currentRoomName}</b> &rsaquo; <b>${currentCabinetName}</b>`;


    populateSelect('targetCity', ALL_CITIES, current_city_id);
    filterLocations(current_city_id, current_location_id);
    filterRooms(current_location_id, current_room_id);
    filterCabinets(current_room_id, current_cabinet_id); 
    
    // CSRF Token'ı forma eklenir (PHP kodu ile eklenmişti)

    openModal();
}


/**
 * Transfer formunu gönderir ve AJAX çağrısı yapar.
 */
async function submitTransfer() {
    const product_id = document.getElementById('modalProductId').value;
    const amount = parseFloat(document.getElementById('transferAmount').value);
    const max_qty = parseFloat(document.getElementById('transferAmount').max);
    const new_cab_id = document.getElementById('targetCabinet').value;
    
    // YENİLEME: Client-side kontrol atlandığı için buradaki mevcut dolap ID'si okuma kaldırıldı.
    // Server tarafı (ajax.php) ürüne ait mevcut dolap ID'sini güvenilir bir şekilde veritabanından çeker.
    
    if (amount <= 0 || amount > max_qty || isNaN(amount)) {
        alert(`Lütfen ${max_qty} değerini aşmayan geçerli bir miktar girin.`);
        return;
    }
    
    if (!new_cab_id) {
        alert("Lütfen hedef dolabı seçin.");
        return;
    }

    // KRİTİK GÜVENLİK DÜZELTMESİ: İstemci tarafındaki (client-side) güvenlik kontrolü KALDIRILDI.
    // Bu kontrol, kolayca atlatılabildiği için artık sunucu tarafına devredilmiştir (ajax.php).
    
    const submitBtn = document.querySelector('#transferForm button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerText = 'İşleniyor...';

    // AJAX isteğine CSRF token'ı eklenir (URL Parametresi olarak)
    // Server'ın (ajax.php) kontrol etmesi için sadece product_id ve new_cab_id gönderilir.
    const res = await fetch(`ajax.php?islem=hizli_transfer&id=${product_id}&amount=${amount}&new_cab_id=${new_cab_id}&csrf_token=${CSRF_TOKEN}`);
    
    try {
        const data = await res.json();
        
        if (data.success) {
            alert(`✅ Başarılı: ${data.amount} ${data.unit} ürünü, ${data.new_cab_name} konumuna taşındı!`);
            window.location.reload(); 
        } else {
            // Sunucudan gelen hata mesajı (Hedef dolap aynı olamaz uyarısı dahil) gösterilir.
            alert(`❌ Transfer Hatası: ${data.error || 'Bilinmeyen Hata'}`);
        }
    } catch(e) {
        alert("Sunucuya bağlanırken hata oluştu.");
        console.error("Transfer AJAX hatası:", e);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerText = 'Transferi Tamamla';
    }
}


// Hızlı Tüket Fonksiyonu (Aynı kalır)
// Fonksiyon tanımı, eklenen token parametresini alacak şekilde güncellendi
async function hizliTuket(btn, id) {
    let girilenDeger = prompt("Kaç adet tüketildi?", "1");
    if (girilenDeger === null || girilenDeger.trim() === "") return;
    let adet = parseFloat(girilenDeger.replace(',', '.'));
    if (isNaN(adet) || adet <= 0) { alert("Lütfen geçerli bir sayı giriniz!"); return; }

    btn.disabled = true;
    const orjinalIcerik = btn.innerHTML;
    const orjinalRenk = btn.className;
    
    btn.className = "w-6 h-6 rounded-full bg-gray-200 text-gray-400 flex items-center justify-center animate-spin border border-gray-300";
    btn.innerHTML = "⟳";

    try {
        // AJAX isteğine CSRF token'ı eklenir (URL Parametresi olarak)
        const res = await fetch(`ajax.php?islem=hizli_tuket&id=${id}&adet=${adet}&csrf_token=${CSRF_TOKEN}`);
        const data = await res.json();

        if (data.success) {
            const miktarSpan = document.getElementById(`qty_${id}`);
            miktarSpan.innerText = `${parseFloat(data.yeni_miktar)} ${data.birim}`;
            
            miktarSpan.classList.add('text-red-600', 'font-bold', 'scale-110', 'transition-transform');
            setTimeout(() => miktarSpan.classList.remove('text-red-600', 'font-bold', 'scale-110'), 500);
            
            if (data.yeni_miktar <= 0) {
                btn.closest('tr').classList.add('opacity-50', 'bg-gray-100', 'dark:bg-slate-800');
            }
        }
    } catch (e) {
        alert("Bir hata oluştu!");
        console.error(e);
    } finally {
        btn.innerHTML = orjinalIcerik; 
        btn.className = orjinalRenk;
        btn.disabled = false;
    }
}
</script>
</body>
</html>
