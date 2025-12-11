<?php
// cikis.php

// Oturum ayarlarını ve doğru klasör yolunu alması için db.php'yi çağırıyoruz
require 'db.php';

// Oturumu sonlandır
session_unset();
session_destroy();
setcookie(session_name(), '', time()-3600, '/');
session_regenerate_id(true);
// Giriş sayfasına yönlendir
header("Location: login.php");
exit;
?>
