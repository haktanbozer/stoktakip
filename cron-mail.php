<?php
// cron-mail.php - Otomatik E-posta Gönderici (PHPMailer + Native Fallback)
require 'db.php';

// PHPMailer'ı dahil et (Eğer Composer ile yüklendiyse)
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
}

// PHPMailer sınıflarını kullan (Varsa)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// 1. Veritabanından Bildirim Ayarlarını Çek
$stmt = $pdo->query("SELECT days FROM notification_thresholds");
$bildirimGunleri = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($bildirimGunleri)) {
    $bildirimGunleri = [90, 60, 30, 7, 3, 1];
}

$bugun = new DateTime();
$kullanicilar = $pdo->query("SELECT email, username FROM users")->fetchAll();
$urunler = $pdo->query("SELECT * FROM products")->fetchAll();

$gonderilecekMailIcerigi = "";
$mailVarMi = false;
$kritikUrunSayisi = 0;

// Ürünleri Kontrol Et
foreach ($urunler as $urun) {
    if (empty($urun['expiry_date'])) continue;

    $skt = new DateTime($urun['expiry_date']);
    if ($skt < $bugun) continue; 

    $fark = $bugun->diff($skt);
    $kalanGun = (int)$fark->format('%a');

    if (in_array($kalanGun, $bildirimGunleri)) {
        $mailVarMi = true;
        $kritikUrunSayisi++;
        
        // GÜVENLİK: XSS Koruması
        $urunAdiGvnli = htmlspecialchars($urun['name']);
        $markaGvnli = htmlspecialchars($urun['brand']);
        
        $gonderilecekMailIcerigi .= "
        <tr>
            <td style='padding:5px; border-bottom:1px solid #ddd;'><b>{$urunAdiGvnli}</b></td>
            <td style='padding:5px; border-bottom:1px solid #ddd;'>{$markaGvnli}</td>
            <td style='padding:5px; border-bottom:1px solid #ddd; color:red;'><b>{$kalanGun} Gün</b></td>
        </tr>";
    }
}

if ($mailVarMi) {
    $konuHam = "StokTakip: $kritikUrunSayisi Ürün İçin Kritik Uyarı";
    
    // HTML Mail Şablonu
    $mesajGovdesi = "
    <html>
    <body style='font-family: Arial, sans-serif;'>
        <h3>Merhaba,</h3>
        <p>Aşağıdaki ürünlerin son kullanma tarihi için belirlediğiniz uyarı gününe gelindi:</p>
        <table style='width:100%; max-width:600px; border-collapse: collapse; text-align:left;'>
            <thead>
                <tr style='background-color:#f8f9fa;'>
                    <th style='padding:10px; border-bottom:2px solid #ddd;'>Ürün</th>
                    <th style='padding:10px; border-bottom:2px solid #ddd;'>Marka</th>
                    <th style='padding:10px; border-bottom:2px solid #ddd;'>Kalan Süre</th>
                </tr>
            </thead>
            <tbody>
                $gonderilecekMailIcerigi
            </tbody>
        </table>
        <br>
        <p><a href='https://Example.com/stok-takip' style='background:#2563eb; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Panele Git</a></p>
    </body>
    </html>
    ";

    // SMTP Ayarlarını .env'den al (Varsa)
    $smtpHost = getenv('SMTP_HOST');
    $smtpUser = getenv('SMTP_USER');
    $smtpPass = getenv('SMTP_PASS');
    $smtpPort = getenv('SMTP_PORT') ?: 587;

    // Kullanıcılara Gönderim Döngüsü
    foreach ($kullanicilar as $kullanici) {
        $email = $kullanici['email'];
        $gonderildi = false;
        $hataMesaji = '';

        // 1. GÜVENLİK: Email Validasyonu
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Geçersiz emaili logla ve geç
            if(function_exists('auditLog')) auditLog('HATA', "Geçersiz e-posta formatı: $email");
            continue; 
        }

        // 2. GÖNDERİM YÖNTEMİ SEÇİMİ (PHPMailer vs Native Mail)
        if (class_exists('PHPMailer\PHPMailer\PHPMailer') && $smtpHost) {
            // --- A) PHPMailer İle Gönderim (SMTP) ---
            try {
                $mail = new PHPMailer(true);
                $mail->CharSet = 'UTF-8';
                $mail->isSMTP();
                $mail->Host       = $smtpHost;
                $mail->SMTPAuth   = true;
                $mail->Username   = $smtpUser;
                $mail->Password   = $smtpPass;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
                $mail->Port       = $smtpPort;

                $mail->setFrom($smtpUser, 'StokTakip Bildirim');
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = $konuHam;
                $mail->Body    = $mesajGovdesi;

                $mail->send();
                $gonderildi = true;
            } catch (Exception $e) {
                $gonderildi = false;
                $hataMesaji = "PHPMailer Hatası: {$mail->ErrorInfo}";
            }
        } else {
            // --- B) Native Mail() İle Gönderim (Fallback) ---
            // GÜVENLİK: Header Injection Koruması
            if (preg_match( "/[\r\n]/", $email)) { continue; } // Email başlıklarında yeni satır olamaz
            
            // Konu başlığı UTF-8 fix
            $konuEncoded = "=?UTF-8?B?" . base64_encode($konuHam) . "?=";
            
            $headers = [
                "MIME-Version: 1.0",
                "Content-type: text/html; charset=UTF-8",
                "From: StokTakip <noreply@Example.com>", // Burayı kendi domaininize göre düzenleyin
                "X-Mailer: PHP/" . phpversion()
            ];
            
            $gonderildi = mail($email, $konuEncoded, $mesajGovdesi, implode("\r\n", $headers));
            if (!$gonderildi) $hataMesaji = "Native mail() fonksiyonu başarısız oldu.";
        }
        
        // 3. LOGLAMA
        try {
            $logId = uniqid('log_');
            $durum = $gonderildi ? 'sent' : 'failed';
            $ozet = "$kritikUrunSayisi adet ürün için SKT uyarısı.";
            if (!$gonderildi) $ozet .= " (" . substr($hataMesaji, 0, 50) . ")";
            
            $stmt = $pdo->prepare("INSERT INTO notification_logs (id, user_email, subject, content_summary, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$logId, $email, $konuHam, $ozet, $durum]);
        } catch(Exception $e) {}
    }
    echo "Bildirim döngüsü tamamlandı.";
} else {
    echo "Bugün tetiklenen bir bildirim kuralı yok.";
}
?>
