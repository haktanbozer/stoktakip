<?php
require 'db.php';
girisKontrol();

// --- 1. SİLME İŞLEMİ (POST GÜVENLİKLİ) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sil_urun_id'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');

    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$_POST['sil_urun_id']]);
    header("Location: envanter.php?silindi=1");
    exit;
}

// --- 2. FİLTRELEME PARAMETRELERİ ---
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

// Şehir Filtresi (Session)
if (isset($_SESSION['aktif_sehir_id'])) {
    $sql .= " AND l.city_id = ?";
    $params[] = $_SESSION['aktif_sehir_id'];
}

// GET Filtreleri
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
if (!empty($_GET['q'])) {
    $sql .= " AND (p.name LIKE ? OR p.brand LIKE ?)";
    $term = "%" . $_GET['q'] . "%";
    $params[] = $term; 
    $params[] = $term;
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

// Sıralama (SKT'si olmayanlar en sona)
$sql .= " ORDER BY (p.expiry_date IS NULL), p.expiry_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tumUrunler = $stmt->fetchAll();

// --- 3. SEÇENEK VERİLERİ (Selectboxlar için) ---
$kategoriler = $pdo->query("SELECT DISTINCT category FROM products")->fetchAll(PDO::FETCH_COLUMN);

// Transfer ve Filtreleme Modalı Verileri
$cityFilterCondition = isset($_SESSION['aktif_sehir_id']) ? "AND l.city_id = '" . $_SESSION['aktif_sehir_id'] . "'" : "";

// Şehirler, Mekanlar, Odalar, Dolaplar
$sehirler_transfer = $pdo->query("SELECT id, name FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$mekanlar_transfer = $pdo->query("SELECT l.id, l.name, l.city_id FROM locations l LEFT JOIN cities c ON l.city_id = c.id WHERE 1=1 $cityFilterCondition ORDER BY l.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$odalar_transfer   = $pdo->query("SELECT r.id, r.name, r.location_id FROM rooms r JOIN locations l ON r.location_id = l.id WHERE 1=1 $cityFilterCondition ORDER BY r.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$dolaplar_transfer = $pdo->query("SELECT c.id, c.name, c.room_id FROM cabinets c JOIN rooms r ON c.room_id = r.id JOIN locations l ON r.location_id = l.id WHERE 1=1 $cityFilterCondition ORDER BY c.name ASC")->fetchAll(PDO::FETCH_ASSOC);

require 'header.php';
?>

<div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
    <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">
        Stok Envanteri 
        <?php if(isset($_SESSION['aktif_sehir_ad'])): ?>
            <span class="text-sm font-normal text-slate-500 dark:text-slate-400 ml-2">(<?= htmlspecialchars($_SESSION['aktif_sehir_ad']) ?>)</span>
        <?php endif; ?>
    </h2>
    <a href="urun-ekle.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center justify-center md:justify-start gap-2 no-underline shadow-lg shadow-blue-500/30 w-full md:w-auto text-sm">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
        + Yeni Ürün
    </a>
</div>

<div class="bg-white dark:bg-slate-800 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-8 transition-colors">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="md:col-span-5">
            <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Sunucu taraflı arama (Ürün adı, marka)..." class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
        </div>

        <div>
            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Kategori</label>
            <select name="cat" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                <option value="">Tümü</option>
                <?php foreach($kategoriler as $k): ?>
                    <option value="<?= $k ?>" <?= (isset($_GET['cat']) && $_GET['cat'] == $k) ? 'selected' : '' ?>><?= $k ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Mekan</label>
            <select name="filter_location_id" id="filter_location" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                <option value="">Tümü</option>
                <?php foreach($mekanlar_transfer as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= (isset($_GET['filter_location_id']) && $_GET['filter_location_id'] == $m['id']) ? 'selected' : '' ?>><?= $m['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Oda</label>
            <select name="filter_room_id" id="filter_room" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
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
                    <option value="<?= $c['id'] ?>" <?= (isset($_GET['filter_cabinet_id']) && $_GET['filter_cinet_id'] == $c['id']) ? 'selected' : '' ?>><?= $c['name'] ?></option>
                 <?php endforeach; ?>
            </select>
        </div>

        <div class="flex items-end">
            <button type="submit" class="bg-slate-800 dark:bg-slate-600 text-white px-6 py-2 rounded hover:bg-slate-700 dark:hover:bg-slate-500 text-sm font-bold w-full transition">Filtrele</button>
        </div>
    </form>
</div>

<div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors p-2 overflow-x-auto">
    <table id="urunTablosu" class="w-full min-w-[800px] text-xs sm:text-sm text-left text-slate-600 dark:text-slate-300">
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
        <?php 
        $bugun = time();
        foreach($tumUrunler as $urun): 
            $durumEtiketi = "";
            $satirClass = "";
            $sktSortValue = "9999999999"; 

            if (empty($urun['expiry_date'])) {
                $durumEtiketi = '<span class="bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 px-2 py-1 rounded text-xs font-bold">Süresiz</span>';
            } else {
                $skt = strtotime($urun['expiry_date']);
                $sktSortValue = $skt;
                $farkGun = ceil(($skt - $bugun) / (60 * 60 * 24));

                if ($farkGun < 0) {
                    $durumEtiketi = '<span class="bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300 px-2 py-1 rounded text-xs font-bold">Geçmiş</span>';
                    $satirClass = "bg-red-50/30 dark:bg-red-900/10";
                } elseif ($farkGun <= 7) {
                    $durumEtiketi = '<span class="bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-300 px-2 py-1 rounded text-xs font-bold">Kritik</span>';
                    $satirClass = "bg-orange-50/30 dark:bg-orange-900/10";
                } elseif ($farkGun <= 30) {
                    $durumEtiketi = '<span class="bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300 px-2 py-1 rounded text-xs font-bold">Yakın</span>';
                } elseif ($farkGun <= 90) {
                    $durumEtiketi = '<span class="bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300 px-2 py-1 rounded text-xs font-bold">Orta Vade</span>';
                } else {
                    $durumEtiketi = '<span class="bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300 px-2 py-1 rounded text-xs font-bold">Uzun Ömür</span>';
                }
            }
        ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors <?= $satirClass ?>">
                <td class="px-4 py-3 whitespace-nowrap"><?= $durumEtiketi ?></td>
                
                <td class="px-4 py-3">
                    <div class="font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($urun['name']) ?></div>
                    <div class="text-xs text-slate-400"><?= htmlspecialchars($urun['brand']) ?></div>
                    <div class="text-[10px] text-blue-500 dark:text-blue-400 mt-1">
                        <?= htmlspecialchars($urun['category']) ?> 
                        <?= !empty($urun['sub_category']) ? ' > '.htmlspecialchars($urun['sub_category']) : '' ?>
                    </div>
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
                        <button type="button" 
                                class="btn-tuket w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/50 text-red-600 dark:text-red-400 hover:bg-red-500 hover:text-white flex items-center justify-center transition font-bold text-lg leading-none shadow-sm border border-red-200 dark:border-red-800" 
                                title="Tüket"
                                data-id="<?= $urun['id'] ?>">
                            -
                        </button>

                        <button type="button" 
                                class="btn-transfer w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400 hover:bg-blue-500 hover:text-white flex items-center justify-center transition font-bold text-md leading-none shadow-sm border border-blue-200 dark:border-blue-800" 
                                title="Hızlı Transfer"
                                data-id="<?= $urun['id'] ?>" 
                                data-qty="<?= (float)$urun['quantity'] ?>" 
                                data-unit="<?= htmlspecialchars($urun['unit']) ?>" 
                                data-name="<?= htmlspecialchars($urun['name']) ?>" 
                                data-city="<?= $urun['city_id'] ?>" 
                                data-loc="<?= $urun['location_id'] ?>" 
                                data-room="<?= $urun['room_id'] ?>" 
                                data-cab="<?= $urun['cabinet_id'] ?>">
                            ⇄
                        </button>

                        <span id="qty_<?= $urun['id'] ?>" class="font-bold text-slate-800 dark:text-slate-200 ml-1">
                            <?= (float)$urun['quantity'] . ' ' . htmlspecialchars($urun['unit']) ?>
                        </span>
                    </div>
                </td>

                <td class="px-4 py-3 whitespace-nowrap" data-order="<?= $sktSortValue ?>">
                    <?php if (!empty($urun['expiry_date'])): ?>
                        <div class="text-slate-700 dark:text-slate-300 font-medium">
                            <?= date('d.m.Y', strtotime($urun['expiry_date'])) ?>
                        </div>
                        <div class="text-[10px] text-slate-400">
                            <?= $farkGun < 0 ? abs($farkGun).' gün geçti' : $farkGun.' gün kaldı' ?>
                        </div>
                    <?php else: ?>
                        <div class="text-slate-400 dark:text-slate-500 italic text-xs">SKT Yok</div>
                    <?php endif; ?>
                </td>

                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <a href="urun-duzenle.php?id=<?= $urun['id'] ?>" class="inline-block bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/50 px-2 py-1 rounded text-xs font-bold transition mr-1">Düzenle</a>

                    <form method="POST" class="inline delete-form">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="hidden" name="sil_urun_id" value="<?= $urun['id'] ?>">
                        <button type="submit" class="bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/50 px-2 py-1 rounded text-xs font-bold transition">Sil</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- TRANSFER MODAL -->
<div id="transferModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-75 transition-opacity duration-300">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md transform transition-all border border-slate-200 dark:border-slate-700">
            <div class="p-6">
                <h3 class="text-xl font-bold text-blue-600 dark:text-blue-400 mb-4 border-b dark:border-slate-700 pb-2">
                    ⇄ <span id="modalProductName">Ürün Transferi</span>
                </h3>
                <form id="transferForm" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
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
                            <select id="targetCity" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white"></select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Hedef Mekan</label>
                            <select id="targetLocation" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white"></select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Hedef Oda</label>
                            <select id="targetRoom" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white"></select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Hedef Dolap</label>
                            <select id="targetCabinet" name="new_cab_id" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white"></select>
                        </div>
                    </div>

                    <div id="targetShelfContainer" class="mt-2 hidden">
                        <label class="block text-xs font-bold text-green-600 dark:text-green-400 mb-1" id="targetShelfLabel">
                            Hedef Raf/Bölüm
                        </label>
                        <select id="targetShelf" class="w-full p-2 border-2 rounded border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300"></select>
                    </div>

                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" id="btnTransferCancel" class="px-4 py-2 text-sm rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition">İptal</button>
                        <button type="button" id="btnTransferSubmit" class="px-4 py-2 text-sm rounded bg-blue-600 hover:bg-blue-700 text-white font-bold transition">Transferi Tamamla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.tailwindcss.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.tailwindcss.min.css">

<script nonce="<?= $cspNonce ?>">
// --- PHP'den JS'e Veri Aktarımı ---
const ALL_CITIES    = <?= json_encode($sehirler_transfer) ?>;
const ALL_LOCATIONS = <?= json_encode($mekanlar_transfer) ?>;
const ALL_ROOMS     = <?= json_encode($odalar_transfer) ?>;
const ALL_CABINETS  = <?= json_encode($dolaplar_transfer) ?>;
const CSRF_TOKEN    = '<?= $_SESSION['csrf_token'] ?? '' ?>';

$(document).ready(function() {
    // DataTables
    $('#urunTablosu').DataTable({
        "language": { "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" },
        "pageLength": 25,
        "order": [[ 4, "asc" ]],
        "responsive": true,
        "columnDefs": [{ "orderable": false, "targets": 5 }]
    });

    // Silme onayı
    $(document).on('submit', '.delete-form', function(e) {
        e.preventDefault();
        const form = this;
        Swal.fire({
            title: 'Emin misiniz?',
            text: "Bu ürünü kalıcı olarak silmek üzeresiniz!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Evet, Sil',
            cancelButtonText: 'İptal',
            background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
            color: document.documentElement.classList.contains('dark') ? '#fff' : '#0f172a'
        }).then((result) => {
            if (result.isConfirmed) form.submit();
        });
    });

    // Hızlı Tüket
    $(document).on('click', '.btn-tuket', function() {
        const btn = this;
        const id = $(this).data('id');
        hizliTuket(btn, id);
    });

    // Hızlı Transfer aç
    $(document).on('click', '.btn-transfer', function() {
        const d = $(this).data();
        transferDialog(d.id, d.qty, d.unit, d.name, d.city, d.loc, d.room, d.cab);
    });

    // Modal butonları
    $('#btnTransferCancel').on('click', closeModal);
    $('#btnTransferSubmit').on('click', submitTransfer);

    // Dolap değişince rafları getir
    $('#targetCabinet').on('change', function () {
        loadTargetShelves(this.value);
    });

    // Üst filtreler
    $('#filter_location').on('change', function () {
        filterRoomsForFilter(this.value);
    });
    $('#filter_room').on('change', function () {
        filterCabinetsForFilter(this.value);
    });
});

// --- HIZLI TÜKET ---
async function hizliTuket(btn, id) {
    const { value: adet } = await Swal.fire({
        title: 'Hızlı Tüketim',
        input: 'number',
        inputLabel: 'Tüketilen miktar:',
        inputValue: 1,
        showCancelButton: true,
        confirmButtonText: 'Tüket',
        inputAttributes: { min: 0.01, step: 0.01 },
        background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
        color: document.documentElement.classList.contains('dark') ? '#fff' : '#0f172a'
    });

    if (!adet) return;

    const originalContent = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '...';

    try {
        const url = `ajax.php?islem=hizli_tuket&id=${encodeURIComponent(id)}&adet=${encodeURIComponent(adet)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`;
        const res = await fetch(url);
        const contentType = res.headers.get("content-type");
        if (!contentType || !contentType.includes("application/json")) {
            const text = await res.text();
            throw new Error("Sunucu JSON döndürmedi: " + text.substring(0, 100));
        }
        const data = await res.json();

        if (data.success) {
            const miktarSpan = document.getElementById(`qty_${id}`);
            if(miktarSpan) miktarSpan.innerText = `${parseFloat(data.yeni_miktar)} ${data.birim}`;

            Swal.fire({
                toast: true, position: 'top-end', showConfirmButton: false, timer: 3000,
                icon: 'success', title: 'Stok güncellendi',
                background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
                color: document.documentElement.classList.contains('dark') ? '#fff' : '#0f172a'
            });

            if (data.yeni_miktar <= 0) $(btn).closest('tr').addClass('opacity-50 grayscale');
        } else {
            Swal.fire('Hata', data.error || 'Bilinmeyen Hata', 'error');
        }
    } catch (e) {
        console.error(e);
        Swal.fire('Sistem Hatası', e.message, 'error');
    } finally {
        btn.innerHTML = originalContent;
        btn.disabled = false;
    }
}

// --- TRANSFER MODAL ---
function transferDialog(product_id, current_qty, unit, product_name, c_city, c_loc, c_room, c_cab) {
    $('#modalProductId').val(product_id);
    $('#modalProductName').text(product_name);
    $('#transferAmount').attr('max', current_qty).val(current_qty);
    $('#maxQtyText').text(`${current_qty} ${unit}`);

    const cName   = (ALL_CITIES.find(x=>x.id==c_city)    || {}).name || '-';
    const lName   = (ALL_LOCATIONS.find(x=>x.id==c_loc) || {}).name || '-';
    const rName   = (ALL_ROOMS.find(x=>x.id==c_room)     || {}).name || '-';
    const cabName = (ALL_CABINETS.find(x=>x.id==c_cab)   || {}).name || '-';
    $('#currentLocationText').html(`${cName} > ${lName} > ${rName} > <b>${cabName}</b>`);

    populateSelect('targetCity', ALL_CITIES, c_city);
    filterLocations(c_city, c_loc);
    filterRooms(c_loc, c_room);
    filterCabinets(c_room, null);

    $('#targetShelfContainer').addClass('hidden');
    $('#targetShelf').html('');

    $('#transferModal').removeClass('hidden');
    $('body').addClass('overflow-hidden');
}

function closeModal() {
    $('#transferModal').addClass('hidden');
    $('body').removeClass('overflow-hidden');
    $('#transferForm')[0].reset();
    $('#targetShelfContainer').addClass('hidden');
    $('#targetShelf').html('');
}

async function submitTransfer() {
    const product_id = $('#modalProductId').val();
    const amount     = parseFloat($('#transferAmount').val());
    const new_cab_id = $('#targetCabinet').val();
    const shelfValue = $('#targetShelf').val() || '';

    if(!new_cab_id) { 
        Swal.fire('Uyarı', 'Hedef dolabı seçmelisiniz.', 'warning'); 
        return; 
    }

    if(!amount || amount <= 0) {
        Swal.fire('Uyarı', 'Geçerli bir miktar girin.', 'warning');
        return;
    }

    try {
        const url = `ajax.php?islem=hizli_transfer` +
                    `&id=${encodeURIComponent(product_id)}` +
                    `&amount=${encodeURIComponent(amount)}` +
                    `&new_cab_id=${encodeURIComponent(new_cab_id)}` +
                    `&shelf_location=${encodeURIComponent(shelfValue)}` +
                    `&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`;

        const res = await fetch(url);
        const contentType = res.headers.get("content-type");
        if (!contentType || !contentType.includes("application/json")) {
            const text = await res.text();
            throw new Error("Sunucu Hatası: " + text.substring(0, 100));
        }

        const data = await res.json();

        if (data.success) {
            closeModal();
            await Swal.fire('Başarılı', 'Transfer tamamlandı.', 'success');
            window.location.reload(); 
        } else {
            Swal.fire('Hata', data.error || 'İşlem başarısız.', 'error');
        }
    } catch(e) {
        console.error(e);
        Swal.fire('Hata', e.message, 'error');
    }
}

// Dolap seçilince rafları getir
async function loadTargetShelves(cabId) {
    const container = $('#targetShelfContainer');
    const sel       = $('#targetShelf');
    const lbl       = $('#targetShelfLabel');

    if(!cabId) {
        container.addClass('hidden');
        sel.html('');
        return;
    }

    try {
        const res = await fetch(`ajax.php?islem=get_dolap_detay&id=${encodeURIComponent(cabId)}`);
        const data = await res.json();

        sel.html('');
        container.removeClass('hidden');

        if (data && data.type && data.type.includes('Buzdolabı')) {
            lbl.text('Hedef Saklama Bölümü');
            ['Soğutucu','Dondurucu','Kahvaltılık','Sebzelik','Kapak İçi'].forEach(o =>
                sel.append(`<option value="${o}">${o}</option>`)
            );
        } else {
            lbl.text('Hedef Raf/Çekmece');
            const r = parseInt(data.shelf_count)  || 0;
            const c = parseInt(data.drawer_count) || 0;

            if(r > 0) {
                sel.append('<optgroup label="Raflar"></optgroup>');
                const og = sel.find('optgroup[label="Raflar"]');
                for(let i = 1; i <= r; i++) {
                    og.append(`<option value="${i}. Raf">${i}. Raf</option>`);
                }
            }

            if(c > 0) {
                sel.append('<optgroup label="Çekmeceler"></optgroup>');
                const og2 = sel.find('optgroup[label="Çekmeceler"]');
                for(let i = 1; i <= c; i++) {
                    og2.append(`<option value="${i}. Çekmece">${i}. Çekmece</option>`);
                }
            }

            sel.append('<option value="Genel">Genel Alan</option>');
        }
    } catch (e) {
        console.error('loadTargetShelves hatası:', e);
        container.addClass('hidden');
    }
}

// Ortak select fonksiyonları
function populateSelect(id, data, val) {
    const el = document.getElementById(id); 
    el.innerHTML = '<option value="">Seçiniz...</option>';
    if(!data || !data.length) { el.disabled = true; return; }
    el.disabled = false;
    data.forEach(i => {
        const isSel = (i.id == val) ? 'selected' : '';
        el.innerHTML += `<option value="${i.id}" ${isSel}>${i.name}</option>`;
    });
}

// Liste filtreleri
function filterRoomsForFilter(locationId) {
    const roomSelect = document.getElementById('filter_room'); 
    roomSelect.innerHTML = '<option value="">Tümü</option>';
    document.getElementById('filter_cabinet').innerHTML = '<option value="">Tümü</option>';
    const src = locationId ? ALL_ROOMS.filter(r => r.location_id == locationId) : ALL_ROOMS;
    src.forEach(r => roomSelect.innerHTML += `<option value="${r.id}">${r.name}</option>`);
}
function filterCabinetsForFilter(roomId) {
    const cabinetSelect = document.getElementById('filter_cabinet'); 
    cabinetSelect.innerHTML = '<option value="">Tümü</option>';
    const src = roomId ? ALL_CABINETS.filter(c => c.room_id == roomId) : ALL_CABINETS;
    src.forEach(c => cabinetSelect.innerHTML += `<option value="${c.id}">${c.name}</option>`);
}

// Modal zinciri
function filterLocations(cid, def) { 
    populateSelect('targetLocation', ALL_LOCATIONS.filter(x=>x.city_id==cid), def); 
    if(!def) { 
        document.getElementById('targetRoom').innerHTML='';
        document.getElementById('targetCabinet').innerHTML='';
        $('#targetShelfContainer').addClass('hidden');
        $('#targetShelf').html('');
    }
}
function filterRooms(lid, def) { 
    populateSelect('targetRoom', ALL_ROOMS.filter(x=>x.location_id==lid), def);
    if(!def) {
        document.getElementById('targetCabinet').innerHTML='';
        $('#targetShelfContainer').addClass('hidden');
        $('#targetShelf').html('');
    }
}
function filterCabinets(rid, def) { 
    populateSelect('targetCabinet', ALL_CABINETS.filter(x=>x.room_id==rid), def);
    $('#targetShelfContainer').addClass('hidden');
    $('#targetShelf').html('');
}
</script>
</body>
</html>
