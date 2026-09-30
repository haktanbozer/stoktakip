<?php
// cron-mail.php - Tarih ve Kritik Stok Bildirim Motoru

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Veritabanı Bağlantısı
require __DIR__ . '/db.php';

// 2. PHPMailer Dosyalarını Dahil Et
$phpMailerYolu = __DIR__ . '/PHPMailer/';

if (!file_exists($phpMailerYolu . 'PHPMailer.php')) {
    die("<h3>❌ HATA:</h3> PHPMailer dosyaları bulunamadı!<br>Aranan yol: " . $phpMailerYolu);
}

require $phpMailerYolu . 'Exception.php';
require $phpMailerYolu . 'PHPMailer.php';
require $phpMailerYolu . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "✅ PHPMailer yüklendi. İşlem başlıyor...<br>";

// --- AYARLAR ---
$sadeceBuSehirId = null; 

// Bildirim Eşiklerini Çek (Gün bazlı)
$stmt = $pdo->query("SELECT days FROM notification_thresholds");
$bildirimGunleri = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($bildirimGunleri)) {
    $bildirimGunleri = [90, 60, 30, 7, 3, 1];
}

$bugun = new DateTime('today');

// --- VERİLERİ HAZIRLA ---
$kullanicilar = $pdo->query("SELECT email, username FROM users")->fetchAll(PDO::FETCH_ASSOC);

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

$urunler = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// --- HTML İÇERİĞİ OLUŞTUR ---
$tarihUyarilariHtml = "";
$stokUyarilariHtml  = "";
$toplamUyariSayisi  = 0;

foreach ($urunler as $urun) {
    $konum = htmlspecialchars(
        ($urun['sehir_adi'] ?? '-') . " > " . 
        ($urun['mekan_adi'] ?? '-') . " > " . 
        ($urun['oda_adi'] ?? '-') . " > " . 
        ($urun['dolap_adi'] ?? '-') . 
        (!empty($urun['shelf_location']) ? " (" . $urun['shelf_location'] . ")" : "")
    );

    // 1. KRİTİK STOK VE BİTEN BAKLİYAT KONTROLÜ
    $miktar        = (float)$urun['quantity'];
    $kritikSeviye  = isset($urun['min_quantity']) ? (float)$urun['min_quantity'] : 1.0;

    if ($miktar <= $kritikSeviye) {
        $toplamUyariSayisi++;
        $stokDurumMetni = ($miktar <= 0) 
            ? "<span style='color:#dc2626; font-weight:bold;'>TÜKENDİ (0 {$urun['unit']})</span>" 
            : "<span style='color:#ea580c; font-weight:bold;'>AZALDI ({$miktar} / Min: {$kritikSeviye} {$urun['unit']})</span>";

        $stokUyarilariHtml .= "
        <tr>
            <td style='padding:8px; border-bottom:1px solid #eee;'>
                <b>" . htmlspecialchars($urun['name']) . "</b><br>
                <span style='font-size:11px; color:#666;'>" . htmlspecialchars($urun['brand'] ?? '') . "</span>
            </td>
            <td style='padding:8px; border-bottom:1px solid #eee; font-size:12px;'>{$konum}</td>
            <td style='padding:8px; border-bottom:1px solid #eee; font-size:12px;'>{$stokDurumMetni}</td>
        </tr>";
    }

    // 2. SON TÜKETİM TARİHİ (STT) KONTROLÜ
    if (!empty($urun['expiry_date'])) {
        $skt = new DateTime($urun['expiry_date']);
        $fark = $bugun->diff($skt);
        $kalanGun = (int)$fark->format('%r%a');

        // Günü geçmiş veya bildirim eşiğine denk gelmişse
        if ($kalanGun < 0 || in_array($kalanGun, $bildirimGunleri)) {
            $toplamUyariSayisi++;
            
            if ($kalanGun < 0) {
                $durumRenk = "#991b1b";
                $durumMetni = abs($kalanGun) . " Gün Önce Geçti!";
            } elseif ($kalanGun <= 3) {
                $durumRenk = "#dc2626";
                $durumMetni = "{$kalanGun} Gün Kaldı";
            } else {
                $durumRenk = "#ea580c";
                $durumMetni = "{$kalanGun} Gün Kaldı";
            }

            $acikPaketUyarisi = (!empty($urun['is_opened']) && $urun['is_opened'] == 1) 
                ? "<br><span style='font-size:11px; color:#b91c1c;'>⚠️ Açık Paket (Açılış: " . htmlspecialchars($urun['opened_at'] ?? '-') . ")</span>" 
                : "";

            $tarihUyarilariHtml .= "
            <tr>
                <td style='padding:8px; border-bottom:1px solid #eee;'>
                    <b>" . htmlspecialchars($urun['name']) . "</b><br>
                    <span style='font-size:11px; color:#666;'>" . htmlspecialchars($urun['brand'] ?? '') . "</span>
                    {$acikPaketUyarisi}
                </td>
                <td style='padding:8px; border-bottom:1px solid #eee; font-size:12px;'>{$konum}</td>
                <td style='padding:8px; border-bottom:1px solid #eee; color:{$durumRenk}; font-weight:bold;'>{$durumMetni}</td>
            </tr>";
        }
    }
}

// --- MAİL GÖNDERİMİ ---
if ($toplamUyariSayisi > 0) {
    echo "⚠️ {$toplamUyariSayisi} adet bildirim unsuru tespit edildi. Mail hazırlanıyor...<br>";

    $govdeIcerik = "";

    if (!empty($stokUyarilariHtml)) {
        $govdeIcerik .= "
        <h4 style='color:#b91c1c; margin-top:20px; margin-bottom:8px;'>📦 Kritik Stok & Biten Ürünler</h4>
        <table style='width:100%; border-collapse: collapse; text-align:left; margin-bottom:20px;'>
            <tr style='background:#fee2e2;'>
                <th style='padding:8px; border-bottom:2px solid #f87171;'>Ürün</th>
                <th style='padding:8px; border-bottom:2px solid #f87171;'>Konum</th>
                <th style='padding:8px; border-bottom:2px solid #f87171;'>Mevcut Stok</th>
            </tr>
            {$stokUyarilariHtml}
        </table>";
    }

    if (!empty($tarihUyarilariHtml)) {
        $govdeIcerik .= "
        <h4 style='color:#c2410c; margin-top:20px; margin-bottom:8px;'>⏳ Son Tüketim Tarihi Yaklaşanlar / Geçenler</h4>
        <table style='width:100%; border-collapse: collapse; text-align:left;'>
            <tr style='background:#ffedd5;'>
                <th style='padding:8px; border-bottom:2px solid #fb923c;'>Ürün</th>
                <th style='padding:8px; border-bottom:2px solid #fb923c;'>Konum</th>
                <th style='padding:8px; border-bottom:2px solid #fb923c;'>Kalan Süre</th>
            </tr>
            {$tarihUyarilariHtml}
        </table>";
    }

    $mesaj = "
    <html>
    <body style='font-family: Arial, sans-serif; padding:15px; color:#333; line-height:1.5;'>
        <div style='max-width:650px; margin:0 auto; border:1px solid #e2e8f0; border-radius:8px; padding:20px;'>
            <h2 style='color:#0f172a; margin-top:0;'>🏠 Ev Stok Takip Raporu</h2>
            <p style='color:#64748b; font-size:13px;'>Tarih: " . date('d.m.Y H:i') . "</p>
            {$govdeIcerik}
            <div style='margin-top:25px; padding-top:15px; border-top:1px solid #e2e8f0; text-align:center;'>
                <a href='https://bozer.com.tr/stok-takip' style='background:#2563eb; color:#fff; text-decoration:none; padding:10px 18px; border-radius:5px; font-weight:bold; font-size:13px; display:inline-block;'>Stok Takip Paneline Git</a>
            </div>
        </div>
    </body>
    </html>";

    $konu = "⚠️ StokTakip: {$toplamUyariSayisi} Ürün İçin Durum Özeti";

    // SMTP Ayarları (.env / getenv)
    $smtpHost = getenv('SMTP_HOST');
    $smtpUser = getenv('SMTP_USER');
    $smtpPass = getenv('SMTP_PASS');
    $smtpPort = getenv('SMTP_PORT') ?: 587;

    if (!$smtpHost || !$smtpUser || !$smtpPass) {
        die("<h3>❌ HATA:</h3> SMTP ortam değişkenleri eksik!");
    }

    $mail = new PHPMailer(true);

    try {
        $mail->CharSet   = 'UTF-8';
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpPort;

        $mail->setFrom($smtpUser, 'StokTakip Bildirim');
        $mail->isHTML(true);
        $mail->Subject = $konu;
        $mail->Body    = $mesaj;

        $gonderilenSayisi = 0;
        foreach ($kullanicilar as $kullanici) {
            if (filter_var($kullanici['email'], FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($kullanici['email']);
                $mail->send();
                $gonderilenSayisi++;
                $mail->clearAddresses();

                // Bildirim logu at
                $stmtLog = $pdo->prepare("INSERT INTO notification_logs (id, user_email, subject, content_summary, status) VALUES (UUID(), ?, ?, ?, 'sent')");
                $stmtLog->execute([$kullanici['email'], $konu, "{$toplamUyariSayisi} bildirim"]);
            }
        }

        echo "🚀 <b>BAŞARILI:</b> Toplam {$gonderilenSayisi} kullanıcıya özet rapor gönderildi.<br>";

    } catch (Exception $e) {
        echo "<h3>❌ MAİL GÖNDERİM HATASI:</h3> " . $mail->ErrorInfo . "<br>";
        $stmtErr = $pdo->prepare("INSERT INTO notification_logs (id, user_email, subject, content_summary, status) VALUES (UUID(), 'system', ?, ?, 'failed')");
        $stmtErr->execute(["Mail Hatası", $mail->ErrorInfo]);
    }

} else {
    echo "✅ Bugün için gönderilecek bir stok veya tarih bildirimi yok.<br>";
}
?>