<?php
// cron-mail.php - Günlük SKT ve Haftalık Pazartesi Alışveriş Raporu Motoru

// 1. Veritabanı ve Ortam Ayarlarını Dahil Et
require_once __DIR__ . '/db.php';

// --- GÜVENLİK: Web Erişim Koruması ---
if (php_sapi_name() !== 'cli') {
    // Ortam değişkeni yoksa varsayılan güvenli anahtar kullanılır
    $cronSecret = getenv('CRON_SECRET') ?: ($_ENV['CRON_SECRET'] ?? ($_SERVER['CRON_SECRET'] ?? 'stok_cron_2026_key'));
    $gelenToken = $_GET['token'] ?? $_GET['secret'] ?? '';
    if (empty($cronSecret) || !hash_equals((string)$cronSecret, (string)$gelenToken)) {
        http_response_code(403);
        if (function_exists('sistemLogla')) {
            sistemLogla("cron-mail.php'ye yetkisiz web erişimi: IP=" . ($_SERVER['REMOTE_ADDR'] ?? '-'), 'SECURITY');
        }
        die('403 Forbidden - Gecersiz veya eksik Cron Token.');
    }
}

// --- GÜVENLİK: Production'da hataları gizle, loga yaz ---
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// 2. PHPMailer Dosyalarını Dahil Et
$phpMailerYolu = __DIR__ . '/PHPMailer/';

if (!file_exists($phpMailerYolu . 'PHPMailer.php')) {
    sistemLogla("PHPMailer bulunamadı: " . $phpMailerYolu, 'CRITICAL');
    die("PHPMailer bulunamadı.");
}

require $phpMailerYolu . 'Exception.php';
require $phpMailerYolu . 'PHPMailer.php';
require $phpMailerYolu . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- RAPOR TİPİNİ BELİRLE ---
// 'skt'        -> Sadece Günlük Son Kullanma Tarihi Raporu
// 'alisveris'  -> Sadece Haftalık Alışveriş Listesi Önerisi (Pazartesi)
// Parametre yoksa: Her gün 'skt', eğer Pazartesi ise hem 'skt' hem 'alisveris' çalışır.
$raporTipi = $_GET['tip'] ?? '';
if (php_sapi_name() === 'cli') {
    foreach ($argv as $arg) {
        if (strpos($arg, '--tip=') === 0) {
            $raporTipi = trim(substr($arg, 6));
        }
    }
}

$isPazartesi = ((int)date('N') === 1); // 1 = Pazartesi
$calisacakRaporlar = [];

if ($raporTipi === 'skt') {
    $calisacakRaporlar = ['skt'];
} elseif ($raporTipi === 'alisveris') {
    $calisacakRaporlar = ['alisveris'];
} elseif ($raporTipi === 'hepsi') {
    $calisacakRaporlar = ['skt', 'alisveris'];
} else {
    // Otomatik Mod: Günlük her zaman SKT kontrolü, Pazartesi ise ayrıca Alışveriş Listesi
    $calisacakRaporlar = ['skt'];
    if ($isPazartesi) {
        $calisacakRaporlar[] = 'alisveris';
    }
}

// Bildirim Eşiklerini Çek (Gün bazlı)
$stmt = $pdo->query("SELECT days FROM notification_thresholds");
$bildirimGunleri = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($bildirimGunleri)) {
    $bildirimGunleri = [90, 60, 30, 7, 3, 1];
}

$bugun = new DateTime('today');

// --- KULLANICILAR VE ŞEHİR YETKİLERİ ---
$kullanicilariCek = $pdo->query("SELECT u.id as user_id, u.email, u.username, u.role,
    GROUP_CONCAT(uca.city_id) as sehir_idleri
    FROM users u
    LEFT JOIN user_city_assignments uca ON u.id = uca.user_id
    GROUP BY u.id");
$kullanicilar = $kullanicilariCek->fetchAll(PDO::FETCH_ASSOC);

// Tipler tablosundaki min_threshold değerleri
$tipBilgileri = $pdo->query("SELECT id, name, category, default_unit, min_threshold FROM product_types")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

// Ürünleri getirecek ortak fonksiyon (şehir filtreli)
function urunleriGetir(PDO $pdo, ?array $sehirIdler = null): array {
    if ($sehirIdler && count($sehirIdler) > 0) {
        $placeholders = implode(',', array_fill(0, count($sehirIdler), '?'));
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
                WHERE ci.id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($sehirIdler);
    } else {
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
                LEFT JOIN cities ci ON l.city_id = ci.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- SMTP AYARLARI ---
$smtpHost = getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? '');
$smtpUser = getenv('SMTP_USER') ?: ($_ENV['SMTP_USER'] ?? '');
$smtpPass = getenv('SMTP_PASS') ?: ($_ENV['SMTP_PASS'] ?? '');
$smtpPort = (int)(getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? 587));

if (!$smtpHost || !$smtpUser || !$smtpPass) {
    if (function_exists('sistemLogla')) {
        sistemLogla("SMTP ortam değişkenleri eksik (.env), cron çalışamadı.", 'CRITICAL');
    }
    die("SMTP ayarları eksik. Lütfen .env dosyasındaki SMTP_HOST, SMTP_USER, SMTP_PASS alanlarını kontrol edin.");
}

function createMailer($smtpHost, $smtpUser, $smtpPass, $smtpPort) {
    $mail = new PHPMailer(true);
    $mail->CharSet   = 'UTF-8';
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $smtpPort;
    $mail->setFrom($smtpUser, 'Ev Stok Takip');
    $mail->isHTML(true);
    return $mail;
}

$toplamGonderilenSkt = 0;
$toplamGonderilenAlisveris = 0;

// ============================================================================
// DÖNGÜ: HER KULLANICI İÇİN ŞEHİR İZOLELİ RAPOR OLUŞTUR
// ============================================================================
foreach ($kullanicilar as $kullanici) {
    if (!filter_var($kullanici['email'], FILTER_VALIDATE_EMAIL)) continue;

    $sehirIdler = null;
    if (!empty($kullanici['sehir_idleri'])) {
        $sehirIdler = explode(',', $kullanici['sehir_idleri']);
    } elseif (($kullanici['role'] ?? '') !== 'ADMIN') {
        continue;
    }

    $urunler = urunleriGetir($pdo, $sehirIdler);
    if (empty($urunler)) continue;

    // ────────────────────────────────────────────────────────────────────────
    // RAPOR 1: GÜNLÜK SON KULLANMA TARİHİ (SKT) RAPORU
    // ────────────────────────────────────────────────────────────────────────
    if (in_array('skt', $calisacakRaporlar)) {
        $sktSatirlariHtml = "";
        $sktUyarisiSayisi = 0;

        foreach ($urunler as $urun) {
            if (empty($urun['expiry_date'])) continue;

            $skt = new DateTime($urun['expiry_date']);
            $fark = $bugun->diff($skt);
            $kalanGun = (int)$fark->format('%r%a');

            // Günü geçmiş veya bildirim gün eşiğinde ise veya son 30 günün içindeyse
            if ($kalanGun < 0 || in_array($kalanGun, $bildirimGunleri) || $kalanGun <= 15) {
                $sktUyarisiSayisi++;

                if ($kalanGun < 0) {
                    $badgeBg = "#fee2e2";
                    $badgeColor = "#991b1b";
                    $durumMetni = abs($kalanGun) . " Gün Önce Geçti!";
                } elseif ($kalanGun <= 3) {
                    $badgeBg = "#fef2f2";
                    $badgeColor = "#dc2626";
                    $durumMetni = ($kalanGun == 0) ? "Bugün Son Gün!" : "{$kalanGun} Gün Kaldı!";
                } elseif ($kalanGun <= 7) {
                    $badgeBg = "#ffedd5";
                    $badgeColor = "#ea580c";
                    $durumMetni = "{$kalanGun} Gün Kaldı";
                } else {
                    $badgeBg = "#fef9c3";
                    $badgeColor = "#854d0e";
                    $durumMetni = "{$kalanGun} Gün Kaldı";
                }

                $konum = htmlspecialchars(
                    ($urun['mekan_adi'] ?? '-') . " › " . 
                    ($urun['oda_adi'] ?? '-') . " › " . 
                    ($urun['dolap_adi'] ?? '-') . 
                    (!empty($urun['shelf_location']) ? " (" . $urun['shelf_location'] . ")" : "")
                );

                $acikPaketUyarisi = (!empty($urun['is_opened']) && $urun['is_opened'] == 1) 
                    ? "<br><span style='display:inline-block; font-size:10px; background:#fef3c7; color:#92400e; padding:1px 5px; border-radius:3px; font-weight:bold; margin-top:2px;'>⚠️ AÇIK PAKET (Açılış: " . htmlspecialchars($urun['opened_at'] ?? '-') . ")</span>" 
                    : "";

                $sktSatirlariHtml .= "
                <tr>
                    <td style='padding:10px 12px; border-bottom:1px solid #f1f5f9;'>
                        <b style='color:#0f172a; font-size:14px;'>" . htmlspecialchars($urun['name']) . "</b>
                        " . ($urun['brand'] && $urun['brand'] !== 'Açık / Markasız' ? "<span style='color:#64748b; font-size:12px;'> — " . htmlspecialchars($urun['brand']) . "</span>" : "") . "
                        {$acikPaketUyarisi}
                        <div style='font-size:11px; color:#94a3b8; margin-top:2px;'>📍 {$konum}</div>
                    </td>
                    <td style='padding:10px 12px; border-bottom:1px solid #f1f5f9; text-align:center;'>
                        <span style='font-size:12px; font-weight:bold; color:#334155;'>" . (float)$urun['quantity'] . " " . htmlspecialchars($urun['unit']) . "</span>
                    </td>
                    <td style='padding:10px 12px; border-bottom:1px solid #f1f5f9; text-align:right;'>
                        <span style='background:{$badgeBg}; color:{$badgeColor}; font-weight:bold; font-size:11px; padding:4px 8px; border-radius:6px; display:inline-block;'>
                            {$durumMetni}
                        </span>
                        <div style='font-size:10px; color:#94a3b8; margin-top:3px;'>" . date('d.m.Y', strtotime($urun['expiry_date'])) . "</div>
                    </td>
                </tr>";
            }
        }

        // Eğer dikkat çeken SKT varsa e-posta gönder
        if ($sktUyarisiSayisi > 0) {
            $sktMailGovde = "
            <html><body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background:#f8fafc; padding:20px; color:#1e293b; line-height:1.5;'>
                <div style='max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);'>
                    <div style='background:#ea580c; padding:20px 24px; color:#ffffff;'>
                        <div style='font-size:12px; text-transform:uppercase; letter-spacing:1px; opacity:0.9;'>Günlük Stok Takip Raporu</div>
                        <h2 style='margin:4px 0 0 0; font-size:20px; font-weight:bold;'>⏳ Son Tüketim Tarihi Yaklaşanlar</h2>
                    </div>
                    <div style='padding:20px 24px;'>
                        <p style='margin-top:0; font-size:13px; color:#64748b;'>
                            Merhaba <b>" . htmlspecialchars($kullanici['username']) . "</b>, evinizdeki <b>{$sktUyarisiSayisi}</b> ürünün son kullanma tarihi yaklaştı veya doldu. İsrafı önlemek için öncelikle tüketmenizi öneririz:
                        </p>
                        <table style='width:100%; border-collapse:collapse; text-align:left; margin-top:16px;'>
                            <thead>
                                <tr style='background:#f8fafc; font-size:11px; text-transform:uppercase; color:#64748b;'>
                                    <th style='padding:8px 12px; border-bottom:2px solid #e2e8f0;'>Ürün & Konum</th>
                                    <th style='padding:8px 12px; border-bottom:2px solid #e2e8f0; text-align:center;'>Miktar</th>
                                    <th style='padding:8px 12px; border-bottom:2px solid #e2e8f0; text-align:right;'>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$sktSatirlariHtml}
                            </tbody>
                        </table>
                        <div style='margin-top:24px; text-align:center;'>
                            <a href='https://bozer.com.tr/stok-takip/envanter.php' style='background:#ea580c; color:#ffffff; text-decoration:none; padding:10px 20px; border-radius:8px; font-weight:bold; font-size:13px; display:inline-block;'>Envanteri Görüntüle</a>
                        </div>
                    </div>
                    <div style='background:#f1f5f9; padding:12px 24px; font-size:11px; color:#94a3b8; text-align:center;'>
                        Bu bilgilendirme e-postası her gün otomatik olarak gönderilir. Tarih: " . date('d.m.Y H:i') . "
                    </div>
                </div>
            </body></html>";

            $konu = "⏳ Günlük SKT Raporu: {$sktUyarisiSayisi} Ürünün Tarihi Yaklaşıyor (" . date('d.m.Y') . ")";

            try {
                $mail = createMailer($smtpHost, $smtpUser, $smtpPass, $smtpPort);
                $mail->Subject = $konu;
                $mail->Body    = $sktMailGovde;
                $mail->addAddress($kullanici['email']);
                $mail->send();
                $toplamGonderilenSkt++;

                $stmtLog = $pdo->prepare("INSERT INTO notification_logs (id, user_email, subject, content_summary, status) VALUES (UUID(), ?, ?, ?, 'sent')");
                $stmtLog->execute([$kullanici['email'], $konu, "{$sktUyarisiSayisi} SKT uyarısı"]);
            } catch (Exception $e) {
                sistemLogla("SKT mail hatası: {$kullanici['email']} - " . $e->getMessage(), 'ERROR');
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // RAPOR 2: HAFTALIK PAZARTESİ ALIŞVERİŞ LİSTESİ VE KRİTİK EŞİK RAPORU
    // ────────────────────────────────────────────────────────────────────────
    if (in_array('alisveris', $calisacakRaporlar)) {
        $cinsGruplari = [];
        $tekilKritikler = [];

        foreach ($urunler as $urun) {
            $miktar = (float)$urun['quantity'];
            $konum  = htmlspecialchars(
                ($urun['mekan_adi'] ?? '-') . " › " . 
                ($urun['oda_adi'] ?? '-') . " › " . 
                ($urun['dolap_adi'] ?? '-')
            );

            $tipId = !empty($urun['product_type_id']) ? $urun['product_type_id'] : null;

            if ($tipId) {
                if (!isset($cinsGruplari[$tipId])) {
                    $cinsAdi   = $tipBilgileri[$tipId]['name'] ?? ($urun['product_type'] ?: $urun['name']);
                    $cinsBirim = $tipBilgileri[$tipId]['default_unit'] ?? $urun['unit'];
                    $cinsEsik  = isset($tipBilgileri[$tipId]['min_threshold']) ? (float)$tipBilgileri[$tipId]['min_threshold'] : (float)$urun['min_quantity'];
                    
                    $cinsGruplari[$tipId] = [
                        'ad'       => $cinsAdi,
                        'birim'    => $cinsBirim,
                        'esik'     => $cinsEsik,
                        'toplam'   => 0.0,
                        'konum'    => $konum,
                        'sonMarka' => $urun['brand'] ?? ''
                    ];
                }
                $cinsGruplari[$tipId]['toplam'] += $miktar;
            } else {
                $kritikSeviye = isset($urun['min_quantity']) ? (float)$urun['min_quantity'] : 1.0;
                if ($miktar <= $kritikSeviye) {
                    $tekilKritikler[] = [
                        'ad'       => $urun['name'],
                        'marka'    => $urun['brand'] ?? '',
                        'miktar'   => $miktar,
                        'esik'     => $kritikSeviye,
                        'birim'    => $urun['unit'],
                        'konum'    => $konum
                    ];
                }
            }
        }

        $alisverisSatirlariHtml = "";
        $alisverisMaddeSayisi = 0;

        // Cins bazlı kritik stoklar
        foreach ($cinsGruplari as $cins) {
            if ($cins['toplam'] <= $cins['esik']) {
                $alisverisMaddeSayisi++;
                $eksikMiktar = max(1.0, (float)($cins['esik'] - $cins['toplam']));
                $durumRenk = ($cins['toplam'] <= 0) ? "#ef4444" : "#f97316";
                $durumYazi = ($cins['toplam'] <= 0) ? "TÜKENDİ (0)" : "Kritik ({$cins['toplam']} {$cins['birim']})";

                $alisverisSatirlariHtml .= "
                <tr>
                    <td style='padding:10px 12px; border-bottom:1px solid #f1f5f9;'>
                        <div style='display:flex; align-items:flex-start; gap:8px;'>
                            <span style='font-size:16px; line-height:1; color:#94a3b8;'>☐</span>
                            <div>
                                <b style='color:#0f172a; font-size:14px;'>" . htmlspecialchars($cins['ad']) . "</b>
                                " . ($cins['sonMarka'] && $cins['sonMarka'] !== 'Açık / Markasız' ? "<span style='color:#64748b; font-size:11px;'> (Tercih: " . htmlspecialchars($cins['sonMarka']) . ")</span>" : "") . "
                                <div style='font-size:11px; color:#94a3b8; margin-top:2px;'>📍 Normal Yeri: {$cins['konum']}</div>
                            </div>
                        </div>
                    </td>
                    <td style='padding:10px 12px; border-bottom:1px solid #f1f5f9; text-align:center;'>
                        <span style='font-size:11px; font-weight:bold; color:{$durumRenk};'>{$durumYazi}</span>
                        <div style='font-size:10px; color:#94a3b8;'>Eşik: {$cins['esik']} {$cins['birim']}</div>
                    </td>
                    <td style='padding:10px 12px; border-bottom:1px solid #f1f5f9; text-align:right;'>
                        <span style='background:#dcfce7; color:#15803d; font-weight:bold; font-size:12px; padding:4px 8px; border-radius:6px; display:inline-block;'>
                            +{$eksikMiktar} {$cins['birim']} Al
                        </span>
                    </td>
                </tr>";
            }
        }

        // Tekil kritik ürünler
        foreach ($tekilKritikler as $tk) {
            $alisverisMaddeSayisi++;
            $eksikMiktar = max(1.0, (float)($tk['esik'] - $tk['miktar']));
            $durumRenk = ($tk['miktar'] <= 0) ? "#ef4444" : "#f97316";
            $durumYazi = ($tk['miktar'] <= 0) ? "TÜKENDİ (0)" : "Kritik ({$tk['miktar']} {$tk['birim']})";

            $alisverisSatirlariHtml .= "
            <tr>
                <td style='padding:10px 12px; border-bottom:1px solid #f1f5f9;'>
                    <div style='display:flex; align-items:flex-start; gap:8px;'>
                        <span style='font-size:16px; line-height:1; color:#94a3b8;'>☐</span>
                        <div>
                            <b style='color:#0f172a; font-size:14px;'>" . htmlspecialchars($tk['ad']) . "</b>
                            " . ($tk['marka'] && $tk['marka'] !== 'Açık / Markasız' ? "<span style='color:#64748b; font-size:11px;'> (" . htmlspecialchars($tk['marka']) . ")</span>" : "") . "
                            <div style='font-size:11px; color:#94a3b8; margin-top:2px;'>📍 {$tk['konum']}</div>
                        </div>
                    </div>
                </td>
                <td style='padding:10px 12px; border-bottom:1px solid #f1f5f9; text-align:center;'>
                    <span style='font-size:11px; font-weight:bold; color:{$durumRenk};'>{$durumYazi}</span>
                    <div style='font-size:10px; color:#94a3b8;'>Eşik: {$tk['esik']} {$tk['birim']}</div>
                </td>
                <td style='padding:10px 12px; border-bottom:1px solid #f1f5f9; text-align:right;'>
                    <span style='background:#dcfce7; color:#15803d; font-weight:bold; font-size:12px; padding:4px 8px; border-radius:6px; display:inline-block;'>
                        +{$eksikMiktar} {$tk['birim']} Al
                    </span>
                </td>
            </tr>";
        }

        // Eğer alınacak ürün varsa Alışveriş Listesi maili gönder
        if ($alisverisMaddeSayisi > 0) {
            $alisverisMailGovde = "
            <html><body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background:#f8fafc; padding:20px; color:#1e293b; line-height:1.5;'>
                <div style='max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);'>
                    <div style='background:#2563eb; padding:20px 24px; color:#ffffff;'>
                        <div style='font-size:12px; text-transform:uppercase; letter-spacing:1px; opacity:0.9;'>Pazartesi Haftalık Planlama</div>
                        <h2 style='margin:4px 0 0 0; font-size:20px; font-weight:bold;'>🛒 Haftalık Alışveriş Listesi Önerisi</h2>
                    </div>
                    <div style='padding:20px 24px;'>
                        <p style='margin-top:0; font-size:13px; color:#64748b;'>
                            Günaydın <b>" . htmlspecialchars($kullanici['username']) . "</b>, iyi haftalar! Ev kilerinizdeki kritik stok eşikleri kontrol edildi. Bu hafta tükenen veya kritik seviyenin altına düşen <b>{$alisverisMaddeSayisi}</b> ürün tespit edildi. Markete çıkarken bu listeyi referans alabilirsiniz:
                        </p>
                        <table style='width:100%; border-collapse:collapse; text-align:left; margin-top:16px;'>
                            <thead>
                                <tr style='background:#f8fafc; font-size:11px; text-transform:uppercase; color:#64748b;'>
                                    <th style='padding:8px 12px; border-bottom:2px solid #e2e8f0;'>Alınacak Ürün</th>
                                    <th style='padding:8px 12px; border-bottom:2px solid #e2e8f0; text-align:center;'>Mevcut / Eşik</th>
                                    <th style='padding:8px 12px; border-bottom:2px solid #e2e8f0; text-align:right;'>Öneri</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$alisverisSatirlariHtml}
                            </tbody>
                        </table>
                        <div style='margin-top:24px; text-align:center;'>
                            <a href='https://bozer.com.tr/stok-takip/envanter.php' style='background:#2563eb; color:#ffffff; text-decoration:none; padding:10px 20px; border-radius:8px; font-weight:bold; font-size:13px; display:inline-block;'>Stokları Yönet</a>
                        </div>
                    </div>
                    <div style='background:#f1f5f9; padding:12px 24px; font-size:11px; color:#94a3b8; text-align:center;'>
                        Bu alışveriş listesi her Pazartesi saat 09:00'da otomatik olarak hazırlanır. Tarih: " . date('d.m.Y H:i') . "
                    </div>
                </div>
            </body></html>";

            $konu = "🛒 Haftalık Alışveriş Listesi: {$alisverisMaddeSayisi} Ürün İçin Takviye Önerisi (" . date('d.m.Y') . ")";

            try {
                $mail = createMailer($smtpHost, $smtpUser, $smtpPass, $smtpPort);
                $mail->Subject = $konu;
                $mail->Body    = $alisverisMailGovde;
                $mail->addAddress($kullanici['email']);
                $mail->send();
                $toplamGonderilenAlisveris++;

                $stmtLog = $pdo->prepare("INSERT INTO notification_logs (id, user_email, subject, content_summary, status) VALUES (UUID(), ?, ?, ?, 'sent')");
                $stmtLog->execute([$kullanici['email'], $konu, "{$alisverisMaddeSayisi} ürün alışveriş listesi"]);
            } catch (Exception $e) {
                sistemLogla("Alışveriş listesi mail hatası: {$kullanici['email']} - " . $e->getMessage(), 'ERROR');
            }
        }
    }
} // foreach kullanicilar sonu

sistemLogla("Cron tamamlandı. Gönderilen SKT: {$toplamGonderilenSkt}, Gönderilen Alışveriş: {$toplamGonderilenAlisveris}", 'INFO');

// Tarayıcıdan test edildiğinde şık özet göster
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<div style='font-family:system-ui,sans-serif; background:#0f172a; color:#f8fafc; padding:30px; border-radius:12px; max-width:550px; margin:40px auto; border:1px solid #334155;'>";
    echo "<h2 style='color:#38bdf8; margin-top:0;'>✅ Rapor Motoru Başarıyla Çalıştı</h2>";
    echo "<p style='color:#94a3b8; font-size:13px;'>Tarih: " . date('d.m.Y H:i:s') . " | Çalışan Mod: <b>" . implode(', ', $calisacakRaporlar) . "</b></p>";
    echo "<div style='background:#1e293b; padding:15px; border-radius:8px; margin:15px 0; font-size:14px; border:1px solid #334155;'>";
    echo "<p style='margin:5px 0;'>⏳ <b>Gönderilen Günlük SKT E-postası:</b> {$toplamGonderilenSkt}</p>";
    echo "<p style='margin:5px 0;'>🛒 <b>Gönderilen Pazartesi Alışveriş Listesi:</b> {$toplamGonderilenAlisveris}</p>";
    echo "</div>";
    echo "<p style='color:#4ade80; font-size:12px;'>Kullanıcılara ait şehir izolasyonu ve eşik kontrolleri başarıyla uygulandı.</p>";
    echo "</div>";
}
?>