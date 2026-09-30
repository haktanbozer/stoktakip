<?php
// bildirim-ayarlari.php - Son Kullanma Tarihi Bildirim Eşikleri
require 'db.php';
girisKontrol();

// Güvenlik & Yetki Kontrolü
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    if (function_exists('sistemLogla')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user = $_SESSION['username'] ?? 'Bilinmeyen';
        sistemLogla("Yetkisiz Sayfa Erişimi Engellendi: bildirim-ayarlari.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    }
    header("Location: index.php?hata=yetkisiz");
    exit;
}

if (!isset($cspNonce)) { $cspNonce = ''; }

$mesaj = '';
$mesajTuru = 'info';

// --- İŞLEMLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    // Ekleme İşlemi
    if (isset($_POST['ekle'])) {
        $gun = (int)($_POST['gun'] ?? 0);
        if ($gun <= 0 || $gun > 730) {
            $mesaj = "Lütfen 1 ile 730 gün arasında geçerli bir değer giriniz.";
            $mesajTuru = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO notification_thresholds (days) VALUES (?)");
                $stmt->execute([$gun]);
                
                if (function_exists('auditLog')) {
                    auditLog('BİLDİRİM_AYARI', "Yeni bildirim eşiği eklendi: $gun gün");
                }
                
                $mesaj = "$gun gün kala bildirim kuralı başarıyla eklendi.";
                $mesajTuru = 'success';
            } catch (PDOException $e) {
                $mesaj = "Bu gün sayısı ($gun gün) zaten tanımlı.";
                $mesajTuru = 'warning';
            }
        }
    }

    // Silme İşlemi
    if (isset($_POST['sil_gun'])) {
        $silGun = (int)$_POST['sil_gun'];
        $stmt = $pdo->prepare("DELETE FROM notification_thresholds WHERE days = ?");
        $stmt->execute([$silGun]);

        if (function_exists('auditLog')) {
            auditLog('BİLDİRİM_AYARI', "Bildirim eşiği silindi: $silGun gün");
        }

        $mesaj = "$silGun gün kuralı silindi.";
        $mesajTuru = 'success';
    }
}

// Mevcut Ayarları Çek (Büyükten küçüğe sıralı)
$gunler = $pdo->query("SELECT days FROM notification_thresholds ORDER BY days DESC")->fetchAll(PDO::FETCH_COLUMN);

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                🔔 Bildirim & Eşik Ayarları
            </h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">Otomatik e-posta bildirimlerinin tetikleneceği son kullanma tarihi eşikleri.</p>
        </div>

        <?php if(!empty($mesaj)): ?>
            <?php 
                $alertRenk = $mesajTuru === 'success' 
                    ? 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 border-green-500' 
                    : ($mesajTuru === 'error' ? 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 border-red-500' : 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200 border-yellow-500');
            ?>
            <div class="<?= $alertRenk ?> p-3.5 rounded-xl mb-6 border-l-4 transition-colors text-sm font-medium">
                <?= htmlspecialchars($mesaj) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            
            <div class="space-y-6">
                <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-base mb-2 text-slate-800 dark:text-white">➕ Yeni Eşik Kuralı Ekle</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 leading-relaxed">
                        Ürünlerin son kullanma tarihine kaç gün kala e-posta gönderileceğini belirleyin (Örn: 90, 30, 7 gün).
                    </p>
                    
                    <form method="POST" class="flex gap-2">
                        <?php echo csrfAlaniniEkle(); ?>
                        <input type="number" name="gun" placeholder="Örn: 15" required min="1" max="730" class="flex-1 p-2.5 border rounded-lg focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white text-sm transition-colors">
                        <button type="submit" name="ekle" value="1" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-bold text-sm shadow-md shadow-blue-500/20 transition">Kuralı Ekle</button>
                    </form>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-800 text-xs text-blue-800 dark:text-blue-300 transition-colors leading-relaxed">
                    <span class="text-base mr-1">ℹ️</span> <b>Nasıl Çalışır?</b><br>
                    <div class="mt-1.5 opacity-90 space-y-1">
                        <p>• Sunucuda zamanlanmış Cron Job (<code>cron-mail.php</code>) her sabah bu eşikleri kontrol eder[cite: 4, 22].</p>
                        <p>• Son kullanma tarihine tam olarak bu gün sayısı kalan ürünler ilgili konumdaki kullanıcılara e-posta olarak iletilir[cite: 4, 22].</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
                <div class="flex items-center justify-between border-b dark:border-slate-700 pb-3 mb-4">
                    <h3 class="font-bold text-base text-slate-800 dark:text-white">Aktif Bildirim Günleri</h3>
                    <span class="text-xs bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded-full font-bold">
                        <?= count($gunler) ?> Kural
                    </span>
                </div>
                
                <?php if(empty($gunler)): ?>
                    <p class="text-slate-400 dark:text-slate-500 text-center py-6 text-sm">Hiç kural tanımlanmamış. Sistem otomatik uyarı gönderemez[cite: 22].</p>
                <?php else: ?>
                    <div class="space-y-2 max-h-96 overflow-y-auto pr-1">
                        <?php foreach($gunler as $g): ?>
                        <div class="flex justify-between items-center p-3 bg-slate-50 dark:bg-slate-700/40 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700/70 border border-slate-100 dark:border-slate-700 transition group">
                            <span class="font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2 text-sm">
                                📅 <span class="font-bold text-blue-600 dark:text-blue-400"><?= (int)$g ?></span> Gün Kala
                            </span>
                            <form method="POST" onsubmit="return confirm('<?= (int)$g ?> gün kuralını silmek istediğinize emin misiniz?')">
                                <?php echo csrfAlaniniEkle(); ?>
                                <input type="hidden" name="sil_gun" value="<?= (int)$g ?>">
                                <button type="submit" class="text-red-400 hover:text-red-600 dark:hover:text-red-400 px-2.5 py-1 text-xs rounded transition opacity-80 group-hover:opacity-100 font-bold" title="Kuralı Sil">✕ Sil</button>
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