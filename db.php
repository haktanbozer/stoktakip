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

// --- DOSYA LOGLAMA MEKANİZMASI (Sistem Hataları İçin) ---
function sistemLogla($mesaj, $seviye = 'ERROR') {
    $logDizini = __DIR__ . '/logs';
    if (!file_exists($logDizini)) {
        mkdir($logDizini, 0755, true);
        // Güvenlik için log klasörüne dışarıdan erişimi engelleyen .htaccess
        file_put_contents($logDizini . '/.htaccess', 'Deny from all');
    }
    // Günlük dosya adı (örn: app_2023-10-27.log)
    $logDosyasi = $logDizini . '/app_' . date('Y-m-d') . '.log';
    
    // Log formatı: [Tarih Saat] [Seviye] Mesaj
    $logIcerigi = sprintf("[%s] [%s] %s%s", date('Y-m-d H:i:s'), $seviye, $mesaj, PHP_EOL);
    
    // Dosyaya ekle (Append modu)
    error_log($logIcerigi, 3, $logDosyasi);
}

// --- GLOBAL HATA YAKALAYICILAR (YENİ EKLENDİ) ---

// 1. Uyarılar ve Noticeler için (Warning, Notice vb.)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        // Bu hata kodu error_reporting ayarına dahil değilse yoksay
        return;
    }
    
    $seviye = 'ERROR';
    if ($errno === E_WARNING || $errno === E_USER_WARNING) $seviye = 'WARNING';
    if ($errno === E_NOTICE || $errno === E_USER_NOTICE) $seviye = 'INFO';
    
    $mesaj = "$errstr | Dosya: $errfile | Satır: $errline";
    sistemLogla($mesaj, $seviye);
    
    // false döndürerek PHP'nin standart işlemesine izin ver (Geliştirme aşamasında ekranda da görünsün)
    // Canlı ortamda true döndürüp ekrana basılması engellenebilir.
    return false; 
});

// 2. Yakalanmamış İstisnalar için (Uncaught Exceptions)
set_exception_handler(function($e) {
    $mesaj = "Yakalanmamış İstisna: " . $e->getMessage() . " | Dosya: " . $e->getFile() . " | Satır: " . $e->getLine();
    sistemLogla($mesaj, 'CRITICAL');
    
    // Kullanıcıya şık bir hata sayfası göster
    if (!headers_sent()) {
         // error.php varsa oraya yönlendir
         if(file_exists('error.php')) {
             header("Location: error.php");
             exit;
         }
    }
    // error.php yoksa veya header gönderildiyse basit mesaj
    echo "Sistemde teknik bir sorun oluştu. Lütfen yöneticinize başvurun.";
});

// 3. Kritik Hatalar (Fatal Errors) için Kapanış Fonksiyonu
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR])) {
         $mesaj = "Kritik Hata (Fatal): " . $error['message'] . " | Dosya: " . $error['file'] . " | Satır: " . $error['line'];
         sistemLogla($mesaj, 'FATAL');
         
         if (!headers_sent() && file_exists('error.php')) {
             header("Location: error.php");
         }
    }
});


// --- AUDIT LOG MEKANİZMASI ---
function auditLog($islem, $detay) {
    global $pdo;
    
    // Kullanıcı giriş yapmamışsa loglama yapma
    if (!isset($_SESSION['user_id'])) return;

    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $_SESSION['username'] ?? 'Bilinmiyor',
            $islem, 
            $detay, 
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (Exception $e) {
        sistemLogla("Audit Log Yazma Hatası: " . $e->getMessage(), 'WARNING');
    }
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
            session_destroy(); 
            header("Location: login.php?msg=deleted"); 
            exit;
        }
    } catch (PDOException $e) {
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
