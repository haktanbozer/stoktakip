<?php
// cron-mail.php - Konum Detaylı ve Filtreli Bildirim Sistemi
require 'db.php';

// PHPMailer (Varsa)
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
}
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- AYARLAR ---

// Eğer sadece belirli bir şehrin bildirimleri gitsin istiyorsanız ID'sini yazın (Örn: 'city_657...')
// Tüm şehirler için çalışsın istiyorsanız null bırakın.
$sadeceBuSehirId = null; 

// Bildirim günlerini çek
$stmt = $pdo->query("SELECT days FROM notification_thresholds");
$bildirimGunleri = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($bildirimGunleri)) $bildirimGunleri = [90, 60, 30, 7, 3, 1];

$bugun = new DateTime();

// --- 1. SORGULARI HAZIRLA ---

// Kullanıcıları Çek
$kullanicilar = $pdo->query("SELECT email, username FROM users")->fetchAll();

// Ürünleri ve TAM KONUM BİLGİLERİNİ Çek (JOIN İşlemi)
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

// Eğer şehir filtresi varsa sorguya ekle
$params = [];
if ($sadeceBuSehirId !== null) {
    $sql .= " AND ci.id = ?";
    $params[] = $sadeceBuSehirId;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$urunler = $stmt->fetchAll();

// --- 2. MAİL İÇERİĞİNİ OLUŞTUR ---

$gonderilecekMailIcerigi = "";
$mailVarMi = false;
$kritikUrunSayisi = 0;

foreach ($urunler as $urun) {
    if (empty($urun['expiry_date'])) continue;

    $skt = new DateTime($urun['expiry_date']);
    if ($skt < $bugun) continue; // Geçmişleri atla (veya tercihe göre dahil et)

    $fark = $bugun->diff($skt);
    $kalanGun = (int)$fark->format('%a');

    if (in_array($kalanGun, $bildirimGunleri)) {
        $mailVarMi = true;
        $kritikUrunSayisi++;
        
        $urunAdi = htmlspecialchars($urun['name']);
        $marka = htmlspecialchars($urun['brand']);
        
        // Konum bilgisini birleştir (Şehir > Mekan > Oda > Dolap)
        $konumBilgisi = "
            <div style='font-size:11px; color:#555;'>
                📍 <b>" . htmlspecialchars($urun['sehir_adi'] ?? '-') . "</b><br>
                " . htmlspecialchars($urun['mekan_adi'] ?? '') . " &rsaquo; 
                " . htmlspecialchars($urun['oda_adi'] ?? '') . " &rsaquo; 
                <b>" . htmlspecialchars($urun['dolap_adi'] ?? '') . "</b>
            </div>";

        $renk = ($kalanGun <= 3) ? '#dc2626' : '#ea580c'; // Kırmızı veya Turuncu

        $gonderilecekMailIcerigi .= "
        <tr>
            <td style='padding:8px; border-bottom:1px solid #eee; vertical-align:top;'>
                <b style='font-size:14px;'>{$urunAdi}</b><br>
                <span style='font-size:11px; color:#777;'>{$marka}</span>
            </td>
            <td style='padding:8px; border-bottom:1px solid #eee; vertical-align:top;'>
                {$konumBilgisi}
            </td>
            <td style='padding:8px; border-bottom:1px solid #eee; vertical-align:top; color:{$renk}; white-space:nowrap;'>
                <b>{$kalanGun} Gün</b>
            </td>
        </tr>";
    }
}

// --- 3. MAİL GÖNDERİMİ ---

if ($mailVarMi) {
    $konuHam = "⚠️ StokTakip: $kritikUrunSayisi Ürün İçin Kritik SKT Uyarısı";
    
    $mesajGovdesi = "
    <html>
    <body style='font-family: Arial, sans-serif; background-color:#f4f4f9; padding:20px;'>
        <div style='max-width:650px; margin:0 auto; background:white; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);'>
            <h3 style='color:#1e293b; border-bottom:2px solid #e2e8f0; padding-bottom:10px;'>Stok Takip Bildirimi</h3>
            <p style='color:#475569;'>Aşağıdaki ürünlerin son kullanma tarihleri yaklaşıyor:</p>
            
            <table style='width:100%; border-collapse: collapse; text-align:left;'>
                <thead>
                    <tr style='background-color:#f1f5f9; color:#334155;'>
                        <th style='padding:10px; border-bottom:2px solid #cbd5e1;'>Ürün Detayı</th>
                        <th style='padding:10px; border-bottom:2px solid #cbd5e1;'>Tam Konum</th>
                        <th style='padding:10px; border-bottom:2px solid #cbd5e1;'>Kalan Süre</th>
                    </tr>
                </thead>
                <tbody>
                    $gonderilecekMailIcerigi
                </tbody>
            </table>
            
            <div style='margin-top:20px; text-align:center;'>
                <a href='https://bozer.com.tr/stok-takip' style='background:#2563eb; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:14px;'>Panele Git ve İşlem Yap</a>
            </div>
            <p style='margin-top:20px; font-size:11px; color:#94a3b8; text-align:center;'>Bu e-posta otomatik oluşturulmuştur.</p>
        </div>
    </body>
    </html>
    ";

    // SMTP Ayarları (.env'den)
    $smtpHost = getenv('SMTP_HOST');
    $smtpUser = getenv('SMTP_USER');
    $smtpPass = getenv('SMTP_PASS');
    $smtpPort = getenv('SMTP_PORT') ?: 587;

    foreach ($kullanicilar as $kullanici) {
        $email = $kullanici['email'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

        $gonderildi = false;
        $hataMesaji = '';

        // A) PHPMailer
        if (class_exists('PHPMailer\PHPMailer\PHPMailer') && $smtpHost) {
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
                $hataMesaji = $mail->ErrorInfo;
            }
        } 
        // B) Native Mail
        else {
            if (preg_match( "/[\r\n]/", $email)) continue;
            $konuEncoded = "=?UTF-8?B?" . base64_encode($konuHam) . "?=";
            $headers = [
                "MIME-Version: 1.0",
                "Content-type: text/html; charset=UTF-8",
                "From: StokTakip <$smtpUser>",
                "X-Mailer: PHP/" . phpversion()
            ];
            $gonderildi = mail($email, $konuEncoded, $mesajGovdesi, implode("\r\n", $headers));
        }
        
        // Loglama
        try {
            if(isset($pdo)) {
                $durum = $gonderildi ? 'sent' : 'failed';
                $ozet = "$kritikUrunSayisi ürün. " . ($hataMesaji ? "Hata: $hataMesaji" : "");
                $stmt = $pdo->prepare("INSERT INTO notification_logs (id, user_email, subject, content_summary, status) VALUES (UUID(), ?, ?, ?, ?)");
                $stmt->execute([$email, $konuHam, $ozet, $durum]);
            }
        } catch(Exception $e) {}
    }
    echo "İşlem tamamlandı: $kritikUrunSayisi ürün bildirildi.";
} else {
    echo "Bildirim yapılacak ürün yok.";
}
?>
