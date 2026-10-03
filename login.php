<?php
require 'db.php';

// 1. Zaten oturum açıksa yönlendir
if (isset($_SESSION['user_id'])) {
    header("Location: sehir-sec.php");
    exit;
}

// 2. "BENİ HATIRLA" ÇEREZİNİ KONTROL ET VE OTOMATİK GİRİŞ YAP
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_user'])) {
    $rawToken = $_COOKIE['remember_user'];
    $hashedToken = hash('sha256', $rawToken);

    $stmtRem = $pdo->prepare("SELECT id, username, role FROM users WHERE remember_token = ?");
    $stmtRem->execute([$hashedToken]);
    $remUser = $stmtRem->fetch(PDO::FETCH_ASSOC);

    if ($remUser) {
        // Token rotation: Eski token'ı geçersiz kıl, yeni üret
        $newRawToken = bin2hex(random_bytes(32));
        $newHashedToken = hash('sha256', $newRawToken);
        $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")->execute([$newHashedToken, $remUser['id']]);
        
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        setcookie('remember_user', $newRawToken, time() + (30 * 24 * 3600), '/', '', $isSecure, true);
        
        session_regenerate_id(true);
        $_SESSION['user_id'] = $remUser['id'];
        $_SESSION['username'] = $remUser['username'];
        $_SESSION['role'] = $remUser['role'];

        if (function_exists('auditLog')) {
            auditLog('LOGIN_COOKIE', "Beni hatırla çereziyle otomatik giriş: {$remUser['username']}");
        }

        header("Location: sehir-sec.php");
        exit;
    } else {
        // Geçersiz veya süresi dolmuş çerezi temizle
        setcookie('remember_user', '', time() - 3600, '/');
    }
}

// CSP Nonce Kontrolü
if (!isset($cspNonce)) { $cspNonce = ''; }

$error = '';
$infoMsg = '';

// URL Mesajları
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'cikis_yapildi') {
        $infoMsg = "Oturumunuz başarıyla kapatıldı.";
    } elseif ($_GET['msg'] === 'deleted') {
        $error = "Hesabınız sistemde bulunamadı veya silinmiş.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    $username    = trim($_POST['username'] ?? '');
    $password    = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    $ip_address  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // --- RATE LIMITING (Kullanıcı Adı + IP Bazlı) ---
    $limit = 3; 
    $lockout_time = 30; // Dakika

    $stmtCheck = $pdo->prepare("SELECT * FROM login_attempts WHERE (ip_address = ? OR username = ?) AND locked_until > NOW() ORDER BY locked_until DESC LIMIT 1");
    $stmtCheck->execute([$ip_address, $username]);
    $attempt = $stmtCheck->fetch();

    if ($attempt) {
        $kalanDakika = ceil((strtotime($attempt['locked_until']) - time()) / 60);
        $error = "⛔ Çok fazla başarısız deneme! Girişiniz kilitlendi. {$kalanDakika} dakika bekleyin.";
    }

    if (empty($error)) {
        $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // --- BAŞARILI GİRİŞ ---
            session_regenerate_id(true);
            
            // Başarısız deneme kayıtlarını sıfırla
            $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR username = ?")->execute([$ip_address, $username]);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            if (function_exists('auditLog')) {
                auditLog('LOGIN', "Giriş başarılı: {$user['username']}");
            }

            // Beni Hatırla Çerezi Yaz
            if ($remember_me) {
                $token = bin2hex(random_bytes(32)); 
                $hashedToken = hash('sha256', $token);
                $expiry = time() + (30 * 24 * 60 * 60); // 30 Gün

                try {
                    $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")->execute([$hashedToken, $user['id']]);
                } catch (Exception $e) {}

                $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
                
                setcookie(
                    'remember_user',
                    $token,
                    [
                        'expires'  => $expiry,
                        'path'     => '/',
                        'domain'   => '',
                        'secure'   => $isSecure,
                        'httponly' => true,
                        'samesite' => 'Strict'
                    ]
                );
            }

            header("Location: sehir-sec.php");
            exit;

        } else {
            // --- BAŞARISIZ GİRİŞ ---
            $stmtCount = $pdo->prepare("SELECT MAX(attempts) as count FROM login_attempts WHERE ip_address = ? OR username = ?");
            $stmtCount->execute([$ip_address, $username]);
            $currentAttempts = $stmtCount->fetchColumn() ?: 0;
            $new_count = $currentAttempts + 1;
            
            if ($new_count >= $limit) {
                $locked_until = date('Y-m-d H:i:s', time() + ($lockout_time * 60));
                $stmtInsert = $pdo->prepare("INSERT INTO login_attempts (ip_address, username, attempts, locked_until, last_attempt) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attempts = ?, locked_until = ?, last_attempt = NOW()");
                $stmtInsert->execute([$ip_address, $username, $new_count, $locked_until, $new_count, $locked_until]);
                $error = "⛔ 3 kez hatalı giriş yapıldı! Hesabınız {$lockout_time} dakika kilitlendi.";
                if (function_exists('sistemLogla')) {
                    sistemLogla("Brute Force Şüphesi: {$username} ({$ip_address})", 'SECURITY');
                }
            } else {
                $stmtInsert = $pdo->prepare("INSERT INTO login_attempts (ip_address, username, attempts, last_attempt) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
                $stmtInsert->execute([$ip_address, $username, 1]);
                $kalanHak = $limit - $new_count;
                $error = "Hatalı kullanıcı adı veya şifre! (Kalan deneme hakkı: {$kalanHak})";
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
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" href="icons/favicon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192x192.png">
    <link rel="apple-touch-icon" href="icons/icon-192x192.png">
    <meta name="theme-color" content="#4f46e5">
    <script src="https://cdn.tailwindcss.com"></script>
    <script nonce="<?= $cspNonce ?>">
        tailwind.config = { darkMode: 'class' };
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    <style> body { transition: background-color 0.3s, color 0.3s; } </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900 flex items-center justify-center min-h-screen relative p-4 transition-colors">

    <button id="theme-toggle" type="button" class="absolute top-4 right-4 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-700 rounded-lg text-sm p-2.5 transition">
        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
    </button>

    <div class="bg-white dark:bg-slate-800 p-8 rounded-2xl shadow-xl w-full max-w-md border border-slate-200 dark:border-slate-700 transition-colors">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-blue-600 dark:text-blue-400 transition-colors">StokTakip</h1>
            <p class="text-slate-400 dark:text-slate-500 mt-2 text-sm">Ev & Kiler Envanter Yönetimi</p>
        </div>

        <?php if(!empty($infoMsg)): ?>
            <div class="bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 p-3 rounded-lg mb-6 text-center text-sm border border-blue-200 dark:border-blue-800">
                <?= htmlspecialchars($infoMsg) ?>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($error)): ?>
            <div class="bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 p-3.5 rounded-lg mb-6 text-center text-sm border border-red-200 dark:border-red-800 font-medium">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if(empty($attempt['locked_until']) || strtotime($attempt['locked_until']) < time()): ?>
        <form method="POST" class="space-y-5">
            <?php echo csrfAlaniniEkle(); ?>
            <div>
                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Kullanıcı Adı</label>
                <input type="text" name="username" required autocomplete="username" class="w-full p-2.5 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white dark:bg-slate-700 text-slate-900 dark:text-white transition-colors">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Şifre</label>
                <input type="password" name="password" required autocomplete="current-password" class="w-full p-2.5 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white dark:bg-slate-700 text-slate-900 dark:text-white transition-colors">
            </div>
            <div class="flex items-center justify-between">
                <label for="remember_me" class="flex items-center cursor-pointer">
                    <input id="remember_me" name="remember_me" type="checkbox" class="h-4 w-4 text-blue-600 dark:bg-slate-700 dark:border-slate-600 focus:ring-blue-500 border-gray-300 rounded">
                    <span class="ml-2 block text-sm text-slate-600 dark:text-slate-300">Beni Hatırla (30 Gün)</span>
                </label>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition shadow-lg shadow-blue-500/30">
                Giriş Yap
            </button>
        </form>
        <?php else: ?>
            <div class="text-center py-4">
                <p class="text-slate-500 dark:text-slate-400 text-sm">Giriş denemeleri geçici olarak askıya alındı.</p>
                <button onclick="window.location.reload()" class="mt-4 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 px-4 py-2 rounded text-sm hover:bg-slate-300 dark:hover:bg-slate-600 transition">
                    Durumu Kontrol Et
                </button>
            </div>
        <?php endif; ?>
    </div>

    <script nonce="<?= $cspNonce ?>">
        var themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
        var themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

        if (document.documentElement.classList.contains('dark')) {
            themeToggleLightIcon.classList.remove('hidden');
        } else {
            themeToggleDarkIcon.classList.remove('hidden');
        }

        var themeToggleBtn = document.getElementById('theme-toggle');
        themeToggleBtn.addEventListener('click', function() {
            themeToggleDarkIcon.classList.toggle('hidden');
            themeToggleLightIcon.classList.toggle('hidden');

            if (localStorage.getItem('color-theme')) {
                if (localStorage.getItem('color-theme') === 'light') {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('color-theme', 'dark');
                } else {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('color-theme', 'light');
                }
            } else {
                if (document.documentElement.classList.contains('dark')) {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('color-theme', 'light');
                } else {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('color-theme', 'dark');
                }
            }
        });
    </script>
</body>
</html>