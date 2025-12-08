<?php
require 'db.php';
girisKontrol();

$mesaj = '';
$hata = '';

// Kullanıcı bilgilerini çek
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');

    $email = $_POST['email'];
    $mevcut_sifre = $_POST['current_password'];
    $yeni_sifre = $_POST['new_password'];
    $yeni_sifre_tekrar = $_POST['confirm_password'];

    // 1. Mevcut şifreyi doğrula
    if (!password_verify($mevcut_sifre, $user['password'])) {
        $hata = "❌ Mevcut şifreniz hatalı!";
    } 
    // 2. Yeni şifreler eşleşiyor mu?
    elseif (!empty($yeni_sifre) && $yeni_sifre !== $yeni_sifre_tekrar) {
        $hata = "❌ Yeni şifreler birbiriyle uyuşmuyor!";
    }
    // 3. İşlem
    else {
        try {
            // Şifre değişecek mi?
            if (!empty($yeni_sifre)) {
                $hash = password_hash($yeni_sifre, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET email = ?, password = ? WHERE id = ?";
                $params = [$email, $hash, $_SESSION['user_id']];
                $detay = "Kullanıcı şifresini ve bilgilerini güncelledi.";
            } else {
                // Sadece email değişiyor
                $sql = "UPDATE users SET email = ? WHERE id = ?";
                $params = [$email, $_SESSION['user_id']];
                $detay = "Kullanıcı profil bilgilerini güncelledi.";
            }

            $update = $pdo->prepare($sql);
            $update->execute($params);
            
            // Audit Log
            if(function_exists('auditLog')) {
                auditLog('PROFİL', $detay);
            }

            $mesaj = "✅ Bilgileriniz başarıyla güncellendi.";
            
            // Güncel bilgiyi tekrar çek
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

        } catch (PDOException $e) {
            $hata = "Bir hata oluştu: " . $e->getMessage();
        }
    }
}

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full max-w-2xl mx-auto">
        
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-6 transition-colors flex items-center gap-2">
            👤 Profilim
        </h2>

        <?php if($mesaj): ?>
            <div class="bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 p-4 rounded-lg mb-6 border border-green-200 dark:border-green-800">
                <?= $mesaj ?>
            </div>
        <?php endif; ?>
        
        <?php if($hata): ?>
            <div class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 p-4 rounded-lg mb-6 border border-red-200 dark:border-red-800">
                <?= $hata ?>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 p-8 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
            
            <div class="flex items-center gap-4 mb-8 pb-6 border-b border-slate-100 dark:border-slate-700">
                <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center text-2xl font-bold text-blue-600 dark:text-blue-400">
                    <?= strtoupper(substr($user['username'], 0, 1)) ?>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-slate-800 dark:text-white"><?= htmlspecialchars($user['username']) ?></h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400"><?= $user['role'] === 'ADMIN' ? 'Yönetici Hesabı' : 'Standart Kullanıcı' ?></p>
                </div>
            </div>

            <form method="POST" class="space-y-6">
                <?php echo csrfAlaniniEkle(); ?>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">E-Posta Adresi</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required class="w-full p-3 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                </div>

                <div class="pt-4 border-t border-slate-100 dark:border-slate-700">
                    <h4 class="text-sm font-bold text-slate-500 dark:text-slate-400 mb-4 uppercase tracking-wider">Şifre Değiştir</h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Mevcut Şifre (Zorunlu)</label>
                            <input type="password" name="current_password" required class="w-full p-3 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors" placeholder="Değişiklikleri kaydetmek için giriniz">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Yeni Şifre</label>
                            <input type="password" name="new_password" class="w-full p-3 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors" placeholder="Değiştirmek istemiyorsanız boş bırakın">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Yeni Şifre (Tekrar)</label>
                            <input type="password" name="confirm_password" class="w-full p-3 border rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-4">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-bold shadow-lg shadow-blue-500/30 transition transform hover:scale-105">
                        Değişiklikleri Kaydet
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>
</body>
</html>
