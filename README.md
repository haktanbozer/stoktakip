# 📦 Akıllı Ev & Kiler Stok Takip Sistemi
### (Smart Home Pantry & Inventory Management System)

[![PHP Version](https://img.shields.io/badge/PHP-7.4%20|%208.x-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%20|%208.x-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Tailwind CSS](https://img.shields.io/badge/TailwindCSS-3.x-38B2AC?style=flat-square&logo=tailwind-css&logoColor=white)](https://tailwindcss.com/)
[![PWA Ready](https://img.shields.io/badge/PWA-Ready-4f46e5?style=flat-square&logo=pwa&logoColor=white)](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps)
[![Security](https://img.shields.io/badge/Security-OWASP%20Hardened-emerald?style=flat-square&logo=shield)](https://owasp.org/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)

Ev, yazlık veya ofis ortamlarındaki tüm kiler, buzdolabı ve erzak depolarını organize etmek, **son kullanma tarihlerini (SKT)** takip etmek ve **gıda israfını sıfırlamak** amacıyla geliştirilmiş, **PWA (Progressive Web App)** ve **akıllı barkod okuyucu** destekli açık kaynaklı stok takip platformudur.

---

## 💡 Gerçek Hayat Kullanım Senaryoları

Bu sistem sıradan bir liste uygulamasının ötesinde, gerçek ev dinamikleri ve mutfak alışkanlıkları göz önüne alınarak tasarlanmıştır:

### 1. 🥫 Kiler ve Kuru Gıda İsrafını Önleme
* **Problem:** Kilerde kaç paket makarna veya pirinç olduğunu unutup markette sürekli aynı şeyleri almak; arka raflarda unutulan ürünlerin bozulması.
* **Çözüm:** Kilerdeki her ürünün miktarını, rafını ve son kullanma tarihini anlık görün. Sistem paketi açılmış ürünleri (`Açık Paket`) özel olarak işaretler ve tüketim önceliği verir.

### 2. 🥩 Derin Dondurucu & Buzdolabı Organizasyonu
* **Problem:** Dondurucuya atılan et, tavuk veya sebzelerin ne zaman girdiği ve ne zaman tüketilmesi gerektiğinin unutulması.
* **Çözüm:** Buzdolabı dolap tipinde `Soğutucu` ve `Dondurucu` bölme ayrımı. Et/Tavuk/Balık ürünleri otomatik `Kg` birimiyle ve hassas SKT uyarılarıyla takip edilir.

### 3. 📧 Günlük SKT Yaklaşanlar E-Posta Raporu
* **Problem:** Yoğun iş temposunda dolaptaki ürünlerin son kullanma tarihlerini tek tek kontrol edememek.
* **Çözüm:** Her sabah otomatik çalışan Cron servisi sayesinde 7 gün içinde süresi dolacak veya süresi geçmiş ürünler yöneticinin e-posta kutusuna düzenli bir bülten olarak iletilir.

### 4. 🛒 Pazartesi Sabahı Akıllı Alışveriş Listesi
* **Problem:** Markete gitmeden önce dolapları tek tek açıp neyin bittiğini veya azaldığını kontrol etmeye vakit ayıramamak.
* **Çözüm:** Her Pazartesi saat 09:00'da tetiklenen akıllı stok motoru, belirlenen kritik eşiğin altına düşen tüm temel gıda ve tüketim malzemelerini tespit eder ve doğrudan hazır bir alışveriş listesi olarak e-posta gönderir.

### 5. 🏡 Çoklu Mekan Yönetimi (Ev, Yazlık, Ofis)
* **Problem:** Hem ana evdeki hem yazlıktaki kilerin durumunu aynı panelde takip etmek, ancak kullanıcıların yalnızca yetkili oldukları lokasyonları görmesini istemek.
* **Çözüm:** `Şehir > Mekan > Oda > Dolap > Raf` hiyerarşisi. Kullanıcı bazlı şehir yetkilendirmesi (IDOR korumalı) ile herkes yalnızca yetkili olduğu mülkün envanterini yönetebilir.

### 6. 📱 Mobil Barkod Okuma ile Anında Tüketim
* **Problem:** Dolaptan bir ürün aldığınızda stoktan düşmek için bilgisayar başına geçmenin zor olması.
* **Çözüm:** Telefon kamerasıyla veya barkod okuyucu tabancayla ürün barkodunu tarayın; sistem ürünü anında tanır ve tek tıkla stoktan 1 adet düşer.

---

## ✨ Öne Çıkan Özellikler

* **📷 Mobil Arka Kamera & Barkod Tarayıcı:** iOS Safari ve Android Chrome'da doğrudan arka kamerayı açan Html5-Qrcode barkod tarayıcı.
* **🌍 Global Veritabanı Desteği (Open Food Facts):** Kendi veritabanınızda bulunmayan bir barkod okutulduğunda ürün adı ve markası otomatik olarak internetten çekilir.
* **📱 Progressive Web App (PWA):** Tarayıcıdan "Ana Ekrana Ekle" diyerek telefonunuza yerel uygulama gibi kurulabilir; çevrimdışı fallback ekranı barındırır.
* **📑 Toplu Ürün Ekleme (Spreadsheet Deneyimi):** Kategori, Barkod, Marka, Ürün Adı, Miktar, SKT ve Dolap alanlarını tek bir sayfada hızlıca ekleyebileceğiniz dinamik tablo.
* **⚖️ Standart Birim & Otomatik Eşik Motoru:** Türk market ve mutfak yapısına uygun 21 hazır ana kategori; `Et/Tavuk/Balık` için `Kg`, diğer tüm kategoriler için `Adet` standardizasyonu.
* **📊 Excel / CSV İçe & Dışa Aktarma:** Mevcut envanteri Excel olarak indirebilme veya elinizdeki listeleri tek seferde sisteme yükleme.
* **🌓 Modern & Duyarlı Arayüz:** Tailwind CSS ile geliştirilmiş, gece ve gündüz modları (Dark/Light Mode) ile tam uyumlu responsive tasarım.

---

## 🛡️ Güvenlik Mimarisi (OWASP Top 10)

Platform kurumsal düzeyde güvenlik standartları ile inşa edilmiştir:
* **SQL Injection Koruması:** Tüm veritabanı sorguları istisnasız `PDO Prepared Statements` kullanılarak çalıştırılır.
* **CSRF (Cross-Site Request Forgery) Koruması:** Tüm durum değiştiren POST istekleri tek kullanımlık zaman aşımı olan CSRF tokenları ile doğrulanır.
* **CSP (Content Security Policy) & Nonce:** Sayfa başına dinamik üretilen kriptografik `nonce` ile izinsiz JavaScript yürütülmesi engellenir.
* **Brute-Force Kalkanı:** Başarısız giriş denemeleri IP ve kullanıcı adı bazında takip edilir, aşırı denemelerde hesap geçici olarak kilitlenir.
* **Yetkilendirme & IDOR Koruması:** Dolap transferleri, şehir seçimleri ve ürün silme işlemleri sunucu tarafında kullanıcının yetki matrisine göre denetlenir.
* **Audit & Sistem Logları:** Kritik tüm işlemler (silme, güncelleme, aktarma) IP ve kullanıcı bilgisiyle loglanır; log dosyalarına dış web erişimi engellenmiştir.

---

## 🚀 Kurulum

### Yöntem 1: Web Kurulum Sihirbazı (Önerilen — 1 Dakika)

1. Proje dosyalarını web sunucunuza (Apache, LiteSpeed, Nginx) veya yerel geliştirme ortamınıza (XAMPP, Laragon, Docker) yükleyin.
2. Tarayıcınızdan `http://localhost/stok-takip/install.php` (veya alan adınız) adresine gidin.
3. Açılan kurulum sihirbazında:
   - MySQL veritabanı bağlantı bilgilerinizi girin.
   - Yönetici kullanıcı adı ve şifrenizi belirleyin.
   - **"Kurulumu Başlat"** butonuna tıklayın.
4. Sihirbaz veritabanı tablolarını, **21 hazır kategoriyi**, yüzlerce alt kategoriyi, standart birimleri ve başlangıç dolaplarını otomatik olarak oluşturur, `.env` dosyasını yazar ve kendini kilitler.
5. Kurulum bittikten sonra doğrudan `login.php` üzerinden giriş yapabilirsiniz!

---

### Yöntem 2: Manuel Kurulum

1. Depoyu klonlayın:
   ```bash
   git clone https://github.com/kullanici-adiniz/stok-takip.git
   ```
2. `.env.example` dosyasını `.env` olarak kopyalayın ve veritabanı bilgilerinizi girin:
   ```env
   DB_HOST="localhost"
   DB_NAME="stok_takip"
   DB_USER="root"
   DB_PASS="sifreniz"
   CRON_SECRET_KEY="rastgele_guclu_bir_token"
   ```
3. Boş MySQL veritabanınıza `install.php` sihirbazını çalıştırarak veya temiz şema dosyasını içe aktararak tabloları oluşturun.

---

## ⏰ Otomatik E-Posta Raporları (Cron Ayarları)

Sistemde iki farklı amaca hizmet eden otomatik e-posta raporu bulunmaktadır. Sunucunuzun cPanel veya Crontab paneline şu komutları ekleyebilirsiniz:

```bash
# 1. Günlük SKT Yaklaşan Ürünler Raporu (Her sabah saat 08:00'de)
0 8 * * * curl -s "https://siteniz.com/cron-mail.php?tip=skt&token=CRON_SECRET_KEY" > /dev/null 2>&1

# 2. Haftalık Akıllı Alışveriş Listesi Önerisi (Her Pazartesi saat 09:00'da)
0 9 * * 1 curl -s "https://siteniz.com/cron-mail.php?tip=alisveris&token=CRON_SECRET_KEY" > /dev/null 2>&1
```

---

## 📁 Proje Dizin Yapısı

```
stok-takip/
├── icons/                  # PWA uygulama ikonları ve manifest varlıkları
├── logs/                   # Güvenli sistem ve hata logları (.htaccess ile kilitli)
├── sessions/               # Güvenli oturum dosyaları
├── ajax.php                # Dinamik AJAX uç noktaları ve API işlemleri
├── bildirim-ayarlari.php   # Bildirim günleri ve kritik eşik tercihleri
├── cron-mail.php           # Otomatik e-posta bildirim motoru (SKT & Alışveriş)
├── db.php                  # PDO bağlantısı, CSP nonceleri, oturum ve güvenlik kalkanı
├── envanter.php            # Gelişmiş filtreleme ve arama destekli stok listesi
├── excel-export.php        # CSV / Excel dışa aktarma servisi
├── excel-import.php        # Toplu CSV / Excel içe aktarma servisi
├── hizli-tuket.php         # Kamera ile anında barkod okuma ve tüketim ekranı
├── install.php             # Tek tıkla otomatik kurulum sihirbazı
├── kategoriler.php         # Kategori, alt kategori ve cins yönetim paneli
├── login.php               # Güvenli giriş ekranı ve brute-force koruması
├── mekan-yonetimi.php      # Şehir, mekan, oda ve dolap yönetim paneli
├── toplu-ekle.php          # Çoklu ürün giriş tablosu (Kamera + Barkod Sorgulama)
├── urun-ekle.php           # Adım adım ürün ekleme sihirbazı
├── urun-duzenle.php        # Ürün düzenleme ve lokasyon güncelleme
├── manifest.json           # PWA Web App manifesti
├── sw.js                   # PWA Service Worker (Çevrimdışı önbellekleme)
├── .env.example            # Örnek çevre değişkenleri şablonu
└── README.md               # Proje dökümantasyonu
```

---

## 🛠️ Teknoloji Yığını

* **Sunucu Tarafı:** PHP 7.4+ / 8.0+ / 8.1+ / 8.2+ (Vanilla PHP, PDO)
* **Veritabanı:** MySQL 5.7+ / MariaDB 10.3+
* **Arayüz:** HTML5, Tailwind CSS, DataTables, SweetAlert2
* **Barkod & Kamera:** Html5-Qrcode JavaScript Library
* **Harici Entegrasyon:** Open Food Facts REST API
* **Mobil Standart:** Progressive Web App (PWA), Service Worker v2

---

## 📄 Lisans

Bu proje **[MIT Lisansı](LICENSE)** kapsamında lisanslanmıştır. Dilediğiniz gibi kullanabilir, özelleştirebilir ve geliştirebilirsiniz.
