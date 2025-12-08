<?php
// db.php

// 1. Session ayarı: Sunucunun hata veren klasörü yerine kendi klasörümüzü kullanalım
$session_folder = __DIR__ . '/sessions';

// Eğer sessions klasörü yoksa oluştur
if (!file_exists($session_folder)) {
    mkdir($session_folder, 0755, true);
}

// PHP'ye oturumları bu klasöre kaydetmesini söyle
session_save_path($session_folder);

// Şimdi oturumu başlat
session_start();

// 2. Veritabanı Bağlantı Bilgileri
$host = 'localhost';
$db   = 'db';       // Kendi DB adın
$user = 'user';       // Kendi DB kullanıcın
$pass = 'pass'; // Kendi DB şifren
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}

// 3. Giriş Kontrol Fonksiyonu
function girisKontrol() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

// 4. Otomatik Bildirim Güncelleyici (DÜZELTİLDİ)
function bildirimleriGuncelle($pdo) {
    // DÜZELTME: 'created_at' yerine 'timestamp' kullanıldı
    $pdo->query("DELETE FROM notifications WHERE is_read = 1 AND timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY)");

    // Kritik Ürünleri Bul (7 gün kalanlar)
    $stmt = $pdo->query("SELECT id, name, expiry_date FROM products WHERE expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)");
    $kritikUrunler = $stmt->fetchAll();

    foreach ($kritikUrunler as $urun) {
        $skt = new DateTime($urun['expiry_date']);
        $bugun = new DateTime();
        
        // Geçmiş tarihli mi kontrol et
        if ($skt < $bugun) {
            $fark = 0; // Süresi geçmiş
        } else {
            $fark = $bugun->diff($skt)->format("%a"); // Kalan gün
        }
        
        // Bu ürün için bugün bildirim oluşturulmuş mu?
        $check = $pdo->prepare("SELECT id FROM notifications WHERE product_id = ? AND is_read = 0");
        $check->execute([$urun['id']]);
        
        if ($check->rowCount() == 0) {
            $msg = "⚠️ {$urun['name']} için son kullanma tarihi yaklaşıyor ({$fark} gün).";
            // DÜZELTME: 'timestamp' sütununu kullanıyoruz
            $ins = $pdo->prepare("INSERT INTO notifications (id, product_id, product_name, days_remaining, severity, timestamp) VALUES (UUID(), ?, ?, ?, ?, NOW())");
            $ins->execute([$urun['id'], $urun['name'], $fark, 'high']);
        }
    }
}

// Her sayfa açıldığında tetikle
if(isset($_SESSION['user_id'])) {
    // Hata bastırmayı geçici olarak açabiliriz, ama kod düzeltildiği için gerek kalmayacak
    try {
        bildirimleriGuncelle($pdo);
    } catch(Exception $e) {
        // Bildirim hatası sistemi kilitlemesin
        error_log("Bildirim hatası: " . $e->getMessage());
    }
}
?>