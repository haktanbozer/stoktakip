<?php
require 'db.php';
girisKontrol();
include 'header.php';
?>

<div class="max-w-md mx-auto bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-4 md:p-6 mb-8 mt-4">
    <h2 class="text-xl font-black text-slate-800 dark:text-white mb-2 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-red-500"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
        Hızlı Tüketim (Çöpe At)
    </h2>
    <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Biten veya çöpe atacağınız ürünün barkodunu okutarak stoktan anında düşün.</p>

    <div id="scannerContainer" class="rounded-xl overflow-hidden border-2 border-slate-200 dark:border-slate-700 mb-4 bg-slate-900">
        <div id="reader" style="width:100%; min-height: 250px;"></div>
    </div>
    <div id="statusText" class="text-center text-sm font-bold text-slate-600 dark:text-slate-400 mb-4">
        Kamera başlatılıyor...
    </div>

    <!-- Sonuç Kartı (Gizli) -->
    <div id="resultCard" class="hidden bg-slate-50 dark:bg-slate-900/50 p-4 rounded-xl border border-slate-200 dark:border-slate-700">
        <h3 class="font-bold text-lg text-slate-800 dark:text-white mb-3 border-b dark:border-slate-700 pb-2">Bulunan Ürünler</h3>
        <div id="productList" class="flex flex-col gap-3">
            <!-- JS ile doldurulacak -->
        </div>
        <button type="button" onclick="kamerayiTekrarAc()" class="mt-4 w-full bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white px-4 py-2 rounded-lg text-sm font-bold transition">
            🔄 Başka Ürün Okut
        </button>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js" nonce="<?= $cspNonce ?>"></script>
<script nonce="<?= $cspNonce ?>">
let html5QrCode = null;
const csrfToken = "<?= $_SESSION['csrf_token'] ?>";

document.addEventListener('DOMContentLoaded', () => {
    baslatKamera();
});

function baslatKamera() {
    document.getElementById('resultCard').classList.add('hidden');
    document.getElementById('scannerContainer').classList.remove('hidden');
    document.getElementById('statusText').textContent = 'Kamera başlatılıyor...';
    document.getElementById('statusText').className = 'text-center text-sm font-bold text-slate-600 dark:text-slate-400 mb-4';

    Html5Qrcode.getCameras().then(devices => {
        if (devices && devices.length) {
            let cameraId = devices[0].id;
            for (let i = 0; i < devices.length; i++) {
                if (devices[i].label.toLowerCase().includes('back') || devices[i].label.toLowerCase().includes('arka')) {
                    cameraId = devices[i].id;
                    break;
                }
            }
            
            html5QrCode = new Html5Qrcode("reader");
            html5QrCode.start(
                cameraId,
                { fps: 10, qrbox: { width: 250, height: 150 } },
                (decodedText) => {
                    kameraDurdur();
                    stokSorgula(decodedText);
                },
                () => {}
            ).catch(err => {
                document.getElementById('statusText').innerHTML = `<span class="text-red-500">Kamera Hatası: ${err}</span>`;
            });
        } else {
            document.getElementById('statusText').innerHTML = '<span class="text-red-500">Kamera bulunamadı!</span>';
        }
    }).catch(err => {
        document.getElementById('statusText').innerHTML = `<span class="text-red-500">Kamera İzni Reddedildi: ${err.message}</span>`;
    });
}

function kameraDurdur() {
    if (html5QrCode) {
        html5QrCode.stop().catch(()=>{}).finally(()=>{ html5QrCode = null; });
    }
    document.getElementById('scannerContainer').classList.add('hidden');
}

function kamerayiTekrarAc() {
    baslatKamera();
}

function escHtml(unsafe) {
    return (unsafe || '').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

async function stokSorgula(barkod) {
    document.getElementById('statusText').innerHTML = `<span class="text-blue-500">🔍 Stoklar aranıyor... (${escHtml(barkod)})</span>`;
    
    try {
        const res = await fetch(`ajax.php?islem=barkodla_stok_bul&barkod=${encodeURIComponent(barkod)}`);
        const data = await res.json();
        
        if (data.length > 0) {
            document.getElementById('statusText').textContent = '';
            document.getElementById('resultCard').classList.remove('hidden');
            
            const list = document.getElementById('productList');
            list.innerHTML = '';
            
            data.forEach(urun => {
                const konum = `${escHtml(urun.city_name)} > ${escHtml(urun.loc_name)} > ${escHtml(urun.room_name)} > ${escHtml(urun.cab_name)}`;
                list.innerHTML += `
                    <div class="bg-white dark:bg-slate-800 p-3 rounded-lg border border-slate-100 dark:border-slate-700 flex justify-between items-center gap-2 shadow-sm" id="urun_satir_${urun.id}">
                        <div>
                            <div class="font-bold text-sm text-slate-800 dark:text-white">${escHtml(urun.name)} ${urun.brand ? '('+escHtml(urun.brand)+')' : ''}</div>
                            <div class="text-[10px] text-slate-500 dark:text-slate-400 mt-1">📍 ${konum}</div>
                            <div class="text-[10px] text-slate-500 dark:text-slate-400">Mevcut: <strong class="text-blue-600 dark:text-blue-400">${parseFloat(urun.quantity)} ${escHtml(urun.unit)}</strong></div>
                        </div>
                        <button type="button" onclick="hizliTuket(${urun.id}, '${escHtml(urun.unit)}')" class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-xs font-bold transition shadow whitespace-nowrap">
                            🗑️ Tüket (-1)
                        </button>
                    </div>
                `;
            });
        } else {
            document.getElementById('statusText').innerHTML = `
                <span class="text-amber-600 dark:text-amber-400 block mb-2">⚠️ Bu barkoda sahip stokta ürün bulunamadı.</span>
                <button type="button" onclick="kamerayiTekrarAc()" class="bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-white px-4 py-1.5 rounded-lg text-xs font-bold">Tekrar Dene</button>
            `;
        }
    } catch (e) {
        document.getElementById('statusText').innerHTML = `<span class="text-red-500">Bağlantı hatası oluştu.</span>`;
    }
}

async function hizliTuket(id, birim) {
    if(!confirm('Bu üründen 1 ' + birim + ' tüketmek (stoktan düşmek) istediğinize emin misiniz?')) return;
    
    try {
        const res = await fetch(`ajax.php?islem=hizli_tuket&id=${id}&adet=1&csrf_token=${csrfToken}`);
        const result = await res.json();
        
        if (result.success) {
            Swal.fire({ icon: 'success', title: 'Tüketildi!', text: 'Stok başarıyla güncellendi.', timer: 1500, showConfirmButton: false });
            document.getElementById('urun_satir_' + id).style.opacity = '0.5';
            document.getElementById('urun_satir_' + id).querySelector('button').disabled = true;
            document.getElementById('urun_satir_' + id).querySelector('button').textContent = '✅ Tüketildi';
            document.getElementById('urun_satir_' + id).querySelector('button').classList.replace('bg-red-500', 'bg-green-500');
        } else {
            Swal.fire('Hata', result.error || 'Bir sorun oluştu.', 'error');
        }
    } catch (e) {
        Swal.fire('Hata', 'Bağlantı hatası.', 'error');
    }
}
</script>
</body>
</html>
