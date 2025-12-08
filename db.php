<?php
// db.php

// 1. Session ve Klasör Ayarları
$session_folder = __DIR__ . '/sessions';

// Eğer sessions klasörü yoksa oluştur
if (!file_exists($session_folder)) {
    mkdir($session_folder, 0755, true);
}

// PHP'ye oturumları bu klasöre kaydetmesini söyle
session_save_path($session_folder);

// Şimdi oturumu başlat
session_start();

// 1.1. CSRF TOKEN OLUŞTURMA
if (empty($_SESSION['csrf_token'])) {
    if (function_exists('random_bytes')) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// --- LOGLAMA MEKANİZMASI ---
function sistemLogla($mesaj, $seviye = 'ERROR') {
    $logDizini = __DIR__ . '/logs';
    if (!file_exists($logDizini)) {
        mkdir($logDizini, 0755, true);
        file_put_contents($logDizini . '/.htaccess', 'Deny from all');
    }
    $logDosyasi = $logDizini . '/app_' . date('Y-m-d') . '.log';
    $logIcerigi = sprintf("[%s] [%s] %s%s", date('Y-m-d H:i:s'), $seviye, $mesaj, PHP_EOL);
    error_log($logIcerigi, 3, $logDosyasi);
}

// 2. Veritabanı Bağlantı Bilgileri
$host = 'localhost';
$db   = 'db';       
$user = 'user';       
$pass = 'pass'; 
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
    sistemLogla("Veritabanı Bağlantı Hatası: " . $e->getMessage(), 'CRITICAL');
    if (!headers_sent()) {
        header("Location: error.php");
        exit;
    } else {
        die("Sistemde teknik bir sorun oluştu.");
    }
}

// 3. GÜNCELLENMİŞ GİRİŞ KONTROL FONKSİYONU
// Bu güncelleme ile silinen kullanıcılar anında çıkışa zorlanır.
function girisKontrol() {
    global $pdo;

    // 1. Session yoksa at
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }

    // 2. Session var ama veritabanında kullanıcı duruyor mu?
    try {
        $stmt = $pdo->prepare("SELECT count(*) FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userExists = $stmt->fetchColumn();

        if ($userExists == 0) {
            // Kullanıcı veritabanından silinmiş!
            session_destroy(); // Oturumu öldür
            header("Location: login.php?msg=deleted"); // Logine at
            exit;
        }
    } catch (PDOException $e) {
        // DB hatası olursa güvenlik gereği oturumu sonlandırabiliriz veya loglayıp geçebiliriz
        sistemLogla("Kullanıcı Doğrulama Hatası: " . $e->getMessage());
    }
}

// 3.1. CSRF KORUMA FONKSİYONLARI
function csrfKontrol($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        sistemLogla("CSRF Hatası: Geçersiz token girişimi. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor'), 'SECURITY');
        http_response_code(403);
        die("Güvenlik Hatası: İşlem doğrulanamadı.");
    }
}

function csrfAlaniniEkle() {
    if (!isset($_SESSION['csrf_token'])) {
        if (function_exists('random_bytes')) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } else {
            $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        }
    }
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

// 4. Otomatik Bildirim Güncelleyici
function bildirimleriGuncelle($pdo) {
    $pdo->query("DELETE FROM notifications WHERE is_read = 1 AND timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt = $pdo->query("SELECT id, name, expiry_date FROM products WHERE expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)");
    $kritikUrunler = $stmt->fetchAll();

    foreach ($kritikUrunler as $urun) {
        $skt = new DateTime($urun['expiry_date']);
        $bugun = new DateTime();
        if ($skt < $bugun) {
            $fark = 0; 
        } else {
            $fark = $bugun->diff($skt)->format("%a"); 
        }
        
        $check = $pdo->prepare("SELECT id FROM notifications WHERE product_id = ? AND is_read = 0");
        $check->execute([$urun['id']]);
        
        if ($check->rowCount() == 0) {
            $ins = $pdo->prepare("INSERT INTO notifications (id, product_id, product_name, days_remaining, severity, timestamp) VALUES (UUID(), ?, ?, ?, ?, NOW())");
            $ins->execute([$urun['id'], $urun['name'], $fark, 'high']);
        }
    }
}

if(isset($_SESSION['user_id'])) {
    try {
        bildirimleriGuncelle($pdo);
    } catch(Exception $e) {
        sistemLogla("Bildirim Güncelleme Hatası: " . $e->getMessage(), 'WARNING');
    }
}
?>
