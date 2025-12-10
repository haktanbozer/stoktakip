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

$islem = $_GET['islem'] ?? '';
// ID’ler VARCHAR (prod_..., cab_...) olduğu için numeric’e çevirmiyoruz
$id    = $_GET['id'] ?? '';

try {
    // 1-5. DROPDOWN VE KATEGORİ İŞLEMLERİ
    if ($islem === 'get_mekanlar') {
        $cityId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE city_id = ? ORDER BY name ASC");
        $stmt->execute([$cityId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));   // <-- sadece assos
        exit;
    }
    elseif ($islem === 'get_odalar') {
        $locId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT id, name FROM rooms WHERE location_id = ? ORDER BY name ASC");
        $stmt->execute([$locId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    elseif ($islem === 'get_dolaplar') {
        $roomId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT id, name FROM cabinets WHERE room_id = ? ORDER BY name ASC");
        $stmt->execute([$roomId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    elseif ($islem === 'get_dolap_detay') {
        $cabId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM cabinets WHERE id = ?");
        $stmt->execute([$cabId]);
        $dolap = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($dolap ?: []);
        exit;
    }
    elseif ($islem === 'get_alt_kategoriler') {
        $name = $_GET['name'] ?? '';

        // categories tablosunda sub_categories VARCHAR(…) "a,b,c" gibi tutulduğunu varsayıyorum
        $stmt = $pdo->prepare("SELECT sub_categories FROM categories WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);

        $subCats = [];
        if ($cat && !empty($cat['sub_categories'])) {
            $subCats = explode(',', $cat['sub_categories']);
            $subCats = array_map('trim', $subCats);
            $subCats = array_filter($subCats, fn($v) => $v !== '');
        }

        echo json_encode(array_values($subCats));
        exit;
    }

    // 6. HIZLI TÜKETİM
    elseif ($islem === 'hizli_tuket') {
        // CSRF Kontrolü (hem token hem csrf_token kabul)
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

        // Ürün bilgisini kilitleyerek al
        $stmtInfo = $pdo->prepare("SELECT id, name, unit, quantity FROM products WHERE id = ? FOR UPDATE");
        $stmtInfo->execute([$id]);
        $urunInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        if ($urunInfo) {
            $mevcutMiktar = (float)$urunInfo['quantity'];
            $yeniMiktar   = $mevcutMiktar - $adet;

            if ($yeniMiktar <= 0) {
                // Stok bittiyse ürün satırını tamamen sil
                $del = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $del->execute([$id]);
                $yeniMiktar = 0;
            } else {
                // Hâlâ stok varsa sadece güncelle
                $update = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                $update->execute([$yeniMiktar, $id]);
            }
            
            // Audit Log
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
        // CSRF Kontrol (hizli_tuket ile aynı mantık)
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
        $new_cab_id   = $_GET['new_cab_id'] ?? '';   // VARCHAR ID (ör: cab_...)
        $shelf_param  = $_GET['shelf_location'] ?? ''; // Yeni raf/bölüm seçimi

        if ($amount <= 0 || empty($new_cab_id)) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz miktar veya dolap seçimi.']);
            return;
        }

        $pdo->beginTransaction();

        try {
            // 1. Kaynak Ürünü Çek ve Kilitle
            $stmt_current = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
            $stmt_current->execute([$id]);
            $urun = $stmt_current->fetch(PDO::FETCH_ASSOC);
            
            if (!$urun) {
                throw new Exception('Ürün bulunamadı.');
            }

            // Aynı dolaba transferi engelle
            if ($new_cab_id === $urun['cabinet_id']) {
                throw new Exception('Hedef dolap zaten mevcut dolap.');
            }

            if ($amount > (float)$urun['quantity']) {
                throw new Exception('Yetersiz stok.');
            }
            
            // Hedef Dolap Adını Öğren
            $stmt_cab = $pdo->prepare("SELECT name FROM cabinets WHERE id = ?");
            $stmt_cab->execute([$new_cab_id]);
            $new_cab_name = $stmt_cab->fetchColumn();

            if (!$new_cab_name) {
                throw new Exception('Hedef dolap bulunamadı.');
            }

            // 2. KAYNAKTAN DÜŞ
            $new_source_qty = (float)$urun['quantity'] - $amount;

            if ($new_source_qty <= 0) {
                // Kaynak dolaptaki stok tamamen bittiyse bu satırı sil
                $delSrc = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $delSrc->execute([$id]);
                $new_source_qty = 0;
            } else {
                // Hâlâ stok varsa güncelle
                $update_src = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                $update_src->execute([$new_source_qty, $id]);
            }

            // 3. HEDEFİ KONTROL ET
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

            // Hedef raf bilgisini belirle (boşsa kaynaktaki rafa düşsün)
            $target_shelf = $shelf_param !== '' 
                ? $shelf_param 
                : ($urun['shelf_location'] ?? null);

            if ($existingProduct) {
                // VARSA: Hedef ürüne ekle (rafını değiştirmiyoruz)
                $update_dest = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                $update_dest->execute([$amount, $existingProduct['id']]);
            } else {
                // YOKSA: Yeni Satır Oluştur
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
