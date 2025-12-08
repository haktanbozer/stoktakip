<?php
// ajax.php - Frontend ile Arka Plan Haberleşmesi
require 'db.php';
girisKontrol(); // KRİTİK GÜVENLİK DÜZELTMESİ: Oturum kontrolü eklendi

// JSON formatında yanıt vereceğimizi belirtelim
header('Content-Type: application/json; charset=utf-8');

$islem = $_GET['islem'] ?? '';
$id    = $_GET['id'] ?? '';

try {
    // 1-5. KONUM VE KATEGORİ İŞLEMLERİ
    if ($islem === 'get_mekanlar') {
        $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE city_id = ? ORDER BY name ASC");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetchAll());
    }
    elseif ($islem === 'get_odalar') {
        $stmt = $pdo->prepare("SELECT id, name FROM rooms WHERE location_id = ? ORDER BY name ASC");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetchAll());
    }
    elseif ($islem === 'get_dolaplar') {
        $stmt = $pdo->prepare("SELECT id, name FROM cabinets WHERE room_id = ? ORDER BY name ASC");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetchAll());
    }
    elseif ($islem === 'get_dolap_detay') {
        $stmt = $pdo->prepare("SELECT * FROM cabinets WHERE id = ?"); 
        $stmt->execute([$id]);
        $dolap = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($dolap);
    }
    elseif ($islem === 'get_alt_kategoriler') {
        $name = $_GET['name'] ?? '';
        $stmt = $pdo->prepare("SELECT sub_categories FROM categories WHERE name = ?");
        $stmt->execute([$name]);
        $cat = $stmt->fetch();
        
        $subCats = $cat ? explode(',', $cat['sub_categories']) : [];
        $subCats = array_map('trim', $subCats);
        $subCats = array_filter($subCats);
        
        echo json_encode(array_values($subCats));
    }

    // 6. HIZLI TÜKETİM (Tüketim Geçmişi Kaydı ile birlikte)
    elseif ($islem === 'hizli_tuket') {
        // KRİTİK GÜVENLİK DÜZELTMESİ: CSRF token kontrolü
        // AJAX isteğinin GET parametresi olarak token'ı taşıması gerekir.
        csrfKontrol($_GET['token'] ?? $_GET['csrf_token'] ?? ''); 
        
        $adet = isset($_GET['adet']) ? (float)$_GET['adet'] : 1;
        
        if ($adet <= 0) $adet = 1;

        $update = $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
        $update->execute([$adet, $id]);
        
        // Tüketim Geçmişi Kaydı (Tüketim Analizi için)
        $pdo->prepare("INSERT INTO consumption_history (product_id, amount, consumed_at) VALUES (?, ?, NOW())")
            ->execute([$id, $adet]);
        
        $stmt = $pdo->prepare("SELECT quantity, unit FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $urun = $stmt->fetch();
        
        echo json_encode([
            'success' => true, 
            'yeni_miktar' => $urun['quantity'], 
            'birim' => $urun['unit'],
            'dusulen' => $adet
        ]);
    }
    
    // 7. BARKOD ARAMA (Önce Yerel DB, Sonra OpenFoodFacts API)
    elseif ($islem === 'search_barcode') {
        $barcode = $_GET['barcode'] ?? '';
        
        // Güvenlik Kontrolü
        if (empty($barcode) || !is_numeric($barcode) || strlen($barcode) < 8) {
            echo json_encode(['error' => 'Geçersiz barkod formatı.', 'found' => false]);
            return;
        }

        // 1. ÖNCE YEREL VERİTABANINI KONTROL ET (ÖĞRENME MEKANİZMASI)
        $stmt_local = $pdo->prepare("SELECT name, brand, category FROM products WHERE barcode = ? LIMIT 1");
        $stmt_local->execute([$barcode]);
        $local_product = $stmt_local->fetch(PDO::FETCH_ASSOC);

        if ($local_product) {
            echo json_encode([
                'found' => true,
                'source' => 'local',
                'name' => htmlspecialchars($local_product['name']),
                'brand' => htmlspecialchars($local_product['brand']),
                'category' => htmlspecialchars($local_product['category'] ?? 'Genel')
            ]);
            return; 
        }

        // 2. YERELDE BULUNAMADIYSA, OPENFOODFACTS API'Yİ SORGULA
        $url = "https://world.openfoodfacts.org/api/v2/product/{$barcode}.json";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'StokTakipEvSistemi - v1.0'); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); 

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        
        if ($httpCode === 200 && isset($data['product']) && $data['status'] === 1) {
            $product = $data['product'];
            
            // API'den gelen veriyi al ve temizle
            $urunAdi = $product['product_name_tr'] ?? $product['product_name_en'] ?? $product['product_name'] ?? 'Bilinmeyen Ürün';
            $marka = $product['brands'] ?? '';
            $kategori = $product['categories_tags'][0] ?? 'Genel'; 

            // Kategori etiketlerini temizle (en:snacks -> Snacks)
            $kategori = preg_replace('/[a-z]{2}:/', '', $kategori);
            $kategori = ucwords(str_replace('-', ' ', $kategori));
            
            echo json_encode([
                'found' => true,
                'source' => 'api',
                'name' => htmlspecialchars($urunAdi),
                'brand' => htmlspecialchars($marka),
                'category' => htmlspecialchars($kategori),
                'barcode' => $barcode,
            ]);
        } else {
            echo json_encode(['found' => false, 'message' => 'Barkod veritabaninda bulunamadi.']);
        }
    }


} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
