<?php
// cikis.php - Güvenli Oturum Kapatma
require 'db.php';

// 1. Çıkış işlemini denetim günlüğüne kaydet (Oturum yok edilmeden önce)
if (isset($_SESSION['user_id'])) {
    if (function_exists('auditLog')) {
        auditLog('ÇIKIŞ', "Kullanıcı oturumu kapattı: " . ($_SESSION['username'] ?? 'Bilinmiyor'));
    }
    // B9: remember_token'ı DB'den temizle — çıkış sonrası otomatik giriş engellenir
    try {
        $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")
            ->execute([$_SESSION['user_id']]);
    } catch (Exception $e) { /* sessiz hata */ }
}

// B9: Tarayıcıdaki "Beni Hatırla" çerezini sil
setcookie('remember_user', '', time() - 3600, '/', '', false, true);

// 2. Oturum değişkenlerini temizle
$_SESSION = [];
session_unset();

// 3. Tarayıcıdaki oturum çerezini güvenlik bayraklarıyla birlikte sil
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 4. Sunucudaki oturum dosyasını tamamen yok et
session_destroy();

// 5. Giriş sayfasına yönlendir
header("Location: login.php?msg=cikis_yapildi");
exit;