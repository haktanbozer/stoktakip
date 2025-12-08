<?php
require 'db.php';
girisKontrol();

// Sadece Admin Girebilir
if ($_SESSION['role'] !== 'ADMIN') die("Yetkisiz erişim.");

$mesaj = '';

// --- İŞLEMLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // KRİTİK GÜVENLİK DÜZELTMESİ: CSRF token kontrolü
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    // Ekleme İşlemi
    if (isset($_POST['ekle'])) {
        $gun = (int)$_POST['gun'];
        if ($gun > 0) {
            try {
                // Aynı gün zaten varsa hata verir (Primary Key)
                $stmt = $pdo->prepare("INSERT INTO notification_thresholds (days) VALUES (?)");
                $stmt->execute([$gun]);
                $mesaj = "✅ $gun gün kala bildirimi eklendi.";
            } catch (PDOException $e) {
                $mesaj = "⚠️ Bu gün sayısı zaten listede var.";
            }
        }
    }

    // Silme İşlemi
    if (isset($_POST['sil_gun'])) {
        $stmt = $pdo->prepare("DELETE FROM notification_thresholds WHERE days = ?");
        $stmt->execute([$_POST['sil_gun']]);
        $mesaj = "🗑️ Ayar silindi.";
    }
}

// Mevcut Ayarları Çek (Büyükten küçüğe sırala)
$gunler = $pdo->query("SELECT * FROM notification_thresholds ORDER BY days DESC")->fetchAll(PDO::FETCH_COLUMN);

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-6 transition-colors">Bildirim Ayarları</h2>

        <?php if($mesaj): ?>
            <div class="bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200 p-3 rounded mb-6 border-l-4 border-blue-500 dark:border-blue-400 transition-colors">
                <?= $mesaj ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            
            <div class="space-y-6">
                <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-lg mb-4 text-slate-800 dark:text-white">Yeni Kural Ekle</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                        Ürünlerin son kullanma tarihine kaç gün kala e-posta gönderileceğini belirleyin.
                        (Örn: 90 gün, 30 gün, 3 gün vb.)
                    </p>
                    
                    <form method="POST" class="flex gap-2">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="number" name="gun" placeholder="Gün Sayısı" required min="1" class="flex-1 p-2 border rounded focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:focus:ring-blue-400 transition-colors">
                        <button type="submit" name="ekle" value="1" class="bg-blue-600 dark:bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-700 dark:hover:bg-blue-600 font-medium transition-colors">Ekle</button>
                    </form>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border border-blue-100 dark:border-blue-800 text-sm text-blue-800 dark:text-blue-300 transition-colors">
                    <span class="text-xl mr-1">ℹ️</span> <b>Nasıl Çalışır?</b><br>
                    <div class="mt-1 opacity-90">
                        Sistem her gün (Cron Job ile) bu listeyi kontrol eder. Eğer bir ürünün SKT'sine listedeki gün sayısı kadar vakit kaldıysa, tüm kullanıcılara otomatik e-posta gönderilir.
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-lg mb-4 text-slate-800 dark:text-white border-b dark:border-slate-700 pb-2">Aktif Bildirim Günleri</h3>
                
                <?php if(empty($gunler)): ?>
                    <p class="text-slate-400 dark:text-slate-500 text-center py-4">Hiç kural tanımlanmamış.</p>
                <?php else: ?>
                    <div class="space-y-2 max-h-96 overflow-y-auto pr-1 custom-scrollbar">
                        <?php foreach($gunler as $g): ?>
                        <div class="flex justify-between items-center p-3 bg-slate-50 dark:bg-slate-700/40 rounded hover:bg-white dark:hover:bg-slate-700 border border-slate-100 dark:border-slate-700/50 hover:border-slate-300 dark:hover:border-slate-600 transition group">
                            <span class="font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
                                📅 <?= $g ?> Gün Kala
                            </span>
                            <form method="POST" onsubmit="return confirm('Bu kuralı silmek istediğinize emin misiniz?')">
                                <?php echo csrfAlaniniEkle(); ?>
                                <input type="hidden" name="sil_gun" value="<?= $g ?>">
                                <button type="submit" class="text-red-400 hover:text-red-600 dark:hover:text-red-400 p-2 opacity-60 group-hover:opacity-100 transition" title="Sil">✕</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
</body>
</html>
