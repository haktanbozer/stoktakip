<?php
require 'db.php';
girisKontrol();

// Güvenlik & Yetki Kontrolü (Loglu)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    if (function_exists('sistemLogla')) {
        $ip =$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user =$_SESSION['username'] ?? 'Bilinmeyen';
        sistemLogla("Yetkisiz Sayfa Erişimi Engellendi: kategoriler.php (Kullanıcı: $user, IP:$ip)", 'SECURITY');
    }
    header("Location: index.php?hata=yetkisiz");
    exit;
}

// CSP Nonce Kontrolü
if (!isset($cspNonce)) {$cspNonce = ''; }

// --- TÜRKÇE SIRALAMA FONKSİYONU ---
function turkceSirala(&$array) {
    if (class_exists('Collator')) {
        $collator = new Collator('tr_TR');
        $collator->sort($array);
    } else {
        usort($array, function($a, $b) {$tr_map = [
                'ç' => 'c1', 'Ç' => 'C1', 'ğ' => 'g1', 'Ğ' => 'G1',
                'ı' => 'h1', 'I' => 'H1', 'i' => 'h2', 'İ' => 'H2',
                'ö' => 'o1', 'Ö' => 'O1', 'ş' => 's1', 'Ş' => 'S1',
                'ü' => 'u1', 'Ü' => 'U1'
            ];
            $transA = strtr(mb_strtolower($a, 'UTF-8'), $tr_map);$transB = strtr(mb_strtolower($b, 'UTF-8'),$tr_map);
            return strcmp($transA,$transB);
        });
    }
}

$mesaj = '';
$mesajTuru = 'info';$duzenleModu = false;
$duzenlenecekKat = null;

// --- POST İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    // 1. YENİ ANA KATEGORİ EKLEME
    if (isset($_POST['yeni_ana_kategori'])) {
        $isim = trim($_POST['ana_kategori_adi']);
        if (empty($isim)) {$mesaj = "Kategori adı boş bırakılamaz.";
            $mesajTuru = 'error';
        } else {
            $check =$pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ?");
            $check->execute([$isim]);
            if ($check->fetchColumn() > 0) {$mesaj = "Bu isimde bir kategori zaten mevcut.";
                $mesajTuru = 'error';
            } else {
                $id = uniqid('cat_');
                $stmt =$pdo->prepare("INSERT INTO categories (id, name, sub_categories) VALUES (?, ?, ?)");
                try {
                    $stmt->execute([$id,$isim, '']);
                    if (function_exists('auditLog')) auditLog('EKLEME', "Yeni kategori eklendi: $isim");
                    $mesaj = "Ana kategori oluşturuldu: $isim";
                    $mesajTuru = 'success';
                } catch(PDOException $e) {$mesaj = "Hata: " . $e->getMessage();$mesajTuru = 'error';
                    if (function_exists('sistemLogla')) sistemLogla("Kategori Ekleme Hatası: " . $e->getMessage());
                }
            }
        }
    }

    // 2. MEVCUT KATEGORİYE HIZLI ALT KATEGORİ EKLEME
    elseif (isset($_POST['hizli_alt_ekle'])) {
        $catId =$_POST['parent_id'];
        $yeniAlt = trim($_POST['yeni_alt_kategori']);

        if (!empty($catId) && !empty($yeniAlt)) {
            $stmt =$pdo->prepare("SELECT name, sub_categories FROM categories WHERE id = ?");
            $stmt->execute([$catId]);
            $catData =$stmt->fetch(PDO::FETCH_ASSOC);

            if ($catData) {
                $mevcutDizi = array_filter(array_map('trim', explode(',',$catData['sub_categories'])));
                
                if (!in_array($yeniAlt,$mevcutDizi)) {
                    $mevcutDizi[] =$yeniAlt;
                    turkceSirala($mevcutDizi);
                    $yeniString = implode(',',$mevcutDizi);
                    
                    $update =$pdo->prepare("UPDATE categories SET sub_categories = ? WHERE id = ?");
                    $update->execute([$yeniString,$catId]);
                    
                    if (function_exists('auditLog')) auditLog('GÜNCELLEME', "{$catData['name']} altına yeni alt kategori eklendi: $yeniAlt");
                    $mesaj = "Alt kategori eklendi: $yeniAlt";
                    $mesajTuru = 'success';
                } else {
                    $mesaj = "Bu alt kategori zaten mevcut.";
                    $mesajTuru = 'warning';
                }
            }
        }
    }

    // 3. DÜZENLEME MODUNDAKİ GÜNCELLEME (Ürünleri de günceller)
    elseif (isset($_POST['guncelle'])) {$id = $_POST['id'];$yeniIsim = trim($_POST['name']);$subCatsString = '';
        if (isset($_POST['alt_kat']) && is_array($_POST['alt_kat'])) {
            $doluOlanlar = array_filter($_POST['alt_kat'], function($value) { return !empty(trim($value)); });
            $doluOlanlar = array_unique(array_map('trim',$doluOlanlar));
            turkceSirala($doluOlanlar);
            $subCatsString = implode(',',$doluOlanlar);
        }

        try {
            $pdo->beginTransaction();

            $stmtOld =$pdo->prepare("SELECT name FROM categories WHERE id = ?");
            $stmtOld->execute([$id]);
            $eskiIsim =$stmtOld->fetchColumn();

            $stmt =$pdo->prepare("UPDATE categories SET name = ?, sub_categories = ? WHERE id = ?");
            $stmt->execute([$yeniIsim, $subCatsString,$id]);

            if ($eskiIsim && $eskiIsim !==$yeniIsim) {
                $stmtProd =$pdo->prepare("UPDATE products SET category = ? WHERE category = ?");
                $stmtProd->execute([$yeniIsim,$eskiIsim]);
            }

            $pdo->commit();

            if (function_exists('auditLog')) auditLog('GÜNCELLEME', "Kategori güncellendi: $eskiIsim ->$yeniIsim");
            header("Location: kategoriler.php?basarili=1"); 
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();$mesaj = "Güncelleme Hatası: " . $e->getMessage();$mesajTuru = 'error';
            if (function_exists('sistemLogla')) sistemLogla("Kategori Güncelleme Hatası: " . $e->getMessage());
        }
    }

    // 4. SİLME (Ürün korumalı)
    elseif (isset($_POST['sil_id'])) {
        $silId =$_POST['sil_id'];

        $stmtKat =$pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $stmtKat->execute([$silId]);
        $katAdi =$stmtKat->fetchColumn();

        if ($katAdi) {
            $stmtSay =$pdo->prepare("SELECT COUNT(*) FROM products WHERE category = ?");
            $stmtSay->execute([$katAdi]);
            $urunSayisi =$stmtSay->fetchColumn();

            if ($urunSayisi > 0) {$mesaj = "Bu kategori silinemez! İçinde kayıtlı {$urunSayisi} adet ürün bulunmaktadır. Önce ürünleri başka kategoriye taşıyın.";
                $mesajTuru = 'error';
            } else {
                $stmt =$pdo->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->execute([$silId]);
                if (function_exists('auditLog')) auditLog('SİLME', "Kategori silindi: $katAdi");
                $mesaj = "Kategori silindi: $katAdi";
                $mesajTuru = 'success';
            }
        }
    }
}

// Düzenleme Modu Kontrolü
if (isset($_GET['duzenle'])) {
    $stmt =$pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$_GET['duzenle']]);
    $duzenlenecekKat =$stmt->fetch(PDO::FETCH_ASSOC);
    if ($duzenlenecekKat)$duzenleModu = true;
}

if (isset($_GET['basarili'])) {$mesaj = "İşlem başarıyla kaydedildi.";
    $mesajTuru = 'success';
}

$sql = "SELECT c.*, COUNT(p.id) as urun_sayisi 
        FROM categories c 
        LEFT JOIN products p ON p.category = c.name 
        GROUP BY c.id 
        ORDER BY c.name ASC";
$kategoriler = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

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
            <?php 
                $alertRenk =$mesajTuru === 'success' 
                    ? 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 border-green-500' 
                    : ($mesajTuru === 'error' ? 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 border-red-500' : 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200 border-yellow-500');
            ?>
            <div class="<?= $alertRenk ?> p-3 rounded mb-6 border-l-4 transition-colors"><?= htmlspecialchars($mesaj) ?></div>
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
                                    $altlar = !empty($duzenlenecekKat['sub_categories']) ? explode(',', $duzenlenecekKat['sub_categories']) : [''];
                                    $altlar = array_map('trim',$altlar);
                                    $altlar = array_filter($altlar);
                                    turkceSirala($altlar);
                                    if(empty($altlar))$altlar = [''];

                                    foreach($altlar as$alt): 
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
                                <input type="text" name="ana_kategori_adi" placeholder="Örn: Bakliyat" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
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
                                    <?php foreach($kategoriler as$k): ?>
                                        <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Yeni Alt Kategori İsmi</label>
                                <input type="text" name="yeni_alt_kategori" placeholder="Örn: Mercimek" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-green-500 outline-none transition-colors">
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
                <?php foreach($kategoriler as$k): ?>
                <div class="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 flex justify-between items-start group hover:border-blue-300 dark:hover:border-blue-700 transition gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="font-bold text-lg text-slate-800 dark:text-white"><?= htmlspecialchars($k['name']) ?></h4>
                            <span class="text-xs bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 px-2 py-0.5 rounded-full font-medium">
                                <?= (int)$k['urun_sayisi'] ?> ürün
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-1 mt-2">
                            <?php 
                            $altCats = explode(',',$k['sub_categories']);
                            $altCats = array_filter(array_map('trim',$altCats));
                            turkceSirala($altCats);
                            
                            if(empty($altCats)) echo "<span class='text-xs text-slate-400 dark:text-slate-500 italic'>Alt kategori yok</span>";
                            foreach($altCats as$alt) { 
                                echo "<span class='bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs px-2 py-1 rounded border border-slate-200 dark:border-slate-600'>".htmlspecialchars($alt)."</span>"; 
                            } 
                            ?>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 items-end opacity-75 group-hover:opacity-100 transition">
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