<?php
require 'db.php';
girisKontrol();

// Şehir Seçildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // KRİTİK GÜVENLİK DÜZELTMESİ: CSRF token kontrolü
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    $sehir_id = $_POST['sehir_id'];
    
    if ($sehir_id == 'tum') {
        // "Tüm Şehirler" seçildiyse session'ı temizle
        unset($_SESSION['aktif_sehir_id']);
        $_SESSION['aktif_sehir_ad'] = "Tüm Şehirler";
    } else {
        // Şehir adını bul ve kaydet
        $stmt = $pdo->prepare("SELECT name FROM cities WHERE id = ?");
        $stmt->execute([$sehir_id]);
        $sehir = $stmt->fetch();
        
        if ($sehir) {
            $_SESSION['aktif_sehir_id'] = $sehir_id;
            $_SESSION['aktif_sehir_ad'] = $sehir['name'];
        }
    }
    // Artık panele gidebiliriz
    header("Location: index.php");
    exit;
}

// Şehirleri Çek
$sehirler = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şehir Seçimi - StokTakip</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {}
            }
        }
    </script>

    <script>
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <style>
        body { transition: background-color 0.3s, color 0.3s; }
    </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900 min-h-screen flex items-center justify-center p-4 relative transition-colors">

    <button id="theme-toggle" type="button" class="absolute top-4 right-4 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-700 rounded-lg text-sm p-2.5 transition">
        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
    </button>

    <div class="max-w-2xl w-full">
        <div class="text-center mb-10">
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white transition-colors">Çalışma Alanını Seç</h1>
            <p class="text-slate-500 dark:text-slate-400 mt-2 transition-colors">İşlem yapmak istediğiniz lokasyonu belirleyin.</p>
        </div>

        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
            <?php echo csrfAlaniniEkle(); ?>
            <button type="submit" name="sehir_id" value="tum" class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border-2 border-transparent dark:border-slate-700 hover:border-blue-500 dark:hover:border-blue-500 hover:shadow-md transition text-center group">
                <div class="text-4xl mb-3">🌍</div>
                <div class="font-bold text-slate-700 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Tüm Şehirler</div>
                <div class="text-xs text-slate-400 dark:text-slate-500 mt-1 transition-colors">Genel Bakış</div>
            </button>

            <?php foreach($sehirler as $sehir): ?>
            <button type="submit" name="sehir_id" value="<?= $sehir['id'] ?>" class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border-2 border-transparent dark:border-slate-700 hover:border-blue-500 dark:hover:border-blue-500 hover:shadow-md transition text-center group">
                <div class="text-4xl mb-3">📍</div>
                <div class="font-bold text-slate-700 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors"><?= htmlspecialchars($sehir['name']) ?></div>
                <div class="text-xs text-slate-400 dark:text-slate-500 mt-1 transition-colors">Bu şehirde çalış</div>
            </button>
            <?php endforeach; ?>
        </form>
        
        <div class="text-center mt-8">
            <a href="cikis.php" class="text-slate-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 text-sm transition-colors">Giriş Ekranına Dön</a>
        </div>
    </div>

    <script>
        var themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
        var themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

        // İkon durumunu ayarla
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
