<?php
// cron-mail.php - Manuel PHPMailer Yüklemeli

// Hataları Göster (Test için)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Veritabanı Bağlantısı
require __DIR__ . '/db.php';

// 2. PHPMailer Dosyalarını Manuel Dahil Et
// (vendor/autoload.php yerine bunları kullanıyoruz)
$phpMailerYolu = __DIR__ . '/PHPMailer/';

if (!file_exists($phpMailerYolu . 'PHPMailer.php')) {
    die("<h3>❌ HATA:</h3> PHPMailer dosyaları bulunamadı!<br>Lütfen 'PHPMailer' klasörünü oluşturup içine Exception.php, PHPMailer.php ve SMTP.php dosyalarını yüklediğinizden emin olun.<br>Aranan yol: " . $phpMailerYolu);
}

require $phpMailerYolu . 'Exception.php';
require $phpMailerYolu . 'PHPMailer.php';
require $phpMailerYolu . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "✅ PHPMailer yüklendi. İşlem başlıyor...<br>";

// --- AYARLAR ---
$sadeceBuSehirId = null; 

// Bildirim Eşiklerini Çek
$stmt = $pdo->query("SELECT days FROM notification_thresholds");
$bildirimGunleri = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($bildirimGunleri)) $bildirimGunleri = [90, 60, 30, 7, 3, 1];

$bugun = new DateTime();

// --- VERİLERİ HAZIRLA ---
$kullanicilar = $pdo->query("SELECT email, username FROM users")->fetchAll();

$sql = "SELECT p.*, 
               c.name as dolap_adi, 
               r.name as oda_adi, 
               l.name as mekan_adi, 
               ci.name as sehir_adi,
               ci.id as sehir_id
        FROM products p
        LEFT JOIN cabinets c ON p.cabinet_id = c.id
        LEFT JOIN rooms r ON c.room_id = r.id
        LEFT JOIN locations l ON r.location_id = l.id
        LEFT JOIN cities ci ON l.city_id = ci.id
        WHERE 1=1";

if ($sadeceBuSehirId !== null) {
    $sql .= " AND ci.id = " . $pdo->quote($sadeceBuSehirId);
}

$urunler = $pdo->query($sql)->fetchAll();

// --- HTML İÇERİĞİ OLUŞTUR ---
$gonderilecekMailIcerigi = "";
$mailVarMi = false;
$kritikUrunSayisi = 0;

foreach ($urunler as $urun) {
    if (empty($urun['expiry_date'])) continue;

    $skt = new DateTime($urun['expiry_date']);
    if ($skt < $bugun) continue; 

    $fark = $bugun->diff($skt);
    $kalanGun = (int)$fark->format('%a');

    if (in_array($kalanGun, $bildirimGunleri)) {
        $mailVarMi = true;
        $kritikUrunSayisi++;
        
        $konum = htmlspecialchars(($urun['sehir_adi'] ?? '-') . " > " . ($urun['mekan_adi'] ?? '') . " > " . ($urun['oda_adi'] ?? '') . " > " . ($urun['dolap_adi'] ?? ''));
        $renk = ($kalanGun <= 3) ? '#dc2626' : '#ea580c';

        $gonderilecekMailIcerigi .= "
        <tr>
            <td style='padding:8px; border-bottom:1px solid #eee;'><b>".htmlspecialchars($urun['name'])."</b><br><span style='font-size:11px; color:#666;'>".htmlspecialchars($urun['brand'])."</span></td>
            <td style='padding:8px; border-bottom:1px solid #eee; font-size:12px;'>{$konum}</td>
            <td style='padding:8px; border-bottom:1px solid #eee; color:{$renk};'><b>{$kalanGun} Gün</b></td>
        </tr>";
    }
}

// --- MAİL GÖNDERİMİ ---
if ($mailVarMi) {
    echo "⚠️ $kritikUrunSayisi adet bildirim bulundu. Mail gönderiliyor...<br>";

    $konu = "⚠️ StokTakip: $kritikUrunSayisi Ürün İçin Kritik Uyarı";
    $mesaj = "
    <html>
    <body style='font-family: sans-serif; padding:20px;'>
        <h3>Stok Takip Bildirimi</h3>
        <table style='width:100%; border-collapse: collapse; text-align:left;'>
            <tr style='background:#f1f5f9;'>
                <th style='padding:10px; border-bottom:2px solid #ddd;'>Ürün</th>
                <th style='padding:10px; border-bottom:2px solid #ddd;'>Konum</th>
                <th style='padding:10px; border-bottom:2px solid #ddd;'>Süre</th>
            </tr>
            $gonderilecekMailIcerigi
        </table>
        <p><a href='https://bozer.com.tr/stok-takip'>Panele Git</a></p>
    </body>
    </html>";

    // SMTP Ayarları
    $smtpHost = getenv('SMTP_HOST');
    $smtpUser = getenv('SMTP_USER');
    $smtpPass = getenv('SMTP_PASS');
    $smtpPort = getenv('SMTP_PORT') ?: 587;

    if (!$smtpHost || !$smtpUser || !$smtpPass) {
        die("<h3>❌ HATA:</h3> .env dosyasında SMTP ayarları eksik!");
    }

    $mail = new PHPMailer(true);
    
    try {
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = $smtpPort;

        $mail->setFrom($smtpUser, 'StokTakip Bildirim');
        
        $gonderilenSayisi = 0;
        foreach ($kullanicilar as $kullanici) {
            if (filter_var($kullanici['email'], FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($kullanici['email']);
                $mail->isHTML(true);
                $mail->Subject = $konu;
                $mail->Body    = $mesaj;
                $mail->send();
                $gonderilenSayisi++;
                $mail->clearAddresses();
                
                // Logla
                if(isset($pdo)) {
                    $pdo->prepare("INSERT INTO notification_logs (id, user_email, subject, content_summary, status) VALUES (UUID(), ?, ?, ?, 'sent')")->execute([$kullanici['email'], $konu, "$kritikUrunSayisi ürün"]);
                }
            }
        }
        
        echo "🚀 <b>BAŞARILI:</b> Toplam $gonderilenSayisi kişiye mail gönderildi.<br>";

    } catch (Exception $e) {
        echo "<h3>❌ MAİL GÖNDERİM HATASI:</h3> " . $mail->ErrorInfo . "<br>";
        if(isset($pdo)) {
             $pdo->prepare("INSERT INTO notification_logs (id, user_email, subject, content_summary, status) VALUES (UUID(), 'system', ?, ?, 'failed')")->execute(["Mail Hatası", $mail->ErrorInfo]);
        }
    }

} else {
    echo "✅ Bugün için gönderilecek bir bildirim yok.<br>";
}
?>