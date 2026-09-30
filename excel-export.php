<?php
// excel-export.php - Envanteri Excel (CSV) Olarak İndirme
require 'db.php';
girisKontrol();

// Şehir Yetki Kontrolü
$cityParam = [];
$cityCond = "";
if (($_SESSION['role'] ?? '') !== 'ADMIN' && isset($_SESSION['aktif_sehir_id'])) {
    $cityCond = " WHERE l.city_id = ?";
    $cityParam[] = $_SESSION['aktif_sehir_id'];
} elseif (isset($_SESSION['aktif_sehir_id'])) {
    $cityCond = " WHERE l.city_id = ?";
    $cityParam[] = $_SESSION['aktif_sehir_id'];
}

$sql = "SELECT 
            p.barcode, 
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
fputcsv($output, ['Barkod', 'Ürün Adı', 'Marka', 'Kategori', 'Alt Kategori', 'Miktar', 'Birim', 'Min. Miktar', 'SKT', 'Alım Tarihi', 'Dolap', 'Oda', 'Mekan'], ';');

// Verileri yazdır
foreach ($urunler as $row) {
    fputcsv($output, [
        $row['barcode'],
        $row['urun_adi'],
        $row['brand'],
        $row['category'],
        $row['sub_category'],
        $row['quantity'],
        $row['unit'],
        $row['min_quantity'],
        $row['expiry_date'],
        $row['purchase_date'],
        $row['dolap'],
        $row['oda'],
        $row['mekan']
    ], ';');
}

fclose($output);
exit;
