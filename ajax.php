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
    // ── ÜRÜN TİPİ ENDPOINTLERİ ─────────────────────────────────────────────

    // PT-1: Kategoriye göre mevcut ürün tiplerini listele (dropdown/datalist için)
    if ($islem === 'product_types_listele') {
        $kategori   = trim($_GET['kategori'] ?? '');
        $altKategori = trim($_GET['alt_kategori'] ?? '');
        $params = [];
        $sql = "SELECT id, name, default_unit, min_threshold FROM product_types WHERE 1=1";
        if ($kategori !== '') { $sql .= " AND category = ?";     $params[] = $kategori; }
        if ($altKategori !== '') { $sql .= " AND sub_category = ?"; $params[] = $altKategori; }
        $sql .= " ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: Kategori adı & veya ve/ile değişmişse varyasyonu da dene
        if (empty($results) && $kategori !== '') {
            $varKat = '';
            if (strpos($kategori, '&') !== false) {
                $varKat = str_replace('&', 've', $kategori);
            } elseif (strpos($kategori, 've') !== false) {
                $varKat = str_replace('ve', '&', $kategori);
            }
            if ($varKat !== '') {
                $sqlVar = "SELECT id, name, default_unit, min_threshold FROM product_types WHERE category = ?";
                $paramsVar = [$varKat];
                if ($altKategori !== '') { $sqlVar .= " AND sub_category = ?"; $paramsVar[] = $altKategori; }
                $sqlVar .= " ORDER BY name ASC";
                $stmtVar = $pdo->prepare($sqlVar);
                $stmtVar->execute($paramsVar);
                $results = $stmtVar->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        echo json_encode($results);
        exit;
    }

    // PT-2: Yeni ürün tipi kaydet veya var olanı getir
    if ($islem === 'product_type_kaydet' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfKontrol($_POST['csrf_token'] ?? '');
        $name        = trim($_POST['name'] ?? '');
        $category    = trim($_POST['category'] ?? '');
        $subCategory = trim($_POST['sub_category'] ?? '');
        $unit        = trim($_POST['default_unit'] ?? 'Adet');
        $threshold   = (float)($_POST['min_threshold'] ?? 1.00);

        if (empty($name) || empty($category)) {
            echo json_encode(['hata' => 'Ürün tipi adı ve kategori zorunludur.']);
            exit;
        }

        // Aynı isimde varsa getir, yoksa oluştur
        $stmtChk = $pdo->prepare("SELECT id, name, default_unit, min_threshold FROM product_types WHERE name = ? AND category = ? AND sub_category = ?");
        $stmtChk->execute([$name, $category, $subCategory]);
        $mevcut = $stmtChk->fetch(PDO::FETCH_ASSOC);

        if ($mevcut) {
            echo json_encode(['ok' => true, 'yeni' => false, 'tip' => $mevcut]);
        } else {
            $id = 'pt_' . uniqid();
            $stmtIns = $pdo->prepare("INSERT INTO product_types (id, name, category, sub_category, default_unit, min_threshold) VALUES (?,?,?,?,?,?)");
            $stmtIns->execute([$id, $name, $category, $subCategory, $unit, $threshold]);
            echo json_encode(['ok' => true, 'yeni' => true, 'tip' => [
                'id' => $id, 'name' => $name, 'default_unit' => $unit, 'min_threshold' => $threshold
            ]]);
        }
        exit;
    }

    // PT-3: Alt Kategoriye ait kritik esik ve birim bilgisini dondur (Yoksa otomatik olustur)
    if ($islem === 'get_alt_kategori_bilgi') {
        $kategori    = trim($_GET['kategori'] ?? '');
        $altKategori = trim($_GET['alt_kategori'] ?? '');

        if (!empty($kategori) && !empty($altKategori)) {
            $stmt = $pdo->prepare("SELECT id, name, default_unit, min_threshold FROM product_types WHERE category = ? AND (name = ? OR sub_category = ?) LIMIT 1");
            $stmt->execute([$kategori, $altKategori, $altKategori]);
            $tip = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tip) {
                // Fallback: Kategori adı değişmiş olabilir, önce doğrudan alt kategori adıyla ara
                $stmtFallback = $pdo->prepare("SELECT id, name, default_unit, min_threshold FROM product_types WHERE (name = ? OR sub_category = ?) LIMIT 1");
                $stmtFallback->execute([$altKategori, $altKategori]);
                $tip = $stmtFallback->fetch(PDO::FETCH_ASSOC);

                if ($tip) {
                    // Mevcut ürün tipinin kategorisini güncel kategoriye senkronize et
                    try {
                        $pdo->prepare("UPDATE product_types SET category = ? WHERE id = ?")->execute([$kategori, $tip['id']]);
                    } catch (Exception $e) { /* sessiz geç */ }
                } else {
                    $id = 'pt_' . uniqid();
                    $birim = 'Adet';
                    if (preg_match('/(Makarna|Bisküvi|Cips|Çikolata|Gofret|Kraker|Tablet|Mendil|Bez|Poşet|Streç|Folyo|Kağıt|Pil|Bant|Yufka|Lavaş)/iu', $altKategori)) $birim = 'Paket';
                    elseif (preg_match('/(Et|Kıyma|Kuşbaşı|Biftek|Tavuk|Hindi|Somon|Levrek|Çipura|Kalamar|Karides|Sucuk|Sosis|Salam|Pastırma|Kavurma|Füme|Peynir|Kaşar|Pirinç|Bulgur|Mercimek|Nohut|Fasulye|Un|Şeker|Tuz|Patates|Soğan|Meyve|Sebze)/iu', $altKategori)) $birim = 'Kg';
                    elseif (preg_match('/(Damacana|Teneke Yağ|Ayçiçek Yağı|Zeytinyağı|Mısır Özü Yağı)/iu', $altKategori)) $birim = 'Litre';

                    $ins = $pdo->prepare("INSERT INTO product_types (id, name, category, sub_category, default_unit, min_threshold) VALUES (?, ?, ?, ?, ?, 1.00)");
                    $ins->execute([$id, $altKategori, $kategori, $altKategori, $birim]);
                    $tip = [
                        'id' => $id,
                        'name' => $altKategori,
                        'default_unit' => $birim,
                        'min_threshold' => 1.00
                    ];
                }
            }

            echo json_encode($tip);
            exit;
        }

        echo json_encode(['id' => '', 'name' => $altKategori, 'default_unit' => 'Adet', 'min_threshold' => 1.00]);
        exit;
    }

    // ────────────────────────────────────────────────────────────────────────

    // 0. BARKOD SORGUSU (Lokal Veritabanı Öğrenme İçin)
    if ($islem === 'barkod_getir') {
        $barkod = trim($_GET['barkod'] ?? '');
        $stmt = $pdo->prepare("SELECT name, brand, category, sub_category, product_type, product_type_id, unit, min_quantity FROM products WHERE barcode = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$barkod]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($data ?: ['bulunamadi' => true]);
        exit;
    }

    // 0.1 BARKOD İLE STOKTA ARAMA (Hızlı Tüketim İçin)
    elseif ($islem === 'barkodla_stok_bul') {
        $barkod = $_GET['barkod'] ?? '';
        $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
        $params = [$barkod];
        
        $sql = "SELECT p.*, 
                c.name as city_name, l.name as loc_name, 
                r.name as room_name, cab.name as cab_name
                FROM products p
                LEFT JOIN cabinets cab ON p.cabinet_id = cab.id
                LEFT JOIN rooms r ON cab.room_id = r.id
                LEFT JOIN locations l ON r.location_id = l.id
                LEFT JOIN cities c ON l.city_id = c.id
                WHERE p.barcode = ? AND p.quantity > 0";

        if (!$isAdmin) {
            $sql .= " AND l.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)";
            $params[] = $_SESSION['user_id'];
        }

        // Akıllı Sıralama: Önce açık paketler (1), sonra en yakın SKT'ler, en son süresizler
        $sql .= " ORDER BY p.is_opened DESC, CASE WHEN p.expiry_date IS NULL THEN 1 ELSE 0 END ASC, p.expiry_date ASC";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($data);
        exit;
    }

    // 1. MEKANLARI GETİR (Sıralı)
    elseif ($islem === 'get_mekanlar') {
        $cityId  = $_GET['id'] ?? '';
        $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
        if (!$isAdmin) {
            $stmt = $pdo->prepare("SELECT l.id, l.name FROM locations l JOIN user_city_assignments uca ON l.city_id = uca.city_id WHERE l.city_id = ? AND uca.user_id = ?");
            $stmt->execute([$cityId, $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE city_id = ?");
            $stmt->execute([$cityId]);
        }
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        turkceSirala($data, 'name');
        echo json_encode($data);
        exit;
    }
    
    // 2. ODALARI GETİR (Sıralı)
    elseif ($islem === 'get_odalar') {
        $locId   = $_GET['id'] ?? '';
        $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
        if (!$isAdmin) {
            $stmt = $pdo->prepare("SELECT r.id, r.name FROM rooms r JOIN locations l ON r.location_id = l.id JOIN user_city_assignments uca ON l.city_id = uca.city_id WHERE r.location_id = ? AND uca.user_id = ?");
            $stmt->execute([$locId, $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM rooms WHERE location_id = ?");
            $stmt->execute([$locId]);
        }
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        turkceSirala($data, 'name');
        echo json_encode($data);
        exit;
    }
    
    // 3. DOLAPLARI GETİR (Sıralı)
    elseif ($islem === 'get_dolaplar') {
        $roomId  = $_GET['id'] ?? '';
        $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
        if (!$isAdmin) {
            $stmt = $pdo->prepare("SELECT cab.id, cab.name FROM cabinets cab JOIN rooms r ON cab.room_id = r.id JOIN locations l ON r.location_id = l.id JOIN user_city_assignments uca ON l.city_id = uca.city_id WHERE cab.room_id = ? AND uca.user_id = ?");
            $stmt->execute([$roomId, $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM cabinets WHERE room_id = ?");
            $stmt->execute([$roomId]);
        }
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        turkceSirala($data, 'name');
        echo json_encode($data);
        exit;
    }
    
    // 4. DOLAP DETAY
    elseif ($islem === 'get_dolap_detay') {
        $cabId   = $_GET['id'] ?? '';
        $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
        if (!$isAdmin) {
            $stmt = $pdo->prepare("SELECT cab.* FROM cabinets cab JOIN rooms r ON cab.room_id = r.id JOIN locations l ON r.location_id = l.id JOIN user_city_assignments uca ON l.city_id = uca.city_id WHERE cab.id = ? AND uca.user_id = ?");
            $stmt->execute([$cabId, $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM cabinets WHERE id = ?");
            $stmt->execute([$cabId]);
        }
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

    // 6. HIZLI TÜKETİM
    elseif ($islem === 'hizli_tuket') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'POST gerekli.']); exit; }
        $id = $_POST['id'] ?? '';
        // CSRF: GET veya POST'tan al (güvenlik için POST tercih edilir)
        $token = $_POST['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'error' => 'Güvenlik hatası. Sayfayı yenileyin.']);
            exit;
        }

        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz ürün ID.']);
            exit;
        }
        
        $adet = isset($_POST['adet']) ? (float)$_POST['adet'] : 1;
        if ($adet <= 0) $adet = 1;

        $pdo->beginTransaction();

        // IDOR Koruması: Ürünün kullanıcının yetkili şehrine ait olduğunu doğrula
        $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
        if ($isAdmin) {
            $stmtInfo = $pdo->prepare("SELECT p.id, p.name, p.unit, p.quantity FROM products p WHERE p.id = ? FOR UPDATE");
            $stmtInfo->execute([$id]);
        } else {
            $stmtInfo = $pdo->prepare("SELECT p.id, p.name, p.unit, p.quantity FROM products p
                JOIN cabinets cab ON p.cabinet_id = cab.id
                JOIN rooms r ON cab.room_id = r.id
                JOIN locations l ON r.location_id = l.id
                JOIN user_city_assignments uca ON l.city_id = uca.city_id AND uca.user_id = ?
                WHERE p.id = ? FOR UPDATE");
            $stmtInfo->execute([$_SESSION['user_id'], $id]);
        }
        $urunInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        if ($urunInfo) {
            $mevcutMiktar = (float)$urunInfo['quantity'];
            $tuketilenMiktar = min($mevcutMiktar, $adet);
            $yeniMiktar      = max(0, $mevcutMiktar - $adet);

            $stmtCons = $pdo->prepare("INSERT INTO consumption_history (product_id, amount, consumed_at) VALUES (?, ?, NOW())");
            $stmtCons->execute([$id, $tuketilenMiktar]);

            $update = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
            $update->execute([$yeniMiktar, $id]);
            
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
            echo json_encode(['success' => false, 'error' => 'Ürün bulunamadı veya bu işlem için yetkiniz yok.']);
        }
        exit;
    }
    
    // 7. HIZLI TRANSFER (Düzeltildi)
    elseif ($islem === 'hizli_transfer') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'POST gerekli.']); exit; }
        $id = $_POST['id'] ?? '';
        // CSRF: POST veya GET'ten al
        $token = $_POST['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'error' => 'Güvenlik hatası. Sayfayı yenileyin.']);
            exit;
        }

        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz ürün ID.']);
            exit;
        }

        $amount      = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $new_cab_id  = $_POST['new_cab_id'] ?? '';
        $shelf_param = $_POST['shelf_location'] ?? '';

        if ($amount <= 0 || empty($new_cab_id)) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz miktar veya dolap seçimi.']);
            exit;
        }

        $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';

        $pdo->beginTransaction();

        try {
            // IDOR: Kaynak ürünün kullanıcıya ait olduğunu doğrula
            if ($isAdmin) {
                $stmt_current = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $stmt_current->execute([$id]);
            } else {
                $stmt_current = $pdo->prepare("SELECT p.* FROM products p
                    JOIN cabinets cab ON p.cabinet_id = cab.id
                    JOIN rooms r ON cab.room_id = r.id
                    JOIN locations l ON r.location_id = l.id
                    JOIN user_city_assignments uca ON l.city_id = uca.city_id AND uca.user_id = ?
                    WHERE p.id = ? FOR UPDATE");
                $stmt_current->execute([$_SESSION['user_id'], $id]);
            }
            $urun = $stmt_current->fetch(PDO::FETCH_ASSOC);
            
            if (!$urun) {
                throw new Exception('Ürün bulunamadı veya bu işlem için yetkiniz yok.');
            }

            if ($new_cab_id === $urun['cabinet_id']) {
                throw new Exception('Hedef dolap zaten mevcut dolap.');
            }

            if ($amount > (float)$urun['quantity']) {
                throw new Exception('Yetersiz stok.');
            }
            
            // IDOR: Hedef dolabın kullanıcıya ait olduğunu doğrula
            if ($isAdmin) {
                $stmt_cab = $pdo->prepare("SELECT name FROM cabinets WHERE id = ?");
                $stmt_cab->execute([$new_cab_id]);
            } else {
                $stmt_cab = $pdo->prepare("SELECT cab.name FROM cabinets cab
                    JOIN rooms r ON cab.room_id = r.id
                    JOIN locations l ON r.location_id = l.id
                    JOIN user_city_assignments uca ON l.city_id = uca.city_id AND uca.user_id = ?
                    WHERE cab.id = ?");
                $stmt_cab->execute([$_SESSION['user_id'], $new_cab_id]);
            }
            $new_cab_name = $stmt_cab->fetchColumn();

            if (!$new_cab_name) {
                sistemLogla("IDOR Girişimi - Yetkisiz dolaba transfer: user={$_SESSION['user_id']}, cabinet=$new_cab_id", 'SECURITY');
                throw new Exception('Hedef dolap bulunamadı veya bu dolaba transfer için yetkiniz yok.');
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
                        name, barcode, brand, product_type, product_type_id, 
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
                    $urun['product_type_id'] ?? null,
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
    sistemLogla("Ajax PDO Hatası: " . $e->getMessage(), 'ERROR');
    echo json_encode(['success' => false, 'error' => 'Bir hata oluştu. Lütfen tekrar deneyin.']);
}
?>