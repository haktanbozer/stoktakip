<?php
require 'db.php';
girisKontrol();

if ($_SESSION['role'] !== 'ADMIN') die("Yetkisiz erişim.");

$mesaj = '';
$duzenleModu = false;
$duzenlenecekKat = null;

// POST İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subCatsString = '';
    if (isset($_POST['alt_kat']) && is_array($_POST['alt_kat'])) {
        $doluOlanlar = array_filter($_POST['alt_kat'], function($value) { return !empty(trim($value)); });
        $subCatsString = implode(',', $doluOlanlar);
    }

    if (isset($_POST['ekle'])) {
        $id = uniqid('cat_');
        $stmt = $pdo->prepare("INSERT INTO categories (id, name, sub_categories) VALUES (?, ?, ?)");
        try {
            $stmt->execute([$id, $_POST['name'], $subCatsString]);
            $mesaj = "✅ Kategori eklendi.";
        } catch(PDOException $e) { $mesaj = "❌ Hata: " . $e->getMessage(); }
    } elseif (isset($_POST['guncelle'])) {
        $stmt = $pdo->prepare("UPDATE categories SET name = ?, sub_categories = ? WHERE id = ?");
        $stmt->execute([$_POST['name'], $subCatsString, $_POST['id']]);
        header("Location: kategoriler.php?basarili=1"); exit;
    } elseif (isset($_POST['sil_id'])) {
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        try {
            $stmt->execute([$_POST['sil_id']]);
            $mesaj = "🗑️ Kategori silindi.";
        } catch(PDOException $e) { $mesaj = "❌ Hata: Bu kategoriye bağlı ürünler olabilir."; }
    }
}

if (isset($_GET['duzenle'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$_GET['duzenle']]);
    $duzenlenecekKat = $stmt->fetch();
    if ($duzenlenecekKat) $duzenleModu = true;
}
if(isset($_GET['basarili'])) $mesaj = "✅ İşlem kaydedildi.";

$kategoriler = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">Kategori Yönetimi</h2>
            <?php if($duzenleModu): ?>
                <a href="kategoriler.php" class="bg-slate-500 dark:bg-slate-600 text-white px-4 py-2 rounded text-sm hover:bg-slate-600 dark:hover:bg-slate-500 transition">Yeni Ekle Moduna Dön</a>
            <?php endif; ?>
        </div>

        <?php if($mesaj): ?>
            <div class="bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200 p-3 rounded mb-6 border-l-4 border-blue-500 dark:border-blue-400 transition-colors"><?= $mesaj ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border <?= $duzenleModu ? 'border-orange-300 bg-orange-50 dark:bg-orange-900/20 dark:border-orange-800' : 'border-slate-200 dark:border-slate-700' ?> h-fit transition-colors">
                <h3 class="font-bold text-lg mb-4 <?= $duzenleModu ? 'text-orange-600 dark:text-orange-400' : 'text-slate-800 dark:text-white' ?>">
                    <?= $duzenleModu ? 'Düzenle' : 'Yeni Kategori' ?>
                </h3>
                <form method="POST" id="kategoriForm" class="space-y-4">
                    <?php if($duzenleModu): ?>
                        <input type="hidden" name="guncelle" value="1"><input type="hidden" name="id" value="<?= $duzenlenecekKat['id'] ?>">
                    <?php else: ?>
                        <input type="hidden" name="ekle" value="1">
                    <?php endif; ?>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Adı</label>
                        <input type="text" name="name" value="<?= $duzenleModu ? htmlspecialchars($duzenlenecekKat['name']) : '' ?>" placeholder="Örn: Gıda" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-2">Alt Başlıklar</label>
                        <div id="altKategoriListesi" class="space-y-2">
                            <?php 
                            $altlar = $duzenleModu && !empty($duzenlenecekKat['sub_categories']) ? explode(',', $duzenlenecekKat['sub_categories']) : [''];
                            foreach($altlar as $alt): 
                            ?>
                            <div class="flex gap-2 items-center">
                                <input type="text" name="alt_kat[]" value="<?= htmlspecialchars(trim($alt)) ?>" placeholder="Alt Kategori" class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                                <button type="button" onclick="silSatir(this)" class="text-red-400 hover:text-red-600 dark:hover:text-red-300 p-2 transition">✕</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="yeniSatirEkle()" class="mt-2 text-sm text-blue-600 dark:text-blue-400 font-medium hover:underline">+ Ekle</button>
                    </div>

                    <button type="submit" class="w-full <?= $duzenleModu ? 'bg-orange-600 hover:bg-orange-700' : 'bg-blue-600 hover:bg-blue-700' ?> text-white py-2 rounded font-bold transition shadow-md">
                        <?= $duzenleModu ? 'Değişiklikleri Kaydet' : 'Kaydet' ?>
                    </button>
                </form>
            </div>

            <div class="lg:col-span-2 space-y-3">
                <?php foreach($kategoriler as $k): ?>
                <div class="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 flex justify-between items-start group hover:border-blue-300 dark:hover:border-blue-700 transition gap-4">
                    <div>
                        <h4 class="font-bold text-lg text-slate-800 dark:text-white"><?= htmlspecialchars($k['name']) ?></h4>
                        <div class="flex flex-wrap gap-1 mt-2">
                            <?php 
                            $altCats = explode(',', $k['sub_categories']);
                            if(empty(array_filter($altCats))) echo "<span class='text-xs text-slate-400 dark:text-slate-500 italic'>Alt kategori yok</span>";
                            foreach($altCats as $alt) { 
                                if(trim($alt)) echo "<span class='bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs px-2 py-1 rounded border border-slate-200 dark:border-slate-600'>".trim($alt)."</span>"; 
                            } 
                            ?>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 items-end opacity-60 group-hover:opacity-100 transition">
                        <a href="?duzenle=<?= $k['id'] ?>" class="text-blue-600 dark:text-blue-400 text-xs font-bold hover:underline">DÜZENLE</a>
                        <form method="POST" onsubmit="return confirm('Silinsin mi?')">
                            <input type="hidden" name="sil_id" value="<?= $k['id'] ?>">
                            <button class="text-red-500 dark:text-red-400 text-xs hover:underline">SİL</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
function yeniSatirEkle() {
    const container = document.getElementById('altKategoriListesi');
    const div = document.createElement('div');
    div.className = 'flex gap-2 items-center';
    // Dark mode uyumlu class'ları ekliyoruz
    div.innerHTML = `<input type="text" name="alt_kat[]" placeholder="Yeni Alt Kategori" class="flex-1 p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors"><button type="button" onclick="silSatir(this)" class="text-red-400 hover:text-red-600 dark:hover:text-red-300 p-2 transition">✕</button>`;
    container.appendChild(div);
    div.querySelector('input').focus();
}
function silSatir(btn) {
    const container = document.getElementById('altKategoriListesi');
    if (container.children.length > 1) btn.parentElement.remove();
    else btn.parentElement.querySelector('input').value = '';
}
</script>
</body>
</html>