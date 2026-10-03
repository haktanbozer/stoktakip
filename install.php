<?php
// install.php - Turnkey Web Kurulum Sihirbazı (Zero-Config / Turnkey Installer)
// Güvenlik: Kurulum tamamlandığında installed.lock dosyası oluşturulur ve yeniden çalıştırma engellenir.

header('Content-Type: text/html; charset=utf-8');

$lockFile = __DIR__ . '/installed.lock';
$envFile  = __DIR__ . '/.env';

// Kurulum zaten yapılmışsa kilitle
if (file_exists($lockFile)) {
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sistem Zaten Kurulu — Stok Takip</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-slate-800 border border-slate-700 rounded-2xl p-6 shadow-xl text-center">
            <div class="w-16 h-16 bg-amber-500/20 text-amber-400 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4">🔒</div>
            <h1 class="text-xl font-bold mb-2">Sistem Zaten Kurulu!</h1>
            <p class="text-slate-400 text-sm mb-6">Güvenliğiniz için kurulum sihirbazı kilitlenmiştir. Yeniden kurulum yapmak istiyorsanız ana dizindeki <code>installed.lock</code> dosyasını silmeniz gerekir.</p>
            <div class="space-y-3">
                <a href="login.php" class="block w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm transition">Giriş Sayfasına Git</a>
                <p class="text-xs text-rose-400 font-semibold">⚠️ Güvenliğiniz için <code>install.php</code> dosyasını sunucunuzdan silebilirsiniz.</p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$hata   = '';
$basari = '';
$adim   = 'form';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kurulum_yap'])) {
    // 1. Uygulama Ayarları
    $appEnv  = trim($_POST['app_env'] ?? 'production');
    $appUrl  = rtrim(trim($_POST['app_url'] ?? ''), '/');
    if (empty($appUrl)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uriDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $appUrl = $protocol . $host . $uriDir;
    }

    // 2. Veritabanı Ayarları
    $dbHost     = trim($_POST['db_host'] ?? 'localhost');
    $dbName     = trim($_POST['db_name'] ?? '');
    $dbUser     = trim($_POST['db_user'] ?? '');
    $dbPass     = trim($_POST['db_pass'] ?? '');

    // 3. Yönetici Hesabı
    $adminUser  = trim($_POST['admin_user'] ?? 'admin');
    $adminEmail = trim($_POST['admin_email'] ?? 'admin@example.com');
    $adminPass  = trim($_POST['admin_pass'] ?? '');

    // 4. Cron ve Güvenlik
    $cronSecret = trim($_POST['cron_secret'] ?? '');
    if (empty($cronSecret)) {
        $cronSecret = bin2hex(random_bytes(16));
    }

    // 5. Google Gemini API (Opsiyonel)
    $geminiApiKey = trim($_POST['gemini_api_key'] ?? '');

    // 6. SMTP E-Posta Ayarları (Opsiyonel)
    $smtpHost        = trim($_POST['smtp_host'] ?? '');
    $smtpPort        = (int)($_POST['smtp_port'] ?? 587);
    if ($smtpPort <= 0) $smtpPort = 587;
    $smtpUser        = trim($_POST['smtp_user'] ?? '');
    $smtpPass        = trim($_POST['smtp_pass'] ?? '');
    $smtpSecure      = trim($_POST['smtp_secure'] ?? 'tls');
    $mailFromAddress = trim($_POST['mail_from_address'] ?? '');
    if (empty($mailFromAddress)) {
        $mailFromAddress = !empty($smtpUser) ? $smtpUser : $adminEmail;
    }
    $mailFromName    = trim($_POST['mail_from_name'] ?? 'StokTakip Bildirim');
    if (empty($mailFromName)) {
        $mailFromName = 'StokTakip Bildirim';
    }

    if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
        $hata = 'Veritabanı sunucusu, veritabanı adı ve veritabanı kullanıcı adı zorunludur.';
    } elseif (empty($adminUser) || empty($adminEmail) || empty($adminPass)) {
        $hata = 'Yönetici kullanıcı adı, e-posta adresi ve şifre zorunludur.';
    } elseif (strlen($adminPass) < 6) {
        $hata = 'Yönetici şifresi en az 6 karakter olmalıdır.';
    } else {
        try {
            // 1. Veritabanı Sunucusuna Bağlan
            $pdoRoot = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            // 2. Veritabanını Oluştur (Yoksa) veya Seç (Paylaşımlı hosting uyumlu)
            try {
                $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Exception $dbErr) {
                // Paylaşımlı cPanel sunucularda CREATE DATABASE izni olmayabilir, veritabanı önceden oluşturulmuşsa devam et
            }
            $pdoRoot->exec("USE `$dbName`");
            $pdo = $pdoRoot;

            // 3. Tabloları Oluştur
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `users` (
                  `id` varchar(50) NOT NULL,
                  `username` varchar(100) NOT NULL,
                  `email` varchar(150) NOT NULL,
                  `password` varchar(255) NOT NULL,
                  `role` enum('ADMIN','USER') DEFAULT 'USER',
                  `remember_token` varchar(255) DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `username` (`username`),
                  KEY `idx_username` (`username`),
                  KEY `idx_email` (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `cities` (
                  `id` varchar(50) NOT NULL,
                  `name` varchar(100) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `locations` (
                  `id` varchar(50) NOT NULL,
                  `city_id` varchar(50) NOT NULL,
                  `name` varchar(100) NOT NULL,
                  `type` varchar(50) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_city` (`city_id`),
                  CONSTRAINT `locations_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `rooms` (
                  `id` varchar(50) NOT NULL,
                  `location_id` varchar(50) NOT NULL,
                  `name` varchar(100) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_location` (`location_id`),
                  CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `cabinet_types` (
                  `name` varchar(100) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `cabinets` (
                  `id` varchar(50) NOT NULL,
                  `room_id` varchar(50) NOT NULL,
                  `name` varchar(100) NOT NULL,
                  `type` varchar(100) DEFAULT NULL,
                  `description` text DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_room` (`room_id`),
                  KEY `idx_type` (`type`),
                  CONSTRAINT `cabinets_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `cabinets_ibfk_2` FOREIGN KEY (`type`) REFERENCES `cabinet_types` (`name`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `categories` (
                  `id` varchar(50) NOT NULL,
                  `name` varchar(100) NOT NULL,
                  `sub_categories` text NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `product_types` (
                  `id` varchar(30) NOT NULL,
                  `name` varchar(100) NOT NULL,
                  `category` varchar(100) NOT NULL DEFAULT '',
                  `sub_category` varchar(100) NOT NULL DEFAULT '',
                  `default_unit` varchar(30) NOT NULL DEFAULT 'Adet',
                  `min_threshold` decimal(10,2) NOT NULL DEFAULT 1.00,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_product_type_name` (`name`,`category`,`sub_category`),
                  KEY `idx_category` (`category`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `products` (
                  `id` varchar(50) NOT NULL,
                  `cabinet_id` varchar(50) NOT NULL,
                  `shelf_location` varchar(50) DEFAULT NULL,
                  `name` varchar(200) NOT NULL,
                  `barcode` varchar(50) DEFAULT NULL,
                  `brand` varchar(100) DEFAULT NULL,
                  `product_type` varchar(100) DEFAULT NULL,
                  `product_type_id` varchar(30) DEFAULT NULL,
                  `category` varchar(100) NOT NULL,
                  `sub_category` varchar(100) NOT NULL,
                  `quantity` decimal(10,2) NOT NULL,
                  `min_quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
                  `unit` varchar(50) NOT NULL,
                  `purchase_date` date NOT NULL,
                  `expiry_date` date DEFAULT NULL,
                  `is_opened` tinyint(1) NOT NULL DEFAULT 0,
                  `opened_at` date DEFAULT NULL,
                  `added_by_user_id` varchar(50) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_cabinet` (`cabinet_id`),
                  KEY `idx_category` (`category`,`sub_category`),
                  KEY `idx_expiry` (`expiry_date`),
                  KEY `idx_user` (`added_by_user_id`),
                  KEY `idx_barcode` (`barcode`),
                  KEY `idx_product_type_id` (`product_type_id`),
                  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`cabinet_id`) REFERENCES `cabinets` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`added_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `notifications` (
                  `id` varchar(50) NOT NULL,
                  `product_id` varchar(50) NOT NULL,
                  `product_name` varchar(200) NOT NULL,
                  `days_remaining` int(11) DEFAULT NULL,
                  `severity` enum('low','medium','high','critical') DEFAULT 'low',
                  `is_read` tinyint(1) DEFAULT 0,
                  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_product` (`product_id`),
                  KEY `idx_timestamp` (`timestamp`),
                  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `notification_thresholds` (
                  `days` int(11) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`days`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `notification_logs` (
                  `id` varchar(36) NOT NULL,
                  `user_email` varchar(150) NOT NULL,
                  `subject` varchar(255) NOT NULL,
                  `content_summary` text DEFAULT NULL,
                  `status` enum('sent','failed') DEFAULT 'sent',
                  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

                CREATE TABLE IF NOT EXISTS `consumption_history` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `product_id` varchar(50) NOT NULL,
                  `amount` decimal(10,2) NOT NULL,
                  `consumed_at` datetime NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `fk_consumption_product` (`product_id`),
                  CONSTRAINT `fk_consumption_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `audit_logs` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` varchar(50) NOT NULL,
                  `username` varchar(100) NOT NULL,
                  `action` varchar(50) NOT NULL,
                  `details` text DEFAULT NULL,
                  `ip_address` varchar(45) DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

                CREATE TABLE IF NOT EXISTS `login_attempts` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `ip_address` varchar(45) NOT NULL,
                  `username` varchar(100) NOT NULL,
                  `attempts` int(11) DEFAULT 1,
                  `last_attempt` datetime DEFAULT current_timestamp(),
                  `locked_until` datetime DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `ip_user_unique` (`ip_address`,`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

                CREATE TABLE IF NOT EXISTS `user_city_assignments` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` varchar(50) NOT NULL,
                  `city_id` varchar(50) NOT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `user_city_unique` (`user_id`,`city_id`),
                  KEY `idx_user_id` (`user_id`),
                  KEY `idx_city_id` (`city_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 4. Varsayılan Dolap Tiplerini Ekle
            $dolapTipleri = ['Buzdolabı', 'Mutfak Dolabı', 'Metal Dolap', 'Kiler Dolabı', 'Banyo Dolabı', 'Dondurucu', 'Mutfak Tezgahı'];
            $stmtCabType = $pdo->prepare("INSERT IGNORE INTO `cabinet_types` (`name`) VALUES (?)");
            foreach ($dolapTipleri as $dt) {
                $stmtCabType->execute([$dt]);
            }

            // 5. Bildirim Eşik Günlerini Ekle
            $esikGunler = [1, 2, 3, 5, 7, 14, 30, 45, 60, 90];
            $stmtThresh = $pdo->prepare("INSERT IGNORE INTO `notification_thresholds` (`days`) VALUES (?)");
            foreach ($esikGunler as $g) {
                $stmtThresh->execute([$g]);
            }

            // 6. Kategoriler ve Alt Kategoriler (Eksiksiz & Zengin Türk Market/Kiler Envanteri)
            $kategoriler = [
                'Temel Gıda ve Bakliyat' => [
                    'Arpa Şehriye', 'Aşurelik Buğday', 'Baldo Pirinç', 'Basmati Pirinç', 'Biber Salçası', 'Doğranmış Domates',
                    'Domates Püresi', 'Domates Rendesi', 'Domates Salçası', 'Et Suyu', 'Garnitür', 'İlikli Kemik Suyu', 'İrmik',
                    'Karabuğday', 'Kırmızı Mercimek', 'Konserve Köz Biber', 'Konserve Közlenmiş Patlcan', 'Konserve Mısır',
                    'Köftelik Bulgur', 'Kuru Fasulye', 'Kuskus', 'Küp Şeker', 'Makarna', 'Nohut', 'Osmancık Pirinç',
                    'Pilavlık Bulgur', 'Pirinç', 'Siyez Bulguru', 'Şeker', 'Tarhana', 'Tavuk Suyu', 'Tel Şehriye',
                    'Ton Balığı', 'Turşu', 'Un', 'Yeşil Mercimek'
                ],
                'Hazır Yemek/Konserve' => [
                    'Ton Balığı', 'Döner (Et)', 'Döner (Tavuk)', 'Hazır Çorba', 'Kavanoz Çorba', 'Noodle', 'Çabuk Makarna',
                    'Hazır Köfte', 'Şinitzel', 'Nugget', 'Kavurma'
                ],
                'Et/Tavuk/Balık' => [
                    'Biftek', 'Çipura', 'Füme Et', 'Hindi', 'Kavurma', 'Kırmızı Et', 'Kıyma', 'Köfte', 'Kuşbaşı', 'Levrek',
                    'Pastırma', 'Salam', 'Somon', 'Sosis', 'Sucuk', 'Tavuk'
                ],
                'Süt ve Süt Ürünleri' => [
                    'Krema', 'Süt', 'Süzme Yoğurt', 'Tereyağı', 'Yoğurt'
                ],
                'Kahvaltılık' => [
                    'Bal', 'Beyaz Peynir', 'Biberli Zeytin', 'Cheddar', 'Dil Peyniri', 'Eski Kaşar', 'Ezine Peyniri',
                    'Fıstık Ezmesi', 'Hellim', 'Izgara Zeytin', 'Kahvaltılık Sos', 'Kaymak', 'Krem Çikolata', 'Krem Peynir',
                    'Labne', 'Lor Peyniri', 'Marmelat', 'Parmesan', 'Pekmez', 'Reçel', 'Siyah Zeytin', 'Süzme Peynir',
                    'Tahin', 'Taze Kaşar', 'Tereyağı', 'Tulum Peyniri', 'Yeşil Zeytin', 'Yumurta'
                ],
                'Atıştırmalık' => [
                    'Bisküvi', 'Cips', 'Çikolata', 'Gevrek', 'Gofret', 'Kek', 'Kraker'
                ],
                'İçecek' => [
                    'Ayran', 'Bergamot/Tomurcuk Çay', 'Bitki Çayı', 'Demlik Poşet Çay', 'Dökme Çay', 'Enerji İçeceği',
                    'Espresso', 'Filtre Kahve', 'Gazlı İçecek', 'Granül Kahve', 'Kahve', 'Kefir', 'Maden Suyu', 'Meyve Suyu',
                    'Poşet Çay', 'Soğuk Çay', 'Su', 'Süt', 'Şalgam', 'Türk Kahvesi'
                ],
                'Meyve ve Sebze' => [
                    'Kök Sebze', 'Meyve', 'Narenciye', 'Sebze', 'Tropikal Meyve', 'Yeşillik'
                ],
                'Donuk Ürünler' => [
                    'Dondurma', 'Donuk Deniz Ürünü', 'Donuk Et', 'Donuk Meyve', 'Donuk Sebze', 'Donuk Unlu Mamul', 'Mantı', 'Patates Kızartması'
                ],
                'Kuruyemiş ve Kuru Meyve' => [
                    'Antep Fıstığı', 'Badem', 'Ceviz', 'Fındık', 'Kaju', 'Kuru Erik', 'Kuru Kayısı', 'Kuru Üzüm', 'Kuru İncir', 'Leblebi', 'Yer Fıstığı', 'Çekirdek'
                ],
                'Fırın ve Pastacılık' => [
                    'Damla Çikolata', 'Ekmek', 'Hamur Kabartma Tozu', 'Kakao', 'Kuru Maya', 'Lavaş', 'Nişasta', 'Pudra Şekeri', 'Şekerli Vanilin', 'Yaş Maya', 'Yufka'
                ],
                'Sos/Baharat/Yağ' => [
                    'Acı Sos', 'Acı Toz Biber', 'Ayçiçek Yağı', 'Elma Sirkesi', 'Hardal', 'Karabiber', 'Kaya Tuzu', 'Kekik',
                    'Ketçap', 'Kimyon', 'Köri', 'Mayonez', 'Mısır Özü Yağı', 'Nane', 'Nar Ekşisi', 'Pul Biber', 'Soya Sosu',
                    'Tereyağı', 'Toz Biber', 'Tuz', 'Üzüm Sirkesi', 'Zeytinyağı'
                ],
                'Dünya Mutfağı' => [
                    'Balzamik Sirke', 'Hindistan Cevizi Sütü', 'Köri Sosu', 'Noodle', 'Soya Sosu', 'Sushi Pirinci', 'Taco Kabuğu', 'Teriyaki Sos'
                ],
                'Diyet ve Fit Yaşam' => [
                    'Badem Sütü', 'Chia Tohumu', 'Granola', 'Kinoa', 'Müsli', 'Pirinç Patlağı', 'Şekersiz Fıstık Ezmesi', 'Yulaf Ezmesi', 'Yulaf Sütü'
                ],
                'Temizlik' => [
                    'Banyo Temizleyici', 'Beyaz Sirke', 'Bulaşık Deterjanı (Elde)', 'Bulaşık Deterjanı (Makine)', 'Bulaşık Süngeri',
                    'Bulaşık Teli', 'Cam Sil', 'Çamaşır Deterjanı (Beyaz)', 'Çamaşır Deterjanı (Renkli)', 'Çamaşır Deterjanı (Siyah)',
                    'Çamaşır Suyu', 'Çöp Poşeti', 'Kağıt Havlu', 'Kireç Çözücü', 'Krem', 'Lavabo Açıcı', 'Leke Çıkarıcı',
                    'Makine Tuzu', 'Mutfak Temizleyici', 'Oda Kokusu', 'Parlatıcı', 'Tuvalet Kağıdı', 'Tuvalet Temizleyici',
                    'Yağ Çözücü', 'Yumuşatıcı', 'Yüzey Temizleyici'
                ],
                'Kişisel Bakım ve Kozmetik' => [
                    'Deodorant', 'Diş Fırçası', 'Diş Macunu', 'Duş Jeli', 'Güneş Kremi', 'Islak Mendil', 'Katı Sabun',
                    'Kulak Çöpü', 'Nemlendirici Krem', 'Pamuk', 'Parfüm', 'Roll-on', 'Saç Kremi', 'Sıvı Sabun', 'Şampuan',
                    'Tıraş Bıçağı', 'Tıraş Köpüğü'
                ],
                'Sağlık & Takviye' => [
                    'Ateş Düşürücü', 'Ağrı Kesici', 'B Vitamini', 'Balgam Söktürücü', 'C Vitamini', 'D Vitamini', 'Grip İlacı',
                    'Göz Damlası', 'Kas Gevşetici', 'Kolajen', 'Magnezyum', 'Mide İlacı', 'Omega-3', 'Yara Bandı'
                ],
                'Ev Gereçleri ve Sarf' => [
                    'Alüminyum Folyo', 'Ampul', 'Bez', 'Buzdolabı Poşeti', 'Çakmak', 'Çöp Poşeti', 'Kibrit', 'Peçete', 'Pil', 'Pişirme Kağıdı', 'Streç Film'
                ],
                'Kırtasiye' => [
                    'A4 Kağıt', 'Fosforlu Kalem', 'Kurşun Kalem', 'Silgi', 'Şeffaf Bant', 'Tükenmez Kalem', 'Yapıştırıcı (Pritt)', 'Zımba Teli'
                ],
                'Su Arıtma' => [
                    'Aktif Karbon', 'Alkali', 'Membran', 'Mineral', 'Post Karbon', 'Sediment', 'Tatlandırıcı'
                ],
                'Kullan-At & Parti' => [
                    'Bambu Şiş', 'Karton Bardak', 'Kağıt Tabak', 'Kürdan', 'Mangal Kömürü', 'Parti Mumu', 'Plastik Çatal & Kaşık', 'Çöp Şiş', 'Çıra'
                ]
            ];

            $stmtInsCat = $pdo->prepare("INSERT INTO `categories` (`id`, `name`, `sub_categories`) VALUES (?, ?, ?)");
            $stmtInsPt  = $pdo->prepare("INSERT IGNORE INTO `product_types` (`id`, `name`, `category`, `sub_category`, `default_unit`, `min_threshold`) VALUES (?, ?, ?, ?, ?, 1.00)");

            foreach ($kategoriler as $katAd => $altList) {
                $catId = 'cat_' . substr(md5($katAd . time()), 0, 16);
                $subStr = implode(', ', $altList);
                $stmtInsCat->execute([$catId, $katAd, $subStr]);

                // Ürün tiplerini oluştur
                // Kural: Et/Tavuk/Balık = Kg, diğer tüm kategoriler = Adet
                $defaultUnit = ($katAd === 'Et/Tavuk/Balık') ? 'Kg' : 'Adet';

                foreach ($altList as $subAd) {
                    $ptId = 'pt_' . substr(md5($katAd . $subAd), 0, 16);
                    $stmtInsPt->execute([$ptId, $subAd, $katAd, $subAd, $defaultUnit]);
                }
            }

            // 7. Başlangıç Konum Hiyerarşisi (Anonim & Kullanıma Hazır)
            $cityId = 'city_default';
            $locId  = 'loc_default';
            $roomId = 'room_kitchen';
            $cabId1 = 'cab_fridge';
            $cabId2 = 'cab_pantry';

            $pdo->exec("INSERT IGNORE INTO `cities` (`id`, `name`) VALUES ('$cityId', 'Ev / Merkez');");
            $pdo->exec("INSERT IGNORE INTO `locations` (`id`, `city_id`, `name`, `type`) VALUES ('$locId', '$cityId', 'Ana Ev', 'Ev');");
            $pdo->exec("INSERT IGNORE INTO `rooms` (`id`, `location_id`, `name`) VALUES ('$roomId', '$locId', 'Mutfak');");
            $pdo->exec("INSERT IGNORE INTO `cabinets` (`id`, `room_id`, `name`, `type`, `description`) VALUES 
                ('$cabId1', '$roomId', 'Buzdolabı', 'Buzdolabı', 'Ana soğutucu ve dondurucu'),
                ('$cabId2', '$roomId', 'Kiler Dolabı', 'Mutfak Dolabı', 'Kuru gıda ve erzak dolabı');
            ");

            // 8. Yönetici Kullanıcısını Oluştur
            $adminId   = 'admin_' . substr(md5(uniqid()), 0, 10);
            $hashedPass = password_hash($adminPass, PASSWORD_DEFAULT);

            $stmtAdmin = $pdo->prepare("INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`) VALUES (?, ?, ?, ?, 'ADMIN')");
            $stmtAdmin->execute([$adminId, $adminUser, $adminEmail, $hashedPass]);

            // Yöneticiye şehri ata
            $pdo->prepare("INSERT INTO `user_city_assignments` (`user_id`, `city_id`) VALUES (?, ?)")->execute([$adminId, $cityId]);

            // 9. .env Dosyasını Kullanıcı Formatında Eksiksiz Oluştur
            $envIcerik = "# --- UYGULAMA AYARLARI ---\n"
                       . "APP_ENV={$appEnv}\n"
                       . "APP_URL=\"{$appUrl}\"\n\n"
                       . "# --- VERİTABANI AYARLARI ---\n"
                       . "DB_HOST={$dbHost}\n"
                       . "DB_NAME={$dbName}\n"
                       . "DB_USER={$dbUser}\n"
                       . "DB_PASS={$dbPass}\n\n"
                       . "# Cron ve Güvenlik\n"
                       . "CRON_SECRET={$cronSecret}\n"
                       . "APP_URL={$appUrl}\n\n"
                       . "# --- GOOGLE GEMINI API ---\n"
                       . "GEMINI_API_KEY={$geminiApiKey}\n\n"
                       . "# --- SMTP MAIL AYARLARI ---\n"
                       . "SMTP_HOST={$smtpHost}\n"
                       . "SMTP_USER={$smtpUser}\n"
                       . "SMTP_PASS={$smtpPass}\n"
                       . "SMTP_PORT={$smtpPort}\n"
                       . "SMTP_SECURE={$smtpSecure}\n"
                       . "MAIL_FROM_ADDRESS=\"{$mailFromAddress}\"\n"
                       . "MAIL_FROM_NAME=\"{$mailFromName}\"\n";

            file_put_contents($envFile, $envIcerik);

            // 10. Kurulum Kilidi Oluştur
            file_put_contents($lockFile, "Kurulum tamamlandı: " . date('Y-m-d H:i:s'));

            $adim = 'tamamlandi';
        } catch (Exception $e) {
            $hata = 'Kurulum sırasında hata oluştu: ' . $e->getMessage();
        }
    }
}

// Otomatik varsayılan değerleri hazırla
$detectedProtocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$detectedHost      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$detectedDir       = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$defaultAppUrl     = $detectedProtocol . $detectedHost . $detectedDir;
$defaultCronSecret = bin2hex(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Takip Kurulum Sihirbazı</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen py-10 px-4 flex items-center justify-center">

<div class="max-w-2xl w-full bg-slate-800 border border-slate-700 rounded-3xl p-6 sm:p-8 shadow-2xl">
    
    <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-700/80">
        <div class="w-12 h-12 bg-blue-600/20 text-blue-400 rounded-2xl flex items-center justify-center text-2xl font-bold">📦</div>
        <div>
            <h1 class="text-xl font-bold text-white">Stok Takip Kurulum Sihirbazı</h1>
            <p class="text-xs text-slate-400">Veritabanı, ortam ayarları, mail, AI ve yönetici hesabı yapılandırması</p>
        </div>
    </div>

    <?php if (!empty($hata)): ?>
        <div class="mb-5 p-4 rounded-xl bg-red-900/30 border border-red-800 text-red-300 text-xs">
            <strong>Hata:</strong> <?= htmlspecialchars($hata) ?>
        </div>
    <?php endif; ?>

    <?php if ($adim === 'tamamlandi'): ?>
        <div class="text-center py-4 space-y-4">
            <div class="w-16 h-16 bg-emerald-500/20 text-emerald-400 rounded-2xl flex items-center justify-center text-3xl mx-auto">✅</div>
            <h2 class="text-2xl font-extrabold text-white">Kurulum Başarıyla Tamamlandı!</h2>
            <p class="text-slate-300 text-sm">
                Tüm veritabanı tabloları, <strong>21 hazır kategori</strong>, cins bazlı akıllı stok eşikleri ve <code>.env</code> yapılandırması oluşturuldu.
            </p>
            <div class="p-4 bg-slate-900/80 border border-slate-700 rounded-xl text-left text-xs space-y-2 text-slate-300 font-mono">
                <div>• Yönetici Hesabı: <strong class="text-blue-400"><?= htmlspecialchars($adminUser) ?></strong> (<?= htmlspecialchars($adminEmail) ?>)</div>
                <div>• Uygulama URL: <strong class="text-blue-400"><?= htmlspecialchars($appUrl) ?></strong></div>
                <div>• Cron Gizli Anahtarı: <strong class="text-amber-400"><?= htmlspecialchars($cronSecret) ?></strong></div>
                <div>• Gemini AI: <strong class="<?= !empty($geminiApiKey) ? 'text-emerald-400' : 'text-slate-500' ?>"><?= !empty($geminiApiKey) ? 'Aktif' : 'Tanımlanmadı (Opsiyonel)' ?></strong></div>
                <div>• SMTP E-Posta: <strong class="<?= !empty($smtpHost) ? 'text-emerald-400' : 'text-slate-500' ?>"><?= !empty($smtpHost) ? htmlspecialchars($smtpHost . ' (' . $mailFromAddress . ')') : 'Tanımlanmadı (Opsiyonel)' ?></strong></div>
                <div>• Konfigürasyon: <strong class="text-emerald-400">.env başarıyla yazıldı</strong></div>
            </div>

            <!-- Cron Bilgilendirme -->
            <div class="p-3.5 bg-slate-900/50 border border-slate-700 rounded-xl text-left text-xs text-slate-400 space-y-1">
                <div class="font-bold text-slate-200">⏱️ Otomatik Rapor Cron Komutu:</div>
                <p class="text-slate-400 text-[11px]">Sunucunuzun Crontab veya cPanel Cron Jobs bölümüne ekleyebilirsiniz:</p>
                <div class="p-2 bg-slate-950 rounded-lg text-emerald-400 font-mono text-[11px] break-all select-all">
                    0 9 * * * curl -s "<?= htmlspecialchars($appUrl) ?>/cron-mail.php?token=<?= htmlspecialchars($cronSecret) ?>" > /dev/null 2>&1
                </div>
            </div>

            <div class="pt-2">
                <a href="login.php" class="block w-full py-3.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white rounded-xl font-bold text-sm transition shadow-lg shadow-blue-600/30">
                    Sisteme Giriş Yap →
                </a>
            </div>
            <p class="text-xs text-rose-400 font-semibold pt-2">
                🔒 Güvenliğiniz için <code>install.php</code> dosyasını sunucunuzdan silebilirsiniz.
            </p>
        </div>
    <?php else: ?>
        <form method="POST" class="space-y-6">
            
            <!-- 1. Uygulama Ayarları -->
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-blue-400 mb-3 flex items-center gap-1.5">
                    <span>🌐 Uygulama Ayarları</span>
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Ortam (APP_ENV)</label>
                        <select name="app_env" class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                            <option value="production" <?= (($_POST['app_env'] ?? 'production') === 'production') ? 'selected' : '' ?>>production (Canlı)</option>
                            <option value="local" <?= (($_POST['app_env'] ?? '') === 'local') ? 'selected' : '' ?>>local (Geliştirici)</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Kurulu Web Dizini (APP_URL)</label>
                        <input type="url" name="app_url" value="<?= htmlspecialchars($_POST['app_url'] ?? $defaultAppUrl) ?>" required
                            placeholder="http://localhost/stok-takip"
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none font-mono">
                    </div>
                </div>
            </div>

            <!-- 2. Veritabanı Bilgileri -->
            <div class="pt-4 border-t border-slate-700/80">
                <h3 class="text-xs font-bold uppercase tracking-wider text-blue-400 mb-3 flex items-center gap-1.5">
                    <span>🗄️ Veritabanı Bağlantısı (MySQL)</span>
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">MySQL Sunucusu (DB_HOST)</label>
                        <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Veritabanı Adı (DB_NAME)</label>
                        <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'stok_takip') ?>" required
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Veritabanı Kullanıcısı (DB_USER)</label>
                        <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Veritabanı Şifresi (DB_PASS)</label>
                        <input type="password" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>" placeholder="Varsa şifreniz"
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
            </div>

            <!-- 3. Yönetici Hesabı -->
            <div class="pt-4 border-t border-slate-700/80">
                <h3 class="text-xs font-bold uppercase tracking-wider text-blue-400 mb-3 flex items-center gap-1.5">
                    <span>👤 İlk Yönetici Hesabı</span>
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Kullanıcı Adı</label>
                        <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">E-Posta Adresi</label>
                        <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? 'admin@example.com') ?>" required
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Yönetici Şifresi</label>
                        <input type="password" name="admin_pass" required minlength="6" placeholder="En az 6 karakter"
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
            </div>

            <!-- 4. Cron ve Güvenlik -->
            <div class="pt-4 border-t border-slate-700/80">
                <h3 class="text-xs font-bold uppercase tracking-wider text-amber-400 mb-3 flex items-center gap-1.5">
                    <span>🛡️ Cron ve Güvenlik Anahtarı</span>
                </h3>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Cron Güvenlik Tokenı (CRON_SECRET)</label>
                    <input type="text" name="cron_secret" value="<?= htmlspecialchars($_POST['cron_secret'] ?? $defaultCronSecret) ?>" required
                        class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-amber-300 focus:ring-2 focus:ring-amber-500 outline-none font-mono">
                    <p class="text-[11px] text-slate-400 mt-1">cron-mail.php çağrıldığında yetkisiz erişimi engeller. Otomatik güvenli bir anahtar üretilmiştir.</p>
                </div>
            </div>

            <!-- 5. Google Gemini API (Opsiyonel) -->
            <div class="pt-4 border-t border-slate-700/80">
                <h3 class="text-xs font-bold uppercase tracking-wider text-purple-400 mb-3 flex items-center gap-1.5">
                    <span>🤖 Google Gemini API (Opsiyonel - Kiler Şefi)</span>
                </h3>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Gemini API Anahtarı (GEMINI_API_KEY)</label>
                    <input type="text" name="gemini_api_key" value="<?= htmlspecialchars($_POST['gemini_api_key'] ?? '') ?>" placeholder="AIzaSy... (Opsiyonel)"
                        class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-purple-300 focus:ring-2 focus:ring-purple-500 outline-none font-mono">
                    <p class="text-[11px] text-slate-400 mt-1">Yapay Zeka Destekli Kiler Şefi (sef.php) için Google AI Studio anahtarınız. Boş bırakılabilir, sonradan .env içine eklenebilir.</p>
                </div>
            </div>

            <!-- 6. SMTP E-Posta Ayarları (Opsiyonel) -->
            <div class="pt-4 border-t border-slate-700/80">
                <h3 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3 flex items-center gap-1.5">
                    <span>📧 SMTP Mail Ayarları (Opsiyonel - Bildirim & Raporlar)</span>
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-300 mb-1">SMTP Sunucusu (SMTP_HOST)</label>
                        <input type="text" name="smtp_host" value="<?= htmlspecialchars($_POST['smtp_host'] ?? '') ?>" placeholder="mail.domain.com.tr"
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Port (SMTP_PORT)</label>
                        <input type="number" name="smtp_port" value="<?= htmlspecialchars($_POST['smtp_port'] ?? '587') ?>" placeholder="587"
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none font-mono">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Kullanıcı Adı (SMTP_USER)</label>
                        <input type="text" name="smtp_user" value="<?= htmlspecialchars($_POST['smtp_user'] ?? '') ?>" placeholder="bildirim@domain.com.tr"
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Şifre (SMTP_PASS)</label>
                        <input type="password" name="smtp_pass" value="<?= htmlspecialchars($_POST['smtp_pass'] ?? '') ?>" placeholder="E-posta şifreniz"
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Güvenlik (SMTP_SECURE)</label>
                        <select name="smtp_secure" class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                            <option value="tls" <?= (($_POST['smtp_secure'] ?? 'tls') === 'tls') ? 'selected' : '' ?>>tls (Önerilen - 587)</option>
                            <option value="ssl" <?= (($_POST['smtp_secure'] ?? '') === 'ssl') ? 'selected' : '' ?>>ssl (465)</option>
                            <option value="none" <?= (($_POST['smtp_secure'] ?? '') === 'none') ? 'selected' : '' ?>>none (Şifresiz)</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Gönderen E-Posta (MAIL_FROM_ADDRESS)</label>
                        <input type="text" name="mail_from_address" value="<?= htmlspecialchars($_POST['mail_from_address'] ?? '') ?>" placeholder="bildirim@domain.com.tr"
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Gönderen İsmi (MAIL_FROM_NAME)</label>
                        <input type="text" name="mail_from_name" value="<?= htmlspecialchars($_POST['mail_from_name'] ?? 'StokTakip Bildirim') ?>" placeholder="StokTakip Bildirim"
                            class="w-full p-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                    </div>
                </div>
            </div>

            <!-- Bilgilendirme Kartı -->
            <div class="p-3.5 bg-slate-900/60 border border-slate-700/80 rounded-xl text-xs text-slate-400 space-y-1">
                <div class="font-bold text-slate-300">✨ Kurulum Neleri İçerir?</div>
                <div>• 21 Kategori (Hazır Yemek, Bakliyat, Et, Süt, Temizlik vb.) ve yüzlerce alt kategori</div>
                <div>• Cins bazlı akıllı stok eşikleri ve standart birim kuralları (Et: Kg, diğerleri: Adet)</div>
                <div>• Varsayılan Konum (Ev > Mutfak > Buzdolabı & Kiler Dolabı)</div>
                <div>• Kullanım senaryonuza göre tam teşekküllü <code>.env</code> dosyası ve güvenlik kilidi</div>
            </div>

            <button type="submit" name="kurulum_yap" value="1"
                class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white rounded-xl font-bold text-sm transition shadow-lg shadow-blue-600/30 flex items-center justify-center gap-2">
                <span>⚡ Kurulumu Başlat ve .env Dosyasını Oluştur</span>
            </button>
        </form>
    <?php endif; ?>

</div>

</body>
</html>
