<?php
// header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSP Nonce Kontrolü
if (!isset($cspNonce)) {
    $cspNonce = ''; 
}
?>
<!DOCTYPE html>
<html lang="tr" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Takip Sistemi</title>
    
    <!-- PWA / Mobil Uygulama Destek Etiketleri -->
    <link rel="manifest" href="/stok-takip/manifest.json">
    <meta name="theme-color" content="#4f46e5">
    <link rel="icon" type="image/png" href="icons/favicon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192x192.png">
    <link rel="apple-touch-icon" href="icons/icon-192x192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Stok Takip">
    <meta name="mobile-web-app-capable" content="yes">
    <!-- Open Graph (paylaşım önizlemesi için) -->
    <meta property="og:title" content="Stok Takip">
    <meta property="og:description" content="Ev stok ve son kullanma tarihi yönetimi">
    <meta property="og:type" content="website">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- jQuery & DataTables -->
    <!-- TODO: SRI hash'leri https://www.srihash.org/ üzerinden üret ve ekle -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js" integrity="sha256-2Pmvv0kuTBOenSvLm6bvfBSSHrUJ+3A7x6P5Ebd07/g=" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>

    <script nonce="<?= $cspNonce ?>">
        tailwind.config = {
            darkMode: 'class', 
            theme: {
                extend: {
                    colors: {}
                }
            }
        }
    </script>

    <script nonce="<?= $cspNonce ?>">
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <style>
        body { transition: background-color 0.3s, color 0.3s; }
        
        /* Dark Mode Scrollbar */
        .dark ::-webkit-scrollbar { width: 8px; }
        .dark ::-webkit-scrollbar-track { background: #0f172a; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }
        
        .dark input, .dark select, .dark textarea {
            background-color: #1e293b !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }
        .dark tr:hover { background-color: #1e293b !important; }

        /* DataTables Dark Mod Düzeltmeleri */
        .dataTables_wrapper .dataTables_length select {
            background-color: #fff;
            padding-right: 2rem;
            border-radius: 0.25rem;
        }
        .dark .dataTables_wrapper .dataTables_length select {
            background-color: #1e293b;
            color: #fff;
            border-color: #334155;
        }
        .dark .dataTables_wrapper .dataTables_filter input {
            background-color: #1e293b;
            color: #fff;
            border-color: #334155;
            border-radius: 0.25rem;
            padding: 0.25rem;
        }
        .dark .dataTables_info, .dark .dataTables_paginate {
            color: #cbd5e1 !important;
        }
        .dataTables_wrapper { padding: 10px; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 dark:bg-slate-900 dark:text-slate-300 min-h-screen font-sans">

<nav class="bg-slate-900 text-white p-4 shadow-lg sticky top-0 z-50 border-b border-slate-800">
    <div class="container mx-auto flex flex-col md:flex-row justify-between items-center gap-4">
        
        <div class="flex items-center gap-4 w-full md:w-auto justify-between md:justify-start">
            <a href="index.php" class="text-xl font-bold text-blue-400 hover:text-blue-300 transition flex items-center gap-2">
                📦 StokTakip
            </a>
            
            <?php if(isset($_SESSION['aktif_sehir_ad'])): ?>
                <a href="sehir-sec.php" class="bg-slate-800 text-xs px-3 py-1.5 rounded-full flex items-center gap-2 hover:bg-slate-700 transition border border-slate-700" title="Konum Değiştir">
                    📍 <?= htmlspecialchars($_SESSION['aktif_sehir_ad']) ?>
                </a>
            <?php endif; ?>

            <?php 
            try {
                global $pdo; 
                if($pdo) {
                    $sql = "SELECT n.*, 
                                   p.name as urun_adi,
                                   p.expiry_date,
                                   p.quantity,
                                   p.min_quantity,
                                   p.unit,
                                   c.name as dolap_adi,
                                   r.name as oda_adi,
                                   l.name as mekan_adi
                            FROM notifications n
                            INNER JOIN products p ON n.product_id = p.id
                            LEFT JOIN cabinets c ON p.cabinet_id = c.id
                            LEFT JOIN rooms r ON c.room_id = r.id
                            LEFT JOIN locations l ON r.location_id = l.id
                            WHERE n.is_read = 0";
                    
                    $params = [];

                    if (isset($_SESSION['aktif_sehir_id'])) {
                        $sql .= " AND l.city_id = ?";
                        $params[] = $_SESSION['aktif_sehir_id'];
                    } elseif (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') !== 'ADMIN') {
                        $sql .= " AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)";
                        $params[] = $_SESSION['user_id'];
                    }

                    // Akıllı Öncelik Sıralaması:
                    // 1. Süresi dolanlar ve bugün olanlar en üstte
                    // 2. Stoğu tükenenler (0 adet)
                    // 3. SKT'si 1-7 gün kalanlar
                    // 4. Kritik stok seviyesindekiler
                    $sql .= " ORDER BY 
                                CASE 
                                    WHEN p.expiry_date IS NOT NULL AND p.expiry_date < CURRENT_DATE THEN 1
                                    WHEN p.expiry_date IS NOT NULL AND p.expiry_date = CURRENT_DATE THEN 2
                                    WHEN p.quantity <= 0 THEN 3
                                    WHEN p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY) THEN 4
                                    WHEN p.quantity <= p.min_quantity THEN 5
                                    ELSE 6
                                END ASC,
                                CASE WHEN p.expiry_date IS NOT NULL THEN p.expiry_date ELSE '9999-12-31' END ASC,
                                n.timestamp DESC
                              LIMIT 15";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $bildirimler = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $bildirimSayisi = count($bildirimler);
                } else { $bildirimSayisi = 0; $bildirimler = []; }
            } catch(Exception $e) { $bildirimSayisi = 0; $bildirimler = []; }
            ?>

            <!-- BİLDİRİM DROPDOWN -->
            <div class="relative mr-2">
                <button type="button" id="notifDropdownBtn" class="relative p-2 text-slate-300 hover:text-white transition focus:outline-none" title="Bildirimler">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                    <?php if($bildirimSayisi > 0): ?>
                        <span class="absolute top-0 right-0 bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full animate-pulse"><?= $bildirimSayisi ?></span>
                    <?php endif; ?>
                </button>

                <div id="notifDropdownMenu" class="absolute right-0 top-full mt-2 w-80 md:w-96 bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 hidden z-50 overflow-hidden">
                    <div class="bg-slate-50 dark:bg-slate-900 p-3 border-b dark:border-slate-700 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <span>Bildirimler</span>
                            <?php if($bildirimSayisi > 0): ?>
                                <span class="text-[10px] bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 px-1.5 py-0.5 rounded font-bold"><?= $bildirimSayisi ?> Uyarı</span>
                            <?php endif; ?>
                        </div>
                        <?php if($bildirimSayisi > 0): ?>
                        <form method="POST" action="bildirim-oku.php" class="m-0">
                            <?= csrfAlaniniEkle() ?>
                            <input type="hidden" name="hepsini_oku" value="1">
                            <button type="submit" class="text-[11px] font-semibold lowercase text-blue-600 dark:text-blue-400 hover:underline cursor-pointer bg-transparent border-0 p-0" title="Tüm bildirimleri okundu olarak işaretle">tümünü temizle</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="max-h-80 overflow-y-auto">
                        <?php if($bildirimSayisi == 0): ?>
                            <div class="p-5 text-center text-slate-400 dark:text-slate-500 text-sm">Bu konumda yeni bildirim yok 🎉</div>
                        <?php else: ?>
                            <?php foreach($bildirimler as $notif): 
                                $hasExpiry       = !empty($notif['expiry_date']);
                                $qty             = (float)($notif['quantity'] ?? 0);
                                $minQty          = (float)($notif['min_quantity'] ?? 1);
                                $unit            = htmlspecialchars($notif['unit'] ?? 'Adet');
                                $isDepleted      = ($qty <= 0);
                                $isCriticalStock = ($qty <= $minQty);

                                if ($hasExpiry) {
                                    $notifBugun = new DateTime('today');
                                    $skt        = new DateTime($notif['expiry_date']);
                                    $fark       = (int)$notifBugun->diff($skt)->format('%r%a');
                                } else {
                                    $fark       = null;
                                }
                            ?>
                            <div class="p-3 border-b dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/60 transition relative group/item text-slate-800 dark:text-slate-200">
                                <p class="text-sm font-bold truncate pr-6"><?= htmlspecialchars($notif['urun_adi']) ?></p>
                                
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5 mb-1.5 flex items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                    <?= htmlspecialchars($notif['mekan_adi'] ?? '') ?> &rsaquo; 
                                    <?= htmlspecialchars($notif['oda_adi'] ?? '') ?> &rsaquo; 
                                    <?= htmlspecialchars($notif['dolap_adi'] ?? '') ?>
                                </p>

                                <div class="text-xs font-semibold flex items-center gap-1.5 flex-wrap">
                                    <?php if (!$hasExpiry): ?>
                                        <!-- SKT'SİZ (SÜRESİZ) ÜRÜNLER: ASLA "SÜRESİ GEÇTİ" YAZMAZ -->
                                        <?php if ($isDepleted): ?>
                                            <span class="text-red-600 dark:text-red-400 font-bold flex items-center gap-1">
                                                🔴 Stok Tükendi (0 <?= $unit ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-amber-600 dark:text-amber-400 font-bold flex items-center gap-1">
                                                ⚠️ Kritik Stok Seviyesi (<?= $qty ?> <?= $unit ?> kaldı)
                                            </span>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <!-- SKT'Lİ ÜRÜNLER -->
                                        <?php if ($fark < 0): ?>
                                            <span class="text-red-600 dark:text-red-400 font-bold">
                                                ⚠️ Süresi Geçti (<?= abs($fark) ?> gün önce)
                                            </span>
                                        <?php elseif ($fark === 0): ?>
                                            <span class="text-red-600 dark:text-red-400 font-bold animate-pulse">
                                                ⚠️ Son Kullanma Tarihi Bugün!
                                            </span>
                                        <?php elseif ($fark <= 7): ?>
                                            <span class="text-orange-500 font-bold">
                                                ⏳ <?= $fark ?> gün kaldı
                                            </span>
                                        <?php endif; ?>

                                        <!-- SKT'si ileri tarihte veya normal olsa bile stok bitmiş/kritikse ek göster -->
                                        <?php if ($isDepleted): ?>
                                            <span class="text-red-600 dark:text-red-400 font-bold text-[11px] bg-red-100 dark:bg-red-900/40 px-1.5 py-0.5 rounded">
                                                🔴 Stok Bitti
                                            </span>
                                        <?php elseif ($isCriticalStock && ($fark > 7 || $fark === null)): ?>
                                            <span class="text-amber-600 dark:text-amber-400 font-bold">
                                                ⚠️ Kritik Stok (<?= $qty ?> <?= $unit ?> kaldı)
                                            </span>
                                        <?php elseif ($isCriticalStock && $fark <= 7): ?>
                                            <span class="text-amber-600 dark:text-amber-400 text-[11px] bg-amber-100 dark:bg-amber-900/40 px-1 py-0.5 rounded">
                                                (<?= $qty ?> <?= $unit ?>)
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <form method="POST" action="bildirim-oku.php" style="display:inline;margin:0">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                    <input type="hidden" name="id" value="<?= $notif['id'] ?>">
                                    <button type="submit" class="absolute right-2 top-3 text-xs bg-slate-200 dark:bg-slate-600 hover:bg-blue-600 hover:text-white px-2 py-1 rounded transition" title="Okundu Olarak İşaretle">✓</button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ÜST NAVİGASYON LİNKLERİ -->
        <div class="flex gap-4 text-sm items-center overflow-x-auto w-full md:w-auto pb-2 md:pb-0">
            
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN'): ?>
                <a href="admin.php" class="text-orange-400 hover:text-orange-300 transition font-bold bg-orange-400/10 px-2 py-1 rounded whitespace-nowrap flex items-center gap-1">
                    🛠️ Panel
                </a>
                <a href="log-goruntule.php" class="text-rose-400 hover:text-rose-300 transition font-bold bg-rose-400/10 px-2 py-1 rounded whitespace-nowrap flex items-center gap-1" title="Sistem Hata Kayıtları">
                    📋 Loglar
                </a>
            <?php endif; ?>

            <a href="index.php" class="hover:text-blue-300 transition whitespace-nowrap">Özet</a>
            <a href="envanter.php" class="hover:text-blue-300 transition whitespace-nowrap">Envanter</a>
            <div class="flex items-center gap-2 border-l border-r border-slate-700 px-2 mx-1">
                <a href="urun-ekle.php" class="hover:text-blue-300 transition whitespace-nowrap text-blue-400 hover:text-blue-200 font-bold flex items-center gap-1" title="Tekli Ürün Ekle">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    Ürün Ekle
                </a>
                <span class="text-slate-600">|</span>
                <a href="toplu-ekle.php" class="hover:text-blue-300 transition whitespace-nowrap text-green-400 hover:text-green-200 font-bold flex items-center gap-1" title="Toplu Ürün Ekle">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Toplu Ekle
                </a>
            </div>
            
            <a href="hizli-tuket.php" class="hover:text-red-300 transition whitespace-nowrap text-red-400 font-bold flex items-center gap-1 bg-red-400/10 px-2 py-1 rounded">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                Hızlı Tüket
            </a>
            <a href="odalar.php" class="hover:text-blue-300 transition whitespace-nowrap">Odalar</a>
            
            <a href="tuketim-analizi.php" class="hover:text-blue-300 transition whitespace-nowrap font-bold flex items-center gap-1">
                📊 Tüketim Analizi
            </a>
            
            <a href="sef.php" class="text-purple-300 hover:text-white transition whitespace-nowrap font-bold flex items-center gap-1">✨ AI Şef</a>
            
            <!-- PWA Kurulum Butonu (beforeinstallprompt ile tetiklenir) -->
            <button id="pwa-install-btn" class="hidden hover:text-green-300 transition whitespace-nowrap text-green-400 font-bold flex items-center gap-1 bg-green-400/10 px-2 py-1 rounded">
                📱 Uygulamayı Yükle
            </button>

            <span class="text-slate-600 hidden md:inline">|</span>

            <!-- Tema Butonu -->
            <button id="theme-toggle" type="button" class="text-gray-400 hover:bg-gray-700 hover:text-white focus:outline-none focus:ring-2 focus:ring-gray-600 rounded-lg text-sm p-2 transition">
                <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
                <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
            </button>
            
            <!-- Kullanıcı & Çıkış Linki (Mobilde de Görünür) -->
            <div class="flex items-center gap-3 border-l border-slate-700 pl-3 ml-1">
                <a href="profil.php" class="hidden sm:flex flex-col items-end group" title="Profil Ayarları">
                    <span class="text-slate-300 group-hover:text-white transition capitalize text-xs font-bold"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                    <span class="text-[10px] text-slate-500 group-hover:text-blue-400 transition">Profil</span>
                </a>
                
                <a href="cikis.php" class="text-red-400 hover:text-white hover:bg-red-600 transition bg-red-500/10 px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1" title="Güvenli Çıkış">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span class="hidden sm:inline">Çıkış</span>
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container mx-auto p-4 md:p-6">

<script nonce="<?= $cspNonce ?>">
    // Tema Değiştirme
    var themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
    var themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

    if (document.documentElement.classList.contains('dark')) {
        themeToggleLightIcon.classList.remove('hidden');
    } else {
        themeToggleDarkIcon.classList.remove('hidden');
    }

    var themeToggleBtn = document.getElementById('theme-toggle');
    themeToggleBtn.addEventListener('click', function() {
        themeToggleDarkIcon.classList.toggle('hidden');
        themeToggleLightIcon.classList.toggle('hidden');

        if (localStorage.getItem('color-theme')) {
            if (localStorage.getItem('color-theme') === 'light') {
                document.documentElement.classList.add('dark');
                localStorage.setItem('color-theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('color-theme', 'light');
            }
        } else {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('color-theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('color-theme', 'dark');
            }
        }
    });

    // Mobil ve Masaüstü Uyumlu Bildirim Menüsü Açma/Kapatma
    const notifBtn = document.getElementById('notifDropdownBtn');
    const notifMenu = document.getElementById('notifDropdownMenu');

    if (notifBtn && notifMenu) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notifMenu.classList.toggle('hidden');
        });

        document.addEventListener('click', function(e) {
            if (!notifMenu.contains(e.target) && !notifBtn.contains(e.target)) {
                notifMenu.classList.add('hidden');
            }
        });
    }

    // PWA Service Worker Kaydı
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/stok-takip/sw.js', { scope: '/stok-takip/' })
                .then(reg => {
                    reg.addEventListener('updatefound', () => {
                        const newSW = reg.installing;
                        newSW.addEventListener('statechange', () => {
                            if (newSW.state === 'installed' && navigator.serviceWorker.controller) {
                                console.log('[SW] Yeni sürüm mevcut, sayfayı yenileyin.');
                            }
                        });
                    });
                })
                .catch(err => console.warn('[SW] Kayıt hatası:', err));
        });
    }

    // PWA Kurulum Butonu ve beforeinstallprompt Dinleyicisi
    let deferredPrompt;
    const installBtn = document.getElementById('pwa-install-btn');

    window.addEventListener('beforeinstallprompt', (e) => {
        // Tarayıcının varsayılan kurulum çubuğunu gizle
        e.preventDefault();
        deferredPrompt = e;
        
        // Kurulum butonumuzu görünür yap
        if (installBtn) {
            installBtn.classList.remove('hidden');
        }
    });

    if (installBtn) {
        installBtn.addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    installBtn.classList.add('hidden');
                }
                deferredPrompt = null;
            }
        });
    }

    window.addEventListener('appinstalled', () => {
        if (installBtn) {
            installBtn.classList.add('hidden');
        }
        deferredPrompt = null;
    });
</script>