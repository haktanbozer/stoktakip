<?php
require 'db.php';
girisKontrol();

// Güvenlik: Sadece Adminler Girebilir
if ($_SESSION['role'] !== 'ADMIN') {
    die("Bu sayfaya erişim yetkiniz yok. <a href='index.php'>Panele Dön</a>");
}

// Logları Temizle (Toplu Silme)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['temizle'])) {
    // CSRF Koruması
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    // Tabloyu boşalt
    $pdo->query("DELETE FROM audit_logs");
    
    // Temizleme işleminin kendisini de ilk kayıt olarak ekle (İz bırakmak için)
    auditLog('TEMİZLEME', 'Tüm işlem geçmişi (audit logs) yönetici tarafından temizlendi.');
    
    header("Location: islem-gecmisi.php");
    exit;
}

// Logları Çek (Son 1000 kayıt)
$logs = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 1000")->fetchAll();

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                    📋 İşlem Geçmişi (Audit Logs)
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">Sistemdeki kritik kullanıcı hareketlerinin kaydı.</p>
            </div>
            
            <?php if(!empty($logs)): ?>
            <form method="POST" onsubmit="confirmClearAudit(event)">
                <?php echo csrfAlaniniEkle(); ?>
                <button type="submit" name="temizle" value="1" class="bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300 px-4 py-2 rounded-lg text-sm hover:bg-red-100 dark:hover:bg-red-900/50 transition border border-red-200 dark:border-red-800 font-bold flex items-center gap-2 shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    Kayıtları Temizle
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors p-2">
            <table id="auditTable" class="w-full text-sm text-left">
                <thead class="bg-slate-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-bold border-b dark:border-slate-700">
                    <tr>
                        <th class="p-3">Tarih</th>
                        <th class="p-3">Kullanıcı</th>
                        <th class="p-3">İşlem</th>
                        <th class="p-3">Detay</th>
                        <th class="p-3">IP Adresi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-600 dark:text-slate-300">
                    <?php foreach($logs as $log): 
                        $zaman = date('d.m.Y H:i', strtotime($log['created_at']));
                        $timestamp = strtotime($log['created_at']);
                        
                        // İşlem Tipine Göre Renk Kodlaması
                        $badgeClass = "bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300";
                        if($log['action'] == 'EKLEME') $badgeClass = "bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300";
                        elseif($log['action'] == 'SİLME') $badgeClass = "bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300";
                        elseif($log['action'] == 'GÜNCELLEME') $badgeClass = "bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300";
                        elseif($log['action'] == 'TRANSFER') $badgeClass = "bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-300";
                        elseif($log['action'] == 'TÜKETİM') $badgeClass = "bg-orange-100 text-orange-800 dark:bg-orange-900/50 dark:text-orange-300";
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                        <td class="p-3 whitespace-nowrap text-xs" data-order="<?= $timestamp ?>">
                            <?= $zaman ?>
                        </td>
                        <td class="p-3 font-bold">
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-full bg-slate-200 dark:bg-slate-600 flex items-center justify-center text-[10px]">
                                    <?= strtoupper(substr($log['username'], 0, 1)) ?>
                                </div>
                                <?= htmlspecialchars($log['username']) ?>
                            </div>
                        </td>
                        <td class="p-3">
                            <span class="px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wider border border-transparent <?= $badgeClass ?>">
                                <?= htmlspecialchars($log['action']) ?>
                            </span>
                        </td>
                        <td class="p-3 text-xs leading-relaxed">
                            <?= htmlspecialchars($log['details']) ?>
                        </td>
                        <td class="p-3 text-[10px] font-mono text-slate-400">
                            <?= htmlspecialchars($log['ip_address']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#auditTable').DataTable({
        "language": { "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" },
        "pageLength": 20,
        "order": [[ 0, "desc" ]], // En yeniden eskiye sırala
        "responsive": true
    });
});

function confirmClearAudit(event) {
    event.preventDefault();
    const form = event.target;
    
    Swal.fire({
        title: 'Kayıtları Temizle?',
        text: "Tüm işlem geçmişi (audit logs) kalıcı olarak silinecek! Bu işlem geri alınamaz.",
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