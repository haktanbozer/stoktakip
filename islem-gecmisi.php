<?php
// islem-gecmisi.php - Sistem Denetim Günlüğü (Audit Logs)
require 'db.php';
girisKontrol();

// Güvenlik: Sadece Adminler Girebilir
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    if (function_exists('sistemLogla')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user = $_SESSION['username'] ?? 'Bilinmeyen';
        sistemLogla("Yetkisiz Sayfa Erişimi Engellendi: islem-gecmisi.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    }
    header("Location: index.php?hata=yetkisiz");
    exit;
}

if (!isset($cspNonce)) { $cspNonce = ''; }

// Logları Temizle (Toplu Silme)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['temizle'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    // Tabloyu boşalt
    $pdo->query("DELETE FROM audit_logs");
    
    // Temizleme işleminin kendisini de ilk kayıt olarak ekle
    if (function_exists('auditLog')) {
        auditLog('TEMİZLEME', 'Tüm işlem geçmişi (audit logs) yönetici tarafından temizlendi.');
    }
    
    header("Location: islem-gecmisi.php?temizlendi=1");
    exit;
}

// Logları Çek (Son 1000 kayıt)
$logs = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                    📋 İşlem Geçmişi (Audit Logs)
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">Sistemdeki kritik kullanıcı ve envanter hareketlerinin kaydı.</p>
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

        <?php if(isset($_GET['temizlendi'])): ?>
            <div class="bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 p-3 rounded-lg mb-6 border-l-4 border-green-500 text-sm font-medium">
                Tüm işlem geçmişi başarıyla temizlendi.
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors p-3">
            <table id="auditTable" class="w-full text-sm text-left">
                <thead class="bg-slate-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-bold border-b dark:border-slate-700 text-xs uppercase">
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
                        $action = mb_strtoupper($log['action'] ?? '', 'UTF-8');
                        
                        // İşlem Tipine Göre Rozet Renkleri
                        $badgeClass = "bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300";
                        if ($action === 'EKLEME') {
                            $badgeClass = "bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300";
                        } elseif ($action === 'SİLME' || $action === 'TEMİZLEME') {
                            $badgeClass = "bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300";
                        } elseif ($action === 'GÜNCELLEME' || $action === 'PROFİL') {
                            $badgeClass = "bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300";
                        } elseif ($action === 'TRANSFER') {
                            $badgeClass = "bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-300";
                        } elseif ($action === 'TÜKETİM') {
                            $badgeClass = "bg-orange-100 text-orange-800 dark:bg-orange-900/50 dark:text-orange-300";
                        } elseif ($action === 'LOGIN' || $action === 'LOGIN_COOKIE') {
                            $badgeClass = "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300";
                        } elseif ($action === 'ÇIKIŞ') {
                            $badgeClass = "bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300";
                        }
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                        <td class="p-3 whitespace-nowrap text-xs font-mono" data-order="<?= $timestamp ?>">
                            <?= $zaman ?>
                        </td>
                        <td class="p-3 font-semibold text-slate-800 dark:text-slate-200">
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-full bg-slate-200 dark:bg-slate-600 flex items-center justify-center text-[10px] shrink-0 font-bold">
                                    <?= strtoupper(substr($log['username'] ?? 'U', 0, 1)) ?>
                                </div>
                                <span class="truncate"><?= htmlspecialchars($log['username'] ?? 'Bilinmiyor') ?></span>
                            </div>
                        </td>
                        <td class="p-3 whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wide border border-transparent <?= $badgeClass ?>">
                                <?= htmlspecialchars($action) ?>
                            </span>
                        </td>
                        <td class="p-3 text-xs leading-relaxed max-w-md break-words">
                            <?= htmlspecialchars($log['details'] ?? '') ?>
                        </td>
                        <td class="p-3 text-[11px] font-mono text-slate-400 whitespace-nowrap">
                            <?= htmlspecialchars($log['ip_address'] ?? '0.0.0.0') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script nonce="<?= $cspNonce ?>">
$(document).ready(function() {
    $('#auditTable').DataTable({
        "language": {
            "search": "Tabloda Ara:",
            "lengthMenu": "_MENU_ kayıt göster",
            "info": "_TOTAL_ kayıttan _START_ - _END_ arası gösteriliyor",
            "infoEmpty": "Kayıt yok",
            "infoFiltered": "(_MAX_ kayıt içerisinden filtrelendi)",
            "zeroRecords": "Eşleşen kayıt bulunamadı",
            "paginate": {
                "first": "İlk",
                "last": "Son",
                "next": "Sonraki",
                "previous": "Önceki"
            }
        },
        "pageLength": 25,
        "order": [[ 0, "desc" ]],
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