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
