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
        csrfKontrol($_GET['token'] ?? $_GET['csrf_token'] ?? ''); 
        
        $adet = isset($_GET['adet']) ? (float)$_GET['adet'] : 1;
        
        if ($adet <= 0) $adet = 1;

        // ** AUDIT LOG İÇİN BİLGİ ÇEK **
        // Güncellemeden önce ürün adını alalım ki loga yazabilelim
        $stmtInfo = $pdo->prepare("SELECT name, unit FROM products WHERE id = ?");
        $stmtInfo->execute([$id]);
        $urunInfo = $stmtInfo->fetch();

        $update = $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
        $update->execute([$adet, $id]);
        
        // Tüketim Geçmişi Kaydı (Tüketim Analizi için)
        $pdo->prepare("INSERT INTO consumption_history (product_id, amount, consumed_at) VALUES (?, ?, NOW())")
            ->execute([$id, $adet]);
        
        // ** AUDIT LOG KAYDI **
        if ($urunInfo) {
            auditLog('TÜKETİM', "{$urunInfo['name']} ürününden {$adet} {$urunInfo['unit']} hızlı tüketildi.");
        }
        
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

        // 1. ÖNCE YEREL VERİTABANINI KONTROL ET
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
    
    // 8. HIZLI TRANSFER (Sunucu Tarafı Kontrolü Eklendi)
    elseif ($islem === 'hizli_transfer') {
        csrfKontrol($_GET['csrf_token'] ?? ''); 

        // Gelen verileri al ve temizle
        $amount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;
        $new_cab_id = $_GET['new_cab_id'] ?? '';
        
        if ($amount <= 0 || empty($new_cab_id)) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz miktar veya hedef dolap.']);
            return;
        }

        // 1. Ürünün mevcut durumunu veritabanından kontrol et
        $stmt_current = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt_current->execute([$id]);
        $urun = $stmt_current->fetch(PDO::FETCH_ASSOC);
        
        if (!$urun) {
            echo json_encode(['success' => false, 'error' => 'Ürün bulunamadı.']);
            return;
        }

        $current_qty = (float)$urun['quantity'];
        $current_cab_id = $urun['cabinet_id'];

        // Hedef dolap mevcut dolap mı?
        if ($new_cab_id === $current_cab_id) {
            echo json_encode(['success' => false, 'error' => 'Hedef dolap, ürünün mevcut dolabıyla aynı olamaz.']);
            return;
        }
        
        if ($amount > $current_qty) {
            echo json_encode(['success' => false, 'error' => 'Transfer miktarı stok miktarını aşıyor.']);
            return;
        }
        
        // 2. Dolap adını al (Yanıt ve Log için)
        $stmt_cab_name = $pdo->prepare("SELECT name FROM cabinets WHERE id = ?");
        $stmt_cab_name->execute([$new_cab_id]);
        $new_cab_name = $stmt_cab_name->fetchColumn() ?? 'Bilinmeyen Dolap';

        // 3. İşlem: Transferi Gerçekleştir
        $pdo->beginTransaction();
        
        try {
            if ($amount === $current_qty) {
                // Tamamı transfer ediliyorsa
                $update_sql = "UPDATE products SET cabinet_id = ? WHERE id = ?";
                $pdo->prepare($update_sql)->execute([$new_cab_id, $id]);

                // ** AUDIT LOG (TAM TRANSFER) **
                auditLog('TRANSFER', "{$urun['name']} (Tamamı - {$amount} {$urun['unit']}) '$new_cab_name' dolabına taşındı.");

            } else {
                // Kısmi transfer yapılıyorsa:
                // a. Mevcut kaydın miktarını azalt
                $update_sql = "UPDATE products SET quantity = quantity - ? WHERE id = ?";
                $pdo->prepare($update_sql)->execute([$amount, $id]);

                // b. Yeni bir ürün kaydı oluştur
                $new_id = uniqid('prod_');
                $new_sql = "INSERT INTO products (id, name, brand, category, sub_category, quantity, unit, cabinet_id, shelf_location, purchase_date, expiry_date, added_by_user_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $pdo->prepare($new_sql)->execute([
                    $new_id,
                    $urun['name'],
                    $urun['brand'],
                    $urun['category'],
                    $urun['sub_category'],
                    $amount, // Yeni kaydın miktarı
                    $urun['unit'],
                    $new_cab_id, // Yeni dolap ID'si
                    $urun['shelf_location'], 
                    $urun['purchase_date'],
                    $urun['expiry_date'],
                    $urun['added_by_user_id']
                ]);

                // ** AUDIT LOG (KISMI TRANSFER) **
                auditLog('TRANSFER', "{$urun['name']} (Parça - {$amount} {$urun['unit']}) '$new_cab_name' dolabına ayrıldı/taşındı.");
            }

            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'amount' => $amount, 
                'unit' => $urun['unit'],
                'new_cab_name' => $new_cab_name
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Transfer Hatası: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Veritabanı işlemi başarısız: ' . $e->getMessage()]);
        }
    }


} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
