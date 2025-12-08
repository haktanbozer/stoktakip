<?php
// cikis.php

// Oturum ayarlarını ve doğru klasör yolunu alması için db.php'yi çağırıyoruz
require 'db.php';

// Oturumu sonlandır
session_destroy();

// Giriş sayfasına yönlendir
header("Location: login.php");
exit;
?>