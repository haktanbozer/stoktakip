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

    // ── Master Ürün (Cins) Sistemi ──────────────────────────────────────────
    // product_types: Kritik eşiğin paket değil CİNS bazında tutulduğu tablo.
    // Örnek: Taze Kaşar → Gıda > Peynir → min_threshold: 2 Paket
    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_types` (
        `id`            VARCHAR(30)   NOT NULL,
        `name`          VARCHAR(100)  NOT NULL,
        `category`      VARCHAR(100)  NOT NULL DEFAULT '',
        `sub_category`  VARCHAR(100)  NOT NULL DEFAULT '',
        `default_unit`  VARCHAR(30)   NOT NULL DEFAULT 'Adet',
        `min_threshold` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_product_type_name` (`name`, `category`, `sub_category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // products tablosuna product_type_id sütunu ekle (eğer yoksa — kırılmasız migration)
    if (empty($_SESSION['_stok_migration_v2'])) {
        $colCheck = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'product_type_id'")->fetchColumn();
        if ($colCheck === false) {
            $pdo->exec("ALTER TABLE `products`
                ADD COLUMN `product_type_id` VARCHAR(30) NULL DEFAULT NULL
                AFTER `product_type`,
                ADD INDEX `idx_product_type_id` (`product_type_id`);");
        }
        // Mevcut ürünlerde boş olan product_type alanlarını doğrudan alt kategoriye eşitle
        $pdo->exec("UPDATE `products` SET `product_type` = `sub_category` WHERE (`product_type` IS NULL OR `product_type` = '') AND `sub_category` != ''");
        $_SESSION['_stok_migration_v2'] = true;
    }

    // ── Birim Standardizasyonu (Adet, Paket, Kg, Litre) ───────────────────
    if (empty($_SESSION['_stok_migration_v3_units'])) {
        try {
            // 1. Temizlik, Kişisel Bakım, Sağlık, Hırdavat, Kırtasiye, Ev Gereçleri, Su Arıtma ambalajlı ürünleri -> Adet
            $pdo->exec("UPDATE `product_types` SET `default_unit` = 'Adet' 
                WHERE `category` IN ('Temizlik', 'Kişisel Bakım & Kozmetik', 'Kişisel Bakım ve Kozmetik', 'Sağlık & Takviye', 'Hırdavat & Tamirat', 'Kırtasiye & Ev Ofis', 'Kullan-At & Parti', 'Ev Gereçleri & Sarf', 'Ev Gereçleri ve Sarf', 'Su Arıtma') 
                OR `name` LIKE '%Şampuan%' OR `name` LIKE '%Deterjan%' OR `name` LIKE '%Sabun%' OR `name` LIKE '%Çözücü%' OR `name` LIKE '%Macun%' OR `name` LIKE '%Fırça%' OR `name` LIKE '%Jel%' OR `name` LIKE '%Krem%'");

            // 2. Paketli Atıştırmalık, Fırın, Bakliyat ve Hazır Ürünler -> Paket
            $pdo->exec("UPDATE `product_types` SET `default_unit` = 'Paket' 
                WHERE `category` IN ('Atıştırmalık', 'Fırın & Pastacılık', 'Fırın ve Pastacılık') 
                OR `name` LIKE '%Makarna%' OR `name` LIKE '%Bisküvi%' OR `name` LIKE '%Kraker%' OR `name` LIKE '%Cips%' OR `name` LIKE '%Gofret%' OR `name` LIKE '%Çikolata%' OR `name` LIKE '%Tablet%' OR `name` LIKE '%Mendil%' OR `name` LIKE '%Bez%' OR `name` LIKE '%Poşet%' OR `name` LIKE '%Streç%' OR `name` LIKE '%Folyo%' OR `name` LIKE '%Kağıt Havlu%' OR `name` LIKE '%Tuvalet Kağıdı%' OR `name` LIKE '%Pil%' OR `name` LIKE '%Bant%'");

            // 3. Tartılı Manav, Kasap, Peynir ve Açık Bakliyat -> Kg
            $pdo->exec("UPDATE `product_types` SET `default_unit` = 'Kg' 
                WHERE `category` IN ('Et & Tavuk & Balık', 'Et/Tavuk/Balık', 'Meyve & Sebze') 
                OR `name` LIKE '%Kıyma%' OR `name` LIKE '%Kuşbaşı%' OR `name` LIKE '%Biftek%' OR `name` LIKE '%Kırmızı Et%' OR `name` LIKE '%Tavuk%' OR `name` LIKE '%Hindi%' OR `name` LIKE '%Peynir%' OR `name` LIKE '%Kaşar%' OR `name` LIKE '%Un%' OR `name` LIKE '%Şeker%' OR `name` LIKE '%Tuz%' OR `name` LIKE '%Pirinç%' OR `name` LIKE '%Bulgur%' OR `name` LIKE '%Mercimek%' OR `name` LIKE '%Nohut%' OR `name` LIKE '%Fasulye%' OR `name` LIKE '%Patates%' OR `name` LIKE '%Soğan%'");

            // 4. Sıvı Yağlar ve Su harici İçecek ambalajları -> Adet
            $pdo->exec("UPDATE `product_types` SET `default_unit` = 'Adet' 
                WHERE `category` = 'İçecek' AND `name` NOT IN ('Su', 'Maden Suyu')");

            // 5. Standart dışı kalan diğer birimleri Adet'e eşitle
            $pdo->exec("UPDATE `product_types` SET `default_unit` = 'Adet' 
                WHERE `default_unit` NOT IN ('Adet', 'Paket', 'Kg', 'Litre')");

            // 6. Mevcut ürünlerdeki (products) eski birimleri standardize et
            $pdo->exec("UPDATE `products` SET `unit` = 'Adet' WHERE `unit` IN ('Kutu', 'Şişe', 'Kavanoz', 'Rulo')");
            $pdo->exec("UPDATE `products` SET `unit` = 'Paket' WHERE `unit` IN ('Gram') AND `category` IN ('Atıştırmalık', 'Temel Gıda & Bakliyat', 'Fırın & Pastacılık', 'Fırın ve Pastacılık')");
            $pdo->exec("UPDATE `products` SET `unit` = 'Adet' WHERE `unit` NOT IN ('Adet', 'Paket', 'Kg', 'Litre')");

            $_SESSION['_stok_migration_v3_units'] = true;
        } catch (Exception $e) {
            sistemLogla("Birim migration hatası: " . $e->getMessage(), 'WARNING');
        }
    }

    // ── Bildirimler Tablosu İyileştirmesi (days_remaining NULL desteği ve temizlik) ──
    if (empty($_SESSION['_stok_migration_v4_notif'])) {
        try {
            $pdo->exec("ALTER TABLE `notifications` MODIFY COLUMN `days_remaining` INT(11) NULL DEFAULT NULL");
            // Süresiz ürünlerin bildirimlerindeki days_remaining = 0 olanları NULL yap
            $pdo->exec("UPDATE notifications n JOIN products p ON n.product_id = p.id SET n.days_remaining = NULL WHERE p.expiry_date IS NULL");
            // Süresi olanların gün bilgisini bugüne göre güncelle
            $pdo->exec("UPDATE notifications n JOIN products p ON n.product_id = p.id SET n.days_remaining = DATEDIFF(p.expiry_date, CURRENT_DATE) WHERE p.expiry_date IS NOT NULL");
            // Artık kritik veya süresi yakın olmayan eski bildirimleri temizle
            $pdo->exec("DELETE n FROM notifications n JOIN products p ON n.product_id = p.id WHERE n.is_read = 0 AND (p.expiry_date IS NULL OR p.expiry_date > DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)) AND (p.quantity > p.min_quantity)");
            $_SESSION['_stok_migration_v4_notif'] = true;
        } catch (Exception $e) {
            sistemLogla("Bildirim migration hatası: " . $e->getMessage(), 'WARNING');
        }
    }

    // ── Kategori & Ürün Tipi Senkronizasyonu ve Çift Kayıt Temizliği (v5) ──
    if (empty($_SESSION['_stok_migration_v5_catsync'])) {
        try {
            // 1. Çift oluşan ürün tiplerini ana kayıtlara aktar
            $pdo->exec("UPDATE `products` SET `product_type_id` = 'pt_6abfbbccadce9' WHERE `product_type_id` = 'pt_6abfeb9ebd240'");
            $pdo->exec("UPDATE `products` SET `product_type_id` = 'pt_6abfbbccae02a' WHERE `product_type_id` = 'pt_6abfebea56933'");

            // 2. Çift ürün tiplerini temizle
            $pdo->exec("DELETE FROM `product_types` WHERE `id` IN ('pt_6abfeb9ebd240', 'pt_6abfebea56933')");

            // 3. Güncellenen kategori isimlerini product_types tablosunda da senkronize et
            $pdo->exec("UPDATE `product_types` SET `category` = 'Kişisel Bakım ve Kozmetik' WHERE `category` = 'Kişisel Bakım & Kozmetik'");
            $pdo->exec("UPDATE `product_types` SET `category` = 'Et/Tavuk/Balık' WHERE `category` = 'Et & Tavuk & Balık'");
            $pdo->exec("UPDATE `product_types` SET `category` = 'Fırın ve Pastacılık' WHERE `category` = 'Fırın & Pastacılık'");
            $pdo->exec("UPDATE `product_types` SET `category` = 'Ev Gereçleri ve Sarf' WHERE `category` = 'Ev Gereçleri & Sarf'");
            $pdo->exec("UPDATE `product_types` SET `category` = 'Diyet ve Fit Yaşam' WHERE `category` = 'Diyet & Fit Yaşam'");

            $_SESSION['_stok_migration_v5_catsync'] = true;
        } catch (Exception $e) {
            sistemLogla("Kategori senkronizasyon migration hatası: " . $e->getMessage(), 'WARNING');
        }
    }

    // ── v6: Et/Tavuk/Balık Hariç Her Şey Zorunlu Adet ve Eşik 1.00 ─────────────
    if (empty($_SESSION['_stok_migration_v6_unit_adet'])) {
        try {
            // 1. Tüm alt kategoriler Adet ve 1.00
            $pdo->exec("UPDATE `product_types` SET `default_unit` = 'Adet', `min_threshold` = 1.00");
            // 2. Sadece Et/Tavuk/Balık Kg ve 1.00
            $pdo->exec("UPDATE `product_types` SET `default_unit` = 'Kg', `min_threshold` = 1.00 WHERE `category` IN ('Et/Tavuk/Balık', 'Et & Tavuk & Balık')");
            // 3. Mevcut ürünler de aynı kurala
            $pdo->exec("UPDATE `products` SET `unit` = 'Adet', `min_quantity` = 1.00");
            $pdo->exec("UPDATE `products` SET `unit` = 'Kg', `min_quantity` = 1.00 WHERE `category` IN ('Et/Tavuk/Balık', 'Et & Tavuk & Balık')");

            $_SESSION['_stok_migration_v6_unit_adet'] = true;
        } catch (Exception $e) {
            sistemLogla("v6 birim migration hatası: " . $e->getMessage(), 'WARNING');
        }
    }
    // ────────────────────────────────────────────────────────────────────────

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

// Otomatik Bildirim Güncelleyici (Akıllı SKT & Kritik Stok Ayrımı)
function bildirimleriGuncelle($pdo) {
    // 1. 7 günden eski okunmuş bildirimleri temizle
    $pdo->query("DELETE FROM notifications WHERE is_read = 1 AND timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    
    // 2. Artık ne süresi geçmiş ne de kritik stoğu kalmış (stoğu yenilenmiş/doldurulmuş) okunmamış bildirimleri temizle
    $pdo->query("
        DELETE n FROM notifications n
        JOIN products p ON n.product_id = p.id
        WHERE n.is_read = 0
          AND (p.expiry_date IS NULL OR p.expiry_date > DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY))
          AND (p.quantity > p.min_quantity)
    ");

    // 3. Şehir bilgisi olan ürünleri kontrol et (şehir izolasyonlu)
    $stmt = $pdo->query("
        SELECT p.id, p.name, p.expiry_date, p.quantity, p.min_quantity, l.city_id
        FROM products p
        JOIN cabinets cab ON p.cabinet_id = cab.id
        JOIN rooms r ON cab.room_id = r.id
        JOIN locations l ON r.location_id = l.id
        WHERE (
            (p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY))
            OR (p.quantity <= p.min_quantity)
        )
    ");
    $kritikUrunler = $stmt->fetchAll();

    $bugun = new DateTime('today');

    foreach ($kritikUrunler as $urun) {
        $kalanGun = null;
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
        } else {
            // Mevcut okunmamış bildirimin kalan gün ve önceliğini dinamik güncelle
            $upd = $pdo->prepare("UPDATE notifications SET days_remaining = ?, severity = ?, product_name = ? WHERE product_id = ? AND is_read = 0");
            $upd->execute([$kalanGun, $oncelik, $urun['name'], $urun['id']]);
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
                 "script-src 'self' https://cdn.tailwindcss.com https://code.jquery.com https://cdn.datatables.net https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com 'nonce-{$cspNonce}'; " .
                 "style-src 'self' 'unsafe-inline' https://cdn.datatables.net https://cdn.jsdelivr.net; " .
                 "img-src 'self' data: blob: https://cdn-icons-png.flaticon.com; " .
                 "media-src 'self' blob:; " .
                 "worker-src 'self' blob:; " .
                 "font-src 'self' https://cdnjs.cloudflare.com; " .
                 "connect-src 'self' https://generativelanguage.googleapis.com https://cdn.datatables.net https://world.openfoodfacts.org;";

    header("Content-Security-Policy: " . $cspHeader);
}
?>