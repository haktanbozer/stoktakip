<?php
require 'db.php';
girisKontrol();

// --- AYARLAR ---
// GÜVENLİK GÜNCELLEMESİ: API Anahtarı artık .env dosyasından okunuyor
$apiKey = getenv('GEMINI_API_KEY'); 
$mesaj = '';
$tarif = '';

// ... (Kodun geri kalanı aynı şekilde devam eder) ...
// YENİ EKLENEN: İzin verilen GIDA kategorileri listesi
$izinVerilenGidaKategorileri = ['Gıda', 'Bakliyat', 'Süt Ürünleri', 'İçecek', 'Atıştırmalık', 'Baharat', 'Pirinç', 'Bulgur', 'Un', 'Makarna', 'Tatlı', 'Ayçiçek Yağı', 'Zeytinyağı', 'Sirke', 'Zeytin', 'Peynir', 'Şarküteri', 'Et', 'Balık', 'Tavuk', 'Dondurma', 'Sos', 'Tereyağı', 'Hazır Çorba', 'Kuruyemiş']; 
$kategoriKosulu = "'" . implode("','", $izinVerilenGidaKategorileri) . "'"; 

// Malzemeleri Çek
$sql = "SELECT 
            p.name, 
            p.quantity, 
            p.unit, 
            p.category,
            p.sub_category,
            p.expiry_date
        FROM products p 
        WHERE p.quantity > 0 
          AND p.category IN ($kategoriKosulu) 
          AND (p.expiry_date <= DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY) OR p.expiry_date IS NULL)
        ORDER BY (p.expiry_date IS NULL), p.expiry_date ASC, p.name ASC 
        LIMIT 30";

$urunler = $pdo->query($sql)->fetchAll(); 

// ... (Geri kalan liste oluşturma ve API sorgusu kısımları aynı) ...
// Malzeme Listesini Metne Çevir
$malzemeListesi = [];
$bugun = time();
foreach ($urunler as $u) {
    $ekBilgi = "";
    if ($u['expiry_date']) {
        $skt = strtotime($u['expiry_date']);
        $kalanGun = ceil(($skt - $bugun) / (60 * 60 * 24));
        if ($kalanGun < 0) {
            $ekBilgi = "(ÇOK ACİL/GEÇMİŞ)";
        } elseif ($kalanGun <= 14) {
            $ekBilgi = "({$kalanGun} GÜN KALDI)";
        }
    }
    $malzemeListesi[] = "{$u['name']} (Alt Kategori: {$u['sub_category']}) ({$u['quantity']} {$u['unit']}) {$ekBilgi}";
}
$malzemeMetni = implode('; ', $malzemeListesi);

// YAPAY ZEKA SORGUSU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oner'])) {
    
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    if (empty($apiKey)) {
        $mesaj = "⚠️ API anahtarı yapılandırılmamış (.env dosyası eksik veya boş).";
    } elseif (empty($malzemeMetni)) {
        $mesaj = "⚠️ Menü önerisi için stokta yeterli gıda malzemesi bulunmuyor.";
    } else {
        // ... (Prompt ve cURL işlemleri aynı) ...
        $prompt = "Sen dünyaca ünlü, yaratıcı ve pratik bir Türk şefisin. Elimde şu malzemeler var: [$malzemeMetni]. 
        LÜTFEN ÖZELLİKLE '(ÇOK ACİL/GEÇMİŞ)' veya '(XX GÜN KALDI)' etiketi olan malzemeleri öncelikli olarak kullanmaya çalış. 
        Bu malzemelerin çoğunu (hepsini kullanmak zorunda değilsin) kullanarak yapabileceğim lezzetli bir yemek tarifi öner. 
        Evde yağ, tuz, salça, baharat gibi temel malzemelerin olduğunu varsay.
        Cevabı şu formatta ver:
        1. Yemek Adı (Kalın ve büyük)
        2. Kısa ve iştah açıcı bir açıklama.
        3. Gerekli Malzemeler listesi.
        4. Adım adım yapılışı.
        5. Şefin Püf Noktası.";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
        
        $data = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $mesaj = "Bağlantı hatası: " . curl_error($ch);
        } else {
            $result = json_decode($response, true);

            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $hamMetin = $result['candidates'][0]['content']['parts'][0]['text'];
                $tarif = nl2br(htmlspecialchars($hamMetin));
                $tarif = str_replace(['**', '##', '#'], '', $tarif); 
            } else {
                $hataDetayi = isset($result['error']['message']) ? $result['error']['message'] : 'Bilinmeyen hata';
                $mesaj = "❌ Hata: " . $hataDetayi;
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
                    👨‍🍳 Yapay Zeka Şef
                    <span class="bg-gradient-to-r from-blue-500 to-purple-600 text-white text-xs px-2 py-1 rounded-full">AI Powered</span>
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">Stoklarınızdaki **gıda ürünlerine** göre akıllı yemek önerileri.</p>
            </div>
        </div>

        <?php if($mesaj): ?>
            <div class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 p-4 rounded-xl mb-6 border border-red-200 dark:border-red-800 transition-colors"><?= $mesaj ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow border border-slate-200 dark:border-slate-700 h-fit transition-colors">
                <h3 class="font-bold text-slate-700 dark:text-white mb-4 border-b dark:border-slate-700 pb-2">📦 Mevcut Gıda Malzemelerim</h3>
                <div class="text-sm text-slate-600 dark:text-slate-300 space-y-1 mb-6 max-h-60 overflow-y-auto custom-scrollbar">
                    <?php foreach($urunler as $u): ?>
                        <div class="flex justify-between">
                            <span class="font-medium"><?= htmlspecialchars($u['name']) ?></span>
                            <span class="text-slate-400 text-xs">
                                <?= $u['quantity'] . ' ' . $u['unit'] ?>
                                <?php 
                                    if ($u['expiry_date']) {
                                        $skt = strtotime($u['expiry_date']);
                                        $kalanGun = ceil(($skt - time()) / (60 * 60 * 24));
                                        if ($kalanGun < 0) {
                                            echo "<span class='text-red-500 ml-1'> (GEÇMİŞ)</span>";
                                        } elseif ($kalanGun <= 14) {
                                            echo "<span class='text-orange-500 ml-1'> ({$kalanGun} gün)</span>";
                                        }
                                    }
                                ?>
                            </span>
                        </div>
                        <div class="text-[10px] text-slate-400 dark:text-slate-500 ml-2">Alt Kategori: <?= htmlspecialchars($u['sub_category']) ?></div>
                    <?php endforeach; ?>
                    <?php if(empty($urunler)) echo "<p class='text-slate-400 dark:text-slate-500'>Stokta gıda ürünü yok.</p>"; ?>
                </div>
                
                <form method="POST">
                    <?php echo csrfAlaniniEkle(); ?>
                    <button type="submit" name="oner" <?= empty($urunler) ? 'disabled' : '' ?> class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white py-3 rounded-lg font-bold shadow-lg shadow-indigo-500/30 transition transform hover:scale-105 flex justify-center items-center gap-2 disabled:bg-gray-500 disabled:shadow-none disabled:transform-none">
                        ✨ Bana Yemek Öner
                    </button>
                </form>
                <p class="text-[10px] text-slate-400 dark:text-slate-500 text-center mt-3">Google Gemini AI tarafından desteklenmektedir.</p>
            </div>

            <div class="lg:col-span-2">
                <?php if($tarif): ?>
                    <div class="bg-white dark:bg-slate-800 p-8 rounded-xl shadow-lg border border-slate-200 dark:border-slate-700 transition-colors">
                        <div class="flex items-center gap-2 mb-4 text-purple-600 dark:text-purple-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01"/><path d="M16 15.5v.01"/><path d="M12 12v.01"/><path d="M11 17a2 2 0 0 1 2 2"/></svg>
                            <h3 class="font-bold text-xl">Şefin Önerisi Hazır!</h3>
                        </div>
                        <div class="text-slate-700 dark:text-slate-300 leading-relaxed whitespace-pre-line bg-orange-50/50 dark:bg-orange-900/30 p-6 rounded-lg border border-orange-100 dark:border-orange-800">
                            <?= $tarif ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-slate-50 dark:bg-slate-700/50 border-2 border-dashed border-slate-200 dark:border-slate-600 rounded-xl h-64 flex flex-col items-center justify-center text-slate-400">
                        <div class="text-4xl mb-2">🍽️</div>
                        <p class="dark:text-slate-400">Henüz bir öneri istemediniz.</p>
                        <p class="text-sm dark:text-slate-500">Butona basın, şefimiz mutfağa girsin.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
</body>
</html>
