<?php
require 'db.php';
girisKontrol();

if ($_SESSION['role'] !== 'ADMIN') die("Yetkisiz erişim.");

// Logları Temizle (Eski kayıtları silmek için)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['temizle'])) {
    // KRİTİK GÜVENLİK DÜZELTMESİ: CSRF token kontrolü
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    $pdo->query("DELETE FROM notification_logs");
    header("Location: bildirim-gecmisi.php");
    exit;
}

// Logları Çek (En yeniden eskiye - Limit artırılabilir çünkü DataTables sayfalama yapacak)
$loglar = $pdo->query("SELECT * FROM notification_logs ORDER BY sent_at DESC LIMIT 500")->fetchAll();

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors">Gönderilen Bildirim Geçmişi</h2>
            
            <?php if(!empty($loglar)): ?>
            <form method="POST" onsubmit="confirmClearLogs(event)">
                <?php echo csrfAlaniniEkle(); ?>
                <button type="submit" name="temizle" value="1" class="bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300 px-4 py-2 rounded text-sm hover:bg-red-100 dark:hover:bg-red-900/50 transition border border-red-200 dark:border-red-800 font-bold flex items-center gap-2 shadow-sm">
                    <span>🗑️</span> Geçmişi Temizle
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors p-2">
            <table id="logTablosu" class="w-full text-sm text-left">
                <thead class="bg-slate-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-bold border-b dark:border-slate-700">
                    <tr>
                        <th class="p-4">Tarih</th>
                        <th class="p-4">Alıcı (E-Posta)</th>
                        <th class="p-4">Konu / İçerik</th>
                        <th class="p-4 text-center">Durum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    <?php if(empty($loglar)): ?>
                        <?php else: ?>
                        <?php foreach($loglar as $log): 
                            $zaman = date('d.m.Y H:i', strtotime($log['sent_at']));
                            $timestamp = strtotime($log['sent_at']); // Sıralama için
                        ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                            <td class="p-4 text-slate-500 dark:text-slate-400 whitespace-nowrap" data-order="<?= $timestamp ?>">
                                <?= $zaman ?>
                            </td>
                            <td class="p-4 font-medium text-slate-700 dark:text-slate-300">
                                <?= htmlspecialchars($log['user_email']) ?>
                            </td>
                            <td class="p-4">
                                <div class="font-bold text-slate-800 dark:text-white"><?= htmlspecialchars($log['subject']) ?></div>
                                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?= htmlspecialchars($log['content_summary']) ?></div>
                            </td>
                            <td class="p-4 text-center">
                                <?php if($log['status'] == 'sent'): ?>
                                    <span class="bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 px-2 py-1 rounded text-xs font-bold border border-green-200 dark:border-green-800">Gönderildi</span>
                                <?php else: ?>
                                    <span class="bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 px-2 py-1 rounded text-xs font-bold border border-red-200 dark:border-red-800">Başarısız</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-4 text-center transition-colors mb-6">* Son 500 işlem gösterilmektedir.</p>

    </div>
</div>

<script>
    // 1. DataTables Başlatma
    $(document).ready(function() {
        $('#logTablosu').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" // Türkçe Dil
            },
            "pageLength": 15,
            "order": [[ 0, "desc" ]], // Tarihe göre tersten sırala (En yeni en üstte)
            "responsive": true
        });
    });

    // 2. SweetAlert2 ile Geçmişi Temizleme Onayı
    function confirmClearLogs(event) {
        event.preventDefault(); // Formun hemen gitmesini engelle
        const form = event.target;

        Swal.fire({
            title: 'Emin misiniz?',
            text: "Tüm bildirim geçmişi kalıcı olarak silinecek! Bu işlem geri alınamaz.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444', // Kırmızı
            cancelButtonColor: '#64748b', // Gri
            confirmButtonText: 'Evet, Temizle',
            cancelButtonText: 'İptal',
            background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
            color: document.documentElement.classList.contains('dark') ? '#fff' : '#0f172a'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit(); // Onaylanırsa formu gönder
            }
        });
    }
</script>
</body>
</html>
