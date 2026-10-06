<?php
/* ============================================================
   XRE Mesai — KİŞİSEL TELEGRAM KİMLİKLERİ (örnek)
   Bu dosyayı "kisiler.php" adıyla kopyalayıp doldurun. Gerçek dosya git'e
   eklenmez (kişisel veri). Anahtar: uygulamadaki KULLANICI ADI.
   Değer: sayısal Telegram ID'si ("5238375820") ya da "@kullaniciadi".
   Admin panelinden kişiye özel girilen değer bu dosyadakinden önceliklidir.

   ÖNEMLİ: Telegram kuralı gereği bot bir kişiye ancak o kişi botu bir kez
   açıp BAŞLAT'a bastıysa yazabilir. @kullaniciadi verilenlerin sayısal
   kimliği de bot başlatılınca otomatik öğrenilir.
   ============================================================ */
if (!defined("XRE_API")) { http_response_code(403); exit; }

/* Kişisel hatırlatmaları gönderen AYRI bot'un token'ı (BotFather). Boşsa grup botu kullanılır. */
$TG_OZEL_TOKEN = "";

$TG_KISILER = [
  // "kerem" => "@keremerginbas",
  // "ahmet" => "5238375820",
];
