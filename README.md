# 📦 Akıllı Ev & Kiler Stok Takip Sistemi
### (Smart Home Pantry & Inventory Management System)

[![PHP Version](https://img.shields.io/badge/PHP-7.4%20|%208.x-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%20|%208.x-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Tailwind CSS](https://img.shields.io/badge/TailwindCSS-3.x-38B2AC?style=flat-square&logo=tailwind-css&logoColor=white)](https://tailwindcss.com/)
[![PWA Ready](https://img.shields.io/badge/PWA-Ready-4f46e5?style=flat-square&logo=pwa&logoColor=white)](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps)
[![Security](https://img.shields.io/badge/Security-OWASP%20Hardened-emerald?style=flat-square&logo=shield)](https://owasp.org/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)

Ev, yazlık veya ofis ortamlarındaki tüm kiler, buzdolabı ve erzak depolarını organize etmek, **son kullanma tarihlerini (SKT)** 10 kademeli akıllı uyarı sistemiyle takip etmek, **gıda israfını sıfırlamak** ve dolaptaki malzemelerden **yapay zeka ile yemek tarifleri üretmek** amacıyla geliştirilmiş; **PWA (Progressive Web App)**, **kamera barkod okuyucu** ve **tek tıkla web kurulum sihirbazı (Turnkey Installer)** destekli açık kaynaklı stok takip platformudur.

---

## 💡 Gerçek Hayat Kullanım Senaryoları

Bu sistem sıradan bir liste uygulamasının ötesinde, gerçek ev dinamikleri ve mutfak alışkanlıkları göz önüne alınarak tasarlanmıştır:

### 1. 🥫 Kiler ve Kuru Gıda İsrafını Önleme
* **Problem:** Kilerde kaç paket makarna veya pirinç olduğunu unutup markette sürekli aynı şeyleri almak; arka raflarda unutulan ürünlerin bozulması.
* **Çözüm:** Kilerdeki her ürünün miktarını, rafını ve son kullanma tarihini anlık görün. Sistem paketi açılmış ürünleri (`Açık Paket`) özel olarak işaretler ve tüketim önceliği verir.

### 2. 🥩 Derin Dondurucu & Buzdolabı Organizasyonu
* **Problem:** Dondurucuya atılan et, tavuk veya sebzelerin ne zaman girdiği ve ne zaman tüketilmesi gerektiğinin unutulması.
* **Çözüm:** Buzdolabı dolap tipinde `Soğutucu` ve `Dondurucu` bölme ayrımı. Et/Tavuk/Balık ürünleri otomatik `Kg` birimiyle ve hassas SKT uyarılarıyla takip edilir.

### 3. ⏰ 10 Kademeli Akıllı Bildirim Sistemi (90, 60, 45, 30, 14, 7, 5, 3, 2, 1 Gün)
* **Problem:** Ürünlerin tarihi geçmeden hemen önce fark edilememesi veya aylar öncesinden tüketim planlaması yapılamaması.
* **Çözüm:** Sistem ürünlerin son kullanma tarihine **90, 60, 45, 30, 14, 7, 5, 3, 2 ve 1 gün kala** kademeli olarak hem web arayüzünde hem de e-posta bülteninde uyarı üretir.

### 4. 🛒 Pazartesi Sabahı Akıllı Alışveriş Listesi Önerisi
* **Problem:** Markete gitmeden önce dolapları tek tek açıp neyin bittiğini veya azaldığını kontrol etmeye vakit ayıramamak.
* **Çözüm:** Her Pazartesi saat 09:00'da tetiklenen akıllı stok motoru, belirlenen kritik eşiğin altına düşen tüm temel gıda ve tüketim malzemelerini tespit eder ve doğrudan hazır bir alışveriş listesi olarak e-posta gönderir.

### 5. 🤖 Google Gemini Destekli Akıllı Kiler Şefi (`sef.php`)
* **Problem:** Dolapta kalan ve son kullanma tarihi yaklaşan malzemelerle ne yemek yapacağını bilememek.
* **Çözüm:** Kiler Şefi, dolabınızda bulunan malzemeleri ve öncelikli olarak SKT'si yaklaşan gıdaları analiz eder; Google Gemini AI motoru sayesinde elinizdeki malzemelerle yapabileceğiniz pratik, israfı önleyici yemek tarifleri sunar.

### 6. 🏡 Çoklu Mekan Yönetimi (Ev, Yazlık, Ofis)
* **Problem:** Hem ana evdeki hem yazlıktaki kilerin durumunu aynı panelde takip etmek, ancak kullanıcıların yalnızca yetkili oldukları lokasyonları görmesini istemek.
* **Çözüm:** `Şehir > Mekan > Oda > Dolap > Raf` hiyerarşisi. Kullanıcı bazlı şehir yetkilendirmesi (IDOR korumalı) ile herkes yalnızca yetkili olduğu mülkün envanterini yönetebilir.

### 7. 📱 Mobil Barkod Okuma ile Anında Tüketim
* **Problem:** Dolaptan bir ürün aldığınızda stoktan düşmek için bilgisayar başına geçmenin zor olması.
* **Çözüm:** Telefon kamerasıyla veya barkod okuyucu tabancayla ürün barkodunu tarayın; sistem ürünü anında tanır ve tek tıkla stoktan 1 adet düşer.

---

## 🔔 Kademeli Bildirim Eşikleri Mimarisi

Sistem, ürünlerin son kullanma tarihine kalan gün sayısına göre **4 farklı önem seviyesinde (Severity)** otomatik bildirim üretir:

| Kalan Gün | Bildirim Kademesi | Öncelik / Renk | Açıklama |
| :---: | :---: | :---: | :--- |
| **90 Gün** | Uzun Vadeli Takip | ⚪ Bilgi (Low) | Kilerde uzun ömürlü konserveler ve bakliyatlar için ilk erken uyarı |
| **60 Gün** | 2 Ay Bildirimi | ⚪ Bilgi (Low) | Tüketim planlamasına dahil etme uyarısı |
| **45 Gün** | 1.5 Ay Bildirimi | 🔵 Orta (Medium) | İlaçlar, vitaminler ve kuru gıdalar için ara kontrol |
| **30 Gün** | 1 Ay Bildirimi | 🔵 Orta (Medium) | Ay bazlı menü planlamasına alma tavsiyesi |
| **14 Gün** | 2 Hafta Bildirimi | 🟡 Yüksek (High) | Tüketim önceliğine geçiş |
| **7 Gün** | 1 Hafta Bildirimi | 🟡 Yüksek (High) | Haftalık menüye zorunlu dahil etme |
| **5 Gün** | 5 Gün Bildirimi | 🟠 Kritik (Critical) | Hızlı tüketim uyarısı |
| **3 Gün** | 3 Gün Bildirimi | 🔴 Çok Kritik (Critical) | Acil tüketilmesi gereken taze ürünler (Süt, et, peynir vb.) |
| **2 Gün** | 2 Gün Bildirimi | 🔴 Çok Kritik (Critical) | Kiler Şefi ile tarif üretilmesi önerilir |
| **1 Gün** | Son Gün Uyarısı | 🔴 Son Çağrı (Critical) | Bozulmayı ve israfı önleyen son uyarı |

> 💡 **Not:** Bu eşikler veritabanındaki `notification_thresholds` tablosunda saklanır ve web panelindeki **Bildirim Ayarları** sayfasından dilediğiniz zaman güncellenebilir veya yeni günler eklenebilir.

---

## 🛠️ Sistem Gereksinimleri

Kuruluma başlamadan önce sunucunuzun veya barındırma ortamınızın aşağıdaki gereksinimleri karşıladığından emin olun:

* **PHP:** `7.4` veya `8.0+` (PHP 8.1 / 8.2 / 8.3 ile tam uyumludur)
* **PHP Eklentileri (Extensions):**
  * `pdo_mysql` (Veritabanı iletişimi için zorunlu)
  * `fileinfo` (Excel/CSV yükleme güvenlik kontrolleri için)
  * `openssl` (Güvenli oturum ve token üretimi için)
  * `curl` (Open Food Facts ve Gemini API bağlantıları için)
* **Veritabanı:** MySQL `5.7+` veya MariaDB `10.3+`
* **Web Sunucusu:** Apache (önerilen), LiteSpeed veya Nginx (URL rewrite destekli)
* **Modüller:** Apache `mod_rewrite` ve `mod_headers` aktif olmalıdır (güvenlik başlıkları ve `.htaccess` için)

---

## 🚀 Detaylı Kurulum Rehberi

Stok Takip sistemi, kurulumu herkes için zahmetsiz hale getiren **Otomatik Kurulum Sihirbazı (Turnkey Installer)** ile birlikte gelir. Sıfırdan bir sunucuya kurmak yalnızca 1-2 dakika sürer.

```
İndir / Yükle ──▶ Tarayıcıdan Aç ──▶ install.php Formunu Doldur ──▶ Tamamlandı!
```

---

### YÖNTEM 1: Otomatik Web Kurulum Sihirbazı (Önerilen)

#### Adım 1: Dosyaları Sunucuya Yükleyin
Proje dizinindeki tüm dosyaları web sunucunuza yükleyin:
* **cPanel / Plesk Hosting:** Dosyaları `public_html/` veya `public_html/stok-takip/` klasörüne yükleyin.
* **XAMPP (Yerel):** `C:\xampp\htdocs\stok-takip\` klasörüne kopyalayın.
* **Laragon (Yerel):** `C:\laragon\www\stok-takip\` klasörüne kopyalayın.

#### Adım 2: Kurulum Sihirbazını Başlatın
Tarayıcınızı açın ve sitenizin adresine gidin:
```
http://localhost/stok-takip/
veya
https://siteniz.com/stok-takip/
```
> ✨ **Akıllı Yönlendirme:** Sistem henüz kurulmamışsa `index.php`'ye gitseniz bile otomatik olarak doğrudan `install.php` kurulum sihirbazına yönlendirilirsiniz.

#### Adım 3: Kurulum Formunu Doldurun
Kurulum sihirbazında yer alan 6 ana bölümü yapılandırın:

1. **🌐 Uygulama Ayarları:**
   * **Ortam (`APP_ENV`):** Canlı sunucu için `production`, yerel test için `local` seçin.
   * **Kurulu Web Dizini (`APP_URL`):** Sistem sunucunuzdan tam adresi **otomatik algılar** (Örn: `https://siteniz.com/stok-takip`). E-posta linklerinin doğru çalışması için bu adres kullanılır.
2. **🗄️ Veritabanı Bağlantısı (MySQL):**
   * **Sunucu (`DB_HOST`):** Genellikle `localhost`.
   * **Veritabanı Adı (`DB_NAME`):** cPanel'de oluşturduğunuz veritabanı adı (Örn: `kullanici_stoktakip`).
   * **Kullanıcı Adı (`DB_USER`):** Veritabanı kullanıcı adı.
   * **Şifre (`DB_PASS`):** Veritabanı kullanıcısının şifresi.
3. **👤 İlk Yönetici Hesabı:**
   * Panele giriş yapacağınız yönetici kullanıcı adı, e-posta adresi ve en az 6 karakterli güçlü bir şifre belirleyin.
4. **🛡️ Cron ve Güvenlik:**
   * **Cron Tokenı (`CRON_SECRET`):** Otomatik e-posta motorunun yetkisiz kişilerce tetiklenmesini engelleyen güvenlik anahtarıdır. Sistem sizin için **otomatik 32 karakterlik rastgele güvenli anahtar** üretir.
5. **🤖 Google Gemini API (Opsiyonel):**
   * Akıllı Kiler Şefi (`sef.php`) için [Google AI Studio](https://aistudio.google.com/)'dan **ücretsiz** alacağınız API anahtarı. Boş bırakıp daha sonra da ekleyebilirsiniz.
6. **📧 SMTP E-Posta Bildirim Ayarları (Opsiyonel):**
   * Günlük SKT raporları ve haftalık alışveriş listelerinin e-posta kutunuza gelmesi için SMTP sunucu bilgileriniz:
     * `SMTP_HOST`: Örn. `mail.domain.com.tr` veya `smtp.gmail.com`
     * `SMTP_PORT`: Örn. `587` (TLS için) veya `465` (SSL için)
     * `SMTP_USER` & `SMTP_PASS`: Mail adresi ve şifresi
     * `SMTP_SECURE`: `tls` (önerilen) / `ssl` / `none`
     * `MAIL_FROM_ADDRESS`: Gönderen e-posta adresi
     * `MAIL_FROM_NAME`: Gönderen ismi (Varsayılan: `StokTakip Bildirim`)

#### Adım 4: "Kurulumu Başlat" Butonuna Tıklayın
Sihirbaz tek tıklamayla:
* Tüm tabloları (`products`, `categories`, `product_types`, `notifications`, `audit_logs` vb.) oluşturur.
* **21 ana kategori** ve yüzlerce hazır alt kategoriyi ekler.
* Cins bazlı akıllı stok eşiklerini ve standart birimleri (Et: Kg, diğerleri: Adet) tanımlar.
* **10 kademeli bildirim günlerini** (`90, 60, 45, 30, 14, 7, 5, 3, 2, 1`) kaydeder.
* Başlangıç konumu hiyerarşisini (Ev > Mutfak > Buzdolabı & Kiler Dolabı) oluşturur.
* Yönetici hesabınızı oluşturup yetkilendirir.
* Tüm ayarları içeren `.env` dosyasını otomatik yazar ve güvenliğiniz için `installed.lock` dosyasını oluşturur.

---

### YÖNTEM 2: Manuel Kurulum (.env ile)

Eğer komut satırı veya FTP üzerinden manuel kurulum yapmayı tercih ediyorsanız:

1. Depoyu klonlayın veya indirin:
   ```bash
   git clone https://github.com/haktanbozer/stoktakip.git
   ```
2. `.env.example` dosyasının adını `.env` olarak değiştirin ve düzenleyin:
   ```env
   # --- UYGULAMA AYARLARI ---
   APP_ENV=production
   APP_URL="https://siteniz.com/stok-takip"

   # --- VERİTABANI AYARLARI ---
   DB_HOST=localhost
   DB_NAME=stok_takip
   DB_USER=stok_kullanici
   DB_PASS=parolaniz

   # Cron ve Güvenlik
   CRON_SECRET=rastgele_guclu_bir_anahtar_giriniz
   APP_URL=https://siteniz.com/stok-takip

   # --- GOOGLE GEMINI API ---
   GEMINI_API_KEY=AIzaSy...

   # --- SMTP MAIL AYARLARI ---
   SMTP_HOST=mail.domain.com.tr
   SMTP_USER=bildirim@domain.com.tr
   SMTP_PASS=mail_sifreniz
   SMTP_PORT=587
   SMTP_SECURE=tls
   MAIL_FROM_ADDRESS="bildirim@domain.com.tr"
   MAIL_FROM_NAME="StokTakip Bildirim"
   ```
3. Tarayıcınızdan `install.php` dosyasını bir kez çalıştırarak veritabanı tablolarının ve kategorilerin otomatik yüklenmesini sağlayın.

---

## ⏱️ Otomatik Rapor Cron Kurulumu (cPanel & Crontab)

Sistemdeki e-posta raporlama motoru iki temel görevi yerine getirir:
1. **Günlük SKT Raporu:** Belirlenen eşiklere (90, 60, 45, 30, 14, 7, 5, 3, 2, 1 gün) giren ürünleri listeler.
2. **Pazartesi Alışveriş Listesi:** Kritik stok eşiğinin altına düşen ürünleri tespit edip market takviye listesi sunar.

### cPanel Üzerinden Cron Görevi Ekleme:
1. cPanel kontrol panelinize giriş yapın ve **Cron Jobs (Zamanlanmış Görevler)** sayfasına gidin.
2. **Ortak Ayarlar (Common Settings):** "Günde Bir Kez (0 8 * * *)" veya sabah 08:00 olarak seçin.
3. **Komut (Command)** satırına şunu yapıştırın:
   ```bash
   curl -s "https://siteniz.com/stok-takip/cron-mail.php?token=CRON_SECRET_ANAHTARINIZ" > /dev/null 2>&1
   ```

### Crontab (Linux / VPS) İle Ayarlama:
Terminalden `crontab -e` komutunu çalıştırın ve aşağıdaki satırları ekleyin:

```bash
# Otomatik Mod: Her sabah 08:00'de çalışır (Her gün SKT kontrolü yapar, Pazartesi günleri ayrıca Alışveriş Listesi ekler):
0 8 * * * curl -s "https://siteniz.com/stok-takip/cron-mail.php?token=CRON_SECRET" > /dev/null 2>&1

# Sadece Günlük SKT Raporu (İsteğe bağlı ayrı çalıştırmak için):
0 8 * * * curl -s "https://siteniz.com/stok-takip/cron-mail.php?tip=skt&token=CRON_SECRET" > /dev/null 2>&1

# Sadece Pazartesi Alışveriş Listesi (Her Pazartesi saat 09:00'da):
0 9 * * 1 curl -s "https://siteniz.com/stok-takip/cron-mail.php?tip=alisveris&token=CRON_SECRET" > /dev/null 2>&1
```

---

## 📱 PWA (Mobil Uygulama Olarak Kurulum)

Stok Takip, modern **Progressive Web App (PWA)** standartlarını tam olarak destekler:

1. Telefonunuzun tarayıcısından (iOS Safari veya Android Chrome) sisteme giriş yapın.
2. **Safari (iOS):** Paylaş butonuna basıp **"Ana Ekrana Ekle"** seçeneğini seçin.
3. **Chrome (Android):** Sağ üstteki menüden **"Uygulamayı Yükle"** veya **"Ana Ekrana Ekle"** butonuna dokunun.
4. Uygulama telefonunuzun ana ekranında yerel bir mobil uygulama gibi ikonlaşacak; tam ekran modunda, adres çubuğu olmadan ve çevrimdışı önbellekleme desteğiyle çalışacaktır.

---

## 🛡️ Güvenlik Tavsiyeleri (Canlı Sunucu İçin)

* **`install.php` Güvenliği:** Kurulum tamamlandığında sistem `installed.lock` oluşturarak sihirbazı otomatik kilitler. Ek güvenlik için kurulum tamamlandıktan sonra `install.php` dosyasını sunucunuzdan silebilir veya adını değiştirebilirsiniz.
* **`.env` Dosyası:** Web sunucunuzdaki `.htaccess` dosyası `.env`, `.log` ve `sessions/` dizinlerine dışarıdan doğrudan erişimi varsayılan olarak engeller. Bu dosyayı asla silmeyin.
* **HTTPS Kullanımı:** Kamera ile barkod tarama özelliğinin (WebRTC) iOS Safari ve Android Chrome üzerinde çalışabilmesi için sitenizin **HTTPS (SSL)** protokolüyle çalışması gerekmektedir (Yerel ağda `localhost` üzerinde SSL olmadan da çalışır).

---

## 📁 Proje Dizin Mimarisi

```
stok-takip/
├── icons/                  # PWA mobil uygulama ikonları (72x72 -> 512x512)
├── logs/                   # Güvenli sistem ve hata logları (.htaccess ile kilitli)
├── PHPMailer/              # SMTP e-posta gönderim kütüphanesi (v6.9)
├── sessions/               # Güvenli PHP oturum dosyaları
├── ajax.php                # Dinamik AJAX uç noktaları ve API işlemleri
├── bildirim-ayarlari.php   # Bildirim günleri ve kritik eşik tercihleri
├── cron-mail.php           # Otomatik e-posta bildirim motoru (SKT & Alışveriş)
├── db.php                  # PDO bağlantısı, CSP nonce'ları, oturum ve güvenlik kalkanı
├── envanter.php            # Gelişmiş filtreleme ve arama destekli stok listesi
├── excel-export.php        # CSV / Excel dışa aktarma servisi
├── excel-import.php        # Toplu CSV / Excel içe aktarma servisi
├── hizli-tuket.php         # Kamera ile anında barkod okuma ve tüketim ekranı
├── install.php             # Tek tıkla otomatik kurulum sihirbazı (Turnkey Installer)
├── kategoriler.php         # Kategori, alt kategori ve cins yönetim paneli
├── login.php               # Güvenli giriş ekranı ve brute-force koruması
├── mekan-yonetimi.php      # Şehir, mekan, oda ve dolap yönetim paneli
├── sef.php                 # Yapay zeka destekli Akıllı Kiler Şefi (Google Gemini)
├── toplu-ekle.php          # Çoklu ürün giriş tablosu (Kamera + Barkod Sorgulama)
├── urun-ekle.php           # Adım adım ürün ekleme sihirbazı
├── urun-duzenle.php        # Ürün düzenleme ve lokasyon güncelleme
├── manifest.json           # PWA Web App manifest dosyası
├── sw.js                   # PWA Service Worker (Çevrimdışı fallback ve cache)
├── .env.example            # Örnek çevre değişkenleri şablonu
├── .gitignore              # Hassas dosyaları engelleyen kurallar
├── .htaccess               # Apache güvenlik ve mod_rewrite başlıkları
└── README.md               # Kapsamlı proje dokümantasyonu
```

---

## 📄 Lisans

Bu proje **[MIT Lisansı](LICENSE)** kapsamında açık kaynaklı olarak lisanslanmıştır. Dilediğiniz gibi özgürce kullanabilir, kişisel veya kurumsal ihtiyaçlarınıza göre uyarlayabilirsiniz.
