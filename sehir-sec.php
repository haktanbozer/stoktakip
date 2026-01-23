<?php
require 'db.php';
girisKontrol();

// CSP Nonce
if (!isset($cspNonce)) { $cspNonce = ''; }

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// --- 1. Şehir Seçim İşlemi (GÜVENLİK GÜNCELLENDİ) ---
if (isset($_GET['sec'])) {
    $sehirId = $_GET['sec'];
    
    // Kullanıcı bu şehre erişebilir mi?
    if ($userRole === 'ADMIN') {
        // Admin her şehri seçebilir
        $stmt = $pdo->prepare("SELECT name FROM cities WHERE id = ?");
        $stmt->execute([$sehirId]);
    } else {
        // Normal kullanıcı sadece atandığı şehri seçebilir
        $stmt = $pdo->prepare("
            SELECT c.name 
            FROM cities c 
            JOIN user_city_assignments uca ON c.id = uca.city_id 
            WHERE c.id = ? AND uca.user_id = ?
        ");
        $stmt->execute([$sehirId, $userId]);
    }

    $sehir = $stmt->fetch();

    if ($sehir) {
        $_SESSION['aktif_sehir_id'] = $sehirId;
        $_SESSION['aktif_sehir_ad'] = $sehir['name'];
        header("Location: index.php");
        exit;
    } else {
        // Yetkisiz erişim denemesi
        die("Bu şehre erişim yetkiniz yok.");
    }
}

// --- 2. Tüm şehirleri görmek (SADECE ADMIN) ---
if (isset($_GET['hepsi'])) {
    if ($userRole === 'ADMIN') {
        unset($_SESSION['aktif_sehir_id']);
        unset($_SESSION['aktif_sehir_ad']);
        header("Location: index.php");
        exit;
    }
}

// --- 3. Listelenecek Şehirleri Çek ---
if ($userRole === 'ADMIN') {
    // Admin hepsini görür
    $sehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
} else {
    // Kullanıcı sadece yetkili olduklarını görür
    $stmt = $pdo->prepare("
        SELECT c.* FROM cities c 
        JOIN user_city_assignments uca ON c.id = uca.city_id 
        WHERE uca.user_id = ? 
        ORDER BY c.name ASC
    ");
    $stmt->execute([$userId]);
    $sehirler = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="tr" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şehir Seç - StokTakip</title>
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
<body class="bg-slate-100 dark:bg-slate-900 flex flex-col items-center justify-center min-h-screen relative transition-colors">

    <button id="theme-toggle" type="button" class="absolute top-4 right-4 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-700 rounded-lg text-sm p-2.5 transition">
        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
    </button>

    <div class="text-center mb-8">
<h1 class="text-4xl font-bold text-slate-800 dark:text-white mb-2">Hoş Geldiniz, <?= htmlspecialchars($_SESSION['username']) ?> 👋</h1>        <p class="text-slate-500 dark:text-slate-400">
            <?= empty($sehirler) && $userRole !== 'ADMIN' ? 'Size atanmış bir şehir bulunamadı. Lütfen yöneticiyle görüşün.' : 'İşlem yapmak istediğiniz konumu seçiniz.' ?>
        </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 max-w-4xl w-full px-4">
        
        <?php if($userRole === 'ADMIN'): ?>
        <a href="?hepsi=1" class="group bg-gradient-to-br from-blue-500 to-indigo-600 p-6 rounded-2xl shadow-lg hover:shadow-2xl transform hover:-translate-y-1 transition text-white text-center flex flex-col items-center justify-center h-40">
            <span class="text-4xl mb-2 transition transform group-hover:scale-110">🌍</span>
            <span class="font-bold text-lg">Tüm Şehirler</span>
            <span class="text-xs opacity-75 mt-1">Yönetici Erişimi</span>
        </a>
        <?php endif; ?>

        <?php foreach($sehirler as $s): ?>
        <a href="?sec=<?= $s['id'] ?>" class="group bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-md hover:shadow-xl border border-slate-200 dark:border-slate-700 transform hover:-translate-y-1 transition flex flex-col items-center justify-center h-40">
            <span class="text-3xl mb-2 transition transform group-hover:scale-110">📍</span>
            <span class="font-bold text-slate-700 dark:text-slate-200 text-lg group-hover:text-blue-600 dark:group-hover:text-blue-400 transition"><?= htmlspecialchars($s['name']) ?></span>
        </a>
        <?php endforeach; ?>

    </div>

    <div class="mt-12 text-slate-400 text-sm">
        <a href="cikis.php" class="hover:text-red-500 transition underline">Çıkış Yap</a>
    </div>

    <script nonce="<?= $cspNonce ?>">
        // ... (Mevcut dark mode JS kodları aynen kalsın) ...
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
