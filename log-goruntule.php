<?php
// log-goruntule.php - Güvenli Sistem Log Görüntüleyici (Yalnızca Admin)
require 'db.php';
girisKontrol();

// SADECE ADMIN ERİŞEBİLİR
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user = $_SESSION['username'] ?? 'Bilinmeyen';
    sistemLogla("Yetkisiz Erişim Engellendi: log-goruntule.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    header("Location: index.php?hata=yetkisiz");
    exit;
}

if (!isset($cspNonce)) { $cspNonce = ''; }

$logDizini = __DIR__ . '/logs';
$mesaj = '';
$mesajTuru = 'info';

// --- LOG SİLME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sil_log'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');

    $silinecek = basename($_POST['sil_log'] ?? ''); // Güvenlik: basename ile path traversal engeli
    if (preg_match('/^app_[\d-]+\.log$/', $silinecek)) {
        $tamYol = $logDizini . '/' . $silinecek;
        if (file_exists($tamYol)) {
            unlink($tamYol);
            auditLog('LOG_SİLME', "Log dosyası silindi: $silinecek");
            $mesaj = "\"$silinecek\" başarıyla silindi.";
            $mesajTuru = 'success';
        }
    } else {
        $mesaj = "Geçersiz log dosyası adı.";
        $mesajTuru = 'error';
    }
}

// --- LOG DOSYALARINI LİSTELE ---
$logDosyalari = [];
if (is_dir($logDizini)) {
    $dosyalar = glob($logDizini . '/app_*.log');
    if ($dosyalar) {
        // En yeni tarihli dosya önce
        rsort($dosyalar);
        foreach ($dosyalar as $d) {
            $logDosyalari[] = basename($d);
        }
    }
}

// --- SEÇİLEN LOG DOSYASINI OKU ---
// Güvenlik: Yalnızca geçerli formattaki dosya adlarına izin ver
$secilenDosya = '';
$satirlar     = [];
$toplamSatir  = 0;
$filtre       = strtoupper(trim($_GET['filtre'] ?? ''));
$sayfa        = max(1, (int)($_GET['sayfa'] ?? 1));
$sayfaBasi    = 100; // Sayfa başına satır

if (!empty($_GET['dosya'])) {
    $istenen = basename($_GET['dosya']);
    // Beyaz liste: Yalnızca app_YYYY-MM-DD.log formatına izin ver
    if (preg_match('/^app_[\d-]+\.log$/', $istenen) && in_array($istenen, $logDosyalari)) {
        $secilenDosya = $istenen;
        $tamYol = $logDizini . '/' . $secilenDosya;

        if (file_exists($tamYol)) {
            $icerik = file($tamYol, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $icerik = array_reverse($icerik); // En yeni satır üstte

            // Filtrele
            if (!empty($filtre)) {
                $icerik = array_filter($icerik, fn($s) => str_contains(strtoupper($s), $filtre));
                $icerik = array_values($icerik);
            }

            $toplamSatir = count($icerik);
            $toplamSayfa = max(1, ceil($toplamSatir / $sayfaBasi));
            $sayfa       = min($sayfa, $toplamSayfa);
            $baslangic   = ($sayfa - 1) * $sayfaBasi;
            $satirlar    = array_slice($icerik, $baslangic, $sayfaBasi);
        }
    }
}

// Varsayılan: En son log dosyasını aç
if (empty($secilenDosya) && !empty($logDosyalari)) {
    header("Location: log-goruntule.php?dosya=" . urlencode($logDosyalari[0]));
    exit;
}

require 'header.php';
?>

<div class="max-w-6xl mx-auto">
    <!-- Başlık -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-3">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white flex items-center gap-2">
                📋 Hata Kayıtları (Sistem Logları)
            </h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Yalnızca yöneticiler bu sayfayı görebilir.</p>
        </div>
        <a href="admin.php" class="text-sm text-blue-500 hover:underline flex items-center gap-1 dark:text-blue-400">
            ← Yönetim Paneli
        </a>
    </div>

    <?php if ($mesaj): ?>
        <div class="mb-4 p-3 rounded-lg text-sm font-medium
            <?= $mesajTuru === 'success' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' ?>">
            <?= htmlspecialchars($mesaj) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">

        <!-- Sol: Dosya Listesi -->
        <div class="col-span-1 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-4">
            <h3 class="font-bold text-slate-700 dark:text-slate-300 text-sm mb-3 flex items-center gap-2">
                📁 Log Dosyaları
                <span class="text-xs font-normal text-slate-400">(<?= count($logDosyalari) ?> adet)</span>
            </h3>

            <?php if (empty($logDosyalari)): ?>
                <p class="text-xs text-slate-400 italic">Henüz log dosyası yok.</p>
            <?php else: ?>
                <ul class="space-y-1">
                    <?php foreach ($logDosyalari as $dosya): ?>
                        <?php
                        $tamYolBoyut = $logDizini . '/' . $dosya;
                        $boyut = file_exists($tamYolBoyut) ? round(filesize($tamYolBoyut) / 1024, 1) . ' KB' : '-';
                        $aktif = $dosya === $secilenDosya;
                        ?>
                        <li class="flex items-center justify-between gap-1">
                            <a href="?dosya=<?= urlencode($dosya) ?>"
                               class="flex-1 text-xs px-2 py-1.5 rounded-lg truncate transition
                                      <?= $aktif ? 'bg-blue-600 text-white font-bold' : 'hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400' ?>">
                                <?= htmlspecialchars(str_replace(['app_', '.log'], '', $dosya)) ?>
                                <span class="<?= $aktif ? 'opacity-70' : 'text-slate-400' ?> font-normal"><?= $boyut ?></span>
                            </a>
                            <!-- Silme Formu -->
                            <form method="POST" onsubmit="return confirm('Bu log dosyasını silmek istediğinize emin misiniz?')">
                                <?php echo csrfAlaniniEkle(); ?>
                                <input type="hidden" name="sil_log" value="<?= htmlspecialchars($dosya) ?>">
                                <button type="submit" class="text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 p-1 rounded transition" title="Bu Logu Sil">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Sağ: Log İçeriği -->
        <div class="col-span-1 md:col-span-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-4">

            <?php if (!empty($secilenDosya)): ?>

                <!-- Araç Çubuğu -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
                    <div>
                        <h3 class="font-bold text-slate-700 dark:text-white text-sm">
                            📄 <?= htmlspecialchars($secilenDosya) ?>
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">
                            <?= $toplamSatir ?> kayıt
                            <?= !empty($filtre) ? '(filtrelenmiş)' : '' ?>
                        </p>
                    </div>

                    <!-- Filtre -->
                    <form method="GET" class="flex gap-2 items-center">
                        <input type="hidden" name="dosya" value="<?= htmlspecialchars($secilenDosya) ?>">
                        <select name="filtre" onchange="this.form.submit()"
                                class="text-xs border rounded-lg px-2 py-1.5 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                            <option value="" <?= empty($filtre) ? 'selected' : '' ?>>Tümü</option>
                            <option value="CRITICAL" <?= $filtre === 'CRITICAL' ? 'selected' : '' ?>>🔴 CRITICAL</option>
                            <option value="SECURITY" <?= $filtre === 'SECURITY' ? 'selected' : '' ?>>🔐 SECURITY</option>
                            <option value="ERROR"    <?= $filtre === 'ERROR'    ? 'selected' : '' ?>>🟠 ERROR</option>
                            <option value="WARNING"  <?= $filtre === 'WARNING'  ? 'selected' : '' ?>>🟡 WARNING</option>
                            <option value="INFO"     <?= $filtre === 'INFO'     ? 'selected' : '' ?>>🔵 INFO</option>
                            <option value="FATAL"    <?= $filtre === 'FATAL'    ? 'selected' : '' ?>>💀 FATAL</option>
                        </select>
                    </form>
                </div>

                <!-- Log Satırları -->
                <?php if (empty($satirlar)): ?>
                    <div class="text-center py-10 text-slate-400">
                        <p class="text-3xl mb-2">✅</p>
                        <p class="text-sm">Bu filtreyle eşleşen kayıt bulunamadı.</p>
                    </div>
                <?php else: ?>
                    <div class="font-mono text-xs space-y-0.5 max-h-[70vh] overflow-y-auto">
                        <?php foreach ($satirlar as $satir): ?>
                            <?php
                            // Seviyeye göre renk belirle
                            $renk = 'text-slate-500 dark:text-slate-400';
                            $bg   = '';
                            if (str_contains($satir, '[CRITICAL]') || str_contains($satir, '[FATAL]')) {
                                $renk = 'text-red-700 dark:text-red-400 font-bold';
                                $bg   = 'bg-red-50 dark:bg-red-900/20';
                            } elseif (str_contains($satir, '[SECURITY]')) {
                                $renk = 'text-purple-700 dark:text-purple-400 font-bold';
                                $bg   = 'bg-purple-50 dark:bg-purple-900/20';
                            } elseif (str_contains($satir, '[ERROR]')) {
                                $renk = 'text-orange-700 dark:text-orange-400';
                                $bg   = 'bg-orange-50 dark:bg-orange-900/10';
                            } elseif (str_contains($satir, '[WARNING]')) {
                                $renk = 'text-yellow-700 dark:text-yellow-400';
                                $bg   = 'bg-yellow-50 dark:bg-yellow-900/10';
                            } elseif (str_contains($satir, '[INFO]')) {
                                $renk = 'text-blue-600 dark:text-blue-400';
                            }
                            ?>
                            <div class="<?= $bg ?> px-2 py-0.5 rounded <?= $renk ?> break-all leading-relaxed">
                                <?= htmlspecialchars($satir) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Sayfalama -->
                    <?php
                    $toplamSayfa = max(1, ceil($toplamSatir / $sayfaBasi));
                    if ($toplamSayfa > 1):
                    ?>
                        <div class="flex justify-center gap-1 mt-4 flex-wrap">
                            <?php for ($s = 1; $s <= $toplamSayfa; $s++): ?>
                                <a href="?dosya=<?= urlencode($secilenDosya) ?>&filtre=<?= urlencode($filtre) ?>&sayfa=<?= $s ?>"
                                   class="px-2.5 py-1 rounded text-xs border transition
                                          <?= $s === $sayfa
                                              ? 'bg-blue-600 text-white border-blue-600'
                                              : 'border-slate-200 dark:border-slate-600 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                    <?= $s ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                        <p class="text-center text-xs text-slate-400 mt-2">
                            Sayfa <?= $sayfa ?> / <?= $toplamSayfa ?> — Toplam <?= $toplamSatir ?> kayıt
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

            <?php else: ?>
                <div class="text-center py-16 text-slate-400">
                    <p class="text-4xl mb-3">📂</p>
                    <p class="text-sm">Soldan bir log dosyası seçin.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
