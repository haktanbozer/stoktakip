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
function turkceSirala(&$array, $key = null) {
    if (class_exists('Collator')) {
        $collator = new Collator('tr_TR');
        if ($key) {
            usort($array, function($a, $b) use ($collator, $key) {
                return $collator->compare($a[$key], $b[$key]);
            });
        } else {
            $collator->sort($array);
        }
    } else {
        $sortFunc = function($a, $b) use ($key) {
            $valA = $key ? $a[$key] : $a;
            $valB = $key ? $b[$key] : $b;

            $tr_map = [
                'ç' => 'c1', 'Ç' => 'C1',
                'ğ' => 'g1', 'Ğ' => 'G1',
                'ı' => 'h1', 'I' => 'H1',
                'i' => 'h2', 'İ' => 'H2',
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
    // 0. BARKOD SORGUSU (Lokal Veritabanı Öğrenme İçin)
    if ($islem === 'barkod_getir') {
        $barkod = $_GET['barkod'] ?? '';
        $stmt = $pdo->prepare("SELECT name, brand, category, sub_category FROM products WHERE barcode = ? LIMIT 1");
        $stmt->execute([$barkod]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($data ?: ['bulunamadi' => true]);
        exit;
    }

    // 0.1 BARKOD İLE STOKTA ARAMA (Hızlı Tüketim İçin)
    elseif ($islem === 'barkodla_stok_bul') {
        $barkod = $_GET['barkod'] ?? '';
        
        $sql = "SELECT p.*, 
                c.name as city_name, l.name as loc_name, 
                r.name as room_name, cab.name as cab_name
                FROM products p
                LEFT JOIN cities c ON p.city_id = c.id
                LEFT JOIN locations l ON p.location_id = l.id
                LEFT JOIN rooms r ON p.room_id = r.id
                LEFT JOIN cabinets cab ON p.cabinet_id = cab.id
                WHERE p.barcode = ? AND p.quantity > 0
                ORDER BY p.expiry_date ASC, p.is_opened DESC";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$barkod]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($data);
        exit;
    }

    // 1. MEKANLARI GETİR (Sıralı)
    elseif ($islem === 'get_mekanlar') {
        $cityId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE city_id = ?");
        $stmt->execute([$cityId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        turkceSirala($data, 'name');
        echo json_encode($data);
        exit;
    }
    
    // 2. ODALARI GETİR (Sıralı)
    elseif ($islem === 'get_odalar') {
        $locId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT id, name FROM rooms WHERE location_id = ?");
        $stmt->execute([$locId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        turkceSirala($data, 'name');
        echo json_encode($data);
        exit;
    }
    
    // 3. DOLAPLARI GETİR (Sıralı)
    elseif ($islem === 'get_dolaplar') {
        $roomId = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT id, name FROM cabinets WHERE room_id = ?");
        $stmt->execute([$roomId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        turkceSirala($data, 'name');
        echo json_encode($data);
        exit;
    }
    
    // 4. DOLAP DETAY
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
            turkceSirala($subCats);
        }

        echo json_encode(array_values($subCats));
        exit;
    }

    // 6. HIZLI TÜKETİM (Düzeltildi)
    elseif ($islem === 'hizli_tuket') {
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
            
            // Tüketilen miktar mevcut olandan fazlaysa stoğu 0 yap, fazlasını tüketim sayma
            $tuketilenMiktar = min($mevcutMiktar, $adet);
            $yeniMiktar      = max(0, $mevcutMiktar - $adet);

            // 1. Tüketim analiz tablosuna kaydet
            $stmtCons = $pdo->prepare("INSERT INTO consumption_history (product_id, amount, consumed_at) VALUES (?, ?, NOW())");
            $stmtCons->execute([$id, $tuketilenMiktar]);

            // 2. Ürün miktarını güncelle (Geçmiş logların ve CASCADE'in korunması için silmek yerine 0 yapıyoruz)
            $update = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
            $update->execute([$yeniMiktar, $id]);
            
            // 3. Denetim günlüğüne (audit log) yaz
            if (function_exists('auditLog')) {
                auditLog('TÜKETİM', "{$urunInfo['name']} ürününden {$tuketilenMiktar} {$urunInfo['unit']} hızlı tüketildi.");
            }
            
            $pdo->commit();

            echo json_encode([
                'success'     => true, 
                'yeni_miktar' => $yeniMiktar, 
                'birim'       => $urunInfo['unit'],
                'dusulen'     => $tuketilenMiktar
            ]);
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Ürün bulunamadı.']);
        }
        exit;
    }
    
    // 7. HIZLI TRANSFER (Düzeltildi)
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

        $amount      = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;
        $new_cab_id  = $_GET['new_cab_id'] ?? '';
        $shelf_param = $_GET['shelf_location'] ?? '';

        if ($amount <= 0 || empty($new_cab_id)) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz miktar veya dolap seçimi.']);
            exit;
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

            $new_source_qty = max(0, (float)$urun['quantity'] - $amount);

            // Kaynak ürünün miktarını güncelle
            $update_src = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
            $update_src->execute([$new_source_qty, $id]);

            $checkSql = "SELECT id FROM products 
                         WHERE cabinet_id = ? 
                           AND name = ? 
                           AND (brand = ? OR (brand IS NULL AND ? IS NULL))
                           AND (expiry_date = ? OR (expiry_date IS NULL AND ? IS NULL))";
            
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([
                $new_cab_id, 
                $urun['name'], 
                $urun['brand'], 
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
                        name, barcode, brand, product_type, weight_volume, 
                        category, sub_category, quantity, min_quantity, unit, 
                        purchase_date, expiry_date, is_opened, opened_at, added_by_user_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmtIns = $pdo->prepare($insertSql);
                $newId = uniqid('prod_');

                $stmtIns->execute([
                    $newId,
                    $new_cab_id,
                    $target_shelf,
                    $urun['name'],
                    $urun['barcode'] ?? null,
                    $urun['brand'],
                    $urun['product_type'] ?? null,
                    $urun['weight_volume'] ?? null,
                    $urun['category'],
                    $urun['sub_category'],
                    $amount,
                    $urun['min_quantity'] ?? 1.00,
                    $urun['unit'],
                    $urun['purchase_date'],
                    $urun['expiry_date'],
                    $urun['is_opened'] ?? 0,
                    $urun['opened_at'] ?? null,
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