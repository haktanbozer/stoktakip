<?php
// header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="tr" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Takip Sistemi</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js" integrity="sha256-2Pmvv0kuTBOenSvLm6bvfBSSHrUJ+3A7x6P5Ebd07/g=" crossorigin="anonymous"></script>

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.tailwindcss.min.css">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js" integrity="sha256-J7pX7ZJ8yX9xq3x8X1xY7x5x5x5x5x5x5x5x5x5x5w=" crossorigin="anonymous"></script>

    <script>
        tailwind.config = {
            darkMode: 'class', 
            theme: {
                extend: {
                    colors: {}
                }
            }
        }
    </script>

    <script>
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

        /* DataTables Özelleştirmeleri */
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
                <a href="sehir-sec.php" class="bg-slate-800 text-xs px-3 py-1.5 rounded-full flex items-center gap-2 hover:bg-slate-700 transition border border-slate-700">
                    📍 <?= htmlspecialchars($_SESSION['aktif_sehir_ad']) ?>
                </a>
            <?php endif; ?>

            <?php 
            try {
                // Bildirim tablosu yoksa hata vermesin diye try-catch
                global $pdo; 
                if($pdo) {
                    $stmt = $pdo->query("SELECT * FROM notifications WHERE is_read = 0 ORDER BY days_remaining ASC LIMIT 10");
                    $bildirimler = $stmt->fetchAll();
                    $bildirimSayisi = count($bildirimler);
                } else { $bildirimSayisi = 0; $bildirimler = []; }
            } catch(Exception $e) { $bildirimSayisi = 0; $bildirimler = []; }
            ?>
            <div class="relative group mr-2">
                <button class="relative p-2 text-slate-300 hover:text-white transition">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                    <?php if($bildirimSayisi > 0): ?>
                        <span class="absolute top-0 right-0 bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full animate-pulse"><?= $bildirimSayisi ?></span>
                    <?php endif; ?>
                </button>
                <div class="absolute left-0 md:left-auto md:right-0 top-full mt-2 w-80 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 hidden group-hover:block z-50 overflow-hidden">
                    <div class="bg-slate-50 dark:bg-slate-900 p-3 border-b dark:border-slate-700 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Bildirimler</div>
                    <div class="max-h-64 overflow-y-auto custom-scrollbar">
                        <?php if($bildirimSayisi == 0): ?>
                            <div class="p-4 text-center text-slate-400 dark:text-slate-500 text-sm">Yeni bildirim yok 🎉</div>
                        <?php else: ?>
                            <?php foreach($bildirimler as $notif): ?>
                            <div class="p-3 border-b dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700 transition relative group/item text-slate-800 dark:text-slate-200">
                                <p class="text-sm font-bold"><?= htmlspecialchars($notif['product_name']) ?></p>
                                <p class="text-xs text-red-500 font-medium"><?= $notif['days_remaining'] ?> gün kaldı</p>
                                <a href="bildirim-oku.php?id=<?= $notif['id'] ?>&token=<?= $_SESSION['csrf_token'] ?? '' ?>" class="absolute right-2 top-3 text-xs bg-slate-200 dark:bg-slate-600 hover:bg-blue-500 hover:text-white px-2 py-1 rounded opacity-0 group-hover/item:opacity-100 transition">✓</a>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex gap-4 text-sm items-center overflow-x-auto w-full md:w-auto pb-2 md:pb-0">
            
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN'): ?>
                <a href="admin.php" class="text-orange-400 hover:text-orange-300 transition font-bold bg-orange-400/10 px-2 py-1 rounded whitespace-nowrap flex items-center gap-1">
                    🛠️ Panel
                </a>
            <?php endif; ?>

            <a href="index.php" class="hover:text-blue-300 transition whitespace-nowrap">Özet</a>
            <a href="envanter.php" class="hover:text-blue-300 transition whitespace-nowrap">Envanter</a>
            <a href="odalar.php" class="hover:text-blue-300 transition whitespace-nowrap">Odalar</a>
            
            <a href="tuketim-analizi.php" class="hover:text-blue-300 transition whitespace-nowrap font-bold flex items-center gap-1">
                ⏳ Tüketim Analizi
            </a>
            
            <a href="sef.php" class="text-purple-300 hover:text-white transition whitespace-nowrap font-bold flex items-center gap-1">✨ AI Şef</a>
            
            <span class="text-slate-600 hidden md:inline">|</span>

            <button id="theme-toggle" type="button" class="text-gray-400 hover:bg-gray-700 hover:text-white focus:outline-none focus:ring-2 focus:ring-gray-600 rounded-lg text-sm p-2 transition">
                <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
                <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
            </button>
            
            <div class="hidden md:flex items-center gap-3 border-l border-slate-700 pl-4 ml-2">
                <a href="profil.php" class="flex flex-col items-end group">
                    <span class="text-slate-300 group-hover:text-white transition capitalize text-xs font-bold"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                    <span class="text-[10px] text-slate-500 group-hover:text-blue-400 transition">Profili Düzenle</span>
                </a>
                
                <a href="cikis.php" class="text-red-400 hover:text-white hover:bg-red-500 transition bg-red-500/10 px-3 py-2 rounded-lg text-xs font-bold flex items-center gap-1" title="Güvenli Çıkış">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Çıkış
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container mx-auto p-4 md:p-6">

<script>
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
</script>
