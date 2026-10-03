<?php
// excel-export.php - Envanteri Excel (CSV) Olarak İndirme
require 'db.php';
girisKontrol();

// Şehir Yetki Kontrolü
$cityParam = [];
$cityCond = "";
$isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';

if ($isAdmin) {
    if (isset($_SESSION['aktif_sehir_id'])) {
        $cityCond = " WHERE loc.city_id = ?";
        $cityParam[] = $_SESSION['aktif_sehir_id'];
    }
} else {
    // Normal kullanıcı: Yalnızca yetkili olduğu şehirleri indirebilir
    if (isset($_SESSION['aktif_sehir_id'])) {
        $cityCond = " WHERE loc.city_id = ? AND loc.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)";
        $cityParam[] = $_SESSION['aktif_sehir_id'];
        $cityParam[] = $_SESSION['user_id'];
    } else {
        $cityCond = " WHERE loc.city_id IN (SELECT city_id FROM user_city_assignments WHERE user_id = ?)";
        $cityParam[] = $_SESSION['user_id'];
    }
}

$sql = "SELECT 
            p.barcode, 
            p.product_type,
            p.name as urun_adi, 
            p.brand, 
            p.category, 
            p.sub_category, 
            p.quantity, 
            p.unit, 
            p.min_quantity, 
            p.expiry_date, 
            p.purchase_date,
            c.name as dolap, 
            r.name as oda, 
            loc.name as mekan
        FROM products p
        LEFT JOIN cabinets c ON p.cabinet_id = c.id
        LEFT JOIN rooms r ON c.room_id = r.id
        LEFT JOIN locations loc ON r.location_id = loc.id
        $cityCond
        ORDER BY loc.name, r.name, c.name, p.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($cityParam);
$urunler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Audit Log
if (function_exists('auditLog')) {
    auditLog('DIŞA_AKTAR', "Kullanıcı envanteri Excel olarak indirdi.");
}

// Dosya indirme başlıkları (Excel uyumlu CSV)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="StokTakip_Envanter_' . date('Y-m-d_H-i') . '.csv"');

// Çıktı tamponunu aç
$output = fopen('php://output', 'w');

// Türkçe karakter sorunu olmaması için BOM (Byte Order Mark) ekle
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Sütun başlıkları (Noktalı virgül ';' Excel'de sütunları düzgün ayırır)
fputcsv($output, ['Barkod', 'Ürün Tipi (Cins)', 'Ürün Adı', 'Marka', 'Kategori', 'Alt Kategori', 'Miktar', 'Birim', 'Min. Miktar', 'SKT', 'Alım Tarihi', 'Dolap', 'Oda', 'Mekan'], ';');

// CSV Formül Enjeksiyonu (CWE-1236) Koruması
function csvTemizle($val) {
    if ($val === null) return '';
    $val = (string)$val;
    if (isset($val[0]) && in_array($val[0], ['=', '+', '-', '@', "\t", "\r"])) {
        return "'" . $val;
    }
    return $val;
}

// Verileri yazdır
foreach ($urunler as $row) {
    fputcsv($output, [
        csvTemizle($row['barcode']),
        csvTemizle($row['product_type']),
        csvTemizle($row['urun_adi']),
        csvTemizle($row['brand']),
        csvTemizle($row['category']),
        csvTemizle($row['sub_category']),
        csvTemizle($row['quantity']),
        csvTemizle($row['unit']),
        csvTemizle($row['min_quantity']),
        csvTemizle($row['expiry_date']),
        csvTemizle($row['purchase_date']),
        csvTemizle($row['dolap']),
        csvTemizle($row['oda']),
        csvTemizle($row['mekan'])
    ], ';');
}

fclose($output);
exit;
