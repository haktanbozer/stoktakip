<?php
// profil.php - Profil ve Şifre Yönetimi
require 'db.php';
girisKontrol();

$mesaj = '';
$hata = '';

// Kullanıcı bilgilerini çek
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: login.php?msg=deleted");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');

    $email              = trim($_POST['email'] ?? '');
    $mevcut_sifre       = $_POST['current_password'] ?? '';
    $yeni_sifre         = $_POST['new_password'] ?? '';
    $yeni_sifre_tekrar  = $_POST['confirm_password'] ?? '';

    // 1. E-Posta Format Kontrolü
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $hata = "Geçerli bir e-posta adresi giriniz.";
    }
    // 2. Mevcut Şifre Doğrulaması
    elseif (!password_verify($mevcut_sifre, $user['password'])) {
        $hata = "Mevcut şifreniz hatalı!";
        if (function_exists('sistemLogla')) {
            sistemLogla("Profil Güncelleme Başarısız: Hatalı mevcut şifre denemesi (Kullanıcı: {$user['username']})", 'WARNING');
        }
    } 
    // 3. Yeni Şifre Kontrolleri
    elseif (!empty($yeni_sifre) && strlen($yeni_sifre) < 8) {
        $hata = "Yeni şifre en az 8 karakter uzunluğunda olmalıdır.";
    }
    elseif (!empty($yeni_sifre) && $yeni_sifre !== $yeni_sifre_tekrar) {
        $hata = "Yeni şifreler birbiriyle uyuşmuyor!";
    }
    else {
        // 4. E-Posta Benzersizlik Kontrolü (Kendi ID'si hariç)
        $stmtEmail = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmtEmail->execute([$email, $_SESSION['user_id']]);
        
        if ($stmtEmail->fetchColumn() > 0) {
            $hata = "Bu e-posta adresi başka bir kullanıcı tarafından kullanılıyor.";
        } else {
            try {
                $pdo->beginTransaction();

                if (!empty($yeni_sifre)) {
                    $hash = password_hash($yeni_sifre, PASSWORD_DEFAULT);
                    // Şifre değiştiğinde eski remember_token'ı güvenlik için sıfırla
                    $sql = "UPDATE users SET email = ?, password = ?, remember_token = NULL WHERE id = ?";
                    $params = [$email, $hash, $_SESSION['user_id']];
                    $detay = "Kullanıcı şifresini ve profilini güncelledi.";
                } else {
                    $sql = "UPDATE users SET email = ? WHERE id = ?";
                    $params = [$email, $_SESSION['user_id']];
                    $detay = "Kullanıcı e-posta adresini güncelledi.";
                }

                $update = $pdo->prepare($sql);
                $update->execute($params);
                $pdo->commit();
                
                if (function_exists('auditLog')) {
                    auditLog('PROFİL', $detay);
                }

                $mesaj = "Bilgileriniz başarıyla güncellendi.";
                
                // Güncel veriyi tekrar al
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

            } catch (PDOException $e) {
                $pdo->rollBack();
                if (function_exists('sistemLogla')) {
                    sistemLogla("Profil Güncelleme Hatası: " . $e->getMessage(), 'ERROR');
                }
                $hata = "Güncelleme sırasında bir hata oluştu. Lütfen tekrar deneyin.";
            }
        }
    }
}

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full max-w-2xl mx-auto">
        
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-6 transition-colors flex items-center gap-2">
            👤 Profil Ayarları
        </h2>

        <?php if(!empty($mesaj)): ?>
            <div class="bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 p-4 rounded-xl mb-6 border border-green-200 dark:border-green-800 font-medium">
                <?= htmlspecialchars($mesaj) ?>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($hata)): ?>
            <div class="bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 p-4 rounded-xl mb-6 border border-red-200 dark:border-red-800 font-medium">
                <?= htmlspecialchars($hata) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
            
            <div class="flex items-center gap-4 mb-8 pb-6 border-b border-slate-100 dark:border-slate-700">
                <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center text-2xl font-bold text-blue-600 dark:text-blue-400">
                    <?= strtoupper(substr($user['username'], 0, 1)) ?>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-slate-800 dark:text-white"><?= htmlspecialchars($user['username']) ?></h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Yetki: <span class="font-semibold text-slate-700 dark:text-slate-300"><?= $user['role'] === 'ADMIN' ? 'Yönetici' : 'Standart Kullanıcı' ?></span>
                    </p>
                </div>
            </div>

            <form method="POST" class="space-y-6">
                <?php echo csrfAlaniniEkle(); ?>
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">E-Posta Adresi</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required class="w-full p-2.5 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                </div>

                <div class="pt-6 border-t border-slate-100 dark:border-slate-700">
                    <h4 class="text-xs font-bold text-slate-500 dark:text-slate-400 mb-4 uppercase tracking-wider">Güvenlik & Şifre Değiştir</h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Mevcut Şifreniz <span class="text-red-500">*</span></label>
                            <input type="password" name="current_password" required class="w-full p-2.5 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors" placeholder="Değişiklikleri onaylamak için mevcut şifrenizi girin">
                        </div>
                        
                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Yeni Şifre <span class="text-slate-400 font-normal">(Opsiyonel, min. 6 karakter)</span></label>
                            <input type="password" name="new_password" minlength="6" class="w-full p-2.5 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors" placeholder="Değişmeyecekse boş bırakın">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Yeni Şifre (Tekrar)</label>
                            <input type="password" name="confirm_password" minlength="6" class="w-full p-2.5 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-4">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-bold shadow-md shadow-blue-500/20 transition">
                        Değişiklikleri Kaydet
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>
</body>
</html>