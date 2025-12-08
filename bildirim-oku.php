<?php
require 'db.php';
girisKontrol();

if (isset($_GET['id'])) {
    // KRİTİK GÜVENLİK DÜZELTMESİ: URL'den gelen token'ı kontrol et.
    // Not: Bu işlem için, bildirimin gösterildiği sayfadaki linkin (örneğin header.php) 
    // şu formatta güncellenmesi gerekir: bildirim-oku.php?id=...&token=<?= $_SESSION['csrf_token'] ?>
    csrfKontrol($_GET['token'] ?? '');
    
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$_GET['id']]);
}

// Geldiği yere geri gönder
header("Location: " . $_SERVER['HTTP_REFERER']);
exit;
?>
