<?php
require 'db.php';
girisKontrol();

// Silme İşlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sil_urun_id'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');

    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$_POST['sil_urun_id']]);
    header("Location: envanter.php?silindi=1");
    exit;
}

// --- FİLTRELEME & SORGULAMA ---
$params = [];

// 1. Temel Sorgu
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

// 3. DETAYLI FİLTRELER (Sizin Orijinal Filtreleriniz)
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

// Filtre Seçenekleri İçin Veriler
$kategoriler = $pdo->query("SELECT DISTINCT category FROM products")->fetchAll(PDO::FETCH_COLUMN);

// Transfer Modalı İçin Konum Verileri
$cityFilterCondition = isset($_SESSION['aktif_sehir_id']) ? "AND l.city_id = '" . $_SESSION['aktif_sehir_id'] . "'" : "";
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
    <a href="urun-ekle.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center gap-2 no-underline shadow-lg shadow-blue-500/30">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
        + Yeni Ürün
    </a>
</div>

<div class="bg-white dark:bg-slate-800 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-8 transition-colors">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        
        <div class="md:col-span-5">
            <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Ürün adı, marka ara..." class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500 text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
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

<div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors p-2">
    <table id="urunTablosu" class="w-full text-sm text-left text-slate-600 dark:text-slate-300">
        <thead class="text-xs text-slate-400 dark:text-slate-500 uppercase bg-slate-50 dark:bg-slate-700/50">
            <tr>
                <th class="px-4 py-3">Durum</th> <th class="px-4 py-3">Ürün</th>
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
                // Durum Hesaplama (Eski gruplama mantığı burada satır bazlı uygulanıyor)
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
                        <button onclick="hizliTuket(this, '<?= $urun['id'] ?>')" class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/50 text-red-600 dark:text-red-400 hover:bg-red-500 hover:text-white flex items-center justify-center transition font-bold text-lg leading-none shadow-sm border border-red-200 dark:border-red-800" title="Tüket">
                            -
                        </button>

                        <button onclick="transferDialog('<?= $urun['id'] ?>', '<?= (float)$urun['quantity'] ?>', '<?= htmlspecialchars($urun['unit']) ?>', '<?= htmlspecialchars($urun['name']) ?>', '<?= $urun['city_id'] ?>', '<?= $urun['location_id'] ?>', '<?= $urun['room_id'] ?>', '<?= $urun['cabinet_id'] ?>')" 
                                class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400 hover:bg-blue-500 hover:text-white flex items-center justify-center transition font-bold text-md leading-none shadow-sm border border-blue-200 dark:border-blue-800" title="Hızlı Transfer">
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
                    
                    <form method="POST" onsubmit="confirmDelete(event)" class="inline">
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

<div id="transferModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black/70 backdrop-blur-sm transition-opacity duration-300">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-md transform transition-all border border-slate-200 dark:border-slate-700">
            <div class="p-6">
                <h3 class="text-xl font-bold text-blue-600 dark:text-blue-400 mb-4 border-b dark:border-slate-700 pb-3 flex items-center gap-2">
                    <span>⇄</span> <span id="modalProductName">Transfer</span>
                </h3>
                <form id="transferForm" onsubmit="event.preventDefault(); submitTransfer();" class="space-y-4">
                    <?php echo csrfAlaniniEkle(); ?>
                    <input type="hidden" id="modalProductId" name="id">
                    
                    <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg text-sm text-slate-700 dark:text-slate-300 border border-blue-100 dark:border-blue-800">
                        <span id="currentLocationText" class="font-medium"></span>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Transfer Miktarı (<span id="maxQtyText"></span>)</label>
                        <input type="number" id="transferAmount" name="amount" step="0.01" required min="0.01" class="w-full p-2.5 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition">
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

                    <div class="flex justify-end gap-3 pt-3">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400 transition font-medium">Vazgeç</button>
                        <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-bold transition shadow-lg shadow-blue-500/30">Onayla ve Taşı</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Veriler (Aynen Korundu)
const ALL_CITIES = <?= json_encode($sehirler_transfer) ?>;
const ALL_LOCATIONS = <?= json_encode($mekanlar_transfer) ?>;
const ALL_ROOMS = <?= json_encode($odalar_transfer) ?>;
const ALL_CABINETS = <?= json_encode($dolaplar_transfer) ?>;
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

// --- JAVASCRIPT & SWEETALERT ENTEGRASYONU ---

// 1. DataTables Başlatma
$(document).ready(function() {
    $('#urunTablosu').DataTable({
        "language": { "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" },
        "pageLength": 25,
        "order": [[ 4, "asc" ]], // Varsayılan: SKT'ye göre sırala
        "responsive": true,
        "columnDefs": [
            { "orderable": false, "targets": 5 } // İşlem sütununu sıralama dışı bırak
        ]
    });
});

// 2. SweetAlert2 ile Silme Onayı
function confirmDelete(event) {
    event.preventDefault();
    const form = event.target;

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
        if (result.isConfirmed) {
            form.submit();
        }
    });
}

// 3. SweetAlert2 ile Hızlı Tüketim
async function hizliTuket(btn, id) {
    const { value: adet } = await Swal.fire({
        title: 'Hızlı Tüketim',
        input: 'number',
        inputLabel: 'Tüketilen miktar:',
        inputValue: 1,
        showCancelButton: true,
        confirmButtonText: 'Tüket',
        cancelButtonText: 'İptal',
        inputAttributes: { min: 0.01, step: 0.01 },
        background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
        color: document.documentElement.classList.contains('dark') ? '#fff' : '#0f172a'
    });

    if (!adet) return;

    btn.disabled = true;
    const originalContent = btn.innerHTML;
    btn.innerHTML = `<svg class="animate-spin h-4 w-4" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>`;

    try {
        const res = await fetch(`ajax.php?islem=hizli_tuket&id=${id}&adet=${adet}&csrf_token=${CSRF_TOKEN}`);
        const data = await res.json();

        if (data.success) {
            const miktarSpan = document.getElementById(`qty_${id}`);
            miktarSpan.innerText = `${parseFloat(data.yeni_miktar)} ${data.birim}`;
            
            // Başarılı Toast
            Swal.fire({
                toast: true, position: 'top-end', showConfirmButton: false, timer: 3000,
                icon: 'success', title: 'Stok güncellendi',
                background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
                color: document.documentElement.classList.contains('dark') ? '#fff' : '#0f172a'
            });

            if (data.yeni_miktar <= 0) {
                btn.closest('tr').classList.add('opacity-50', 'grayscale');
            }
        }
    } catch (e) {
        Swal.fire('Hata', 'Bir sorun oluştu.', 'error');
    } finally {
        btn.innerHTML = originalContent;
        btn.disabled = false;
    }
}

// 4. Transfer İşlemi (SweetAlert2)
async function submitTransfer() {
    const product_id = document.getElementById('modalProductId').value;
    const amount = parseFloat(document.getElementById('transferAmount').value);
    const max_qty = parseFloat(document.getElementById('transferAmount').max);
    const new_cab_id = document.getElementById('targetCabinet').value;

    if (amount <= 0 || amount > max_qty || isNaN(amount)) {
        Swal.fire('Uyarı', `Lütfen geçerli bir miktar girin (Maks: ${max_qty})`, 'warning');
        return;
    }
    if (!new_cab_id) {
        Swal.fire('Uyarı', 'Lütfen hedef dolabı seçin.', 'warning');
        return;
    }

    const submitBtn = document.querySelector('#transferForm button[type="submit"]');
    const oldText = submitBtn.innerText;
    submitBtn.disabled = true;
    submitBtn.innerText = 'İşleniyor...';

    try {
        const res = await fetch(`ajax.php?islem=hizli_transfer&id=${product_id}&amount=${amount}&new_cab_id=${new_cab_id}&csrf_token=${CSRF_TOKEN}`);
        const data = await res.json();
        
        if (data.success) {
            closeModal();
            await Swal.fire({
                title: 'Transfer Başarılı!',
                text: `${data.amount} ${data.unit} ürünü başarıyla taşındı.`,
                icon: 'success',
                confirmButtonColor: '#3b82f6'
            });
            window.location.reload(); 
        } else {
            Swal.fire('Hata', data.error || 'Bilinmeyen Hata', 'error');
        }
    } catch(e) {
        Swal.fire('Hata', 'Sunucu bağlantı hatası.', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerText = oldText;
    }
}

// --- MODAL FONKSİYONLARI (Aynen Korundu) ---
function openModal() {
    document.getElementById('transferModal').classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
}
function closeModal() {
    document.getElementById('transferModal').classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
    document.getElementById('transferForm').reset();
}
function transferDialog(product_id, current_qty, unit, product_name, c_city, c_loc, c_room, c_cab) {
    document.getElementById('modalProductId').value = product_id;
    document.getElementById('modalProductName').innerText = product_name;
    document.getElementById('transferAmount').max = current_qty;
    document.getElementById('transferAmount').value = current_qty;
    document.getElementById('maxQtyText').innerText = `${current_qty} ${unit}`;
    
    // Konum Bilgisi
    const cName = ALL_CITIES.find(x=>x.id==c_city)?.name || '-';
    const lName = ALL_LOCATIONS.find(x=>x.id==c_loc)?.name || '-';
    const rName = ALL_ROOMS.find(x=>x.id==c_room)?.name || '-';
    const cabName = ALL_CABINETS.find(x=>x.id==c_cab)?.name || '-';
    document.getElementById('currentLocationText').innerHTML = `${cName} > ${lName} > ${rName} > <b>${cabName}</b>`;

    // Selectboxları doldur
    populateSelect('targetCity', ALL_CITIES, c_city);
    filterLocations(c_city, c_loc);
    filterRooms(c_loc, c_room);
    filterCabinets(c_room, null); 

    openModal();
}

// Selectbox Zinciri (Sizin Filtreleriniz İçin Gerekli)
function populateSelect(id, data, val) {
    const el = document.getElementById(id); el.innerHTML = '<option value="">Seçiniz...</option>';
    if(!data.length) { el.disabled = true; return; }
    el.disabled = false;
    data.forEach(i => el.innerHTML += `<option value="${i.id}" ${i.id==val?'selected':''}>${i.name}</option>`);
    
    if(id=='targetCity' && val) filterLocations(val, document.getElementById('targetLocation').value);
    else if(id=='targetLocation' && val) filterRooms(val, document.getElementById('targetRoom').value);
    else if(id=='targetRoom' && val) filterCabinets(val, document.getElementById('targetCabinet').value);
}
// Üst Filtreleme İçin Fonksiyonlar (Sizin Yazdıklarınız)
function filterRoomsForFilter(locationId) {
    const roomSelect = document.getElementById('filter_room'); roomSelect.innerHTML = '<option value="">Tümü</option>';
    document.getElementById('filter_cabinet').innerHTML = '<option value="">Tümü</option>';
    if (!locationId) { AllRoomsData.forEach(r => roomSelect.innerHTML += `<option value="${r.id}">${r.name}</option>`); return; }
    AllRoomsData.filter(r => r.location_id === locationId).forEach(r => roomSelect.innerHTML += `<option value="${r.id}">${r.name}</option>`);
}
function filterCabinetsForFilter(roomId) {
    const cabinetSelect = document.getElementById('filter_cabinet'); cabinetSelect.innerHTML = '<option value="">Tümü</option>';
    if (!roomId) { AllCabinetsData.forEach(c => cabinetSelect.innerHTML += `<option value="${c.id}">${c.name}</option>`); return; }
    AllCabinetsData.filter(c => c.room_id === roomId).forEach(c => cabinetSelect.innerHTML += `<option value="${c.id}">${c.name}</option>`);
}
// Modal İçin Fonksiyonlar
function filterLocations(cid, def) { const d = ALL_LOCATIONS.filter(x=>x.city_id==cid); populateSelect('targetLocation', d, def); if(!def) { document.getElementById('targetRoom').innerHTML=''; document.getElementById('targetCabinet').innerHTML=''; } }
function filterRooms(lid, def) { const d = ALL_ROOMS.filter(x=>x.location_id==lid); populateSelect('targetRoom', d, def); if(!def) document.getElementById('targetCabinet').innerHTML=''; }
function filterCabinets(rid, def) { const d = ALL_CABINETS.filter(x=>x.room_id==rid); populateSelect('targetCabinet', d, def); }
</script>
</body>
</html>
