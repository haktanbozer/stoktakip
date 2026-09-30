<?php
// db.php - Güvenlik, Veritabanı ve Oturum Yönetimi

// 1. .env Dosyasını Yükle (Tırnak temizleme destekli)
function yukleEnv($yol) {
    if (!file_exists($yol)) {
        return;
    }
    $satirlar = file($yol, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($satirlar as $satir) {
        $satir = trim($satir);
        if ($satir === '' || strpos($satir, '#') === 0) continue;
        if (strpos($satir, '=') === false) continue;

        list($isim, $deger) = explode('=', $satir, 2);
        $isim  = trim($isim);
        $deger = trim($deger, " \t\n\r\0\x0B\"'"); // Çift ve tek tırnakları ayıkla
        
        if (!array_key_exists($isim, $_SERVER) && !array_key_exists($isim, $_ENV)) {
            putenv(sprintf('%s=%s', $isim, $deger));
            $_ENV[$isim] = $deger;
            $_SERVER[$isim] = $deger;
        }
    }
}

yukleEnv(__DIR__ . '/.env');

// 2. Session ve Klasör Güvenliği
$session_folder = __DIR__ . '/sessions';
if (!file_exists($session_folder)) { 
    mkdir($session_folder, 0755, true); 
}
// Session klasörüne doğrudan web erişimini tamamen engelle
if (!file_exists($session_folder . '/.htaccess')) {
    file_put_contents($session_folder . '/.htaccess', "Require all denied\nDeny from all");
}
session_save_path($session_folder);

// HTTPS kontrolü (Canlıda HTTPS, yerel ağda HTTP desteği)
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

session_set_cookie_params([
    'lifetime' => 0,            // Tarayıcı kapanınca silinsin
    'path'     => '/',          // Tüm sitede geçerli
    'domain'   => '',           // Mevcut domain
    'secure'   => $isSecure,    // HTTPS varsa sadece güvenli kanaldan gönder
    'httponly' => true,         // JavaScript ile erişilemez (XSS Koruması)
    'samesite' => 'Strict'      // CSRF koruması
]);

// B5: Session Temizleme (GC) Ayarları
ini_set('session.gc_maxlifetime', 86400); // 1 günden eski oturumlar = çöp
ini_set('session.gc_probability', 5);     // Her 100 istekte 5 şans
ini_set('session.gc_divisor',    100);    // → %5 ihtimalle temizler

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2.1. CSRF TOKEN OLUŞTURMA (1 Saatlik Süre Aşımı)
if (empty($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time']) > 3600) {
    if (function_exists('random_bytes')) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
    $_SESSION['csrf_token_time'] = time();
}

// --- LOGLAMA MEKANİZMASI ---
function sistemLogla($mesaj, $seviye = 'ERROR') {
    $logDizini = __DIR__ . '/logs';
    if (!file_exists($logDizini)) {
        mkdir($logDizini, 0755, true);
    }
    if (!file_exists($logDizini . '/.htaccess')) {
        file_put_contents($logDizini . '/.htaccess', "Require all denied\nDeny from all");
    }
    $logDosyasi = $logDizini . '/app_' . date('Y-m-d') . '.log';
    $logIcerigi = sprintf("[%s] [%s] %s%s", date('Y-m-d H:i:s'), $seviye, $mesaj, PHP_EOL);
    error_log($logIcerigi, 3, $logDosyasi);
}

// --- GLOBAL HATA YAKALAYICILAR ---
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return;
    }
    
    $seviye = 'ERROR';
    if ($errno === E_WARNING || $errno === E_USER_WARNING) $seviye = 'WARNING';
    if ($errno === E_NOTICE || $errno === E_USER_NOTICE) $seviye = 'INFO';
    
    $mesaj = "$errstr | Dosya: $errfile | Satır: $errline";
    sistemLogla($mesaj, $seviye);
    return false; 
});

set_exception_handler(function($e) {
    $mesaj = "Yakalanmamış İstisna: " . $e->getMessage() . " | Dosya: " . $e->getFile() . " | Satır: " . $e->getLine();
    sistemLogla($mesaj, 'CRITICAL');
    
    if (!headers_sent()) {
         if(file_exists('error.php')) {
             header("Location: error.php");
             exit;
         }
    }
    echo "Sistemde teknik bir sorun oluştu.";
});

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

// 3. Veritabanı Bağlantısı
$host    = getenv('DB_HOST');
$db      = getenv('DB_NAME');
$user    = getenv('DB_USER');
$pass    = getenv('DB_PASS');
$charset = 'utf8mb4';

if (!$host || !$db || !$user) {
    die("Veritabanı yapılandırma hatası. .env dosyası eksik veya hatalı.");
}

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Güvenlik: Eksikse brute-force tablosunu otomatik oluştur
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        username VARCHAR(255) NOT NULL,
        attempts INT DEFAULT 1,
        locked_until DATETIME NULL,
        last_attempt DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ip_user_unique (ip_address, username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
} catch (\PDOException $e) {
    sistemLogla("Veritabanı Bağlantı Hatası: " . $e->getMessage(), 'CRITICAL');
    if (!headers_sent()) {
        header("Location: error.php");
        exit;
    } else {
        die("Sistemde teknik bir sorun oluştu.");
    }
}

// 4. Doğrulama ve Güvenlik Fonksiyonları
function girisKontrol() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        if ($stmt->fetchColumn() == 0) {
            session_destroy();
            header("Location: login.php?msg=deleted");
            exit;
        }
    } catch (PDOException $e) {
        sistemLogla("Kullanıcı Doğrulama Hatası: " . $e->getMessage());
    }
}

function csrfKontrol($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        sistemLogla("CSRF Hatası (Token uyuşmazlığı veya zaman aşımı): IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor'), 'SECURITY');
        http_response_code(403);
        die("Güvenlik Hatası: Oturumunuzun süresi dolmuş veya işlem doğrulanamadı. Lütfen sayfayı yenileyip tekrar deneyin.");
    }
}

function csrfAlaniniEkle() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

// Otomatik Bildirim Güncelleyici (Düzeltildi)
function bildirimleriGuncelle($pdo) {
    // 7 günden eski okunmuş bildirimleri temizle
    $pdo->query("DELETE FROM notifications WHERE is_read = 1 AND timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    
    // Yalnızca STT'si olan ve stoğu sıfırdan büyük ürünleri kontrol et
    $stmt = $pdo->query("SELECT id, name, expiry_date, quantity, min_quantity FROM products WHERE (expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)) OR (quantity <= min_quantity)");
    $kritikUrunler = $stmt->fetchAll();

    $bugun = new DateTime('today');

    foreach ($kritikUrunler as $urun) {
        $kalanGun = 0;
        $oncelik = 'medium';

        if (!empty($urun['expiry_date'])) {
            $skt = new DateTime($urun['expiry_date']);
            $fark = (int)$bugun->diff($skt)->format('%r%a');
            $kalanGun = $fark;
            $oncelik = ($fark <= 3) ? 'critical' : 'high';
        }

        if ((float)$urun['quantity'] <= (float)$urun['min_quantity']) {
            $oncelik = 'critical';
        }
        
        $check = $pdo->prepare("SELECT id FROM notifications WHERE product_id = ? AND is_read = 0");
        $check->execute([$urun['id']]);
        
        if ($check->rowCount() == 0) {
            $ins = $pdo->prepare("INSERT INTO notifications (id, product_id, product_name, days_remaining, severity, timestamp) VALUES (UUID(), ?, ?, ?, ?, NOW())");
            $ins->execute([$urun['id'], $urun['name'], $kalanGun, $oncelik]);
        }
    }
}

if (isset($_SESSION['user_id'])) {
    try {
        bildirimleriGuncelle($pdo);
    } catch(Exception $e) {
        sistemLogla("Bildirim Güncelleme Hatası: " . $e->getMessage(), 'WARNING');
    }
}

// --- GÜVENLİK: CSP NONCE ---
if (!isset($cspNonce)) {
    try {
        $cspNonce = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $cspNonce = bin2hex(openssl_random_pseudo_bytes(16));
    }
}

// --- GÜVENLİK BAŞLIKLARI ---
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    // header("Permissions-Policy: geolocation=(), microphone=(), payment=()");

    $cspHeader = "default-src 'self'; " .
                 "base-uri 'self'; " .
                 "object-src 'none'; " . 
                 "form-action 'self'; " . 
                 "script-src 'self' 'unsafe-eval' https://cdn.tailwindcss.com https://code.jquery.com https://cdn.datatables.net https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com 'nonce-{$cspNonce}'; " .
                 "style-src 'self' 'unsafe-inline' https://cdn.datatables.net https://cdn.jsdelivr.net; " .
                 "img-src 'self' data: blob: https://cdn-icons-png.flaticon.com; " .
                 "media-src 'self' blob:; " .
                 "worker-src 'self' blob:; " .
                 "font-src 'self' https://cdnjs.cloudflare.com; " .
                 "connect-src 'self' https://generativelanguage.googleapis.com https://cdn.datatables.net https://world.openfoodfacts.org;";

    header("Content-Security-Policy: " . $cspHeader);
}
?>
