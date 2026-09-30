<?php
// bildirim-gecmisi.php - Gönderilen E-Posta / Sistem Bildirimleri Geçmişi
require 'db.php';
girisKontrol();

// Güvenlik & Yetki Kontrolü
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    if (function_exists('sistemLogla')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user = $_SESSION['username'] ?? 'Bilinmeyen';
        sistemLogla("Yetkisiz Sayfa Erişimi Engellendi: bildirim-gecmisi.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    }
    header("Location: index.php?hata=yetkisiz");
    exit;
}

if (!isset($cspNonce)) { $cspNonce = ''; }

// Logları Temizle (Toplu Silme)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['temizle'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    $pdo->query("DELETE FROM notification_logs");

    if (function_exists('auditLog')) {
        auditLog('TEMİZLEME', 'Tüm e-posta bildirim geçmişi (notification_logs) yönetici tarafından temizlendi.');
    }

    header("Location: bildirim-gecmisi.php?temizlendi=1");
    exit;
}

// Logları Çek (Son 500 kayıt)
$loglar = $pdo->query("SELECT * FROM notification_logs ORDER BY sent_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                    📨 Gönderilen Bildirim Geçmişi
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">Otomatik cron ve sistem tarafından kullanıcılara gönderilen uyarılar.</p>
            </div>
            
            <?php if(!empty($loglar)): ?>
            <form method="POST" onsubmit="confirmClearLogs(event)">
                <?php echo csrfAlaniniEkle(); ?>
                <button type="submit" name="temizle" value="1" class="bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300 px-4 py-2 rounded-lg text-sm hover:bg-red-100 dark:hover:bg-red-900/50 transition border border-red-200 dark:border-red-800 font-bold flex items-center gap-2 shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    Geçmişi Temizle
                </button>
            </form>
            <?php endif; ?>
        </div>

        <?php if(isset($_GET['temizlendi'])): ?>
            <div class="bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 p-3 rounded-lg mb-6 border-l-4 border-green-500 text-sm font-medium">
                Bildirim geçmişi başarıyla temizlendi.
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors p-3">
            <table id="logTablosu" class="w-full text-sm text-left">
                <thead class="bg-slate-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-bold border-b dark:border-slate-700 text-xs uppercase">
                    <tr>
                        <th class="p-3">Tarih</th>
                        <th class="p-3">Alıcı (E-Posta)</th>
                        <th class="p-3">Konu / İçerik Özeti</th>
                        <th class="p-3 text-center">Durum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-600 dark:text-slate-300">
                    <?php foreach($loglar as $log): 
                        $zaman = date('d.m.Y H:i', strtotime($log['sent_at']));
                        $timestamp = strtotime($log['sent_at']);
                        $isSuccess = strtolower($log['status'] ?? '') === 'sent';
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                        <td class="p-3 text-xs font-mono whitespace-nowrap" data-order="<?= $timestamp ?>">
                            <?= $zaman ?>
                        </td>
                        <td class="p-3 font-medium text-slate-700 dark:text-slate-200">
                            <?= htmlspecialchars($log['user_email'] ?? '') ?>
                        </td>
                        <td class="p-3">
                            <div class="font-bold text-slate-800 dark:text-white"><?= htmlspecialchars($log['subject'] ?? '') ?></div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 leading-relaxed"><?= htmlspecialchars($log['content_summary'] ?? '') ?></div>
                        </td>
                        <td class="p-3 text-center whitespace-nowrap">
                            <?php if($isSuccess): ?>
                                <span class="bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 px-2 py-0.5 rounded text-[11px] font-bold border border-green-200 dark:border-green-800">İletildi</span>
                            <?php else: ?>
                                <span class="bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 px-2 py-0.5 rounded text-[11px] font-bold border border-red-200 dark:border-red-800">Başarısız</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-4 text-center transition-colors mb-6">* Son 500 bildirim kaydı listelenmektedir.</p>

    </div>
</div>

<script nonce="<?= $cspNonce ?>">
$(document).ready(function() {
    $('#logTablosu').DataTable({
        "language": {
            "search": "Kayıtlarda Ara:",
            "lengthMenu": "_MENU_ kayıt göster",
            "info": "_TOTAL_ kayıttan _START_ - _END_ arası gösteriliyor",
            "infoEmpty": "Kayıt yok",
            "infoFiltered": "(_MAX_ kayıt içerisinden filtrelendi)",
            "zeroRecords": "Eşleşen bildirim kaydı bulunamadı",
            "paginate": {
                "first": "İlk",
                "last": "Son",
                "next": "Sonraki",
                "previous": "Önceki"
            }
        },
        "pageLength": 15,
        "order": [[ 0, "desc" ]],
        "responsive": true
    });
});

function confirmClearLogs(event) {
    event.preventDefault();
    const form = event.target;

    Swal.fire({
        title: 'Geçmişi Temizle?',
        text: "Tüm bildirim geçmişi kalıcı olarak silinecek! Bu işlem geri alınamaz.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Evet, Temizle',
        cancelButtonText: 'İptal',
        background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
        color: document.documentElement.classList.contains('dark') ? '#fff' : '#0f172a'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
}
</script>
</body>
</html>