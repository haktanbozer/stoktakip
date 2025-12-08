<?php
// sidebar.php

// Şu anki sayfanın adını bul
$current_page = basename($_SERVER['PHP_SELF']);

// --- BAĞLANTI KONTROLÜ VE ONARMA ---
try {
    $pdo->query("SELECT 1"); 
} catch (PDOException $e) {
    if ($e->errorInfo[1] == 2006) {
        global $host, $db, $user, $pass;
        if(!isset($host)) { 
            $host = 'localhost'; $db = 'haktaace_stok'; $user = 'haktaace_stok'; $pass = 'wJY5LYrLXAH6pS3DQSMx';
        }
        try { $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4"; $pdo = new PDO($dsn, $user, $pass); } catch (PDOException $ex) {}
    }
}

// İstatistikler
try {
    $toplamKullanici = $pdo->query("SELECT count(*) FROM users")->fetchColumn();
} catch (Exception $e) {
    $toplamKullanici = "-";
}

$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN';

// Menü öğeleri için yardımcı fonksiyon (Kod tekrarını önlemek ve temiz görünüm için)
function menuLink($url, $icon, $text, $currentPage) {
    $isActive = ($currentPage == $url);
    // Aktif ve Pasif durumlar için stil sınıfları
    $baseClass = "flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 group";
    
    if ($isActive) {
        // Aktif Sayfa Stili (Mavi zemin, mavi yazı)
        $styleClass = "bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 shadow-sm ring-1 ring-blue-200 dark:ring-blue-800";
    } else {
        // Pasif Sayfa Stili (Gri yazı, hoverda hafif renklenme)
        $styleClass = "text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700/50 hover:text-slate-900 dark:hover:text-slate-200";
    }

    echo "<a href='$url' class='$baseClass $styleClass'>
            <span class='text-lg opacity-80 group-hover:opacity-100 transition-opacity'>$icon</span>
            <span>$text</span>
          </a>";
}
?>

<div class="w-full md:w-64 flex-shrink-0 space-y-6">
    
    <?php if ($isAdmin): ?>
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
        
        <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-700/80 backdrop-blur-sm">
            <h2 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-2">
                🛠️ Yönetim Paneli
            </h2>
        </div>

        <nav class="p-2 space-y-1">
            <?php 
            menuLink("admin.php", "👥", "Kullanıcı Yönetimi", $current_page);
            menuLink("kategoriler.php", "🏷️", "Kategori Yönetimi", $current_page);
            menuLink("mekan-yonetimi.php", "🏠", "Mekan & Dolaplar", $current_page);
            menuLink("dolap-tipleri.php", "⚙️", "Dolap Tipleri", $current_page);
            menuLink("bildirim-ayarlari.php", "🔔", "Bildirim Ayarları", $current_page);
            menuLink("bildirim-gecmisi.php", "📨", "Bildirim Logları", $current_page);
            menuLink("islem-gecmisi.php", "📋", "İşlem Geçmişi", $current_page);
            menuLink("rapor.php", "📄", "Raporlar", $current_page);
            
            // Ayırıcı Çizgi
            echo '<div class="h-px bg-slate-100 dark:bg-slate-700 my-2 mx-2"></div>';
            
            menuLink("envanter.php", "📦", "Ürün Listesi", $current_page);
            menuLink("tuketim-analizi.php", "⏳", "Tüketim Analizi", $current_page);
            ?>
        </nav>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
        <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-700/80">
            <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                Sistem Durumu
            </h3>
        </div>
        <div class="p-4">
            <ul class="text-sm space-y-3">
                <li class="flex justify-between items-center group">
                    <span class="text-slate-600 dark:text-slate-400 group-hover:text-slate-800 dark:group-hover:text-slate-200 transition-colors">Toplam Kullanıcı</span> 
                    <span class="bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2.5 py-0.5 rounded-md text-xs font-bold border border-blue-100 dark:border-blue-800">
                        <?= $toplamKullanici ?>
                    </span>
                </li>
                <li class="flex justify-between items-center group">
                    <span class="text-slate-600 dark:text-slate-400 group-hover:text-slate-800 dark:group-hover:text-slate-200 transition-colors">Veritabanı</span> 
                    <span class="flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 px-2 py-0.5 rounded-md border border-emerald-100 dark:border-emerald-800">
                        <span class="relative flex h-2 w-2">
                          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                          <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        Normal
                    </span>
                </li>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>
