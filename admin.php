<?php
require 'db.php';
girisKontrol();

// Sadece Admin erişebilir
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user = $_SESSION['username'] ?? 'Bilinmeyen';
    sistemLogla("Yetkisiz Erişim Engellendi: admin.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    header("Location: index.php?hata=yetkisiz");
    exit;
}

$mesaj = '';
$mesajTuru = 'info'; // info, success, error
$duzenleModu = false;
$duzenlenecekUser = null;
$kullaniciSehirleri = []; 

// --- TÜM ŞEHİRLERİ ÇEK ---
$tumSehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- DÜZENLEME MODU KONTROLÜ ---
if (isset($_GET['duzenle'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['duzenle']]);
    $duzenlenecekUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($duzenlenecekUser) {
        $duzenleModu = true;
        $stmtSehir = $pdo->prepare("SELECT city_id FROM user_city_assignments WHERE user_id = ?");
        $stmtSehir->execute([$duzenlenecekUser['id']]);
        $kullaniciSehirleri = $stmtSehir->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Yardımcı Fonksiyon: Şehir yetkilerini kaydet
function yetkileriGuncelle($pdo, $userId, $gelenSehirler) {
    $del = $pdo->prepare("DELETE FROM user_city_assignments WHERE user_id = ?");
    $del->execute([$userId]);

    if (!empty($gelenSehirler) && is_array($gelenSehirler)) {
        $benzersizSehirler = array_unique($gelenSehirler);
        $ins = $pdo->prepare("INSERT INTO user_city_assignments (user_id, city_id) VALUES (?, ?)");
        foreach ($benzersizSehirler as $cityId) {
            $ins->execute([$userId, $cityId]);
        }
    }
}

// --- POST İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');

    // 1. KULLANICI EKLEME
    if (isset($_POST['kullanici_ekle'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'] ?? ''; 
        $email    = trim($_POST['email']);
        $role     = in_array($_POST['role'], ['ADMIN', 'USER']) ? $_POST['role'] : 'USER';
        $secilenSehirler = $_POST['sehirler'] ?? [];
        
        if (empty($username) || empty($password) || empty($email)) {
            $mesaj = "Lütfen tüm zorunlu alanları doldurun.";
            $mesajTuru = 'error';
        } elseif (strlen($password) < 8) {
            $mesaj = "Şifre en az 8 karakter olmalıdır.";
            $mesajTuru = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mesaj = "Geçerli bir e-posta adresi giriniz.";
            $mesajTuru = 'error';
        } else {
            // Benzersizlik Kontrolü
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmtCheck->execute([$username, $email]);
            if ($stmtCheck->fetchColumn() > 0) {
                $mesaj = "Bu kullanıcı adı veya e-posta adresi zaten kullanımda.";
                $mesajTuru = 'error';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $id = uniqid('user_');

                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("INSERT INTO users (id, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$id, $username, $email, $hashed_password, $role]);
                    
                    if ($role === 'USER') {
                        yetkileriGuncelle($pdo, $id, $secilenSehirler);
                    }
                    $pdo->commit();
                    
                    if (function_exists('auditLog')) {
                        auditLog('EKLEME', "Yeni kullanıcı eklendi: $username ($role)");
                    }
                    $mesaj = "Kullanıcı başarıyla kaydedildi.";
                    $mesajTuru = 'success';
                } catch (PDOException $e) { 
                    $pdo->rollBack();
                    sistemLogla("Admin Kullanıcı Ekleme Hatası: " . $e->getMessage(), 'ERROR');
                    $mesaj = "Kayıt sırasında bir hata oluştu. Lütfen tekrar deneyin.";
                    $mesajTuru = 'error';
                }
            }
        }
    }

    // 2. KULLANICI GÜNCELLEME
    elseif (isset($_POST['kullanici_guncelle'])) {
        $id       = $_POST['user_id'];
        $username = trim($_POST['username']);
        $email    = trim($_POST['email']);
        $role     = in_array($_POST['role'], ['ADMIN', 'USER']) ? $_POST['role'] : 'USER';
        $password = $_POST['password'] ?? ''; 
        $secilenSehirler = $_POST['sehirler'] ?? [];

        // Yönetici kendi rolünü USER yapamasın
        if ($id === $_SESSION['user_id'] && $role !== 'ADMIN') {
            $role = 'ADMIN';
        }

        // Benzersizlik Kontrolü (Kendi ID'si hariç)
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $stmtCheck->execute([$username, $email, $id]);
        
        if ($stmtCheck->fetchColumn() > 0) {
            $mesaj = "Bu kullanıcı adı veya e-posta başka bir hesapta kullanılıyor.";
            $mesajTuru = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                if (!empty($password)) {
                    if (strlen($password) < 8) {
                        throw new Exception("Şifre en az 8 karakter olmalıdır.");
                    }
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password = ?, role = ? WHERE id = ?");
                    $stmt->execute([$username, $email, $hashed, $role, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
                    $stmt->execute([$username, $email, $role, $id]);
                }

                if ($role === 'USER') {
                    yetkileriGuncelle($pdo, $id, $secilenSehirler);
                } else {
                    $del = $pdo->prepare("DELETE FROM user_city_assignments WHERE user_id = ?");
                    $del->execute([$id]);
                }

                $pdo->commit();
                
                if (function_exists('auditLog')) {
                    auditLog('GÜNCELLEME', "Kullanıcı güncellendi: $username");
                }

                header("Location: admin.php?basarili=1");
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                // Teknik hata logla, kullanıcıya genel mesaj göster
                if ($e instanceof PDOException) {
                    sistemLogla("Admin Kullanıcı Güncelleme Hatası: " . $e->getMessage(), 'ERROR');
                    $mesaj = "Güncelleme sırasında bir hata oluştu.";
                } else {
                    $mesaj = $e->getMessage(); // Exception (örn: şifre kısa) kullanıcıya gösterilebilir
                }
                $mesajTuru = 'error';
            }
        }
    }

    // 3. SİLME
    elseif (isset($_POST['sil_id'])) {
        $silId = $_POST['sil_id'];
        if ($silId === $_SESSION['user_id']) {
            $mesaj = "Kendinizi silemezsiniz!";
            $mesajTuru = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                
                $stmtName = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $stmtName->execute([$silId]);
                $silinenAd = $stmtName->fetchColumn() ?? 'Bilinmeyen';

                $pdo->prepare("DELETE FROM user_city_assignments WHERE user_id = ?")->execute([$silId]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$silId]);
                $pdo->commit();

                if (function_exists('auditLog')) {
                    auditLog('SİLME', "Kullanıcı silindi: $silinenAd");
                }
                $mesaj = "Kullanıcı başarıyla silindi.";
                $mesajTuru = 'success';
            } catch (PDOException $e) {
                $pdo->rollBack();
                sistemLogla("Admin Kullanıcı Silme Hatası: " . $e->getMessage(), 'ERROR');
                $mesaj = "Silme işlemi sırasında bir hata oluştu.";
                $mesajTuru = 'error';
            }
        }
    }
}

if (isset($_GET['basarili'])) {
    $mesaj = "İşlem başarıyla kaydedildi.";
    $mesajTuru = 'success';
}

// --- LİSTELEME SORGUSU ---
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
            <?php 
                $alertRenk = $mesajTuru === 'success' 
                    ? 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 border-green-500' 
                    : ($mesajTuru === 'error' ? 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 border-red-500' : 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200 border-blue-500');
            ?>
            <div class="<?= $alertRenk ?> p-3 rounded mb-6 border-l-4"><?= htmlspecialchars($mesaj) ?></div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-8 transition-colors relative">
            <h3 class="font-bold text-lg <?= $duzenleModu ? 'text-orange-600 dark:text-orange-400' : 'text-slate-800 dark:text-white' ?> mb-4 border-b dark:border-slate-700 pb-2">
                <?= $duzenleModu ? '✏️ Kullanıcıyı Düzenle' : '➕ Yeni Personel / Kullanıcı Ekle' ?>
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
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Şifre <?= $duzenleModu ? '<span class="text-gray-400 font-normal">(Değişmeyecekse boş bırakın)</span>' : '<span class="text-gray-400 font-normal">(En az 8 karakter)</span>' ?></label>
                        <input type="password" name="password" autocomplete="new-password" <?= $duzenleModu ? '' : 'required' ?> minlength="8" class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none" placeholder="<?= $duzenleModu ? '••••••••' : 'Şifre belirleyin (min 8 karakter)' ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Yetki Rolü</label>
                        <select name="role" id="roleSelect" class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white" <?= ($duzenleModu && $duzenlenecekUser['id'] === $_SESSION['user_id']) ? 'disabled' : '' ?>>
                            <option value="USER" <?= ($duzenleModu && $duzenlenecekUser['role'] === 'USER') ? 'selected' : '' ?>>Standart Kullanıcı (User)</option>
                            <option value="ADMIN" <?= ($duzenleModu && $duzenlenecekUser['role'] === 'ADMIN') ? 'selected' : '' ?>>Yönetici (Admin)</option>
                        </select>
                        <?php if($duzenleModu && $duzenlenecekUser['id'] === $_SESSION['user_id']): ?>
                            <input type="hidden" name="role" value="ADMIN">
                            <p class="text-[10px] text-amber-500 mt-1">* Kendi yönetici rolünüzü değiştiremezsiniz.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Erişebileceği Şehirler / Kilerler</label>
                    <div id="cityContainer" class="bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded p-3 h-64 overflow-y-auto">
                        <?php if(empty($tumSehirler)): ?>
                            <div class="text-sm text-red-500 p-2">Sistemde kayıtlı şehir bulunmuyor.</div>
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
                    <p class="text-[10px] text-gray-400 mt-1">* Admin rolü tüm konumlara tam yetkilidir.</p>
                </div>

                <div class="md:col-span-2 text-right mt-2 flex justify-end gap-2 border-t dark:border-slate-700 pt-4">
                    <?php if($duzenleModu): ?>
                        <a href="admin.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-2 rounded font-medium transition">İptal</a>
                    <?php endif; ?>
                    <button type="submit" class="<?= $duzenleModu ? 'bg-orange-600 hover:bg-orange-700' : 'bg-green-600 hover:bg-green-700' ?> text-white px-6 py-2 rounded font-medium transition shadow-lg">
                        <?= $duzenleModu ? 'Değişiklikleri Kaydet' : 'Kullanıcıyı Kaydet' ?>
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
                            <?php if($k['id'] === $_SESSION['user_id']) echo '<span class="text-xs text-green-500 ml-1 font-bold">(Siz)</span>'; ?>
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
                                    <span class="text-slate-700 dark:text-slate-300 font-medium"><?= htmlspecialchars($k['assigned_cities']) ?></span>
                                <?php else: ?>
                                    <span class="text-red-400 italic">Tanımlı Şehir Yok</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <td class="p-3 text-right">
                            <a href="?duzenle=<?= $k['id'] ?>" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 font-medium mr-3 text-xs bg-blue-50 dark:bg-blue-900/20 px-2.5 py-1 rounded">✏️️ Düzenle</a>
                            <?php if($k['id'] !== $_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz?')" class="inline">
                                <?php echo csrfAlaniniEkle(); ?>
                                <input type="hidden" name="sil_id" value="<?= $k['id'] ?>">
                                <button class="text-red-500 dark:text-red-400 hover:text-red-700 font-medium text-xs bg-red-50 dark:bg-red-900/20 px-2.5 py-1 rounded">🗑️ Sil</button>
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
        if(roleSelect && roleSelect.value === 'ADMIN') {
            cityContainer.classList.add('opacity-50', 'pointer-events-none');
            inputs.forEach(input => input.disabled = true);
        } else if(roleSelect) {
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