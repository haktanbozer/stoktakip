<?php
require 'db.php';
girisKontrol();

if ($_SESSION['role'] !== 'ADMIN') {
    die("Bu sayfaya erişim yetkiniz yok. <a href='index.php'>Panele Dön</a>");
}

$mesaj = '';
$duzenleModu = false;
$duzenlenecekUser = null;

// --- DÜZENLEME MODU KONTROLÜ ---
if (isset($_GET['duzenle'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['duzenle']]);
    $duzenlenecekUser = $stmt->fetch();
    
    if ($duzenlenecekUser) {
        $duzenleModu = true;
    }
}

// --- POST İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Koruması
    csrfKontrol($_POST['csrf_token'] ?? '');

    // 1. KULLANICI EKLEME
    if (isset($_POST['kullanici_ekle'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password']; 
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        
        // Basit validasyon
        if(empty($username) || empty($password) || empty($email)) {
            $mesaj = "❌ Lütfen tüm alanları doldurun.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $id = uniqid('user_');

            try {
                $stmt = $pdo->prepare("INSERT INTO users (id, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$id, $username, $email, $hashed_password, $role]);
                
                if(function_exists('auditLog')) auditLog('EKLEME', "Yeni kullanıcı eklendi: $username ($role)");
                
                $mesaj = "✅ Kullanıcı oluşturuldu.";
            } catch (PDOException $e) { 
                $mesaj = "❌ Hata: Kullanıcı adı veya e-posta zaten kayıtlı olabilir."; 
            }
        }
    }

    // 2. KULLANICI GÜNCELLEME (YENİ EKLENDİ)
    elseif (isset($_POST['kullanici_guncelle'])) {
        $id = $_POST['user_id'];
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $password = $_POST['password']; // Boş olabilir

        try {
            // Şifre alanı doluysa şifreyi de güncelle, boşsa dokunma
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password = ?, role = ? WHERE id = ?");
                $stmt->execute([$username, $email, $hashed_password, $role, $id]);
                $logDetay = "Kullanıcı güncellendi (Şifre değişti): $username";
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$username, $email, $role, $id]);
                $logDetay = "Kullanıcı güncellendi: $username";
            }

            if(function_exists('auditLog')) auditLog('GÜNCELLEME', $logDetay);
            
            // Başarılı olursa temiz sayfaya yönlendir
            header("Location: admin.php?basarili=1");
            exit;

        } catch (PDOException $e) {
            $mesaj = "❌ Güncelleme Hatası: " . $e->getMessage();
        }
    }

    // 3. KULLANICI SİLME
    elseif (isset($_POST['sil_id'])) {
        if ($_POST['sil_id'] == $_SESSION['user_id']) {
            $mesaj = "⚠️ Kendinizi silemezsiniz!";
        } else {
            // Silinecek kullanıcı adını al (Log için)
            $stmtName = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmtName->execute([$_POST['sil_id']]);
            $delUser = $stmtName->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$_POST['sil_id']]);
            
            if(function_exists('auditLog')) auditLog('SİLME', "Kullanıcı silindi: $delUser");

            $mesaj = "🗑️ Kullanıcı silindi.";
        }
    }
}

if(isset($_GET['basarili'])) $mesaj = "✅ İşlem başarıyla kaydedildi.";

$kullanicilar = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">Kullanıcı Yönetimi</h2>
            <?php if($duzenleModu): ?>
                <a href="admin.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm transition">
                    ← Yeni Ekleme Moduna Dön
                </a>
            <?php endif; ?>
        </div>

        <?php if($mesaj): ?>
            <div class="bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200 p-3 rounded mb-6 border-l-4 border-blue-500 dark:border-blue-400">
                <?= $mesaj ?>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-8 transition-colors relative overflow-hidden">
            
            <?php if($duzenleModu): ?>
                <div class="absolute top-0 left-0 w-1 h-full bg-orange-500"></div>
            <?php endif; ?>

            <h3 class="font-bold text-lg <?= $duzenleModu ? 'text-orange-600 dark:text-orange-400' : 'text-slate-800 dark:text-white' ?> mb-4 border-b dark:border-slate-700 pb-2">
                <?= $duzenleModu ? '✏️ Kullanıcıyı Düzenle' : '➕ Yeni Personel Ekle' ?>
            </h3>
            
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php echo csrfAlaniniEkle(); ?>
                
                <?php if($duzenleModu): ?>
                    <input type="hidden" name="kullanici_guncelle" value="1">
                    <input type="hidden" name="user_id" value="<?= $duzenlenecekUser['id'] ?>">
                <?php else: ?>
                    <input type="hidden" name="kullanici_ekle" value="1">
                <?php endif; ?>
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Kullanıcı Adı</label>
                    <input type="text" name="username" value="<?= $duzenleModu ? htmlspecialchars($duzenlenecekUser['username']) : '' ?>" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">E-Posta</label>
                    <input type="email" name="email" value="<?= $duzenleModu ? htmlspecialchars($duzenlenecekUser['email']) : '' ?>" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">
                        Şifre <?= $duzenleModu ? '<span class="text-gray-400 font-normal">(Değiştirmek istemiyorsanız boş bırakın)</span>' : '' ?>
                    </label>
                    <input type="text" name="password" <?= $duzenleModu ? '' : 'required' ?> class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors" placeholder="<?= $duzenleModu ? '••••••' : 'Şifre belirleyin' ?>">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Yetki Rolü</label>
                    <select name="role" class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                        <option value="USER" <?= ($duzenleModu && $duzenlenecekUser['role'] === 'USER') ? 'selected' : '' ?>>Standart Kullanıcı (User)</option>
                        <option value="ADMIN" <?= ($duzenleModu && $duzenlenecekUser['role'] === 'ADMIN') ? 'selected' : '' ?>>Yönetici (Admin)</option>
                    </select>
                </div>

                <div class="md:col-span-2 text-right mt-2 flex justify-end gap-2">
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
                        <td class="p-3 text-slate-500 dark:text-slate-400">
                            <?= htmlspecialchars($k['email']) ?>
                        </td>
                        <td class="p-3">
                            <span class="px-2 py-1 rounded text-xs font-bold <?= $k['role']=='ADMIN' ? 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= $k['role'] ?>
                            </span>
                        </td>
                        <td class="p-3 text-right">
                            <a href="?duzenle=<?= $k['id'] ?>" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium transition mr-3 text-xs bg-blue-50 dark:bg-blue-900/20 px-2 py-1 rounded">
                                ✏️ Düzenle
                            </a>

                            <?php if($k['id'] !== $_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="confirmDelete(event)" class="inline">
                                <?php echo csrfAlaniniEkle(); ?>
                                <input type="hidden" name="sil_id" value="<?= $k['id'] ?>">
                                <button type="submit" class="text-red-500 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-medium transition text-xs bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded">
                                    🗑️ Sil
                                </button>
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
function confirmDelete(event) {
    event.preventDefault();
    const form = event.target;
    
    Swal.fire({
        title: 'Kullanıcıyı Sil?',
        text: "Bu işlem geri alınamaz.",
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
</script>
</body>
</html>
