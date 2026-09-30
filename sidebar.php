<?php
// sidebar.php - Güvenli ve Rol Uyumlu Yan Menü

$current_page = basename($_SERVER['PHP_SELF']);
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN';

// Menü bağlantısı yardımcı fonksiyonu
function menuLink($url, $icon, $text, $currentPage) {
    $isActive = ($currentPage == $url);
    $baseClass = "flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 group";
    
    if ($isActive) {
        $styleClass = "bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 shadow-sm ring-1 ring-blue-200 dark:ring-blue-800";
    } else {
        $styleClass = "text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700/50 hover:text-slate-900 dark:hover:text-slate-200";
    }

    echo "<a href=\"$url\" class=\"$baseClass $styleClass\">
            <span class=\"text-lg opacity-80 group-hover:opacity-100 transition-opacity\">$icon</span>
            <span>$text</span>
          </a>";
}

// İstatistikler (Sadece Admin için)
$toplamKullanici = "-";
if ($isAdmin) {
    try {
        $toplamKullanici = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } catch (Exception $e) {
        $toplamKullanici = "-";
    }
}
?>

<div class="w-full md:w-64 flex-shrink-0 space-y-6">
    
    <!-- 1. GENEL MENÜ (Tüm Kullanıcılar İçin) -->
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
        <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-700/80">
            <h2 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-2">
                📦 Envanter & Analiz
            </h2>
        </div>
        <nav class="p-2 space-y-1">
            <?php 
            menuLink("index.php", "📊", "Genel Bakış", $current_page);
            menuLink("envanter.php", "📦", "Ürün Listesi", $current_page);
            menuLink("urun-ekle.php", "➕", "Yeni Ürün Ekle", $current_page);
            menuLink("tuketim-analizi.php", "⏳", "Tüketim Analizi", $current_page);
            menuLink("rapor.php", "📄", "Envanter Raporu", $current_page);
            ?>
        </nav>
    </div>

    <!-- 2. YÖNETİCİ MENÜSÜ (Sadece ADMIN) -->
    <?php if ($isAdmin): ?>
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
        <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-700/80">
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
            ?>
        </nav>
    </div>

    <!-- 3. SİSTEM DURUMU (Sadece ADMIN) -->
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
        <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-700/80">
            <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                Sistem Durumu
            </h3>
        </div>
        <div class="p-4">
            <ul class="text-sm space-y-3">
                <li class="flex justify-between items-center">
                    <span class="text-slate-600 dark:text-slate-400">Toplam Kullanıcı</span> 
                    <span class="bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2.5 py-0.5 rounded-md text-xs font-bold border border-blue-100 dark:border-blue-800">
                        <?= $toplamKullanici ?>
                    </span>
                </li>
                <li class="flex justify-between items-center">
                    <span class="text-slate-600 dark:text-slate-400">Veritabanı</span> 
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