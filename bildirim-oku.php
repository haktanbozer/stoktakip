<?php
// bildirim-oku.php - Bildirim Okundu İşaretleme
require 'db.php';
girisKontrol();

$notifId = $_POST['id'] ?? null;
$hepsi   = isset($_POST['hepsini_oku']);
$token   = $_POST['csrf_token'] ?? '';

if ($hepsi || $notifId) {
    // 1. CSRF Token Kontrolü
    csrfKontrol($token);
    
    $userId  = $_SESSION['user_id'];
    $role    = $_SESSION['role'];

    if ($hepsi) {
        // Tüm bildirimleri okundu olarak işaretle
        if ($role === 'ADMIN') {
            $pdo->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
        } else {
            $stmt = $pdo->prepare("
                UPDATE notifications n
                JOIN products p ON n.product_id = p.id
                JOIN cabinets c ON p.cabinet_id = c.id
                JOIN rooms r ON c.room_id = r.id
                JOIN locations l ON r.location_id = l.id
                JOIN user_city_assignments uca ON l.city_id = uca.city_id
                SET n.is_read = 1 
                WHERE n.is_read = 0 AND uca.user_id = ?
            ");
            $stmt->execute([$userId]);
        }
        if (function_exists('auditLog')) {
            auditLog('BİLDİRİM', "Tüm bildirimler topluca okundu olarak işaretlendi.");
        }
    } else {
        $notifId = trim($notifId);

        // 2. Yetki Kontrolü: Admin tüm bildirimleri kapatabilir, User sadece atandığı şehirdekileri kapatabilir
        if ($role === 'ADMIN') {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $stmt->execute([$notifId]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE notifications n
                JOIN products p ON n.product_id = p.id
                JOIN cabinets c ON p.cabinet_id = c.id
                JOIN rooms r ON c.room_id = r.id
                JOIN locations l ON r.location_id = l.id
                JOIN user_city_assignments uca ON l.city_id = uca.city_id
                SET n.is_read = 1 
                WHERE n.id = ? AND uca.user_id = ?
            ");
            $stmt->execute([$notifId, $userId]);
        }

        if (function_exists('auditLog')) {
            auditLog('BİLDİRİM', "Bildirim okundu olarak işaretlendi (ID: $notifId)");
        }
    }
}

// 3. Güvenli Geri Yönlendirme (Open Redirect Engelleme)
$hedef = 'index.php';

if (!empty($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'];
    
    // Referer sadece bizim kendi sitemizden geliyorsa oraya geri döndür
    if (parse_url($referer, PHP_URL_HOST) === $host) {
        $hedef = $referer;
    }
}

header("Location: " . $hedef);
exit;