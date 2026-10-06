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

## Arayüz
- Selamlama + canlı durum rozeti, kullanıcı avatarı.
- Halka artık **günlük hedefi (8 sa)** gösteriyor; altında Net çalışma / Mola / Giriş kartları.
- **Gün zaman çizelgesi** (renkli şerit), akışta en yeni kayıt üstte ve canlı "ŞU AN" satırı.
- Mola menüsü 2×2 kutucuk; işlemlerde kısa bildirim (toast); bitirirken onay.
- Rapor sekmesi canlı: 3 özet kart, gün dağılımı, mola dağılım çubukları.
- **Veri kaybı düzeltmesi:** "Yeni Gün Başlat" günün kayıtlarını sıfırlıyordu → yerine
  **"Mesaiye Geri Dön"** (aynı günü kaldığı yerden sürdürür).
- Uygulama gece boyu açık kalırsa ertesi gün kendini yeniler.
- `sw.js` önbellek sürümü `v15`.

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
