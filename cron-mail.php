<?php
// cron-mail.php - Otomatik E-posta Gönderici ve Loglayıcı
require 'db.php';

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

foreach ($urunler as $urun) {
    $skt = new DateTime($urun['expiry_date']);
    if ($skt < $bugun) continue; 

    $fark = $bugun->diff($skt);
    $kalanGun = (int)$fark->format('%a');

    if (in_array($kalanGun, $bildirimGunleri)) {
        $mailVarMi = true;
        $kritikUrunSayisi++;
        
        // GÜVENLİK DÜZELTMESİ: XSS riskini önlemek için htmlspecialchars() kullanıldı
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
    $konu = "StokTakip: $kritikUrunSayisi Ürün İçin Kritik Uyarı";
    
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
        <p><a href='https://bozer.com.tr/stok-takip' style='background:#2563eb; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Panele Git</a></p>
    </body>
    </html>
    ";

    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: StokTakip <bildirim@bozer.com.tr>" . "\r\n";

    // Her kullanıcıya gönder ve LOGLA
    foreach ($kullanicilar as $kullanici) {
        $gonderildi = mail($kullanici['email'], $konu, $mesajGovdesi, $headers);
        
        // Log Kaydı Oluştur
        try {
            $logId = uniqid('log_');
            $durum = $gonderildi ? 'sent' : 'failed';
            $ozet = "$kritikUrunSayisi adet ürün için SKT uyarısı gönderildi.";
            
            $stmt = $pdo->prepare("INSERT INTO notification_logs (id, user_email, subject, content_summary, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$logId, $kullanici['email'], $konu, $ozet, $durum]);
        } catch(Exception $e) {
            // Log hatası mail gönderimini durdurmasın
        }
    }
    echo "Bildirimler gönderildi ve loglandı.";
} else {
    echo "Bugün tetiklenen bir bildirim kuralı yok.";
}
?>
