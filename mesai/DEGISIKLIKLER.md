# XRE Mesai — 5.5 Giriş ekranında arka plan videosu

- Giriş ekranının arka planında sessiz, döngülü video. Kartın okunabilmesi için üstünde
  hafif lacivert katman var; kart yarı saydam ve bulanık cam görünümünde.
- Dosyalar: `giris-video.webm` (330 KB — Chrome/Android/Firefox), `giris-video.mp4`
  (840 KB — Safari/iPhone), `giris-poster.jpg` (video yüklenene kadar ilk kare).
  Orijinal 5,6 MB videodan sesi çıkarılıp sıkıştırıldı.
- Video yalnızca giriş ekranı açıkken yüklenir ve oynar; giriş yapınca durdurulup bellekten
  çıkarılır. Veri tasarrufu ya da "hareketi azalt" açık telefonlarda yalnızca ilk kare gösterilir.
- `sw.js` v22 (video service worker önbelleğine alınmaz).

# XRE Mesai — 5.4 Yeni logo

- Logo "X Real Estate Türkiye" (şeffaf arka plan) ile değiştirildi: `logo-v2.png`
  (giriş ekranı, uygulama başlığı, yönetim paneli). Yeni dosya adı sayesinde eski logo
  önbellekte takılı kalmaz. `sw.js` v21.
- Bitrix senkronu bu sürümlerde değişmedi; uçtan uca test: başlat → OPENED, ihtiyaç molası →
  PAUSED, dönüş → OPENED, bitir → CLOSED (+ mola dökümlü rapor).

# XRE Mesai — 5.3 Tek "İhtiyaç Molası"

- Çay ve tuvalet molası kaldırıldı, yerine tek **☕ İhtiyaç Molası** geldi.
  Mola menüsü: İhtiyaç Molası · Yemek Molası · Online Randevu.
- Telegram: "ihtiyaç molasına çıktı" / "ihtiyaç molasından döndü"; akşam raporunda "☕ İhtiyaç".
- Geçmiş kayıtlardaki çay ve tuvalet molaları silinmedi; raporlarda, sıralamada ve
  uygulamada **İhtiyaç Molası** olarak birleştirilip gösterilir. Güncelleme anında çay
  molasında olan kişi normal şekilde döner.
- Uzun mola hatırlatması: ihtiyaç molası 30 dk, yemek 75 dk (`$MOLA_HATIRLATMA_DK`).
- `sw.js` v20 (açık sayfalar kendiliğinden güncellenir).

# XRE Mesai — 5.2 Güncellemeler herkese ulaşsın + "bağlı" düzeltmesi

- **Site yenilenmiyordu:** Service worker sayfayı "önce önbellek" ile açıyordu; yeni sürüm ancak
  ikinci açılışta görünüyordu. Artık sayfa **önce ağdan** gelir (çevrimdışıyken önbellek), yeni sürüm
  yüklenince açık sayfa kendini bir kez yeniler, 30 dk'da bir ve uygulamaya dönülünce güncelleme
  kontrol edilir. `.htaccess`: html/js/json için `Cache-Control: no-cache`. (`sw.js` v19)
  ⚠️ Bu geçiş için herkesin uygulamayı **bir kez kapatıp yeniden açması** gerekir (eski sürümün kısıtı);
  sonraki güncellemeler kendiliğinden gelir.
- **Bağlama şeridi çoğu kişiye çıkmıyordu:** `kisiler.php`'de ID'si olan herkes "bağlı" sayılıyordu,
  botu hiç başlatmamış olsa bile (bot onlara yazamaz). Artık "bağlı" = hatırlatma botunu gerçekten
  başlatmış. Doğrulama: bota yazması, başarılı mesaj ya da sessiz `getChat` kontrolü (kişiye bildirim gitmez).
- Admin listesinde TG ✓ yalnızca botu başlatanlarda; teşhiste "Henüz bağlanmayanlar" listesi.

# XRE Mesai — 5.1 Bot yanıt vermiyordu: düzeltmeler + teşhis

- **PHP 7.4 uyumluluğu:** PHP 8'e özgü `str_starts_with` / `str_contains` ve mbstring fonksiyonları
  için yedekler eklendi. Bu fonksiyonlar eksikse bot gelen kutusu kontrolü sessizce atlanıyordu.
- **Bozuk webhook kendini onarır:** "Anında yanıt" kurulu ama Telegram sunucuya ulaşamıyorsa
  (güvenlik duvarı/403/SSL) webhook otomatik kaldırılır, dakikalık yoklamaya dönülür, biriken mesajlar işlenir.
- **Bağlanma hızlandı:** Uygulamada "Telegram'ı Bağla"ya basınca uygulama 6 sn'de bir sunucuya sorar;
  sunucu bu sırada botun gelen kutusunu hemen işler (webhook olmasa da ~10 sn'de "Bağlandın").
- **🩺 Bot Teşhisi** (admin): PHP sürümü, sunucu→Telegram bağlantısı, webhook durumu ve son hatası,
  gelen kutusu işleme sonucu, bağlı çalışan sayısı.

# XRE Mesai — 5. Ayrı hatırlatma botu (@xremesaibot) + tek dokunuşla bağlanma

- Kişisel mesajlar artık **ayrı bottan** gidiyor (`$TG_OZEL_TOKEN`, kisiler.php içinde).
  Grup mesajları/raporlar eski bottan aynen devam eder; eski bota hiç dokunulmaz.
- Uygulamada bağlı olmayan kişiye **🔔 Telegram'ı Bağla** şeridi çıkar. Kişiye özel, taklit
  edilemez linkle bot açılır; **BAŞLAT**'a basınca sunucu kişiyi eşleştirir ve bot
  "✅ Bağlandın Ozan!" diye yanıt verir. ID toplamaya gerek kalmaz.
- Bot başka mesajlara da yanıt verir (tanıdığı kişiye selam, tanımadığına "uygulamadan bağlan").
- **⚡ Anında Yanıtı Kur** (admin): botu webhook'a bağlar, açıklamasını yazar. Kurulmazsa
  cron/istekler üzerinden dakikada bir gelen kutusu işlenir (yanıt ~1 dk gecikir).
- Akşam hatırlatma saati 18:05 (dakikalı ayar desteği).


- Bot, unutanlara **özelden** yazar:
  - **18:05'ten sonra** mesaisi hâlâ açık olana: "⏰ Ozan, mesain hâlâ açık görünüyor… Çıktıysan ⏹ Mesaiyi Sonlandır'a basmayı unutma".
  - Uzun süre açık kalan **molaya** (çay 30 dk, tuvalet 20 dk, yemek 75 dk): "☕ Ozan, çay molan 14:49'de başladı, 40 dk oldu…".
  - Mesajlarda "📲 Mesai uygulamasını aç" düğmesi var. Her hatırlatma kişi başına bir kez gider.
- Kimlikler `kisiler.php` dosyasında (kullanıcı adı → sayısal ID veya @kullaniciadi). Admin panelinden
  kişiye özel değiştirilebilir (çalışan satırındaki **Telegram** düğmesi, **TG ✓ / ⏳ / —** rozeti).
- **Telegram kuralı:** bot bir kişiye ancak o kişi botu bir kez açıp **BAŞLAT**'a bastıysa yazabilir.
  @kullaniciadi ile verilenlerin sayısal kimliği, kişi botu başlatınca otomatik öğrenilir
  (`getUpdates`; 10 dk'da bir veya paneldeki **Botu Başlatanları Eşleştir** düğmesiyle).
- Admin paneli: **Kişisel Telegram Hatırlatmaları** kartı (bot linki, deneme mesajı, gönderim kayıtları).
- Cron önerisi güncellendi: `*/10 * * * * php /home/KULLANICI/public_html/mesai/api.php`
  (hatırlatmalar + 20:00 raporu). Eski `0 20 * * *` satırı da çalışır ama hatırlatmalar
  yalnızca biri uygulamayı kullanırken tetiklenir.
- Sabah hoş geldin kutusu ve akşam 18:00 uygulama içi hatırlatma (önceki sürüm).

---

# XRE Mesai — 3. Bildirimler, Akşam Raporu, Konum, Yeni Arayüz

## "İnternet bağlantısı yok" şeridi (internet varken görünüyordu)
- **Sebep:** CSS hatası. `body`'deki `perspective` sabit konumlu şeridi body'ye
  bağlıyordu; şerit "gizli" durumdayken bile birkaç piksel ekrana taşıyordu.
  Kayıtlar aslında sunucuya gidiyordu (rozet "Kayıtlı ✓" diyordu).
- Şerit artık `display` ile gizleniyor ve **yalnızca sunucuya gerçekten
  ulaşılamadığında** görünüyor. `navigator.onLine`'a güvenilmiyor; Windows'ta
  VPN/sanal ağ bağdaştırıcıları yüzünden internet varken `false` dönebiliyor.

## Telegram bildirimleri
- **Moladan dönüş bildirimi:** `▶️ Ozan Kabagöz — 15:45: 1 sa 2 dk yemek molasından döndü, çalışmaya devam ediyor.`
- Çevrimdışıyken birleşen mola+dönüş kaybolmuyor: `☕ Ad — 14:49–14:55: 6 dk çay molası verdi, çalışmaya döndü.`
- Bitirdikten sonra tekrar başlayınca: `🔄 Ad — 16:10: mesaisine yeniden başladı.`
- Tuvalet molası artık bitiş raporunda yer alıyor; rapora `🕘 giriş – çıkış · toplam mola` satırı eklendi.
- Okuma + yazma tek kilit (`BEGIN IMMEDIATE`) altında. Aynı anda gelen iki istek
  (normal kayıt + sekme kapanırken beacon) **mükerrer mesaj** göndermiyor.

## Her akşam 20:00 — günlük toplu rapor
- Tek mesajda herkes: çalışma süresi, mola dağılımı (çay/yemek/tuvalet/randevu ve adet),
  giriş–çıkış saati; çalışma süresine göre sıralı. Altında toplam/ortalama,
  **hâlâ mesaide olanlar** ve **kayıt girmeyenler** listesi.
- Tetikleme: cPanel › Cron Jobs → `0 20 * * * php /home/KULLANICI/public_html/mesai/api.php`.
  Cron kurulmasa da 20:00'den sonraki ilk istekte gider (yedek tetik). Hiç istek gelmezse
  ertesi sabah 12:00'ye kadar gönderilir. Aynı gün için asla iki kez gitmez (`gorevler` tablosu).
- Admin paneli: **Günlük Toplu Rapor** kartı (önizle / şimdi gönder / cron komutu).
- Ayar: `config.php` → `$GUNLUK_RAPOR_SAAT = 20;` (yazılmazsa varsayılan 20).

## Konum
- **Çıkış konumu gelmiyordu:** Rapor, konum alınmadan anında gönderiliyordu.
  Artık uygulama `konumBekliyor` bilgisini gönderiyor ve sunucu raporu çıkış konumu
  gelene kadar bekletiyor. Uygulama kapanırsa rapor en geç ~45 sn sonra arka planda gidiyor.
- **Bilgisayarda hiç konum alınamıyordu:** Yalnızca GPS isteniyordu. Şimdi önce
  ağ/Wi-Fi konumu (ve son 5 dk önbellek), paralelde GPS; en iyi okuma kaydediliyor.
  Çalışırken 5 dk'da bir konum önbelleği tazeleniyor, çıkışta anında kullanılıyor.
- Hata sebebi kaydediliyor (`konum izni verilmedi` / `zaman aşımı`); ekranda **Tekrar dene**
  düğmesi ve izin talimatı görünüyor.

## Hoş geldin kutusu
- Günün ilk açılışında: "Günaydın, Ahmet!" + rastgele güler yüzlü bir söz + şakalı
  "çıkarken mesaiyi kapatmayı unutma" notu. Mesai başlamadıysa kutudan tek dokunuşla başlatılır.
- Akşam 18:00'den sonra hâlâ mesaide olan birine bir kez "Mesai bitiyor mu?" hatırlatması
  (doğrudan "Mesaiyi Sonlandır" düğmesiyle). Her ikisi de kişi başına günde bir kez gösterilir.

## Arayüz
- Selamlama + canlı durum rozeti, kullanıcı avatarı.
- Halka artık **günlük hedefi (8 sa)** gösteriyor; altında Net çalışma / Mola / Giriş kartları.
- **Gün zaman çizelgesi** (renkli şerit), akışta en yeni kayıt üstte ve canlı "ŞU AN" satırı.
- Mola menüsü 2×2 kutucuk; işlemlerde kısa bildirim (toast); bitirirken onay.
- Rapor sekmesi canlı: 3 özet kart, gün dağılımı, mola dağılım çubukları.
- **Veri kaybı düzeltmesi:** "Yeni Gün Başlat" günün kayıtlarını sıfırlıyordu → yerine
  **"Mesaiye Geri Dön"** (aynı günü kaldığı yerden sürdürür).
- Uygulama gece boyu açık kalırsa ertesi gün kendini yeniler.
- `sw.js` önbellek sürümü `v16`.

## Bitrix'te molalar
Molalar Bitrix'e `timeman.pause` ile **duraklama** olarak gidiyor (senkron günlüğünde
`timeman.pause … molaya çık` satırları başarılı). Çalışma Zamanı tablosundaki hücre
**net süreyi** gösterir; molayı görmek için ilgili günün hücresine tıklayın
(Mola/Duraklama satırı). Bitrix mola **türünü** (çay/yemek) tutmadığı için mesai
kapanırken Bitrix günlük raporuna artık her mola saatleriyle yazılıyor.

## Kurulum
`api.php`, `index.html`, `admin.html`, `sw.js` dosyalarını yükleyin. `config.php` ve
`data/xre.db` korunur (yeni tablo/kolonlar otomatik eklenir). İsteğe bağlı: cron satırı.

---

# XRE Mesai — Sürüm Notları

## 2. Marka + Oyunlaştırma
- **XRE Beştepe logosu** giriş, çalışan ve yönetim başlıklarına eklendi (koyu
  temada net okunması için beyaz plaka içinde). Marka laciverti tema tokenlarına
  işlendi; PWA adı "XRE Mesai · Beştepe".
- **🏆 Sıralama sekmesi (çalışan uygulaması):** bu ayın "en çok çalışan"
  tablosu — madalyalı podyum (🥇🥈🥉), **yıldızlama** (günlük ortalama çalışmaya
  göre, 8 sa/gün = 5★), "SEN" vurgusu, kişisel özet ve **canlı** yeşil nokta
  (şu an mesaide olanlar). Açıkken dakikada bir kendini yeniler.
  Backend: yeni `siralama` ucu (çalışan oturumuyla, salt-okunur, bu ay).
- `sw.js` cache sürümü `v13`; `logo.png` önbelleğe eklendi.

---

# XRE Mesai — Sağlamlaştırma (Bitrix senkron güvenilirliği)

Belirti: "Uygulamada çalışıyor görünüyor ama Bitrix'e yansımıyor" ve "kişi
uygulamadan başlatıyor ama sistemde görünmüyor."

## Kök nedenler ve çözümler

1. **Öndeki `800 ms` debounce ara durumları düşürüyordu.**
   Hızlı `başlat → mola` geçişinde "başlat" olayı sunucuya hiç gitmiyor,
   Bitrix boşta kalıyordu.
   → Frontend artık **birleştiren, son-durum-kazanır bir kuyruk** kullanıyor;
   hiçbir geçiş düşmez.

2. **Ağ hatasında yeniden deneme yoktu (fire-and-forget).**
   Mobil/kısa kopmalarda kayıt kaybediliyordu.
   → **Yeniden deneme + artan bekleme**, çevrimiçi olunca ve sekme geri
   gelince otomatik gönderim, sekme kapanırken **`sendBeacon`** ile son
   durumun kurtarılması eklendi.

3. **Bitrix durum makinesi körlemesine sürülüyordu.**
   Bir çağrı kaçınca/başarısız olunca sonraki tüm geçişler ("zaten açık",
   "açık değil" gibi) hata veriyor ve gün boyu senkron bozuluyordu.
   → Backend artık her kayıtta **`timeman.status`** ile Bitrix'in gerçek
   durumunu okuyup hedefe (calisiyor→OPENED, molada→PAUSED, bitti→CLOSED)
   ulaşmak için gereken adımları atıyor. **Kendi kendini onarır:** kaçan bir
   olay bir sonraki kayıtta telafi edilir. Ayrıca aktif mesaide dakikalık
   "kalp atışı" ile Bitrix sürekli hizada tutulur.

4. **Hatalar sessizdi — teşhis imkânsızdı.**
   → Her Bitrix denemesi **`bitrix_log`** tablosuna yazılıyor ve admin
   panelinden görülüyor (metot, geçiş, başarı/hata, sebep, HTTP kodu).

5. **İsim tabanlı eşleştirme kırılgandı** (aynı ilk isimden iki kişi çakışıyordu).
   → Kullanıcı başına **`bitrix_id`** (admin panelinden atanır). Config
   haritası yalnızca yedek; çakışma varsa güvenlik için devre dışı kalır.

6. **Boş 500 yanıtı "çevrimdışı" sanılıyordu.**
   → Tüm istek `try/catch` içinde, her durumda geçerli JSON döner; PDO WAL +
   busy_timeout ile kilit dayanıklılığı.

## Admin paneli — yeni "Bitrix Çalışma Zamanı Senkronu" kartı
- Bağlantı testi (webhook + kişinin anlık Bitrix durumu)
- Kişinin bugünkü durumunu Bitrix'e **yeniden itme** (onarım düğmesi)
- Son senkron denemeleri (kırmızı = başarısız, sebebiyle)
- Her çalışan satırında etkin **Bitrix ID** ve düzenleme düğmesi

## Kurulum / güncelleme
1. Sunucuya şu dosyaları yükleyin: `api.php`, `index.html`, `admin.html`, `sw.js`.
   Mevcut `config.php` ve `data/xre.db` **korunur** (geriye dönük uyumlu;
   yeni kolonlar otomatik eklenir).
2. Yeni kurulumda: `config.sample.php` → `config.php` kopyalayıp doldurun.
3. Bitrix webhook'unun **timeman** yetkisi olan **admin** bir kullanıcıya
   bağlı olduğundan emin olun (başkalarının mesaisini USER_ID ile yönetmek için).
4. Admin panelinden her çalışana doğru **Bitrix ID**'yi atayın; "BX yok"
   yazan kişiler senkronlanmaz.
5. `sw.js` cache sürümü `v12`'ye yükseltildi — istemciler yeni arayüzü otomatik alır.

> Güvenlik: `config.php` ve `data/` git'e eklenmez (`.gitignore`). Depoda
> ifşa olmuş token/webhook varsa Telegram'da `/revoke`, Bitrix'te webhook'u
> silip yeniden oluşturarak **yenileyin**.
