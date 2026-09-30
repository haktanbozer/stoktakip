<?php
// sehir-sec.php - Güvenli Şehir ve Konum Seçimi
require 'db.php';
girisKontrol();

if (!isset($cspNonce)) { $cspNonce = ''; }

$userId   = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$hata     = '';

// --- 1. ŞEHİR SEÇİM İŞLEMİ ---
if (isset($_GET['sec'])) {
    $sehirId = trim($_GET['sec']);
    
    if ($userRole === 'ADMIN') {
        $stmt = $pdo->prepare("SELECT name FROM cities WHERE id = ?");
        $stmt->execute([$sehirId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT c.name 
            FROM cities c 
            JOIN user_city_assignments uca ON c.id = uca.city_id 
            WHERE c.id = ? AND uca.user_id = ?
        ");
        $stmt->execute([$sehirId, $userId]);
    }

    $sehir = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sehir) {
        $_SESSION['aktif_sehir_id'] = $sehirId;
        $_SESSION['aktif_sehir_ad'] = $sehir['name'];
        header("Location: index.php");
        exit;
    } else {
        $hata = "Seçilen konuma erişim yetkiniz bulunmuyor.";
    }
}

// --- 2. TÜM ŞEHİRLERİ SEÇ (SADECE ADMIN) ---
if (isset($_GET['hepsi'])) {
    if ($userRole === 'ADMIN') {
        unset($_SESSION['aktif_sehir_id']);
        unset($_SESSION['aktif_sehir_ad']);
        header("Location: index.php");
        exit;
    }
}

// --- 3. LİSTELENECEK ŞEHİRLERİ VE ÜRÜN SAYILARINI ÇEK ---
if ($userRole === 'ADMIN') {
    $sql = "
        SELECT c.*, COUNT(p.id) AS urun_sayisi 
        FROM cities c
        LEFT JOIN locations l ON l.city_id = c.id
        LEFT JOIN rooms r ON r.location_id = l.id
        LEFT JOIN cabinets cab ON cab.room_id = r.id
        LEFT JOIN products p ON p.cabinet_id = cab.id
        GROUP BY c.id
        ORDER BY c.name ASC
    ";
    $sehirler = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(p.id) AS urun_sayisi 
        FROM cities c 
        JOIN user_city_assignments uca ON c.id = uca.city_id 
        LEFT JOIN locations l ON l.city_id = c.id
        LEFT JOIN rooms r ON r.location_id = l.id
        LEFT JOIN cabinets cab ON cab.room_id = r.id
        LEFT JOIN products p ON p.cabinet_id = cab.id
        WHERE uca.user_id = ?
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
    $stmt->execute([$userId]);
    $sehirler = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // KULLANICI İÇİN TEK ŞEHİR VARSA OTOMATİK SEÇİP GEÇ
    if (count($sehirler) === 1 && !isset($_GET['degistir'])) {
        $_SESSION['aktif_sehir_id'] = $sehirler[0]['id'];
        $_SESSION['aktif_sehir_ad'] = $sehirler[0]['name'];
        header("Location: index.php");
        exit;
    }
}

$aktifSehirId = $_SESSION['aktif_sehir_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="tr" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konum Seç - StokTakip</title>
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
<body class="bg-slate-100 dark:bg-slate-900 flex flex-col items-center justify-center min-h-screen relative p-4 transition-colors">

    <!-- Dark Mode Butonu -->
    <button id="theme-toggle" type="button" class="absolute top-4 right-4 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-700 rounded-lg text-sm p-2.5 transition">
        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
    </button>

    <div class="text-center mb-8 max-w-lg">
        <h1 class="text-3xl md:text-4xl font-bold text-slate-800 dark:text-white mb-2">
            Hoş Geldiniz, <?= htmlspecialchars($_SESSION['username'] ?? '') ?> 👋
        </h1>
        <p class="text-slate-500 dark:text-slate-400 text-sm md:text-base">
            <?= empty($sehirler) && $userRole !== 'ADMIN' 
                ? 'Hesabınıza tanımlı bir konum bulunamadı. Lütfen sistem yöneticisiyle iletişime geçin.' 
                : 'Envanterini yönetmek istediğiniz konumu seçiniz.' ?>
        </p>

        <?php if (!empty($hata)): ?>
            <div class="mt-4 p-3 bg-red-100 dark:bg-red-900/50 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-lg text-sm">
                <?= htmlspecialchars($hata) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 max-w-4xl w-full">
        
        <!-- Admin: Tüm Şehirler Kartı -->
        <?php if($userRole === 'ADMIN'): ?>
        <a href="?hepsi=1" class="group bg-gradient-to-br from-blue-600 to-indigo-700 p-6 rounded-2xl shadow-lg hover:shadow-2xl transform hover:-translate-y-1 transition text-white text-center flex flex-col items-center justify-center h-44 relative overflow-hidden border border-blue-500">
            <?php if($aktifSehirId === null): ?>
                <span class="absolute top-3 right-3 bg-white/20 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">Aktif</span>
            <?php endif; ?>
            <span class="text-4xl mb-2 transition transform group-hover:scale-110">🌍</span>
            <span class="font-bold text-lg">Tüm Şehirler</span>
            <span class="text-xs text-blue-100 mt-1">Konsolide Raporlama</span>
        </a>
        <?php endif; ?>

        <!-- Şehir Kartları -->
        <?php foreach($sehirler as $s): 
            $isCurrent = ($s['id'] === $aktifSehirId);
            $activeBorder = $isCurrent ? 'ring-2 ring-blue-500 border-blue-500 dark:border-blue-500' : 'border-slate-200 dark:border-slate-700';
        ?>
        <a href="?sec=<?= htmlspecialchars($s['id']) ?>" class="group bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm hover:shadow-xl border <?= $activeBorder ?> transform hover:-translate-y-1 transition flex flex-col items-center justify-center h-44 relative">
            <?php if($isCurrent): ?>
                <span class="absolute top-3 right-3 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-[10px] font-bold px-2 py-0.5 rounded-full">Aktif Seçim</span>
            <?php endif; ?>
            <span class="text-3xl mb-2 transition transform group-hover:scale-110">📍</span>
            <span class="font-bold text-slate-700 dark:text-slate-200 text-lg group-hover:text-blue-600 dark:group-hover:text-blue-400 transition">
                <?= htmlspecialchars($s['name']) ?>
            </span>
            <span class="text-xs text-slate-400 dark:text-slate-500 mt-2 font-medium">
                📦 <?= (int)($s['urun_sayisi'] ?? 0) ?> Ürün Kayıtlı
            </span>
        </a>
        <?php endforeach; ?>

    </div>

    <div class="mt-12 text-slate-400 text-sm flex gap-4">
        <?php if(isset($_SESSION['aktif_sehir_id'])): ?>
            <a href="index.php" class="hover:text-slate-600 dark:hover:text-slate-200 transition">← Panele Dön</a>
            <span>•</span>
        <?php endif; ?>
        <a href="cikis.php" class="hover:text-red-500 transition underline">Oturumu Kapat</a>
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