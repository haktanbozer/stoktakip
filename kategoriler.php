<?php
require 'db.php';
girisKontrol();

if ($_SESSION['role'] !== 'ADMIN') die("Yetkisiz erişim.");

// CSP Nonce Kontrolü
if (!isset($cspNonce)) { $cspNonce = ''; }

// --- TÜRKÇE SIRALAMA FONKSİYONU ---
// Bu fonksiyon, sunucu ayarlarından bağımsız olarak Türkçe karakterleri (ç,ğ,ı,ö,ş,ü) doğru sıralar.
function turkceSirala(&$array) {
    if (class_exists('Collator')) {
        $collator = new Collator('tr_TR');
        $collator->sort($array);
    } else {
        usort($array, function($a, $b) {
            $tr_map = [
                'ç' => 'c1', 'Ç' => 'C1', 'ğ' => 'g1', 'Ğ' => 'G1',
                'ı' => 'h1', 'I' => 'H1', 'i' => 'h2', 'İ' => 'H2',
                'ö' => 'o1', 'Ö' => 'O1', 'ş' => 's1', 'Ş' => 'S1',
                'ü' => 'u1', 'Ü' => 'U1'
            ];
            $transA = strtr(mb_strtolower($a, 'UTF-8'), $tr_map);
            $transB = strtr(mb_strtolower($b, 'UTF-8'), $tr_map);
            return strcmp($transA, $transB);
        });
    }
}

$mesaj = '';
$duzenleModu = false;
$duzenlenecekKat = null;

// --- POST İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    // 1. YENİ ANA KATEGORİ EKLEME
    if (isset($_POST['yeni_ana_kategori'])) {
        $isim = trim($_POST['ana_kategori_adi']);
        if (!empty($isim)) {
            $id = uniqid('cat_');
            $stmt = $pdo->prepare("INSERT INTO categories (id, name, sub_categories) VALUES (?, ?, ?)");
            try {
                $stmt->execute([$id, $isim, '']);
                $mesaj = "✅ Ana kategori oluşturuldu: $isim";
            } catch(PDOException $e) { 
                $mesaj = "❌ Hata: " . $e->getMessage(); 
            }
        }
    }

    // 2. MEVCUT KATEGORİYE ALT KATEGORİ EKLEME
    elseif (isset($_POST['hizli_alt_ekle'])) {
        $catId = $_POST['parent_id'];
        $yeniAlt = trim($_POST['yeni_alt_kategori']);

        if (!empty($catId) && !empty($yeniAlt)) {
            // Önce mevcut alt kategorileri çek
            $stmt = $pdo->prepare("SELECT sub_categories FROM categories WHERE id = ?");
            $stmt->execute([$catId]);
            $mevcutString = $stmt->fetchColumn();

            // String'i diziye çevir
            $mevcutDizi = array_filter(explode(',', $mevcutString));
            
            // Eğer aynı isimde yoksa ekle
            if (!in_array($yeniAlt, $mevcutDizi)) {
                $mevcutDizi[] = $yeniAlt;
                
                // --- SIRALAMA İŞLEMİ (Kaydetmeden önce) ---
                turkceSirala($mevcutDizi);
                
                $yeniString = implode(',', $mevcutDizi);
                
                $update = $pdo->prepare("UPDATE categories SET sub_categories = ? WHERE id = ?");
                $update->execute([$yeniString, $catId]);
                $mesaj = "✅ Alt kategori eklendi: $yeniAlt";
            } else {
                $mesaj = "⚠️ Bu alt kategori zaten ekli.";
            }
        }
    }

    // 3. DÜZENLEME MODUNDAKİ GÜNCELLEME
    elseif (isset($_POST['guncelle'])) {
        $subCatsString = '';
        if (isset($_POST['alt_kat']) && is_array($_POST['alt_kat'])) {
            $doluOlanlar = array_filter($_POST['alt_kat'], function($value) { return !empty(trim($value)); });
            
            // --- SIRALAMA İŞLEMİ (Kaydetmeden önce) ---
            turkceSirala($doluOlanlar);
            
            $subCatsString = implode(',', $doluOlanlar);
        }
        $stmt = $pdo->prepare("UPDATE categories SET name = ?, sub_categories = ? WHERE id = ?");
        $stmt->execute([$_POST['name'], $subCatsString, $_POST['id']]);
        header("Location: kategoriler.php?basarili=1"); exit;
    }

    // 4. SİLME
    elseif (isset($_POST['sil_id'])) {
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        try {
            $stmt->execute([$_POST['sil_id']]);
            $mesaj = "🗑️ Kategori silindi.";
        } catch(PDOException $e) { $mesaj = "❌ Hata: Bu kategoriye bağlı ürünler olabilir."; }
    }
}

// Düzenleme Modu Kontrolü
if (isset($_GET['duzenle'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$_GET['duzenle']]);
    $duzenlenecekKat = $stmt->fetch();
    if ($duzenlenecekKat) $duzenleModu = true;
}
if(isset($_GET['basarili'])) $mesaj = "✅ İşlem kaydedildi.";

// Tüm kategorileri çek (Ana kategoriler SQL ile sıralı gelir)
$kategoriler = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">Kategori Yönetimi</h2>
            <?php if($duzenleModu): ?>
                <a href="kategoriler.php" class="bg-slate-500 dark:bg-slate-600 text-white px-4 py-2 rounded text-sm hover:bg-slate-600 dark:hover:bg-slate-500 transition">Yeni Ekleme Ekranına Dön</a>
            <?php endif; ?>
        </div>

        <?php if($mesaj): ?>
            <div class="bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200 p-3 rounded mb-6 border-l-4 border-blue-500 dark:border-blue-400 transition-colors"><?= $mesaj ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="space-y-6">
                
                <?php if($duzenleModu): ?>
                    <div class="bg-orange-50 dark:bg-orange-900/20 p-6 rounded-xl shadow border border-orange-300 dark:border-orange-800 transition-colors">
                        <h3 class="font-bold text-lg mb-4 text-orange-600 dark:text-orange-400">
                            ✏️ Kategoriyi Düzenle
                        </h3>
                        <form method="POST" id="kategoriForm" class="space-y-4">
                            <?php echo csrfAlaniniEkle(); ?>
                            <input type="hidden" name="guncelle" value="1">
                            <input type="hidden" name="id" value="<?= $duzenlenecekKat['id'] ?>">

                            <div>
                                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Kategori Adı</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($duzenlenecekKat['name']) ?>" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-orange-500 outline-none transition-colors">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-2">Alt Başlıklar</label>
                                <div id="altKategoriListesi" class="space-y-2">
                                    <?php 
                                    // Düzenleme ekranında da sıralı göster
                                    $altlar = !empty($duzenlenecekKat['sub_categories']) ? explode(',', $duzenlenecekKat['sub_categories']) : [''];
                                    $altlar = array_map('trim', $altlar);
                                    $altlar = array_filter($altlar);
                                    turkceSirala($altlar); // SIRALA
                                    if(empty($altlar)) $altlar = ['']; // En az bir boş kutu kalsın

                                    foreach($altlar as $alt): 
                                    ?>
                                    <div class="flex gap-2 items-center grup-satir">
                                        <input type="text" name="alt_kat[]" value="<?= htmlspecialchars($alt) ?>" placeholder="Alt Kategori" class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-orange-500 outline-none transition-colors">
                                        <button type="button" class="btn-sil text-red-400 hover:text-red-600 dark:hover:text-red-300 p-2 transition" title="Sil">✕</button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" id="btnListeyeEkle" class="mt-2 text-sm text-orange-600 dark:text-orange-400 font-medium hover:underline flex items-center gap-1">
                                    <span>+</span> Yeni Satır Ekle
                                </button>
                            </div>

                            <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white py-2 rounded font-bold transition shadow-md">
                                Değişiklikleri Kaydet
                            </button>
                        </form>
                    </div>

                <?php else: ?>
                    
                    <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                        <h3 class="font-bold text-lg mb-4 text-slate-800 dark:text-white flex items-center gap-2">
                            📂 Yeni Ana Kategori
                        </h3>
                        <form method="POST" class="space-y-4">
                            <?php echo csrfAlaniniEkle(); ?>
                            <input type="hidden" name="yeni_ana_kategori" value="1">
                            
                            <div>
                                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Kategori Adı</label>
                                <input type="text" name="ana_kategori_adi" placeholder="Örn: Elektronik" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                            </div>
                            
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded font-bold transition shadow-md">
                                Oluştur
                            </button>
                        </form>
                    </div>

                    <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                        <h3 class="font-bold text-lg mb-4 text-slate-800 dark:text-white flex items-center gap-2">
                            ⚡ Hızlı Alt Kategori Ekle
                        </h3>
                        <form method="POST" class="space-y-4">
                            <?php echo csrfAlaniniEkle(); ?>
                            <input type="hidden" name="hizli_alt_ekle" value="1">

                            <div>
                                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Hangi Kategoriye Eklenecek?</label>
                                <select name="parent_id" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-green-500 outline-none transition-colors">
                                    <option value="">Seçiniz...</option>
                                    <?php foreach($kategoriler as $k): ?>
                                        <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Yeni Alt Kategori İsmi</label>
                                <input type="text" name="yeni_alt_kategori" placeholder="Örn: Pil" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-green-500 outline-none transition-colors">
                            </div>

                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white py-2 rounded font-bold transition shadow-md">
                                Ekle
                            </button>
                        </form>
                    </div>

                <?php endif; ?>
            </div>

            <div class="lg:col-span-2 space-y-3">
                <h3 class="font-bold text-slate-500 dark:text-slate-400 text-sm uppercase tracking-wider mb-2">Mevcut Kategoriler</h3>
                <?php foreach($kategoriler as $k): ?>
                <div class="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 flex justify-between items-start group hover:border-blue-300 dark:hover:border-blue-700 transition gap-4">
                    <div>
                        <h4 class="font-bold text-lg text-slate-800 dark:text-white"><?= htmlspecialchars($k['name']) ?></h4>
                        <div class="flex flex-wrap gap-1 mt-2">
                            <?php 
                            $altCats = explode(',', $k['sub_categories']);
                            $altCats = array_filter(array_map('trim', $altCats));
                            
                            // --- SIRALAMA İŞLEMİ (Listeleme anında) ---
                            turkceSirala($altCats);
                            
                            if(empty($altCats)) echo "<span class='text-xs text-slate-400 dark:text-slate-500 italic'>Alt kategori yok</span>";
                            foreach($altCats as $alt) { 
                                echo "<span class='bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs px-2 py-1 rounded border border-slate-200 dark:border-slate-600'>".htmlspecialchars($alt)."</span>"; 
                            } 
                            ?>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 items-end opacity-60 group-hover:opacity-100 transition">
                        <a href="?duzenle=<?= $k['id'] ?>" class="text-blue-600 dark:text-blue-400 text-xs font-bold hover:underline bg-blue-50 dark:bg-blue-900/20 px-2 py-1 rounded">DÜZENLE</a>
                        <form method="POST" onsubmit="return confirm('<?= htmlspecialchars($k['name']) ?> kategorisi silinsin mi?')" class="inline">
                            <?php echo csrfAlaniniEkle(); ?>
                            <input type="hidden" name="sil_id" value="<?= $k['id'] ?>">
                            <button class="text-red-500 dark:text-red-400 text-xs hover:underline bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded">SİL</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if($duzenleModu): ?>
<script nonce="<?= $cspNonce ?>">
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('altKategoriListesi');
    const btnListeyeEkle = document.getElementById('btnListeyeEkle');

    if (btnListeyeEkle) {
        btnListeyeEkle.addEventListener('click', function() {
            const div = document.createElement('div');
            div.className = 'flex gap-2 items-center grup-satir';
            div.innerHTML = `
                <input type="text" name="alt_kat[]" placeholder="Yeni Alt Kategori" class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-orange-500 outline-none transition-colors">
                <button type="button" class="btn-sil text-red-400 hover:text-red-600 dark:hover:text-red-300 p-2 transition" title="Sil">✕</button>
            `;
            container.appendChild(div);
            div.querySelector('input').focus();
        });
    }

    if (container) {
        container.addEventListener('click', function(e) {
            if (e.target.closest('.btn-sil')) {
                const satir = e.target.closest('.grup-satir');
                if (container.querySelectorAll('.grup-satir').length > 1) {
                    satir.remove();
                } else {
                    const input = satir.querySelector('input');
                    if(input) { input.value = ''; input.focus(); }
                }
            }
        });
        container.addEventListener('keydown', function(e) {
            if (e.target.tagName === 'INPUT' && e.key === 'Enter') {
                e.preventDefault();
                btnListeyeEkle.click();
            }
        });
    }
});
</script>
<?php endif; ?>

</body>
</html>
