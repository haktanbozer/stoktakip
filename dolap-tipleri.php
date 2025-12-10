<?php
require 'db.php';
girisKontrol();

if ($_SESSION['role'] !== 'ADMIN') die("Yetkisiz erişim.");

$mesaj = '';
$duzenleModu = false;
$duzenlenecekTip = null;

// --- İŞLEMLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // KRİTİK GÜVENLİK DÜZELTMESİ: CSRF token kontrolü
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    // Checkboxlardan gelen array'i string'e çevir (örn: "height,width,shelf_count")
    $fields = isset($_POST['fields']) ? implode(',', $_POST['fields']) : '';

    // 1. EKLEME
    if (isset($_POST['ekle'])) {
        $stmt = $pdo->prepare("INSERT INTO cabinet_types (name, active_fields) VALUES (?, ?)");
        try {
            $stmt->execute([$_POST['name'], $fields]);
            $mesaj = "✅ Dolap tipi oluşturuldu.";
        } catch(PDOException $e) { $mesaj = "❌ Hata: İsim kullanılıyor olabilir."; }
    }
    
    // 2. GÜNCELLEME
    elseif (isset($_POST['guncelle'])) {
        $stmt = $pdo->prepare("UPDATE cabinet_types SET active_fields = ? WHERE name = ?");
        try {
            $stmt->execute([$fields, $_POST['name']]); 
            $mesaj = "✅ Dolap tipi güncellendi.";
            
            // Düzenleme modundan çık
            header("Location: dolap-tipleri.php?basarili=1"); 
            exit;
        } catch(PDOException $e) { $mesaj = "❌ Hata: " . $e->getMessage(); }
    }

    // 3. SİLME
    elseif (isset($_POST['sil'])) {
        $stmt = $pdo->prepare("DELETE FROM cabinet_types WHERE name = ?");
        try {
            $stmt->execute([$_POST['sil']]);
            $mesaj = "🗑️ Tip silindi.";
        } catch(PDOException $e) { $mesaj = "❌ Hata: Bu tipe bağlı dolaplar olabilir."; }
    }
}

// Düzenleme Modu Kontrolü
if (isset($_GET['duzenle'])) {
    $stmt = $pdo->prepare("SELECT * FROM cabinet_types WHERE name = ?");
    $stmt->execute([$_GET['duzenle']]);
    $duzenlenecekTip = $stmt->fetch();
    if ($duzenlenecekTip) $duzenleModu = true;
}

if(isset($_GET['basarili'])) $mesaj = "✅ İşlem kaydedildi.";

$tipler = $pdo->query("SELECT * FROM cabinet_types ORDER BY name ASC")->fetchAll();

// Kullanılabilecek Özellikler
$ozellikler = [
    'height' => 'Yükseklik (cm)',
    'width' => 'Genişlik (cm)',
    'depth' => 'Derinlik (cm)',
    'shelf_count' => 'Raf Sayısı',
    'door_count' => 'Kapak Sayısı',
    'drawer_count' => 'Çekmece Sayısı',
    'cooler_volume' => 'Soğutucu Hacim (Lt)',
    'freezer_volume' => 'Dondurucu Hacim (Lt)'
];

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">Dolap Tipi Yapılandırma</h2>
            <?php if($duzenleModu): ?>
                <a href="dolap-tipleri.php" class="bg-slate-500 dark:bg-slate-600 text-white px-4 py-2 rounded text-sm hover:bg-slate-600 dark:hover:bg-slate-500 transition">Yeni Ekle Moduna Dön</a>
            <?php endif; ?>
        </div>

        <?php if($mesaj): ?>
            <div class="bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200 p-3 rounded mb-6 border-l-4 border-blue-500 dark:border-blue-400 transition-colors"><?= $mesaj ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border <?= $duzenleModu ? 'border-orange-300 bg-orange-50 dark:bg-orange-900/20 dark:border-orange-800' : 'border-slate-200 dark:border-slate-700' ?> h-fit transition-colors">
                <h3 class="font-bold text-lg mb-4 <?= $duzenleModu ? 'text-orange-600 dark:text-orange-400' : 'text-slate-800 dark:text-white' ?>">
                    <?= $duzenleModu ? 'Tipi Düzenle' : 'Yeni Tip Oluştur' ?>
                </h3>
                
                <form method="POST" class="space-y-4">
                    <?php echo csrfAlaniniEkle(); ?>
                    <?php if($duzenleModu): ?>
                        <input type="hidden" name="guncelle" value="1">
                    <?php else: ?>
                        <input type="hidden" name="ekle" value="1">
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Tip Adı</label>
                        <input type="text" name="name" 
                               value="<?= $duzenleModu ? htmlspecialchars($duzenlenecekTip['name']) : '' ?>" 
                               placeholder="Örn: Şaraplık" 
                               required 
                               class="w-full p-2 border rounded font-medium dark:bg-slate-700 dark:border-slate-600 dark:text-white <?= $duzenleModu ? 'bg-slate-200 text-slate-500 dark:bg-slate-600 dark:text-slate-400 cursor-not-allowed' : '' ?>"
                               <?= $duzenleModu ? 'readonly' : '' ?>>
                        <?php if($duzenleModu): ?>
                            <p class="text-[10px] text-orange-600 dark:text-orange-400 mt-1">* İsim değiştirilemez, sadece özellikler güncellenebilir.</p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-2">Aktif Özellikler</label>
                        <div class="space-y-2 max-h-64 overflow-y-auto pr-2 custom-scrollbar">
                            <?php 
                            $mevcutOzellikler = ($duzenleModu && !empty($duzenlenecekTip['active_fields'])) 
                                ? explode(',', $duzenlenecekTip['active_fields']) 
                                : [];
                            
                            foreach($ozellikler as $key => $label): 
                                $checked = in_array($key, $mevcutOzellikler) ? 'checked' : '';
                            ?>
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700/50 p-2 rounded border border-transparent hover:border-slate-200 dark:hover:border-slate-600 transition">
                                <input type="checkbox" name="fields[]" value="<?= $key ?>" class="w-4 h-4 text-blue-600 dark:bg-slate-700 dark:border-slate-500 rounded focus:ring-blue-500 dark:focus:ring-offset-slate-800" <?= $checked ?>>
                                <?= $label ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-2">* Seçili alanlar ürün ekleme formunda görünecektir.</p>
                    </div>

                    <button type="submit" class="w-full <?= $duzenleModu ? 'bg-orange-600 hover:bg-orange-700' : 'bg-blue-600 hover:bg-blue-700' ?> text-white py-2.5 rounded-lg font-bold transition shadow-md">
                        <?= $duzenleModu ? 'Değişiklikleri Kaydet' : 'Oluştur' ?>
                    </button>
                </form>
            </div>

            <div class="lg:col-span-2 space-y-4">
                <?php foreach($tipler as $t): ?>
                <div class="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row justify-between items-start group hover:border-blue-300 dark:hover:border-blue-700 transition gap-4">
                    <div>
                        <h4 class="font-bold text-lg text-slate-800 dark:text-white flex items-center gap-2">
                            <?= htmlspecialchars($t['name']) ?>
                            <?php if($duzenleModu && $duzenlenecekTip['name'] == $t['name']): ?>
                                <span class="text-xs bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-300 px-2 py-0.5 rounded animate-pulse">Düzenleniyor</span>
                            <?php endif; ?>
                        </h4>
                        <div class="flex flex-wrap gap-1 mt-2">
                            <?php 
                            $aktifler = explode(',', $t['active_fields']);
                            if(empty(array_filter($aktifler))) echo "<span class='text-xs text-slate-400 dark:text-slate-500 italic'>Özellik yok</span>";
                            foreach($aktifler as $f) {
                                if(isset($ozellikler[$f])) {
                                    echo "<span class='text-[10px] bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-1 rounded border border-slate-200 dark:border-slate-600'>{$ozellikler[$f]}</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="flex gap-2 sm:flex-col sm:items-end w-full sm:w-auto mt-2 sm:mt-0 border-t sm:border-0 pt-2 sm:pt-0 dark:border-slate-700">
                        <a href="?duzenle=<?= urlencode($t['name']) ?>" class="flex-1 sm:flex-none text-center bg-slate-50 hover:bg-blue-50 dark:bg-slate-700 dark:hover:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-3 py-1.5 rounded text-sm font-medium transition border border-slate-200 dark:border-slate-600">
                            ✏️ Düzenle
                        </a>
                        <form method="POST" onsubmit="return confirm('<?= htmlspecialchars($t['name']) ?> tipini silmek istediğinize emin misiniz?')" class="flex-1 sm:flex-none">
                            <?php echo csrfAlaniniEkle(); ?>
                            <input type="hidden" name="sil" value="<?= $t['name'] ?>">
                            <button class="w-full bg-slate-50 hover:bg-red-50 dark:bg-slate-700 dark:hover:bg-red-900/30 text-red-500 dark:text-red-400 px-3 py-1.5 rounded text-sm font-medium transition border border-slate-200 dark:border-slate-600">
                                ✕ Sil
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>