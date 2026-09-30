<?php
// sef.php - Yapay Zeka Destekli Kiler Şefi
require 'db.php';
girisKontrol();

$apiKey = getenv('GEMINI_API_KEY'); 
$mesaj = '';
$tarif = '';

// Dışlanacak gıda dışı kategoriler (böylece yeni eklenen gıda kategorileri otomatik dahil olur)
$haricKategoriler = ['Temizlik', 'Deterjan', 'Kozmetik', 'Kişisel Bakım', 'Hırdavat', 'Elektronik', 'Diğer'];
$placeholders = implode(',', array_fill(0, count($haricKategoriler), '?'));

// --- SORGULARI HAZIRLA (ŞEHİR FİLTRELİ) ---
$joinSQL = "JOIN cabinets c ON p.cabinet_id = c.id 
            JOIN rooms r ON c.room_id = r.id 
            JOIN locations l ON r.location_id = l.id";

$whereSQL = "WHERE p.quantity > 0 AND (p.category NOT IN ($placeholders) OR p.category IS NULL)";
$params = $haricKategoriler;

// Aktif Şehir Filtresi
if (isset($_SESSION['aktif_sehir_id'])) {
    $whereSQL .= " AND l.city_id = ?";
    $params[] = $_SESSION['aktif_sehir_id'];
}

// Malzemeleri Çek (Önce SKT'si geçenler ve yaklaşanlar, sonra süresizler)
$sql = "SELECT 
            p.name, 
            p.quantity, 
            p.unit, 
            p.category,
            p.sub_category,
            p.expiry_date,
            p.is_opened
        FROM products p 
        $joinSQL
        $whereSQL
        ORDER BY (p.expiry_date IS NULL) ASC, p.expiry_date ASC, p.name ASC 
        LIMIT 40";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$urunler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Malzeme Listesini Metne Çevir
$malzemeListesi = [];
$bugun = strtotime('today');

foreach ($urunler as $u) {
    $ekBilgi = "";
    if (!empty($u['expiry_date'])) {
        $skt = strtotime($u['expiry_date']);
        $kalanGun = (int)round(($skt - $bugun) / 86400);
        if ($kalanGun < 0) {
            $ekBilgi = "(ACİL TÜKET - SKT: " . abs($kalanGun) . " GÜN GEÇTİ)";
        } elseif ($kalanGun <= 14) {
            $ekBilgi = "(ÖNCELİKLİ - {$kalanGun} GÜN KALDI)";
        }
    }
    
    $acikDurum = (!empty($u['is_opened']) && $u['is_opened'] == 1) ? "[Paketi Açık] " : "";
    $malzemeListesi[] = "{$acikDurum}{$u['name']} ({$u['quantity']} {$u['unit']}) {$ekBilgi}";
}
$malzemeMetni = implode('; ', $malzemeListesi);

// --- YAPAY ZEKA SORGUSU ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oner'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    if (empty($apiKey)) {
        $mesaj = "⚠️ GEMINI_API_KEY .env dosyasında bulunamadı. Lütfen API anahtarınızı tanımlayın.";
    } elseif (empty($malzemeMetni)) {
        $mesaj = "⚠️ Seçili konumda yemek önerisi yapabilecek gıda ürünü bulunamadı.";
    } else {
        $prompt = "Sen dünyaca ünlü, pratik, israf karşıtı usta bir Türk aşçısısın. 
Elimdeki mevcut mutfak envanteri: [{$malzemeMetni}]. 

Kurallar:
1. Özellikle '(ACİL TÜKET)' veya '(ÖNCELİKLİ)' veya '[Paketi Açık]' olarak işaretlenmiş gıdaları ziyan etmemek için tarifin merkezine al.
2. Evde temel sıvı yağ, zeytinyağı, tuz, karabiber, salça, soğan ve su gibi temel bileşenlerin olduğunu varsayabilirsin.
3. Listeden mantıklı bir kombinasyon seç (tüm malzemeleri zorla aynı yemeğe koymak zorunda değilsin).
4. Çıktıyı Türkçe olarak tam şu başlık yapısıyla ver:

## Yemek Adı
(İştah kabartan 1-2 cümlelik şef sunumu)

### Gerekli Malzemeler
* Malzeme 1
* Malzeme 2

### Hazırlanışı
1. Adım 1
2. Adım 2

### Şefin Püf Noktası
(Pratik bir pişirme veya saklama tüyosu)";

        // Güncel Gemini 2.5 Flash API Uç Noktası
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . urlencode($apiKey);
        
        $postData = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.7,
                "maxOutputTokens" => 1000
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $mesaj = "Bağlantı hatası: " . curl_error($ch);
            if (function_exists('sistemLogla')) sistemLogla("Gemini cURL Hatası: " . curl_error($ch), 'ERROR');
        } else {
            $result = json_decode($response, true);

            if ($httpCode === 200 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $hamMetin = $result['candidates'][0]['content']['parts'][0]['text'];
                
                // Markdown -> Güvenli HTML Dönüşümü
                $guvenliMetin = htmlspecialchars($hamMetin, ENT_QUOTES, 'UTF-8');
                $guvenliMetin = preg_replace('/^## (.*?)$/m', '<h3 class="text-xl font-bold text-slate-800 dark:text-white mt-2 mb-2 border-b border-orange-200 dark:border-orange-800 pb-1">$1</h3>', $guvenliMetin);
                $guvenliMetin = preg_replace('/^### (.*?)$/m', '<h4 class="text-base font-bold text-orange-600 dark:text-orange-400 mt-4 mb-2">$1</h4>', $guvenliMetin);
                $guvenliMetin = preg_replace('/\*\*(.*?)\*\*/', '<strong class="text-purple-700 dark:text-purple-400 font-bold">$1</strong>', $guvenliMetin);
                $guvenliMetin = preg_replace('/^\* (.*?)$/m', '<li class="ml-4 list-disc marker:text-orange-500 py-0.5">$1</li>', $guvenliMetin);
                $guvenliMetin = preg_replace('/^\d+\.\s+(.*?)$/m', '<li class="ml-4 list-decimal marker:text-blue-500 py-0.5">$1</li>', $guvenliMetin);
                
                $tarif = nl2br($guvenliMetin);
                
                if (function_exists('auditLog')) {
                    auditLog('AI_SEF', "Yapay zeka şefinden yemek önerisi alındı.");
                }
            } else {
                $hataDetayi = $result['error']['message'] ?? "Sunucudan geçersiz yanıt alındı (HTTP $httpCode)";
                $mesaj = "❌ AI Servis Hatası: " . htmlspecialchars($hataDetayi);
                if (function_exists('sistemLogla')) sistemLogla("Gemini API Hatası: " . $hataDetayi, 'WARNING');
            }
        }
        curl_close($ch);
    }
}

require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white flex items-center gap-2 transition-colors">
                    👨‍🍳 Yapay Zeka Kiler Şefi
                    <span class="bg-gradient-to-r from-blue-500 to-purple-600 text-white text-xs px-2.5 py-0.5 rounded-full font-bold">Gemini AI</span>
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <?php if(isset($_SESSION['aktif_sehir_ad'])): ?>
                        Konum: <b><?= htmlspecialchars($_SESSION['aktif_sehir_ad']) ?></b> stoklarına göre israfı önleyen menü önerisi.
                    <?php else: ?>
                        Tüm şehirlerdeki mevcut gıda stoklarınıza göre öneriler.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if(!empty($mesaj)): ?>
            <div class="bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 p-4 rounded-xl mb-6 border border-red-200 dark:border-red-800 transition-colors text-sm font-medium">
                <?= $mesaj ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- MALZEME LİSTESİ -->
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 h-fit transition-colors">
                <div class="flex items-center justify-between border-b dark:border-slate-700 pb-2 mb-4">
                    <h3 class="font-bold text-slate-700 dark:text-white text-sm uppercase tracking-wider">📦 Değerlendirilecek Stoklar</h3>
                    <span class="text-xs bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded-full font-bold">
                        <?= count($urunler) ?> Ürün
                    </span>
                </div>

                <div class="text-sm text-slate-600 dark:text-slate-300 space-y-2 mb-6 max-h-72 overflow-y-auto custom-scrollbar pr-1">
                    <?php if(empty($urunler)): ?>
                        <p class="text-slate-400 dark:text-slate-500 text-xs py-2">Bu konumda değerlendirilecek kayıtlı gıda bulunamadı.</p>
                    <?php else: ?>
                        <?php foreach($urunler as $u): 
                            $kalanGun = null;
                            if (!empty($u['expiry_date'])) {
                                $kalanGun = (int)round((strtotime($u['expiry_date']) - $bugun) / 86400);
                            }
                        ?>
                            <div class="border-b border-slate-100 dark:border-slate-700/60 pb-1.5">
                                <div class="flex justify-between items-center text-xs">
                                    <span class="font-semibold text-slate-800 dark:text-slate-200">
                                        <?= htmlspecialchars($u['name']) ?>
                                        <?php if(!empty($u['is_opened'])): ?>
                                            <span class="text-[9px] bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 px-1 rounded font-bold">Açık</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="font-mono text-slate-500 dark:text-slate-400">
                                        <?= (float)$u['quantity'] . ' ' . htmlspecialchars($u['unit']) ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center text-[10px] mt-0.5">
                                    <span class="text-slate-400"><?= htmlspecialchars($u['category'] ?? 'Gıda') ?></span>
                                    <?php if ($kalanGun !== null): ?>
                                        <?php if ($kalanGun < 0): ?>
                                            <span class="text-red-500 font-bold"><?= abs($kalanGun) ?> gün geçti</span>
                                        <?php elseif ($kalanGun <= 14): ?>
                                            <span class="text-orange-500 font-medium"><?= $kalanGun ?> gün kaldı</span>
                                        <?php else: ?>
                                            <span class="text-slate-400"><?= $kalanGun ?> gün</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-400">Süresiz</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <form method="POST">
                    <?php echo csrfAlaniniEkle(); ?>
                    <button type="submit" name="oner" <?= empty($urunler) ? 'disabled' : '' ?> class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white py-3 rounded-lg font-bold shadow-md shadow-indigo-500/20 transition transform hover:scale-[1.02] flex justify-center items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none">
                        ✨ Bana Yemek Öner
                    </button>
                </form>
                <p class="text-[10px] text-slate-400 dark:text-slate-500 text-center mt-3">Kilerinizdeki öncelikli ve açık gıdaları israf etmeden menüye dönüştürür.</p>
            </div>

            <!-- TARİF GÖSTERİM ALANI -->
            <div class="lg:col-span-2">
                <?php if(!empty($tarif)): ?>
                    <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-xl shadow-lg border border-slate-200 dark:border-slate-700 transition-colors">
                        <div class="flex items-center gap-2 mb-4 text-purple-600 dark:text-purple-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01"/><path d="M16 15.5v.01"/><path d="M12 12v.01"/><path d="M11 17a2 2 0 0 1 2 2"/></svg>
                            <h3 class="font-bold text-xl">Şefin Önerisi Hazır!</h3>
                        </div>
                        <div class="text-slate-700 dark:text-slate-300 leading-relaxed bg-amber-50/50 dark:bg-slate-900/50 p-6 rounded-xl border border-amber-200/60 dark:border-slate-700 text-sm space-y-2">
                            <?= $tarif ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-slate-50 dark:bg-slate-800/50 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl h-72 flex flex-col items-center justify-center text-slate-400">
                        <div class="text-4xl mb-2">🍽️</div>
                        <p class="dark:text-slate-300 font-medium">Henüz bir tarif istenmedi.</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Soldaki butona tıkladığınızda şef kilerdeki öncelikli ürünlere göre tarif hazırlar.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
</body>
</html>