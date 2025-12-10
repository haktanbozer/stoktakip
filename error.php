<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bir Sorun Oluştu</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 dark:bg-slate-900 flex items-center justify-center min-h-screen p-4">
    <div class="max-w-md w-full bg-white dark:bg-slate-800 rounded-xl shadow-xl p-8 text-center border border-slate-200 dark:border-slate-700">
        <div class="mb-6 text-red-500 dark:text-red-400">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-20 w-20 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-800 dark:text-white mb-2">Beklenmedik Bir Hata</h1>
        <p class="text-slate-500 dark:text-slate-400 mb-6">
            İşleminiz sırasında teknik bir sorun oluştu. Hata detayları sistem yöneticisine iletildi.
        </p>
        <div class="flex gap-4 justify-center">
            <a href="index.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition">
                Ana Sayfaya Dön
            </a>
            <button onclick="window.history.back()" class="bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-300 px-6 py-2 rounded-lg font-medium transition">
                Geri Gel
            </button>
        </div>
        <p class="text-xs text-slate-400 mt-8">Hata Kodu: #ERR-<?= date('U') ?></p>
    </div>
</body>
</html>