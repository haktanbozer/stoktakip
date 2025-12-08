<?php
require 'db.php';

// Zaten giriş yapmışsa panele at
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Temel Kontroller
    csrfKontrol($_POST['csrf_token'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    $ip_address = $_SERVER['REMOTE_ADDR'];

    // --- GELİŞMİŞ RATE LIMITING (Kullanıcı Adı + IP Bazlı) ---
    // Hem IP adresini hem de girilen kullanıcı adını kontrol et
    // Böylece IP değiştirip aynı kullanıcıya saldıranlar da engellenir.
    
    $limit = 3; // 3 Deneme Hakkı
    $lockout_time = 30; // 30 Dakika Kilitleme

    $stmtCheck = $pdo->prepare("SELECT * FROM login_attempts WHERE (ip_address = ? OR username = ?) AND locked_until > NOW() ORDER BY locked_until DESC LIMIT 1");
    $stmtCheck->execute([$ip_address, $username]);
    $attempt = $stmtCheck->fetch();

    if ($attempt) {
        $kalanDakika = ceil((strtotime($attempt['locked_until']) - time()) / 60);
        $error = "⛔ Çok fazla başarısız deneme! Hesabınız geçici olarak kilitlendi. Lütfen $kalanDakika dakika bekleyin.";
    }

    // Eğer kilitli değilse işleme devam et
    if (empty($error)) {
        // Kullanıcıyı veritabanından çek
        $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // --- BAŞARILI GİRİŞ ---
            
            session_regenerate_id(true);

            // Başarılı girişte bu IP ve Kullanıcı Adı için tüm hata kayıtlarını temizle
            $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR username = ?")->execute([$ip_address, $username]);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            if(function_exists('auditLog')) auditLog('LOGIN', "Kullanıcı giriş yaptı: {$user['username']}");

            // Beni Hatırla (Güvenli Hash ile)
            if ($remember_me) {
                $token = bin2hex(random_bytes(32)); 
                $hashedToken = hash('sha256', $token);
                $expiry = time() + (30 * 24 * 60 * 60);
                
                try {
                    $stmt_update = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                    $stmt_update->execute([$hashedToken, $user['id']]);
                } catch (Exception $e) { /* Hata yok say */ }

                $secure_cookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; 
                setcookie('remember_user', $token, $expiry, '/', '', $secure_cookie, true);
            }

            header("Location: sehir-sec.php");
            exit;

        } else {
            // --- BAŞARISIZ GİRİŞ ---
            
            // Mevcut deneme sayısını bul (IP veya Kullanıcı Adı için en yüksek olanı al)
            $stmtCount = $pdo->prepare("SELECT MAX(attempts) as count FROM login_attempts WHERE ip_address = ? OR username = ?");
            $stmtCount->execute([$ip_address, $username]);
            $currentAttempts = $stmtCount->fetchColumn() ?: 0;
            
            $new_count = $currentAttempts + 1;
            
            if ($new_count >= $limit) {
                // LİMİT AŞILDI: Kilitle (Hem IP hem Kullanıcı Adı için kayıt aç)
                $locked_until = date('Y-m-d H:i:s', time() + ($lockout_time * 60));
                
                // IP için kayıt
                $stmtInsert = $pdo->prepare("INSERT INTO login_attempts (ip_address, username, attempts, locked_until, last_attempt) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attempts = ?, locked_until = ?, last_attempt = NOW()");
                $stmtInsert->execute([$ip_address, $username, $new_count, $locked_until, $new_count, $locked_until]);
                
                $error = "⛔ Çok fazla deneme yaptınız. $lockout_time dakika engellendiniz.";
                
                if(function_exists('sistemLogla')) sistemLogla("Brute Force: $username ($ip_address) engellendi.", 'SECURITY');
                
            } else {
                // Henüz limit dolmadı, sayacı artır
                $stmtInsert = $pdo->prepare("INSERT INTO login_attempts (ip_address, username, attempts, last_attempt) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
                $stmtInsert->execute([$ip_address, $username, 1]); // 1 yerine dinamik artış tetiklenir
                
                $kalan = $limit - $new_count;
                $error = "Hatalı kullanıcı adı veya şifre! (Kalan hak: $kalan)";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - StokTakip</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: {} } }
    </script>
    <script>
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    <style> body { transition: background-color 0.3s, color 0.3s; } </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900 flex items-center justify-center min-h-screen relative transition-colors">

    <div class="bg-white dark:bg-slate-800 p-8 rounded-2xl shadow-xl w-full max-w-md border border-slate-200 dark:border-slate-700 transition-colors">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-blue-600 dark:text-blue-400 transition-colors">StokTakip</h1>
            <p class="text-slate-400 dark:text-slate-500 mt-2">Yönetim Paneli Girişi</p>
        </div>
        
        <?php if($error): ?>
            <div class="bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 p-4 rounded mb-6 text-center text-sm border border-red-200 dark:border-red-800 font-bold">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <?php if(empty($error) || strpos($error, 'Kalan hak') !== false): ?>
        <form method="POST" class="space-y-6">
            <?php echo csrfAlaniniEkle(); ?>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Kullanıcı Adı</label>
                <input type="text" name="username" required class="w-full p-2.5 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white dark:bg-slate-700 text-slate-900 dark:text-white transition-colors">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Şifre</label>
                <input type="password" name="password" required class="w-full p-2.5 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white dark:bg-slate-700 text-slate-900 dark:text-white transition-colors">
            </div>
            
            <div class="flex items-center justify-between">
                <label for="remember_me" class="flex items-center">
                    <input id="remember_me" name="remember_me" type="checkbox" class="h-4 w-4 text-blue-600 dark:bg-slate-700 dark:border-slate-600 focus:ring-blue-500 border-gray-300 rounded">
                    <span class="ml-2 block text-sm text-slate-700 dark:text-slate-300">Beni Hatırla</span>
                </label>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition shadow-lg shadow-blue-500/30">
                Giriş Yap
            </button>
        </form>
        <?php else: ?>
            <div class="text-center py-4">
                <p class="text-slate-500 dark:text-slate-400 text-sm">Güvenlik nedeniyle girişler geçici olarak durdurulmuştur.</p>
                <button onclick="window.location.reload()" class="mt-4 bg-slate-200 dark:bg-slate-700 px-4 py-2 rounded text-sm hover:bg-slate-300 dark:hover:bg-slate-600 transition">Sayfayı Yenile</button>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
