<?php
// toplu-ekle.php - Marketten Dönünce Hızlı Toplu Ürün Ekleme
require 'db.php';
girisKontrol();

if (!isset($cspNonce)) { $cspNonce = ''; }

$sehirler    = $pdo->query("SELECT * FROM cities ORDER BY name ASC")->fetchAll();
$kategoriler = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$aktifSehirId = $_SESSION['aktif_sehir_id'] ?? '';

$sonuc = ['basarili' => 0, 'hatali' => 0, 'hatalar' => []];
$islemYapildi = false;

// --- POST: TOPLU KAYIT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toplu_kaydet'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');

    $urunler       = $_POST['urunler']       ?? [];
    $cabinet_ids   = $_POST['cabinet_id']    ?? [];
    $kategoris     = $_POST['category']      ?? [];
    $alt_kategoris = $_POST['sub_category']  ?? [];
    $miktarlar     = $_POST['quantity']      ?? [];
    $birimler      = $_POST['unit']          ?? [];
    $sktler        = $_POST['expiry_date']   ?? [];
    $markalar      = $_POST['brand']         ?? [];
    $min_qtys      = $_POST['min_quantity']  ?? [];
    $barkodlar     = $_POST['barcode']       ?? [];
    
    // Global alım tarihi (tüm toplu eklenenlere uygulanır)
    $genel_alim = !empty($_POST['g_purchase_date']) ? $_POST['g_purchase_date'] : date('Y-m-d');

    $count = count($urunler);

    for ($i = 0; $i < $count; $i++) {
        $ad = trim($urunler[$i] ?? '');
        if ($ad === '') continue; // Boş satırları atla

        $cabId   = $cabinet_ids[$i] ?? '';
        $kat     = $kategoris[$i]   ?? 'Gıda';
        $altKat  = $alt_kategoris[$i] ?? '';
        $miktar  = (float)($miktarlar[$i] ?? 1);
        $birim   = $birimler[$i]    ?? 'Adet';
        $skt     = !empty($sktler[$i]) ? $sktler[$i] : null;
        $marka   = trim($markalar[$i] ?? '');
        $minQty  = (float)($min_qtys[$i] ?? 1);
        $barkod  = trim($barkodlar[$i] ?? '');

        if (empty($cabId)) {
            $sonuc['hatali']++;
            $sonuc['hatalar'][] = "$ad — Dolap seçilmedi.";
            continue;
        }

        try {
            $id = uniqid('prod_');
            $stmt = $pdo->prepare("INSERT INTO products
                (id, name, barcode, brand, category, sub_category, quantity, min_quantity, unit,
                 cabinet_id, purchase_date, expiry_date, is_opened, added_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
            $stmt->execute([
                $id, $ad, $barkod ?: null, $marka ?: null, $kat, $altKat, $miktar, $minQty, $birim,
                $cabId, $genel_alim, $skt, $_SESSION['user_id']
            ]);

            auditLog('EKLEME', "$ad ($miktar $birim) toplu ekleme ile sisteme eklendi.");
            $sonuc['basarili']++;
        } catch (PDOException $e) {
            $sonuc['hatali']++;
            $sonuc['hatalar'][] = "$ad — " . $e->getMessage();
        }
    }

    $islemYapildi = true;
}

require 'header.php';
?>

<div class="max-w-5xl mx-auto">

    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-3">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white flex items-center gap-2">
                📋 Toplu Ürün Ekleme
            </h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Marketten dönünce birden fazla ürünü tek seferde ekle.</p>
        </div>
        <a href="urun-ekle.php" class="text-sm text-blue-600 hover:underline flex items-center gap-1 dark:text-blue-400">
            ← Tekli Eklemeye Dön
        </a>
    </div>

    <?php if ($islemYapildi): ?>
        <div class="mb-6 p-4 rounded-xl border <?= $sonuc['hatali'] === 0 ? 'bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-800' : 'bg-amber-50 border-amber-200 dark:bg-amber-900/20 dark:border-amber-800' ?>">
            <p class="font-bold <?= $sonuc['hatali'] === 0 ? 'text-green-700 dark:text-green-300' : 'text-amber-700 dark:text-amber-300' ?>">
                ✅ <?= $sonuc['basarili'] ?> ürün başarıyla eklendi.
                <?= $sonuc['hatali'] > 0 ? '⚠️ ' . $sonuc['hatali'] . ' ürün eklenemedi.' : '' ?>
            </p>
            <?php if (!empty($sonuc['hatalar'])): ?>
                <ul class="mt-2 text-xs text-red-600 dark:text-red-400 space-y-1">
                    <?php foreach ($sonuc['hatalar'] as $h): ?>
                        <li>• <?= htmlspecialchars($h) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($sonuc['basarili'] > 0): ?>
                <a href="envanter.php" class="inline-block mt-3 text-sm font-bold text-blue-600 dark:text-blue-400 hover:underline">→ Envantere Git</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Genel Ayarlar (tüm satırlara uygulanır) -->
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4 mb-5">
        <h3 class="text-sm font-bold text-blue-700 dark:text-blue-300 mb-3 flex items-center gap-2">
            📍 Varsayılan Konum ve Alım Tarihi (Satır bazında değiştirebilirsin)
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div>
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Şehir</label>
                <select id="g_city" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                    <option value="">Seçiniz</option>
                    <?php foreach ($sehirler as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($aktifSehirId == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Mekan</label>
                <select id="g_loc" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" disabled>
                    <option value="">Önce şehir</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Oda</label>
                <select id="g_room" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" disabled>
                    <option value="">Önce mekan</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Dolap</label>
                <select id="g_cab" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white" disabled>
                    <option value="">Önce oda</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">Toplu Alım Tarihi</label>
                <input type="date" form="topluForm" name="g_purchase_date" value="<?= date('Y-m-d') ?>" class="w-full p-2 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
        </div>
        <button type="button" id="btnHepsineUygula"
            class="mt-3 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold transition">
            ↓ Seçilen Dolapı Tüm Satırlara Uygula
        </button>
    </div>

    <form method="POST" id="topluForm">
        <?php echo csrfAlaniniEkle(); ?>
        <input type="hidden" name="toplu_kaydet" value="1">

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-x-auto">
            <div class="min-w-[1100px]">
                <!-- Tablo Başlığı -->
                <div class="grid grid-cols-12 gap-1 px-3 py-2 bg-slate-50 dark:bg-slate-700/50 text-[10px] font-bold uppercase text-slate-500 dark:text-slate-400">
                    <div class="col-span-1"># & Barkod</div>
                    <div class="col-span-2">Ürün Adı *</div>
                    <div class="col-span-1">Marka</div>
                    <div class="col-span-2">Kategori / Alt Kat.</div>
                    <div class="col-span-1">Miktar</div>
                    <div class="col-span-1">Birim</div>
                    <div class="col-span-1">Min. Mktr</div>
                    <div class="col-span-2">SKT</div>
                    <div class="col-span-1 text-center">İşlem</div>
                </div>

                <!-- Satırlar -->
                <div id="satirListesi" class="divide-y divide-slate-100 dark:divide-slate-700/50">
                    <!-- JS ile doldurulacak -->
                </div>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row gap-3 mt-4">
            <button type="button" id="btnSatirEkle"
                class="flex items-center gap-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 px-5 py-2.5 rounded-lg font-bold text-sm transition">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Satır Ekle
            </button>

            <button type="submit" id="btnKaydet"
                class="flex-1 sm:flex-none bg-green-600 hover:bg-green-700 text-white px-8 py-2.5 rounded-lg font-bold text-sm transition shadow-lg shadow-green-500/30 flex items-center justify-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Tümünü Kaydet
            </button>
        </div>
    </form>
</div>

<div class="h-20"></div>

<script nonce="<?= $cspNonce ?>">
const KATEGORILER = <?= json_encode(array_column($kategoriler, 'name')) ?>;
const BİRİMLER   = ['Adet','Paket','Kutu','Şişe','Kavanoz','Kg','Gram','Lt','Mililitre'];
let satirSayaci  = 0;

function satirEkle(ad = '', marka = '', miktar = 1, birim = 'Adet', skt = '', barkod = '', altKat = '', min = 1) {
    satirSayaci++;
    const n = satirSayaci;

    const birimOpts = BİRİMLER.map(b => `<option value="${b}" ${b === birim ? 'selected' : ''}>${b}</option>`).join('');
    const katOpts   = KATEGORILER.map(k => `<option value="${k}">${k}</option>`).join('');

    const html = `
    <div id="satir_${n}" class="grid grid-cols-12 gap-1 px-3 py-2 items-start hover:bg-slate-50 dark:hover:bg-slate-700/30 transition">
        <div class="col-span-1 flex flex-col gap-1">
            <span class="text-xs text-slate-400 font-bold">${n}</span>
            <input type="text" name="barcode[]" value="${escHtml(barkod)}" placeholder="Barkod" 
                class="w-full p-1 border rounded text-xs dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-blue-500 outline-none">
        </div>

        <div class="col-span-2">
            <input type="text" name="urunler[]" value="${escHtml(ad)}" placeholder="Ürün adı..." required
                class="w-full p-1.5 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-blue-500 outline-none">
        </div>

        <div class="col-span-1">
            <input type="text" name="brand[]" value="${escHtml(marka)}" placeholder="Marka"
                class="w-full p-1.5 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-blue-500 outline-none">
        </div>

        <div class="col-span-2 flex flex-col gap-1">
            <select name="category[]" class="w-full p-1 border rounded text-xs dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                <option value="">Kategori Seç</option>
                ${katOpts}
            </select>
            <input type="text" name="sub_category[]" value="${escHtml(altKat)}" placeholder="Alt Kategori" 
                class="w-full p-1 border rounded text-xs dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-blue-500 outline-none">
        </div>

        <div class="col-span-1">
            <input type="number" name="quantity[]" value="${miktar}" min="0.01" step="0.01" required
                class="w-full p-1.5 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white text-center focus:ring-1 focus:ring-blue-500 outline-none">
        </div>

        <div class="col-span-1">
            <select name="unit[]" class="w-full p-1.5 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                ${birimOpts}
            </select>
        </div>

        <div class="col-span-1">
            <input type="number" name="min_quantity[]" value="${min}" min="0.01" step="0.01"
                class="w-full p-1.5 border rounded text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-white text-center focus:ring-1 focus:ring-amber-500 outline-none" title="Minimum Stok Uyarısı">
        </div>

        <div class="col-span-2">
            <input type="date" name="expiry_date[]" value="${skt}"
                class="w-full p-1.5 border rounded text-xs dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-1 focus:ring-red-400 outline-none">
            <input type="hidden" name="cabinet_id[]" id="cab_${n}" value="">
        </div>

        <div class="col-span-1 flex gap-1 justify-center pt-1">
            <button type="button" class="btn-satir-sil text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 p-1.5 rounded transition" data-id="${n}" title="Satırı Sil">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
        </div>
    </div>`;

    document.getElementById('satirListesi').insertAdjacentHTML('beforeend', html);

    // Aktif global dolabı bu satıra da uygula
    const gCab = document.getElementById('g_cab').value;
    if (gCab) document.getElementById(`cab_${n}`).value = gCab;
}

function satirSil(n) {
    const el = document.getElementById(`satir_${n}`);
    if (el) el.remove();
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ===== GLOBAL KONUM CASCADE =====
async function globalMekanYukle() {
    const cityId = document.getElementById('g_city').value;
    const locSel = document.getElementById('g_loc');
    locSel.innerHTML = '<option value="">Yükleniyor...</option>';
    locSel.disabled = true;
    document.getElementById('g_room').innerHTML = '<option value="">Önce mekan</option>';
    document.getElementById('g_room').disabled = true;
    document.getElementById('g_cab').innerHTML  = '<option value="">Önce oda</option>';
    document.getElementById('g_cab').disabled   = true;

    if (!cityId) { locSel.innerHTML = '<option value="">Önce şehir</option>'; return; }

    const data = await fetch(`ajax.php?islem=get_mekanlar&id=${cityId}`).then(r => r.json()).catch(() => []);
    locSel.innerHTML = '<option value="">Seçiniz</option>';
    data.forEach(d => locSel.innerHTML += `<option value="${d.id}">${d.name}</option>`);
    locSel.disabled = false;
}

async function globalOdaYukle() {
    const locId = document.getElementById('g_loc').value;
    const roomSel = document.getElementById('g_room');
    roomSel.innerHTML = '<option value="">Yükleniyor...</option>';
    roomSel.disabled  = true;
    document.getElementById('g_cab').innerHTML = '<option value="">Önce oda</option>';
    document.getElementById('g_cab').disabled  = true;

    if (!locId) { roomSel.innerHTML = '<option value="">Önce mekan</option>'; return; }

    const data = await fetch(`ajax.php?islem=get_odalar&id=${locId}`).then(r => r.json()).catch(() => []);
    roomSel.innerHTML = '<option value="">Seçiniz</option>';
    data.forEach(d => roomSel.innerHTML += `<option value="${d.id}">${d.name}</option>`);
    roomSel.disabled = false;
}

async function globalDolapYukle() {
    const roomId = document.getElementById('g_room').value;
    const cabSel = document.getElementById('g_cab');
    cabSel.innerHTML = '<option value="">Yükleniyor...</option>';
    cabSel.disabled  = true;

    if (!roomId) { cabSel.innerHTML = '<option value="">Önce oda</option>'; return; }

    const data = await fetch(`ajax.php?islem=get_dolaplar&id=${roomId}`).then(r => r.json()).catch(() => []);
    cabSel.innerHTML = '<option value="">Seçiniz</option>';
    data.forEach(d => cabSel.innerHTML += `<option value="${d.id}">${d.name}</option>`);
    cabSel.disabled = false;
}

function hepsineUygula() {
    const cabId = document.getElementById('g_cab').value;
    if (!cabId) { alert('Önce dolap seçin!'); return; }

    document.querySelectorAll('[id^="cab_"]').forEach(el => el.value = cabId);

    const cabName = document.getElementById('g_cab').options[document.getElementById('g_cab').selectedIndex]?.text || '';
    const btn = event.target;
    btn.textContent = `✅ "${cabName}" Uygulandı`;
    setTimeout(() => btn.textContent = '↓ Seçilen Dolapı Tüm Satırlara Uygula', 2000);
}

// Kaydet butonuna tıklayınca boş satırları kontrol et
document.getElementById('topluForm').addEventListener('submit', function(e) {
    const satirlar = document.querySelectorAll('[name="urunler[]"]');
    let dolu = 0;
    satirlar.forEach(s => { if (s.value.trim()) dolu++; });
    if (dolu === 0) {
        e.preventDefault();
        alert('En az bir ürün adı girin!');
    }
});

// Dinamik Butonlar ve Select'ler (CSP Uyumluluğu İçin)
document.addEventListener('DOMContentLoaded', () => {
    // 5 boş satır ekle
    for (let i = 0; i < 5; i++) satirEkle();

    // Satır Ekle Butonu
    const btnSatirEkle = document.getElementById('btnSatirEkle');
    if(btnSatirEkle) btnSatirEkle.addEventListener('click', () => satirEkle());

    // Hepsine Uygula Butonu
    const btnHepsine = document.getElementById('btnHepsineUygula');
    if(btnHepsine) btnHepsine.addEventListener('click', (e) => hepsineUygula(e));

    // Cascade Selectler
    const gCity = document.getElementById('g_city');
    const gLoc = document.getElementById('g_loc');
    const gRoom = document.getElementById('g_room');

    if(gCity) gCity.addEventListener('change', globalMekanYukle);
    if(gLoc) gLoc.addEventListener('change', globalOdaYukle);
    if(gRoom) gRoom.addEventListener('change', globalDolapYukle);

    // Satır Silme İşlemi (Event Delegation)
    document.getElementById('satirListesi').addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-satir-sil');
        if (btn) {
            satirSil(btn.getAttribute('data-id'));
        }
    });

    // Aktif şehir varsa cascade başlat
    if (gCity && gCity.value) globalMekanYukle();
});
</script>
</body>
</html>
