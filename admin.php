<?php
require 'db.php';
girisKontrol();

// Sadece Admin erişebilir
if ($_SESSION['role'] !== 'ADMIN') {
    die("Bu sayfaya erişim yetkiniz yok. <a href='index.php'>Panele Dön</a>");
}

$mesaj = '';
$duzenleModu = false;
$duzenlenecekUser = null;
$kullaniciSehirleri = []; 

// --- TÜM ŞEHİRLERİ ÇEK (Form için) ---
$tumSehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- DÜZENLEME MODU KONTROLÜ ---
if (isset($_GET['duzenle'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['duzenle']]);
    $duzenlenecekUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($duzenlenecekUser) {
        $duzenleModu = true;
        // Kullanıcının mevcut şehir yetkilerini çek
        $stmtSehir = $pdo->prepare("SELECT city_id FROM user_city_assignments WHERE user_id = ?");
        $stmtSehir->execute([$duzenlenecekUser['id']]);
        $kullaniciSehirleri = $stmtSehir->fetchAll(PDO::FETCH_COLUMN);
    }
}

// --- POST İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    // Yardımcı Fonksiyon: Yetkileri güncelle
    function yetkileriGuncelle($pdo, $userId, $gelenSehirler) {
        try {
            // 1. Önce kullanıcının tüm eski yetkilerini sil
            $del = $pdo->prepare("DELETE FROM user_city_assignments WHERE user_id = ?");
            $del->execute([$userId]);

            // 2. Yeni seçimleri ekle (Duplicate kontrolü yaparak)
            if (!empty($gelenSehirler) && is_array($gelenSehirler)) {
                // array_unique ile formdan gelen olası tekrarları engelle
                $benzersizSehirler = array_unique($gelenSehirler);
                
                $ins = $pdo->prepare("INSERT INTO user_city_assignments (user_id, city_id) VALUES (?, ?)");
                foreach ($benzersizSehirler as $cityId) {
                    $ins->execute([$userId, $cityId]);
                }
            }
        } catch (PDOException $e) {
            // Hata olursa logla ama işlemi durdurma
            if(function_exists('sistemLogla')) sistemLogla("Yetki Güncelleme Hatası: " . $e->getMessage());
        }
    }

    // 1. KULLANICI EKLEME
    if (isset($_POST['kullanici_ekle'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password']; 
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $secilenSehirler = $_POST['sehirler'] ?? [];
        
        if(empty($username) || empty($password) || empty($email)) {
            $mesaj = "❌ Lütfen tüm alanları doldurun.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $id = uniqid('user_');

            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("INSERT INTO users (id, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$id, $username, $email, $hashed_password, $role]);
                
                // Sadece USER ise şehirleri kaydet
                if ($role === 'USER') {
                    yetkileriGuncelle($pdo, $id, $secilenSehirler);
                }
                
                $pdo->commit();
                
                if(function_exists('auditLog')) auditLog('EKLEME', "Yeni kullanıcı: $username");
                $mesaj = "✅ Kullanıcı kaydedildi.";
            } catch (PDOException $e) { 
                $pdo->rollBack();
                $mesaj = "❌ Hata: " . $e->getMessage(); 
            }
        }
    }

    // 2. KULLANICI GÜNCELLEME
    elseif (isset($_POST['kullanici_guncelle'])) {
        $id = $_POST['user_id'];
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $password = $_POST['password']; 
        $secilenSehirler = $_POST['sehirler'] ?? [];

        try {
            $pdo->beginTransaction();

            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password = ?, role = ? WHERE id = ?");
                $stmt->execute([$username, $email, $hashed, $role, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$username, $email, $role, $id]);
            }

            // Eğer Rol USER ise yetkileri güncelle, ADMIN ise tüm kısıtlamaları kaldır
            if ($role === 'USER') {
                yetkileriGuncelle($pdo, $id, $secilenSehirler);
            } else {
                $del = $pdo->prepare("DELETE FROM user_city_assignments WHERE user_id = ?");
                $del->execute([$id]);
            }

            $pdo->commit();
            header("Location: admin.php?basarili=1");
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            $mesaj = "❌ Güncelleme Hatası: " . $e->getMessage();
        }
    }

    // 3. SİLME
    elseif (isset($_POST['sil_id'])) {
        if ($_POST['sil_id'] == $_SESSION['user_id']) {
            $mesaj = "⚠️ Kendinizi silemezsiniz!";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM user_city_assignments WHERE user_id = ?")->execute([$_POST['sil_id']]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$_POST['sil_id']]);
                $pdo->commit();
                $mesaj = "🗑️ Kullanıcı silindi.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $mesaj = "❌ Hata: " . $e->getMessage();
            }
        }
    }
}

if(isset($_GET['basarili'])) $mesaj = "✅ İşlem başarıyla kaydedildi.";

// --- LİSTELEME SORGUSU (DISTINCT İLE TEKRARLARI ÖNLE) ---
// GROUP_CONCAT içinde DISTINCT kullanarak şehirlerin mükerrer yazılmasını engelliyoruz.
$sql = "SELECT u.*, GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') as assigned_cities 
        FROM users u
        LEFT JOIN user_city_assignments uca ON u.id = uca.user_id
        LEFT JOIN cities c ON uca.city_id = c.id
        GROUP BY u.id
        ORDER BY u.created_at DESC";
$kullanicilar = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">Kullanıcı Yönetimi</h2>
            <?php if($duzenleModu): ?>
                <a href="admin.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm transition">← Yeni Ekleme Moduna Dön</a>
            <?php endif; ?>
        </div>

        <?php if($mesaj): ?>
            <div class="bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200 p-3 rounded mb-6 border-l-4 border-blue-500 dark:border-blue-400"><?= $mesaj ?></div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-8 transition-colors relative">
            <h3 class="font-bold text-lg <?= $duzenleModu ? 'text-orange-600 dark:text-orange-400' : 'text-slate-800 dark:text-white' ?> mb-4 border-b dark:border-slate-700 pb-2">
                <?= $duzenleModu ? '✏️ Kullanıcıyı Düzenle' : '➕ Yeni Personel Ekle' ?>
            </h3>
            
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php echo csrfAlaniniEkle(); ?>
                <?php if($duzenleModu): ?>
                    <input type="hidden" name="kullanici_guncelle" value="1">
                    <input type="hidden" name="user_id" value="<?= $duzenlenecekUser['id'] ?>">
                <?php else: ?>
                    <input type="hidden" name="kullanici_ekle" value="1">
                <?php endif; ?>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Kullanıcı Adı</label>
                        <input type="text" name="username" value="<?= $duzenleModu ? htmlspecialchars($duzenlenecekUser['username']) : '' ?>" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">E-Posta</label>
                        <input type="email" name="email" value="<?= $duzenleModu ? htmlspecialchars($duzenlenecekUser['email']) : '' ?>" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Şifre <?= $duzenleModu ? '<span class="text-gray-400 font-normal">(Opsiyonel)</span>' : '' ?></label>
                        <input type="text" name="password" <?= $duzenleModu ? '' : 'required' ?> class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none" placeholder="<?= $duzenleModu ? '••••••' : 'Şifre belirleyin' ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Yetki Rolü</label>
                        <select name="role" id="roleSelect" class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                            <option value="USER" <?= ($duzenleModu && $duzenlenecekUser['role'] === 'USER') ? 'selected' : '' ?>>Standart Kullanıcı (User)</option>
                            <option value="ADMIN" <?= ($duzenleModu && $duzenlenecekUser['role'] === 'ADMIN') ? 'selected' : '' ?>>Yönetici (Admin)</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Erişebileceği Şehirler</label>
                    <div id="cityContainer" class="bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded p-3 h-64 overflow-y-auto custom-scrollbar">
                        <?php if(empty($tumSehirler)): ?>
                            <div class="text-sm text-red-500 p-2">Sistemde kayıtlı şehir yok.</div>
                        <?php else: ?>
                            <?php foreach($tumSehirler as $sehir): 
                                $isChecked = in_array($sehir['id'], $kullaniciSehirleri) ? 'checked' : '';
                            ?>
                            <label class="flex items-center gap-3 p-2 hover:bg-white dark:hover:bg-slate-800 rounded cursor-pointer transition">
                                <input type="checkbox" name="sehirler[]" value="<?= $sehir['id'] ?>" <?= $isChecked ?> class="w-4 h-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500">
                                <span class="text-sm text-slate-700 dark:text-slate-300 font-medium">
                                    <?= htmlspecialchars($sehir['name']) ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">* Admin rolü tüm şehirlere erişir.</p>
                </div>

                <div class="md:col-span-2 text-right mt-2 flex justify-end gap-2 border-t dark:border-slate-700 pt-4">
                    <?php if($duzenleModu): ?>
                        <a href="admin.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-2 rounded font-medium transition">İptal</a>
                    <?php endif; ?>
                    <button type="submit" class="<?= $duzenleModu ? 'bg-orange-600 hover:bg-orange-700' : 'bg-green-600 hover:bg-green-700' ?> text-white px-6 py-2 rounded font-medium transition shadow-lg">
                        <?= $duzenleModu ? 'Değişiklikleri Kaydet' : 'Kaydet' ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
            <div class="p-4 border-b dark:border-slate-700 bg-slate-50 dark:bg-slate-700/50 font-bold text-slate-700 dark:text-slate-200">
                Kayıtlı Kullanıcılar
            </div>
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                    <tr>
                        <th class="p-3">Kullanıcı Adı</th>
                        <th class="p-3">E-Posta</th>
                        <th class="p-3">Rol</th>
                        <th class="p-3">Tanımlı Şehirler</th>
                        <th class="p-3 text-right">İşlem</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    <?php foreach($kullanicilar as $k): ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors <?= ($duzenleModu && $duzenlenecekUser['id'] == $k['id']) ? 'bg-orange-50 dark:bg-orange-900/10' : '' ?>">
                        <td class="p-3 font-medium text-slate-800 dark:text-slate-200">
                            <?= htmlspecialchars($k['username']) ?>
                            <?php if($k['id'] === $_SESSION['user_id']) echo '<span class="text-xs text-green-500 ml-1">(Siz)</span>'; ?>
                        </td>
                        <td class="p-3 text-slate-500 dark:text-slate-400"><?= htmlspecialchars($k['email']) ?></td>
                        <td class="p-3">
                            <span class="px-2 py-1 rounded text-xs font-bold <?= $k['role']=='ADMIN' ? 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= $k['role'] ?>
                            </span>
                        </td>
                        
                        <td class="p-3 text-xs">
                            <?php if($k['role'] === 'ADMIN'): ?>
                                <span class="text-slate-400 italic">Tümü (Admin Yetkisi)</span>
                            <?php else: ?>
                                <?php if(!empty($k['assigned_cities'])): ?>
                                    <span class="text-slate-700 dark:text-slate-300"><?= htmlspecialchars($k['assigned_cities']) ?></span>
                                <?php else: ?>
                                    <span class="text-red-400 italic">Tanımlı Şehir Yok</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <td class="p-3 text-right">
                            <a href="?duzenle=<?= $k['id'] ?>" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 font-medium mr-3 text-xs bg-blue-50 dark:bg-blue-900/20 px-2 py-1 rounded">✏️ Düzenle</a>
                            <?php if($k['id'] !== $_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz?')" class="inline">
                                <?php echo csrfAlaniniEkle(); ?>
                                <input type="hidden" name="sil_id" value="<?= $k['id'] ?>">
                                <button class="text-red-500 dark:text-red-400 hover:text-red-700 font-medium text-xs bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded">🗑️ Sil</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('roleSelect');
    const cityContainer = document.getElementById('cityContainer');
    const inputs = cityContainer.querySelectorAll('input[type="checkbox"]');

    function toggleCitySelection() {
        if(roleSelect.value === 'ADMIN') {
            cityContainer.classList.add('opacity-50', 'pointer-events-none');
            inputs.forEach(input => input.disabled = true);
        } else {
            cityContainer.classList.remove('opacity-50', 'pointer-events-none');
            inputs.forEach(input => input.disabled = false);
        }
    }

    if(roleSelect) {
        roleSelect.addEventListener('change', toggleCitySelection);
        toggleCitySelection();
    }
});
</script>
</body>
</html>
