<?php
// sidebar.php

// Şu anki sayfanın adını bul
$current_page = basename($_SERVER['PHP_SELF']);

// --- BAĞLANTI KONTROLÜ VE ONARMA ---
// Yapay zeka yanıtını beklerken bağlantı kopmuş olabilir (Error 2006).
// Bunu kontrol edip gerekirse tekrar bağlanıyoruz.
try {
    $pdo->query("SELECT 1"); // Test sorgusu
} catch (PDOException $e) {
    // Hata 2006 (Server gone away) ise
    if ($e->errorInfo[1] == 2006) {
        // db.php'deki değişkenleri kullanmaya çalışalım
        global $host, $db, $user, $pass;
        
        // Eğer değişkenler düşmüşse manuel tanımlayalım
        if(!isset($host)) { 
            $host = 'localhost';
            $db   = 'haktaace_stok';
            $user = 'haktaace_stok';
            $pass = 'wJY5LYrLXAH6pS3DQSMx';
        }

        try {
            $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass);
        } catch (PDOException $ex) {
            // Tekrar bağlanamazsa sessiz kal
        }
    }
}

// İstatistikler için veri çek
try {
    $toplamKullanici = $pdo->query("SELECT count(*) FROM users")->fetchColumn();
} catch (Exception $e) {
    $toplamKullanici = "-";
}
?>

<div class="w-full md:w-64 flex-shrink-0 space-y-4">
    
    <div class="bg-slate-900 text-white p-6 rounded-xl shadow-lg">
        <h2 class="text-xl font-bold mb-6 flex items-center gap-2">
            🛠️ Admin Paneli
        </h2>
        <nav class="flex flex-col gap-2">
            
            <a href="admin.php" class="<?= $current_page == 'admin.php' ? 'bg-blue-600 shadow-md' : 'hover:bg-slate-800' ?> px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3">
                👥 Kullanıcı Yönetimi
            </a>
            
            <a href="kategoriler.php" class="<?= $current_page == 'kategoriler.php' ? 'bg-blue-600 shadow-md' : 'hover:bg-slate-800' ?> px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3">
                🏷️ Kategori Yönetimi
            </a>
            
            <a href="mekan-yonetimi.php" class="<?= $current_page == 'mekan-yonetimi.php' ? 'bg-blue-600 shadow-md' : 'hover:bg-slate-800' ?> px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3">
                🏠 Mekan & Dolaplar
            </a>

            <a href="dolap-tipleri.php" class="<?= $current_page == 'dolap-tipleri.php' ? 'bg-blue-600 shadow-md' : 'hover:bg-slate-800' ?> px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3">
                ⚙️ Dolap Tipleri
            </a>

            <a href="bildirim-ayarlari.php" class="<?= $current_page == 'bildirim-ayarlari.php' ? 'bg-blue-600 shadow-md' : 'hover:bg-slate-800' ?> px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3">
                🔔 Bildirim Ayarları
            </a>

            <a href="bildirim-gecmisi.php" class="<?= $current_page == 'bildirim-gecmisi.php' ? 'bg-blue-600 shadow-md' : 'hover:bg-slate-800' ?> px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3">
                📨 Bildirim Logları
            </a>

            <a href="rapor.php" class="<?= $current_page == 'rapor.php' ? 'bg-blue-600 shadow-md' : 'hover:bg-slate-800' ?> px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3">
                📄 Raporlar
            </a>
            
            <a href="envanter.php" class="hover:bg-slate-800 px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3 border-t border-slate-700 mt-2 pt-4 text-slate-300 hover:text-white">
                📦 Ürün Listesi
            </a>

            <a href="tuketim-analizi.php" class="hover:bg-slate-800 px-4 py-3 rounded text-sm font-medium transition flex items-center gap-3">
    ⏳ Tüketim Analizi
</a>

        </nav>
    </div>

    <div class="bg-white p-5 rounded-xl shadow border border-slate-200">
        <h3 class="font-bold text-slate-700 mb-3 text-sm uppercase tracking-wider">Sistem Durumu</h3>
        <ul class="text-sm space-y-3 text-slate-600">
            <li class="flex justify-between items-center">
                <span>Toplam Kullanıcı:</span> 
                <span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded text-xs font-bold"><?= $toplamKullanici ?></span>
            </li>
            <li class="flex justify-between items-center">
                <span>Veritabanı:</span> 
                <span class="text-green-600 font-bold flex items-center gap-1">
                    <span class="w-2 h-2 rounded-full bg-green-500"></span> Normal
                </span>
            </li>
        </ul>
    </div>
</div>