<?php
/* ============================================================
   XRE Mesai — AYAR ŞABLONU
   Bu dosyayı "config.php" adıyla KOPYALAYIN ve kendi gizli
   değerlerinizi yazın. Gerçek config.php sürüm kontrolüne (git)
   EKLENMEZ (bkz. .gitignore) — token/webhook sızmasın diye.
   Mümkünse config.php'yi web kökünün DIŞINA taşıyın ve
   api.php'deki require yolunu güncelleyin.
   ============================================================ */
if (!defined("XRE_API")) { http_response_code(403); exit; }

/* Admin PIN — password_hash ile HASH'lenmiş halde saklanır.
   Yeni PIN üretmek için (terminalde):
   php -r "echo password_hash('YENI_PIN', PASSWORD_DEFAULT), PHP_EOL;"
   Üretilen hazır hash'i buraya yapıştırın (düz PIN yazmayın). */
$ADMIN_PIN_HASH = 'BURAYA_password_hash_CIKTISI';

/* ---- TELEGRAM ----
   Botu BotFather'dan alın; grup chat_id'sini öğrenin (genelde -100... ile başlar).
   Token ifşa olduysa /revoke ile yenileyin. */
$TG_TOKEN = "";
$TG_CHAT  = "";

/* ---- BİTRİX24 ----
   Bitrix24 > Geliştirici kaynakları > Gelen webhook oluşturun.
   Webhook'a EN AZINDAN "timeman" (Çalışma Zamanı) yetkisi verin ve
   başkalarının mesaisini yönetebilmesi için ADMIN yetkili bir kullanıcıya
   bağlayın (aksi halde timeman.open/close USER_ID ile reddedilir).
   URL sonundaki eğik çizgiyi bırakın. */
$BITRIX_WEBHOOK = "https://ORNEK.bitrix24.com.tr/rest/1/WEBHOOK_KODU/";

/* Kullanıcı adı/isim -> Bitrix kullanıcı ID eşleştirmesi.
   NOT: Artık öncelikli yol, admin panelinden her çalışana KİŞİYE ÖZEL
   "Bitrix ID" atamaktır (users.bitrix_id). Bu harita yalnızca bu alan
   boş olan kullanıcılar için YEDEK eşleştirmedir ve isim çakışması
   (aynı ilk isimden iki kişi) varsa güvenlik için DEVRE DIŞI kalır —
   o durumda kişiye özel ID atayın. */
$BITRIX_IDS = [
  // "merve" => 116,
  // "ali"   => 17984,
];

/* Oturum süresi (saniye). Bu süre boyunca istek gelmezse token düşer. */
$TOKEN_OMRU = 12 * 3600;

/* Giriş denemesi limiti: aynı IP+kullanıcı için pencere içinde izin verilen hata sayısı */
$RL_LIMIT   = 5;    // deneme
$RL_PENCERE = 900;  // 15 dk

/* ---- GÜNLÜK TOPLU RAPOR ----
   Her akşam bu saatte o günün "kim ne kadar çalıştı / mola verdi" raporu
   Telegram grubuna gider. false yazarsanız kapanır.
   En garantili yol cPanel > Cron Jobs (10 dk'da bir):  */10 * * * * php /home/KULLANICI/public_html/mesai/api.php
   Cron yoksa da 20:00'den sonraki ilk istekte otomatik gönderilir. */
$GUNLUK_RAPOR_SAAT = 20;

/* Cron'u PHP yerine URL ile tetiklemek isterseniz uzun, rastgele bir anahtar yazın:
   https://alanadi/mesai/api.php?action=cron&anahtar=BU_ANAHTAR   (boş = kapalı) */
$CRON_ANAHTAR = "";

/* ---- KİŞİSEL HATIRLATMALAR (bot özelden yazar) ----
   Kişi → Telegram kimliği eşleşmesi kisiler.php dosyasında (bkz. kisiler.sample.php).
   Mesaisi bu saatte hâlâ açık olana "kapatmayı unutma" (false = kapalı): */
$HATIRLATMA_SAAT = 19;
/* Bu kadar dakika açık kalan molaya hatırlatma: */
$MOLA_HATIRLATMA_DK = ["cay"=>30, "tuvalet"=>20, "yemek"=>75];
/* Hatırlatma mesajındaki "uygulamayı aç" düğmesinin adresi: */
$UYGULAMA_URL = "https://xrex.com.tr/mesai/";

/* Teşhis: geliştirirken beklenmeyen hataların mesajını API yanıtında görmek
   isterseniz açın. CANLIDA KAPALI TUTUN. */
// define("XRE_DEBUG", true);
