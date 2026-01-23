<?php
// ajax.php - Frontend ile Arka Plan Haberleşmesi
require 'db.php';

// Oturum başlatılmamışsa başlat
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// girisKontrol fonksiyonu varsa çalıştır
if (function_exists('girisKontrol')) {
    girisKontrol(); 
}

// JSON formatında yanıt vereceğimizi belirtelim
header('Content-Type: application/json; charset=utf-8');

// --- YARDIMCI FONKSİYON: TÜRKÇE SIRALAMA ---
// Bu fonksiyon hem düz dizileri (alt kategoriler) hem de veritabanı sonuçlarını (id, name) sıralar.
function turkceSirala(&$array, $key = null) {
    // 1. Yöntem: Sunucuda Intl (Uluslararasılaştırma) kütüphanesi varsa en temizi budur.
    if (class_exists('Collator')) {
        $collator = new Collator('tr_TR');
        if ($key) {
            // Veritabanı sonucu gibi çok boyutlu diziler için (name alanına göre)
            usort($array, function($a, $b) use ($collator, $key) {
                return $collator->compare($a[$key], $b[$key]);
            });
        } else {
            // Düz liste için (alt kategoriler)
            $collator->sort($array);
        }
    } 
    // 2. Yöntem: Intl yoksa manuel harf dönüşümü ile sıralama
    else {
        $sortFunc = function($a, $b) use ($key) {
            $valA = $key ? $a[$key] : $a;
            $valB = $key ? $b[$key] : $b;

            $tr_map = [
                'ç' => 'c1', 'Ç' => 'C1',
                'ğ' => 'g1', 'Ğ' => 'G1',
                'ı' => 'h1', 'I' => 'H1', // I harfini H'den sonraya at
                'i' => 'h2', 'İ' => 'H2', // İ harfini I'dan sonraya at
                'ö' => 'o1', 'Ö' => 'O1',
                'ş' => 's1', 'Ş' => 'S1',
                'ü' => 'u1', 'Ü' => 'U1'
            ];
            
            $transA = strtr(mb_strtolower($valA, 'UTF-8'), $tr_map);
            $transB = strtr(mb_strtolower($valB, 'UTF-8'), $tr_map);
            
            return strcmp($transA, $transB);
        };
        usort($array, $sortFunc);
    }
}

$islem = $_GET['islem'] ?? '';
$id    = $_GET['id'] ?? '';

try {
    // 1. MEKANLARI GETİR (Sıralı)
    if ($islem === 'get_mekanlar') {
        $cityId = $_GET['id'] ?? '';
        // SQL'de ORDER BY kaldırıldı, PHP'de sıralayacağız
        $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE city_id = ?");
        $stmt->execute([$cityId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        turkceSirala($data, 'name'); // 'name' alanına göre Türkçe sırala
        
        echo json_encode($data);
        exit;
    }
    
    // 2. ODALARI GETİR (Sıralı)
    elseif ($islem === 'get_odalar') {
        $locId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT id, name FROM rooms WHERE location_id = ?");
        $stmt->execute([$locId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        turkceSirala($data, 'name'); // 'name' alanına göre Türkçe sırala
        
        echo json_encode($data);
        exit;
    }
    
    // 3. DOLAPLARI GETİR (Sıralı)
    elseif ($islem === 'get_dolaplar') {
        $roomId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT id, name FROM cabinets WHERE room_id = ?");
        $stmt->execute([$roomId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        turkceSirala($data, 'name'); // 'name' alanına göre Türkçe sırala
        
        echo json_encode($data);
        exit;
    }
    
    // 4. DOLAP DETAY (Sıralama gerekmez, tek kayıt)
    elseif ($islem === 'get_dolap_detay') {
        $cabId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM cabinets WHERE id = ?");
        $stmt->execute([$cabId]);
        $dolap = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($dolap ?: []);
        exit;
    }
    
    // 5. ALT KATEGORİLERİ GETİR (Sıralı)
    elseif ($islem === 'get_alt_kategoriler') {
        $name = $_GET['name'] ?? '';

        $stmt = $pdo->prepare("SELECT sub_categories FROM categories WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);

        $subCats = [];
        if ($cat && !empty($cat['sub_categories'])) {
            $subCats = explode(',', $cat['sub_categories']);
            $subCats = array_map('trim', $subCats);
            $subCats = array_filter($subCats, fn($v) => $v !== '');
            
            turkceSirala($subCats); // Düz dizi olduğu için key vermiyoruz
        }

        echo json_encode(array_values($subCats));
        exit;
    }

    // 6. HIZLI TÜKETİM
    elseif ($islem === 'hizli_tuket') {
        // CSRF Kontrolü
        $token = $_GET['token'] ?? $_GET['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'error' => 'Güvenlik hatası (CSRF). Sayfayı yenileyin.']);
            exit;
        }

        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz ürün ID.']);
            exit;
        }
        
        $adet = isset($_GET['adet']) ? (float)$_GET['adet'] : 1;
        if ($adet <= 0) $adet = 1;

        $pdo->beginTransaction();

        $stmtInfo = $pdo->prepare("SELECT id, name, unit, quantity FROM products WHERE id = ? FOR UPDATE");
        $stmtInfo->execute([$id]);
        $urunInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        if ($urunInfo) {
            $mevcutMiktar = (float)$urunInfo['quantity'];
            $yeniMiktar   = $mevcutMiktar - $adet;

            if ($yeniMiktar <= 0) {
                $del = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $del->execute([$id]);
                $yeniMiktar = 0;
            } else {
                $update = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                $update->execute([$yeniMiktar, $id]);
            }
            
            if (function_exists('auditLog')) {
                auditLog('TÜKETİM', "{$urunInfo['name']} ürününden {$adet} {$urunInfo['unit']} hızlı tüketildi.");
            }
            
            $pdo->commit();

            echo json_encode([
                'success'    => true, 
                'yeni_miktar'=> max(0, $yeniMiktar), 
                'birim'      => $urunInfo['unit'],
                'dusulen'    => $adet
            ]);
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Ürün bulunamadı.']);
        }
        exit;
    }
    
    // 7. HIZLI TRANSFER
    elseif ($islem === 'hizli_transfer') {
        $token = $_GET['token'] ?? $_GET['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'error' => 'Güvenlik hatası (CSRF). Sayfayı yenileyin.']);
            exit;
        }

        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz ürün ID.']);
            exit;
        }

        $amount       = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;
        $new_cab_id   = $_GET['new_cab_id'] ?? '';
        $shelf_param  = $_GET['shelf_location'] ?? '';

        if ($amount <= 0 || empty($new_cab_id)) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz miktar veya dolap seçimi.']);
            return;
        }

        $pdo->beginTransaction();

        try {
            $stmt_current = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
            $stmt_current->execute([$id]);
            $urun = $stmt_current->fetch(PDO::FETCH_ASSOC);
            
            if (!$urun) {
                throw new Exception('Ürün bulunamadı.');
            }

            if ($new_cab_id === $urun['cabinet_id']) {
                throw new Exception('Hedef dolap zaten mevcut dolap.');
            }

            if ($amount > (float)$urun['quantity']) {
                throw new Exception('Yetersiz stok.');
            }
            
            $stmt_cab = $pdo->prepare("SELECT name FROM cabinets WHERE id = ?");
            $stmt_cab->execute([$new_cab_id]);
            $new_cab_name = $stmt_cab->fetchColumn();

            if (!$new_cab_name) {
                throw new Exception('Hedef dolap bulunamadı.');
            }

            $new_source_qty = (float)$urun['quantity'] - $amount;

            if ($new_source_qty <= 0) {
                $delSrc = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $delSrc->execute([$id]);
                $new_source_qty = 0;
            } else {
                $update_src = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                $update_src->execute([$new_source_qty, $id]);
            }

            $checkSql = "SELECT id FROM products 
                         WHERE cabinet_id = ? 
                           AND name = ? 
                           AND brand = ? 
                           AND (expiry_date = ? OR (expiry_date IS NULL AND ? IS NULL))";
            
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([
                $new_cab_id, 
                $urun['name'], 
                $urun['brand'], 
                $urun['expiry_date'], 
                $urun['expiry_date']
            ]);
            $existingProduct = $checkStmt->fetch(PDO::FETCH_ASSOC);

            $target_shelf = $shelf_param !== '' 
                ? $shelf_param 
                : ($urun['shelf_location'] ?? null);

            if ($existingProduct) {
                $update_dest = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                $update_dest->execute([$amount, $existingProduct['id']]);
            } else {
                $insertSql = "INSERT INTO products (
                        id, cabinet_id, shelf_location, 
                        name, brand, product_type, weight_volume, 
                        category, sub_category, quantity, unit, 
                        purchase_date, expiry_date, added_by_user_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmtIns = $pdo->prepare($insertSql);
                $newId = uniqid('prod_');

                $stmtIns->execute([
                    $newId,
                    $new_cab_id,
                    $target_shelf,
                    $urun['name'],
                    $urun['brand'],
                    $urun['product_type'] ?? null,
                    $urun['weight_volume'] ?? null,
                    $urun['category'],
                    $urun['sub_category'],
                    $amount,
                    $urun['unit'],
                    $urun['purchase_date'],
                    $urun['expiry_date'],
                    $urun['added_by_user_id']
                ]);
            }

            if (function_exists('auditLog')) {
                $rafText = $target_shelf ? " (Raf/Bölüm: {$target_shelf})" : "";
                auditLog(
                    'TRANSFER', 
                    "{$urun['name']} ({$amount} {$urun['unit']}) '$new_cab_name' dolabına transfer edildi{$rafText}."
                );
            }

            $pdo->commit();
            
            echo json_encode([
                'success'        => true, 
                'amount'         => $amount, 
                'unit'           => $urun['unit'],
                'new_cab_name'   => $new_cab_name,
                'new_source_qty' => $new_source_qty
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
?>
