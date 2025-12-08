<?php
require 'db.php';
girisKontrol();

if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$_GET['id']]);
}

// Geldiği yere geri gönder
header("Location: " . $_SERVER['HTTP_REFERER']);
exit;
?>