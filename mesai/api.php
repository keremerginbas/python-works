<?php
/* ============ XRE Mesai API — PHP + SQLite ============
   Sağlamlaştırılmış sürüm:
   - Bitrix Çalışma Zamanı (timeman) ile KENDİ KENDİNİ ONARAN senkron:
     her kayıtta timeman.status ile gerçek durum okunur ve hedefe göre
     eksik adımlar tamamlanır. Böylece kaçan/başarısız bir olay bir sonraki
     kayıtta otomatik telafi edilir ("başlattı ama sistemde görünmüyor" biter).
   - Her Bitrix denemesi bitrix_log tablosuna yazılır (admin panelinden görülür).
   - Geçici hatalarda yeniden deneme (retry + backoff).
   - Kullanıcı başına bitrix_id (config haritası yalnızca ilk eşleştirme için).
   - beacon (sekme kapanırken) çağrıları için gövdeden token doğrulama.
   - Tüm istek try/catch içinde: hata olsa bile JSON döner (boş 500 → "çevrimdışı"
     yanılgısını engeller).
   ====================================================== */
define("XRE_API", 1);
require __DIR__ . "/config.php"; // gizli ayarlar (token, webhook, admin hash)

header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: same-origin");
header("Cache-Control: no-store");
error_reporting(0);
ini_set("display_errors", "0");
date_default_timezone_set("Europe/Istanbul");

/* İstek gövdesi boyut sınırı (1 MB) — şişirilmiş JSON'a karşı */
if ((int)($_SERVER["CONTENT_LENGTH"] ?? 0) > 1048576) { http_response_code(413); exit; }

function tlower($s){ // mbstring gerektirmeden Türkçe küçük harf
  return strtolower(strtr((string)$s, ["İ"=>"i","I"=>"ı","Ğ"=>"ğ","Ü"=>"ü","Ş"=>"ş","Ö"=>"ö","Ç"=>"ç"]));
}
function out($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function err($m, $ekstra = []){ out(array_merge(["ok"=>false, "hata"=>$m], $ekstra)); }

/* ---------------- Veritabanı ---------------- */
$dir = __DIR__ . "/data";
if (!is_dir($dir)) @mkdir($dir, 0755, true);
if (!file_exists("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all");

try {
  $db = new PDO("sqlite:$dir/xre.db");
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA journal_mode=WAL");   // eşzamanlı okuma/yazmada kilitlenmeyi azaltır
  $db->exec("PRAGMA busy_timeout=5000");  // kilit varsa 5 sn bekle, hemen patlama
} catch (Throwable $e) {
  http_response_code(500);
  out(["ok"=>false, "hata"=>"Veritabanı açılamadı"]);
}

$db->exec("CREATE TABLE IF NOT EXISTS users(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kadi TEXT UNIQUE NOT NULL, ad TEXT NOT NULL, rol TEXT NOT NULL,
  pin TEXT NOT NULL, token TEXT, aktif INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE IF NOT EXISTS gunler(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL, gun TEXT NOT NULL,
  veri TEXT NOT NULL, guncelleme INTEGER,
  rapor_gitti INTEGER DEFAULT 0,
  UNIQUE(user_id, gun))");
/* Eski kurulumlara kolon ekleme (varsa sessizce geçilir) */
foreach ([
  "ALTER TABLE gunler ADD COLUMN rapor_gitti INTEGER DEFAULT 0",
  "ALTER TABLE gunler ADD COLUMN basla_gitti INTEGER DEFAULT 0",
  "ALTER TABLE gunler ADD COLUMN bx_hedef TEXT",          // en son Bitrix'e uygulanan hedef durum
  "ALTER TABLE gunler ADD COLUMN bx_zaman INTEGER",       // hedefin son doğrulanma zamanı
  "ALTER TABLE users ADD COLUMN token_zaman INTEGER",
  "ALTER TABLE users ADD COLUMN bitrix_id INTEGER",       // kullanıcı başına Bitrix ID
] as $sql) { try { $db->exec($sql); } catch (Throwable $e) {} }

$db->exec("CREATE TABLE IF NOT EXISTS denemeler(
  anahtar TEXT PRIMARY KEY, sayi INTEGER DEFAULT 0, ilk INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS bitrix_log(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  zaman INTEGER, user_id INTEGER, kadi TEXT, bitrix_id INTEGER,
  gun TEXT, eski TEXT, yeni TEXT, metot TEXT,
  ok INTEGER, http INTEGER, sebep TEXT)");
$db->exec("CREATE INDEX IF NOT EXISTS ix_bxlog_zaman ON bitrix_log(zaman DESC)");

/* ---- brute-force koruması ---- */
function rlAnahtar($ek){ return sha1(($_SERVER["REMOTE_ADDR"] ?? "?") . "|" . $ek); }
function rlKontrol($db, $anahtar){
  global $RL_LIMIT, $RL_PENCERE;
  $s = $db->prepare("SELECT sayi, ilk FROM denemeler WHERE anahtar=?");
  $s->execute([$anahtar]); $r = $s->fetch(PDO::FETCH_ASSOC);
  if ($r && time() - $r["ilk"] > $RL_PENCERE) {
    $db->prepare("DELETE FROM denemeler WHERE anahtar=?")->execute([$anahtar]); $r = null;
  }
  if ($r && $r["sayi"] >= $RL_LIMIT) {
    $kalan = ceil(($RL_PENCERE - (time() - $r["ilk"])) / 60);
    out(["ok"=>false, "hata"=>"Çok fazla hatalı deneme. $kalan dk sonra tekrar deneyin."]);
  }
}
function rlHata($db, $anahtar){
  $db->prepare("INSERT INTO denemeler(anahtar,sayi,ilk) VALUES(?,1,?)
    ON CONFLICT(anahtar) DO UPDATE SET sayi=sayi+1")->execute([$anahtar, time()]);
}
function rlTemizle($db, $anahtar){
  $db->prepare("DELETE FROM denemeler WHERE anahtar=?")->execute([$anahtar]);
}

/* ---------------- Süre biçimleme ---------------- */
function fmtSaatPHP($ms){
  $dk = intdiv(max(0,$ms), 60000); $h = intdiv($dk,60); $m = $dk%60;
  if (!$h && !$m) return max(0, intdiv($ms,1000)) . " sn";
  return $h ? "$h sa $m dk" : "$m dk";
}
function raporMetni($ad, $veri){
  $MOLA = ["randevu"=>["Online Randevu",true], "cay"=>["Çay Molası",false], "yemek"=>["Yemek Molası",false], "tuvalet"=>["Tuvalet Molası",false]];
  $calisma = 0; $molalar = [];
  foreach (($veri["segs"] ?? []) as $s) {
    $d = ($s["bit"] ?? 0) - ($s["bas"] ?? 0);
    if ($d <= 0) continue;
    $tip = $s["tip"] ?? "";
    if ($tip === "calisma" || $tip === "onhazirlik") $calisma += $d;
    elseif (isset($MOLA[$tip])) {
      $molalar[$tip] = ($molalar[$tip] ?? 0) + $d;
      if ($MOLA[$tip][1]) $calisma += $d;
    }
  }
  $ilk = explode(" ", trim($ad))[0];
  $m = "📋 $ilk bugün " . fmtSaatPHP($calisma) . " çalıştı";
  if (!empty($molalar["yemek"])) $m .= ", " . fmtSaatPHP($molalar["yemek"]) . " yemek molasına çıktı";
  if (!empty($molalar["cay"]))   $m .= ", " . fmtSaatPHP($molalar["cay"]) . " çay molasına çıktı";
  if (!empty($molalar["randevu"])) $m .= ", " . fmtSaatPHP($molalar["randevu"]) . " online randevuda kaldı";
  $m .= ".";
  $g = $veri["girisKonum"] ?? null; $c = $veri["cikisKonum"] ?? null;
  $m .= "\n" . konumSatiri("Giriş", $g);
  $m .= "\n" . konumSatiri("Çıkış", $c);
  return $m;
}
function konumSatiri($ad, $k){
  if (!$k || !empty($k["hata"]) || !isset($k["lat"]))
    return "⚠️ $ad konumu alınamadı";
  $url = "https://maps.google.com/?q={$k["lat"]},{$k["lng"]}";
  $d = (int)($k["dogruluk"] ?? 0);
  if ($d > 0 && $d <= 100)  return "📍 $ad: $url (±{$d} m ✅)";
  if ($d > 0 && $d <= 1000) return "📍 $ad: $url (±{$d} m, yaklaşık)";
  $km = $d > 0 ? round($d/1000) : "?";
  return "⚠️ $ad: $url (±{$km} km — IP tahmini, GÜVENİLMEZ; gerçek konum için telefondan giriş yapın)";
}

/* ================= Bitrix REST ================= */
/* Tek çağrı — geçici hatalarda yeniden dener. Dönüş: ok, sonuc, http, sebep */
function bitrixCagri($metot, $params, $deneme = 3){
  global $BITRIX_WEBHOOK;
  if (empty($BITRIX_WEBHOOK)) return ["ok"=>false, "http"=>0, "sebep"=>"webhook ayarlanmamış"];
  $url = rtrim($BITRIX_WEBHOOK, "/") . "/" . $metot . ".json";
  $govde = http_build_query($params);
  $son = ["ok"=>false, "http"=>0, "sebep"=>"bilinmeyen"];

  for ($i = 0; $i < max(1,$deneme); $i++){
    if ($i > 0) usleep(($i * 350) * 1000); // 350ms, 700ms backoff
    $res = null; $http = 0; $curlHata = "";
    if (function_exists("curl_init")) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_TIMEOUT=>12,
        CURLOPT_POSTFIELDS=>$govde,
      ]);
      $res = curl_exec($ch);
      $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if ($res === false) $curlHata = curl_error($ch) ?: "curl hatası";
      curl_close($ch);
    } else {
      $ctx = stream_context_create(["http"=>["method"=>"POST","timeout"=>12,
        "header"=>"Content-Type: application/x-www-form-urlencoded\r\n",
        "content"=>$govde, "ignore_errors"=>true]]);
      $res = @file_get_contents($url, false, $ctx);
      if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $mm)) $http = (int)$mm[1];
      if ($res === false) $curlHata = "bağlantı hatası";
    }

    if ($res === false || $res === null || $res === "") {
      $son = ["ok"=>false, "http"=>$http, "sebep"=>$curlHata ?: "boş yanıt (HTTP $http)"];
      if ($http && $http < 500 && $http != 0) return $son; // 4xx: yeniden denemenin anlamı yok
      continue; // ağ/5xx: yeniden dene
    }
    $j = json_decode($res, true);
    if (!is_array($j)) { $son = ["ok"=>false, "http"=>$http, "sebep"=>"geçersiz yanıt"]; continue; }
    if (isset($j["error"])) {
      // Bitrix mantıksal hatası — yeniden denemek durumu düzeltmez
      return ["ok"=>false, "http"=>$http, "sebep"=>($j["error_description"] ?? $j["error"]),
              "kod"=>$j["error"], "sonuc"=>$j["result"] ?? null];
    }
    return ["ok"=>array_key_exists("result",$j), "http"=>$http, "sonuc"=>$j["result"] ?? null, "sebep"=>""];
  }
  return $son;
}

/* Kullanıcının Bitrix ID'sini bul: önce DB (kullanıcı kaydı), sonra config haritası */
function bitrixIdBul($u){
  global $BITRIX_IDS;
  if (!empty($u["bitrix_id"])) return (int)$u["bitrix_id"];
  $eslesenler = [];
  $kadi = tlower(trim($u["kadi"] ?? ""));
  $adIlk = tlower(explode(" ", trim($u["ad"] ?? ""))[0] ?? "");
  foreach (($BITRIX_IDS ?? []) as $k => $v) {
    $kk = tlower(trim($k));
    if ($kk === $kadi || ($adIlk !== "" && $kk === $adIlk)) $eslesenler[] = (int)$v;
  }
  // Yalnızca TEK eşleşme varsa güvenle kullan (isim çakışmalarında yanlış kişiye yazmayı önler)
  if (count(array_unique($eslesenler)) === 1) return $eslesenler[0];
  return 0;
}

function bxLog($db, $u, $bxId, $gun, $eski, $yeni, $metot, $ok, $http, $sebep){
  try {
    $db->prepare("INSERT INTO bitrix_log(zaman,user_id,kadi,bitrix_id,gun,eski,yeni,metot,ok,http,sebep)
      VALUES(?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([time(), (int)($u["id"]??0), $u["kadi"]??"", (int)$bxId, $gun,
                 $eski, $yeni, $metot, $ok?1:0, (int)$http, mb_substr((string)$sebep,0,400)]);
    // Log tablosunu makul boyutta tut (son 5000 kayıt)
    $db->exec("DELETE FROM bitrix_log WHERE id NOT IN (SELECT id FROM bitrix_log ORDER BY id DESC LIMIT 5000)");
  } catch (Throwable $e) {}
}

/* Bitrix'teki güncel timeman durumunu getir: OPENED / PAUSED / CLOSED / EXPIRED / ? */
function bitrixDurumOku($bxId){
  $r = bitrixCagri("timeman.status", ["USER_ID"=>$bxId], 2);
  if (!$r["ok"]) return ["ok"=>false, "durum"=>"?", "http"=>$r["http"], "sebep"=>$r["sebep"]];
  $st = "";
  if (is_array($r["sonuc"])) $st = strtoupper($r["sonuc"]["STATUS"] ?? "");
  elseif (is_string($r["sonuc"])) $st = strtoupper($r["sonuc"]);
  if ($st === "") $st = "CLOSED";
  return ["ok"=>true, "durum"=>$st, "http"=>$r["http"], "sebep"=>""];
}

/* KENDİ KENDİNİ ONARAN senkron.
   Uygulama durumunu Bitrix hedef durumuna eşitler:
     calisiyor -> OPENED, molada -> PAUSED, bitti -> CLOSED, hazir -> (dokunma)
   Gerçek Bitrix durumu okunur; hedefe ulaşmak için gereken adımlar atılır.
   Böylece kaçan bir "başlat"/"mola" olayı bir sonraki kayıtta telafi edilir. */
function bitrixSenkron($db, $u, $gun, $yeniDurum, $rapor = ""){
  $bxId = bitrixIdBul($u);
  if (!$bxId) {
    bxLog($db, $u, 0, $gun, "", $yeniDurum, "atla", false, 0, "Bitrix ID eşleşmedi (kullanıcıya bitrix_id atayın)");
    return ["ok"=>false, "atla"=>true, "sebep"=>"Bitrix ID yok"];
  }
  $hedefHar = ["calisiyor"=>"OPENED", "molada"=>"PAUSED", "bitti"=>"CLOSED"];
  if (!isset($hedefHar[$yeniDurum])) return ["ok"=>true, "atla"=>true, "sebep"=>"hedef yok"];
  $hedef = $hedefHar[$yeniDurum];

  $durum = bitrixDurumOku($bxId);
  $mevcut = $durum["ok"] ? $durum["durum"] : "?";

  /* Gerçek duruma göre atılacak adımları belirle (idempotent) */
  $adimlar = [];
  if ($hedef === "OPENED") {
    if     ($mevcut === "OPENED") { /* zaten açık */ }
    elseif ($mevcut === "PAUSED") { $adimlar[] = ["timeman.open", "moladan dön"]; }
    else                          { $adimlar[] = ["timeman.open", "aç"]; } // CLOSED/EXPIRED/?
  } elseif ($hedef === "PAUSED") {
    if     ($mevcut === "PAUSED") { /* zaten molada */ }
    elseif ($mevcut === "OPENED") { $adimlar[] = ["timeman.pause", "molaya çık"]; }
    else                          { $adimlar[] = ["timeman.open","aç"]; $adimlar[] = ["timeman.pause","molaya çık"]; }
  } elseif ($hedef === "CLOSED") {
    if     ($mevcut === "CLOSED" || $mevcut === "EXPIRED") { /* zaten kapalı */ }
    elseif ($mevcut === "PAUSED") { $adimlar[] = ["timeman.open","kapatmadan önce sürdür"]; $adimlar[] = ["timeman.close","kapat"]; }
    else                          { $adimlar[] = ["timeman.close","kapat"]; } // OPENED / ?
  }

  if (!$adimlar) { // Hedefe zaten ulaşılmış
    bxLog($db, $u, $bxId, $gun, $mevcut, $hedef, "hizada", true, $durum["http"], "zaten $hedef");
    return ["ok"=>true, "mevcut"=>$mevcut, "hedef"=>$hedef, "adim"=>0];
  }

  $tumOk = true; $sonSebep = ""; $sonHttp = 0;
  foreach ($adimlar as [$metot, $aciklama]) {
    $params = ["USER_ID"=>$bxId];
    if ($metot === "timeman.close" && $rapor !== "") $params["REPORT"] = $rapor;
    $r = bitrixCagri($metot, $params);
    // "Zaten o durumda" tarzı hataları başarı say (idempotent güvence)
    $sebepLc = tlower($r["sebep"] ?? "");
    $zatenOk = !$r["ok"] && (
      str_contains($sebepLc, "already") || str_contains($sebepLc, "zaten") ||
      str_contains($sebepLc, "opened")  || str_contains($sebepLc, "closed") ||
      (isset($r["kod"]) && in_array(strtoupper((string)$r["kod"]), ["WRONG_STATUS","ALREADY_OPENED","ALREADY_CLOSED"], true))
    );
    $basarili = $r["ok"] || $zatenOk;
    bxLog($db, $u, $bxId, $gun, $mevcut, $hedef, $metot, $basarili, $r["http"],
          $basarili ? ($r["ok"] ? $aciklama : "idempotent: ".$r["sebep"]) : $r["sebep"]);
    if (!$basarili) { $tumOk = false; $sonSebep = $r["sebep"]; $sonHttp = $r["http"]; break; }
  }

  return ["ok"=>$tumOk, "mevcut"=>$mevcut, "hedef"=>$hedef, "adim"=>count($adimlar),
          "sebep"=>$sonSebep, "http"=>$sonHttp];
}

/* ---------------- Telegram ---------------- */
function telegramGonder($metin){
  global $TG_TOKEN, $TG_CHAT;
  if (empty($TG_TOKEN) || empty($TG_CHAT)) return ["ok"=>false, "sebep"=>"token/chat ayarlanmamış"];
  $url = "https://api.telegram.org/bot$TG_TOKEN/sendMessage";
  $alanlar = ["chat_id"=>$TG_CHAT, "text"=>$metin, "disable_web_page_preview"=>1];
  if (function_exists("curl_init")) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_TIMEOUT=>12, CURLOPT_POSTFIELDS=>http_build_query($alanlar)]);
    $res = curl_exec($ch); curl_close($ch);
  } else {
    $ctx = stream_context_create(["http"=>["method"=>"POST","timeout"=>12,
      "header"=>"Content-Type: application/x-www-form-urlencoded\r\n",
      "content"=>http_build_query($alanlar), "ignore_errors"=>true]]);
    $res = @file_get_contents($url, false, $ctx);
  }
  $j = json_decode((string)$res, true);
  return ["ok" => !empty($j["ok"]), "sebep" => $j["description"] ?? "yanıt alınamadı"];
}

/* ---------------- İstek ayrıştırma ---------------- */
$ham = file_get_contents("php://input");
$in = json_decode($ham, true) ?: [];
$act = $_GET["action"] ?? $in["action"] ?? "";

/* ---- kullanıcı doğrulama ---- */
function auth($db){
  global $TOKEN_OMRU, $in;
  // Normalde X-Token başlığı; sekme kapanırken sendBeacon başlık koyamadığı için
  // gövdedeki _tok da kabul edilir (aynı gizli değer).
  $t = $_SERVER["HTTP_X_TOKEN"] ?? "";
  if (!$t && !empty($in["_tok"])) $t = $in["_tok"];
  if (!$t) err("Oturum yok");
  $s = $db->prepare("SELECT * FROM users WHERE token=? AND aktif=1");
  $s->execute([hash("sha256", $t)]);
  $u = $s->fetch(PDO::FETCH_ASSOC);
  if (!$u) err("Oturum geçersiz, tekrar giriş yapın");
  if ($u["token_zaman"] && time() - $u["token_zaman"] > $TOKEN_OMRU) {
    $db->prepare("UPDATE users SET token=NULL WHERE id=?")->execute([$u["id"]]);
    err("Oturum süresi doldu, tekrar giriş yapın");
  }
  $db->prepare("UPDATE users SET token_zaman=? WHERE id=?")->execute([time(), $u["id"]]);
  return $u;
}
/* ---- admin doğrulama ---- */
function adminAuth(){
  global $ADMIN_PIN_HASH, $db;
  $anahtar = rlAnahtar("admin");
  rlKontrol($db, $anahtar);
  $p = $_SERVER["HTTP_X_ADMIN"] ?? "";
  if (!$p || !password_verify($p, $ADMIN_PIN_HASH)) {
    rlHata($db, $anahtar);
    err("Admin PIN hatalı");
  }
  rlTemizle($db, $anahtar);
}

/* ================= YÖNLENDİRME ================= */
try {
switch ($act) {

/* ================= ÇALIŞAN ================= */
case "login":
  $kadi = tlower(trim(substr($in["kadi"] ?? "", 0, 200)));
  $pin  = trim(substr($in["pin"] ?? "", 0, 64));
  $anahtar = rlAnahtar("login|$kadi");
  rlKontrol($db, $anahtar);
  $s = $db->prepare("SELECT * FROM users WHERE lower(kadi)=? AND aktif=1");
  $s->execute([$kadi]);
  $u = $s->fetch(PDO::FETCH_ASSOC);
  if (!$u || !password_verify($pin, $u["pin"])) {
    rlHata($db, $anahtar);
    err("Kullanıcı adı veya PIN hatalı");
  }
  rlTemizle($db, $anahtar);
  $token = bin2hex(random_bytes(32));
  $db->prepare("UPDATE users SET token=?, token_zaman=? WHERE id=?")
    ->execute([hash("sha256", $token), time(), $u["id"]]);
  out(["ok"=>true, "token"=>$token, "ad"=>$u["ad"], "rol"=>$u["rol"], "kadi"=>$u["kadi"]]);

case "ben":
  $u = auth($db);
  out(["ok"=>true, "ad"=>$u["ad"], "rol"=>$u["rol"], "kadi"=>$u["kadi"]]);

case "gun_kaydet":
  $u = auth($db);
  $gun = $in["gun"] ?? date("Y-m-d");
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun)) err("Geçersiz tarih");
  $veriArr = $in["veri"] ?? [];
  if (!is_array($veriArr)) err("Geçersiz veri");
  $veri = json_encode($veriArr, JSON_UNESCAPED_UNICODE);

  // Önceki kayıtlı durumu al
  $s = $db->prepare("SELECT veri, bx_hedef, bx_zaman FROM gunler WHERE user_id=? AND gun=?");
  $s->execute([$u["id"], $gun]);
  $satir = $s->fetch(PDO::FETCH_ASSOC) ?: [];
  $eski = json_decode($satir["veri"] ?? "{}", true) ?: [];
  $eskiDurum = $eski["durum"] ?? "hazir";
  $yeniDurum = $veriArr["durum"] ?? "hazir";
  $bxHedefEski = $satir["bx_hedef"] ?? null;
  $bxZaman = (int)($satir["bx_zaman"] ?? 0);

  // Her zaman önce yaz — kayıt kaynağı DB'dir; Bitrix/Telegram yan etkidir.
  $db->prepare("INSERT INTO gunler(user_id,gun,veri,guncelleme) VALUES(?,?,?,?)
    ON CONFLICT(user_id,gun) DO UPDATE SET veri=excluded.veri, guncelleme=excluded.guncelleme")
    ->execute([$u["id"], $gun, $veri, time()]);

  // ---- Bitrix senkron (kendi kendini onaran) ----
  $bxSonuc = null;
  $hedef = ["calisiyor"=>"OPENED","molada"=>"PAUSED","bitti"=>"CLOSED"][$yeniDurum] ?? null;
  // Bugünün kaydı değilse Bitrix'e dokunma (geçmiş düzeltme yalnızca DB'de)
  $bugunMu = ($gun === date("Y-m-d"));
  if ($bugunMu && $hedef !== null) {
    // Hizalama önbelleği: aynı hedefe kısa süre önce ulaşıldıysa tekrar çağrı yapma.
    // Ancak durum değiştiyse ya da 4 dk geçtiyse yeniden doğrula (self-heal heartbeat).
    $durumDegisti = ($eskiDurum !== $yeniDurum);
    $tazele = $durumDegisti || $bxHedefEski !== $hedef || (time() - $bxZaman) > 240;
    if ($tazele) {
      try {
        $bxRapor = ($yeniDurum === "bitti") ? raporMetni($u["ad"], $veriArr) : "";
        $bxSonuc = bitrixSenkron($db, $u, $gun, $yeniDurum, $bxRapor);
        if ($bxSonuc["ok"]) {
          $db->prepare("UPDATE gunler SET bx_hedef=?, bx_zaman=? WHERE user_id=? AND gun=?")
            ->execute([$hedef, time(), $u["id"], $gun]);
        }
      } catch (Throwable $e) {
        bxLog($db, $u, bitrixIdBul($u), $gun, $eskiDurum, $yeniDurum, "istisna", false, 0, $e->getMessage());
        $bxSonuc = ["ok"=>false, "sebep"=>"Bitrix hatası"];
      }
    } else {
      $bxSonuc = ["ok"=>true, "atla"=>true, "sebep"=>"yakın zamanda hizalandı"];
    }
  }

  // ---- Telegram bildirimleri ----
  $tgSonuc = null;
  try {
    // Mesai başlangıç bildirimi: hazir/bitti -> calisiyor (moladan dönüş hariç)
    if ($eskiDurum !== "calisiyor" && $eskiDurum !== "molada" && $yeniDurum === "calisiyor") {
      $s = $db->prepare("SELECT basla_gitti FROM gunler WHERE user_id=? AND gun=?");
      $s->execute([$u["id"], $gun]);
      if (!(int)$s->fetchColumn()) {
        $saat = date("H:i");
        $tgSonuc = telegramGonder("🟢 {$u["ad"]} $saat'te mesaisini başlattı.");
        if ($tgSonuc["ok"])
          $db->prepare("UPDATE gunler SET basla_gitti=1 WHERE user_id=? AND gun=?")->execute([$u["id"], $gun]);
      }
    }
    // Yalnızca yeni mola geçişinde bildir; otomatik kayıtlar tekrar göndermez.
    if ($bugunMu && $yeniDurum === "molada" &&
        ($eskiDurum !== "molada" || ($eski["tip"] ?? null) !== ($veriArr["tip"] ?? null))) {
      $molaMesajlari = [
        "cay" => ["☕", "çay molasına çıktı"],
        "yemek" => ["🍽", "yemek molasına çıktı"],
        "tuvalet" => ["🚻", "tuvalet molasına çıktı"],
        "randevu" => ["🎥", "online randevuya başladı"]
      ];
      $molaTipi = $veriArr["tip"] ?? "";
      if (is_string($molaTipi) && isset($molaMesajlari[$molaTipi])) {
        [$emoji, $eylem] = $molaMesajlari[$molaTipi];
        $molaBas = $veriArr["bas"] ?? null;
        $saat = date("H:i", is_numeric($molaBas) && $molaBas > 0 ? (int)($molaBas / 1000) : time());
        $tgSonuc = telegramGonder("$emoji {$u["ad"]} — $saat: $eylem.");
      }
    }
    if ($yeniDurum === "bitti") {
      $s = $db->prepare("SELECT rapor_gitti FROM gunler WHERE user_id=? AND gun=?");
      $s->execute([$u["id"], $gun]);
      if (!(int)$s->fetchColumn()) {
        $tgSonuc = telegramGonder(raporMetni($u["ad"], $veriArr));
        if ($tgSonuc["ok"])
          $db->prepare("UPDATE gunler SET rapor_gitti=1 WHERE user_id=? AND gun=?")->execute([$u["id"], $gun]);
      }
    } elseif ($eskiDurum === "bitti" && $yeniDurum === "calisiyor") {
      $db->prepare("UPDATE gunler SET rapor_gitti=0, basla_gitti=0 WHERE user_id=? AND gun=?")->execute([$u["id"], $gun]);
    }
  } catch (Throwable $e) { $tgSonuc = ["ok"=>false, "sebep"=>"Telegram hatası"]; }

  out(["ok"=>true, "telegram"=>$tgSonuc, "bitrix"=>$bxSonuc]);

case "gun_getir":
  $u = auth($db);
  $gun = $_GET["gun"] ?? date("Y-m-d");
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun)) err("Geçersiz tarih");
  $s = $db->prepare("SELECT veri FROM gunler WHERE user_id=? AND gun=?");
  $s->execute([$u["id"], $gun]);
  $r = $s->fetchColumn();
  out(["ok"=>true, "veri"=>$r ? json_decode($r, true) : null]);

case "siralama": // çalışanlara açık: bu ayın "en çok çalışan" tablosu (oyunlaştırma)
  $u = auth($db);
  $bas = date("Y-m-01"); $bit = date("Y-m-d"); $today = date("Y-m-d");
  $q = $db->prepare("SELECT g.user_id,g.gun,g.veri,us.ad,us.rol
                     FROM gunler g JOIN users us ON us.id=g.user_id
                     WHERE g.gun BETWEEN ? AND ? AND us.aktif=1");
  $q->execute([$bas,$bit]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  $tipWork = ["calisma"=>1,"onhazirlik"=>1,"randevu"=>1];
  $nowMs = (int)round(microtime(true)*1000); $people = [];
  foreach ($rows as $r) {
    $v = json_decode($r["veri"], true) ?: [];
    $durum = $v["durum"] ?? "";
    $segments = $v["segs"] ?? [];
    // Bugün hâlâ açık olan segmenti anlık say (canlı sıralama)
    if ($r["gun"]===$today && in_array($durum,["calisiyor","molada"],true) && !empty($v["bas"]))
      $segments[] = ["bas"=>(int)$v["bas"],"bit"=>$nowMs,"tip"=>($v["tip"] ?: "calisma")];
    $cal = 0;
    foreach ($segments as $s) {
      $a=(int)($s["bas"]??0); $b=(int)($s["bit"]??0); $t=$s["tip"]??"";
      if (!$a||!$b||$b<$a) continue;
      if (isset($tipWork[$t])) $cal += $b-$a;
    }
    $id = (int)$r["user_id"];
    if (!isset($people[$id])) $people[$id] = ["user_id"=>$id,"ad"=>$r["ad"],"rol"=>$r["rol"],
      "calisma_ms"=>0,"gun_sayisi"=>0,"aktif"=>false,"bugun_ms"=>0];
    $people[$id]["calisma_ms"] += $cal;
    if ($cal > 0) $people[$id]["gun_sayisi"]++;
    if ($r["gun"]===$today) { $people[$id]["bugun_ms"] = $cal;
      if (in_array($durum,["calisiyor","molada"],true)) $people[$id]["aktif"] = true; }
  }
  foreach ($people as &$p) $p["gunluk_ortalama_ms"] = $p["gun_sayisi"] ? (int)round($p["calisma_ms"]/$p["gun_sayisi"]) : 0;
  unset($p);
  $liste = array_values($people);
  usort($liste, fn($a,$b)=>$b["calisma_ms"] <=> $a["calisma_ms"]);
  out(["ok"=>true, "bas"=>$bas, "bit"=>$bit, "ben"=>(int)$u["id"], "liste"=>$liste]);

/* ================= ADMİN ================= */
case "admin_giris":
  adminAuth();
  out(["ok"=>true]);

case "kullanici_listesi":
  adminAuth();
  $rows = $db->query("SELECT id,kadi,ad,rol,aktif,bitrix_id FROM users ORDER BY ad")->fetchAll(PDO::FETCH_ASSOC);
  // Etkin (efektif) Bitrix ID'yi de ekle: DB boşsa config haritasından çözülen değer
  foreach ($rows as &$r) {
    $etkin = bitrixIdBul($r);
    $r["bitrix_id"] = $r["bitrix_id"] !== null ? (int)$r["bitrix_id"] : null;
    $r["bitrix_etkin"] = $etkin ?: null;
    $r["bitrix_kaynak"] = !empty($r["bitrix_id"]) ? "db" : ($etkin ? "config" : "yok");
  }
  unset($r);
  out(["ok"=>true, "liste"=>$rows]);

case "bitrix_id_ata":
  adminAuth();
  $uid = (int)($in["id"] ?? 0);
  $bid = $in["bitrix_id"] ?? "";
  $bid = ($bid === "" || $bid === null) ? null : (int)$bid;
  if ($uid <= 0) err("Kullanıcı seçilmedi");
  $db->prepare("UPDATE users SET bitrix_id=? WHERE id=?")->execute([$bid, $uid]);
  out(["ok"=>true]);

case "kullanici_ekle":
  adminAuth();
  $kadi = tlower(trim($in["kadi"] ?? ""));
  $ad = trim($in["ad"] ?? ""); $rol = trim($in["rol"] ?? "");
  $pin = trim($in["pin"] ?? "");
  $bid = isset($in["bitrix_id"]) && $in["bitrix_id"] !== "" ? (int)$in["bitrix_id"] : null;
  if (!$kadi || !$ad || !$rol || strlen($pin) < 4) err("Tüm alanlar gerekli, PIN en az 4 hane");
  try {
    $db->prepare("INSERT INTO users(kadi,ad,rol,pin,bitrix_id) VALUES(?,?,?,?,?)")
      ->execute([$kadi, $ad, $rol, password_hash($pin, PASSWORD_DEFAULT), $bid]);
  } catch (Throwable $e) { err("Bu kullanıcı adı zaten var"); }
  out(["ok"=>true]);

case "kullanici_sil":
  adminAuth();
  $db->prepare("UPDATE users SET aktif=0, token=NULL WHERE id=?")->execute([(int)($in["id"] ?? 0)]);
  out(["ok"=>true]);

case "pin_sifirla":
  adminAuth();
  $pin = trim($in["pin"] ?? "");
  if (strlen($pin) < 4) err("PIN en az 4 hane");
  $db->prepare("UPDATE users SET pin=?, token=NULL WHERE id=?")
    ->execute([password_hash($pin, PASSWORD_DEFAULT), (int)($in["id"] ?? 0)]);
  out(["ok"=>true]);

case "gun_raporu":
  adminAuth();
  $gun = $_GET["gun"] ?? date("Y-m-d");
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun)) err("Geçersiz tarih");
  $s = $db->prepare("SELECT u.ad, u.rol, g.veri FROM gunler g JOIN users u ON u.id=g.user_id WHERE g.gun=?");
  $s->execute([$gun]);
  $rows = array_map(fn($r) => ["ad"=>$r["ad"], "rol"=>$r["rol"], "veri"=>json_decode($r["veri"], true)], $s->fetchAll(PDO::FETCH_ASSOC));
  out(["ok"=>true, "gun"=>$gun, "liste"=>$rows]);

case "mesai_raporu":
  adminAuth();
  $bas = $_GET["bas"] ?? date("Y-m-01");
  $bit = $_GET["bit"] ?? date("Y-m-d");
  $uid = (int)($_GET["user_id"] ?? 0);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bas) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bit) || $bas > $bit) err("Geçersiz tarih aralığı");
  $d1 = new DateTime($bas); $d2 = new DateTime($bit);
  if ($d1->diff($d2)->days > 366) err("Tarih aralığı en fazla 366 gün olabilir");

  $users = $db->query("SELECT id,kadi,ad,rol,aktif FROM users WHERE aktif=1 ORDER BY ad")->fetchAll(PDO::FETCH_ASSOC);
  $sql = "SELECT g.user_id,g.gun,g.veri,u.ad,u.rol FROM gunler g JOIN users u ON u.id=g.user_id WHERE g.gun BETWEEN ? AND ? AND u.aktif=1";
  $args = [$bas,$bit];
  if ($uid > 0) { $sql .= " AND g.user_id=?"; $args[]=$uid; }
  $sql .= " ORDER BY g.gun DESC,u.ad";
  $q=$db->prepare($sql); $q->execute($args); $rows=$q->fetchAll(PDO::FETCH_ASSOC);

  $tipWork=["calisma"=>1,"onhazirlik"=>1,"randevu"=>1];
  $people=[]; $days=[]; $nowMs=(int)round(microtime(true)*1000); $today=date("Y-m-d");
  foreach($rows as $r){
    $v=json_decode($r["veri"],true) ?: [];
    $cal=0;$cay=0;$yemek=0;$rand=0;$first=null;$last=null;
    $segments=$v["segs"] ?? [];
    if($r["gun"]===$today && in_array(($v["durum"]??""),["calisiyor","molada"],true) && !empty($v["bas"]) && !empty($v["tip"])){
      $segments[]=["bas"=>(int)$v["bas"],"bit"=>$nowMs,"tip"=>$v["tip"]];
    } elseif($r["gun"]===$today && ($v["durum"]??"")==="calisiyor" && !empty($v["bas"]) && empty($v["tip"])){
      // Açık çalışma segmenti (henüz molaya çıkılmadı): anlık çalışmayı da say
      $segments[]=["bas"=>(int)$v["bas"],"bit"=>$nowMs,"tip"=>"calisma"];
    }
    foreach($segments as $s){
      $a=(int)($s["bas"]??0);$b=(int)($s["bit"]??0);$t=$s["tip"]??"";
      if(!$a||!$b||$b<$a)continue;$dur=$b-$a;
      if(isset($tipWork[$t]))$cal+=$dur;
      if($t==="cay")$cay+=$dur;
      if($t==="yemek")$yemek+=$dur;
      if($t==="randevu")$rand+=$dur;
      $first=$first===null?min($a,$b):min($first,$a,$b);$last=$last===null?max($a,$b):max($last,$a,$b);
    }
    $mola=$cay+$yemek;
    $active=($r["gun"]===$today && in_array(($v["durum"]??""),["calisiyor","molada"],true));
    $day=["user_id"=>(int)$r["user_id"],"gun"=>$r["gun"],"ad"=>$r["ad"],"rol"=>$r["rol"],"calisma_ms"=>$cal,"mola_ms"=>$mola,"cay_ms"=>$cay,"yemek_ms"=>$yemek,"randevu_ms"=>$rand,"ilk_giris"=>$first?date("H:i",(int)($first/1000)):null,"son_cikis"=>$last?date("H:i",(int)($last/1000)):null,"durum"=>$v["durum"]??"—","aktif"=>$active];
    $days[]=$day;
    $id=(int)$r["user_id"];
    if(!isset($people[$id]))$people[$id]=["user_id"=>$id,"ad"=>$r["ad"],"rol"=>$r["rol"],"calisma_ms"=>0,"mola_ms"=>0,"cay_ms"=>0,"yemek_ms"=>0,"randevu_ms"=>0,"gun_sayisi"=>0,"aktif"=>false];
    foreach(["calisma_ms","mola_ms","cay_ms","yemek_ms","randevu_ms"] as $k)$people[$id][$k]+=$day[$k];
    if($cal>0||$mola>0)$people[$id]["gun_sayisi"]++;
    if($active)$people[$id]["aktif"]=true;
  }
  foreach($people as &$p)$p["gunluk_ortalama_ms"]=$p["gun_sayisi"]?round($p["calisma_ms"]/$p["gun_sayisi"]):0; unset($p);
  usort($people,fn($a,$b)=>$b["calisma_ms"]<=>$a["calisma_ms"]);
  $oz=["calisma_ms"=>0,"mola_ms"=>0,"cay_ms"=>0,"yemek_ms"=>0,"randevu_ms"=>0,"gun_sayisi"=>0,"personel_sayisi"=>count($people),"gunluk_ortalama_ms"=>0];
  foreach($people as $p){foreach(["calisma_ms","mola_ms","cay_ms","yemek_ms","randevu_ms","gun_sayisi"] as $k)$oz[$k]+=$p[$k];}
  $oz["gunluk_ortalama_ms"]=$oz["gun_sayisi"]?round($oz["calisma_ms"]/$oz["gun_sayisi"]):0;
  out(["ok"=>true,"bas"=>$bas,"bit"=>$bit,"ozet"=>$oz,"kullanicilar"=>$users,"personeller"=>array_values($people),"gunler"=>$days]);

case "telegram_test":
  adminAuth();
  out(telegramGonder("✅ XRE Mesai botu bağlandı — test mesajı."));

/* ---- Bitrix teşhis uçları ---- */
case "bitrix_test": // webhook çalışıyor mu + verilen kullanıcının anlık durumu
  adminAuth();
  global $BITRIX_WEBHOOK;
  if (empty($BITRIX_WEBHOOK)) out(["ok"=>false, "sebep"=>"Webhook ayarlanmamış (config.php içindeki \$BITRIX_WEBHOOK)"]);
  $uid = (int)($_GET["user_id"] ?? 0);
  $bxId = 0; $ad = "";
  if ($uid > 0) {
    $s = $db->prepare("SELECT id,kadi,ad,bitrix_id FROM users WHERE id=?");
    $s->execute([$uid]); $u = $s->fetch(PDO::FETCH_ASSOC);
    if ($u) { $bxId = bitrixIdBul($u); $ad = $u["ad"]; }
  }
  if (!$bxId) {
    // Webhook'un kendisini doğrula (profil çağrısı)
    $r = bitrixCagri("profile", [], 2);
    if ($r["ok"]) out(["ok"=>true, "sebep"=>"Webhook çalışıyor (profil alındı).", "profil"=>$r["sonuc"]["NAME"] ?? null]);
    out(["ok"=>false, "sebep"=>$r["sebep"] ?: "Webhook yanıt vermedi", "http"=>$r["http"]]);
  }
  $d = bitrixDurumOku($bxId);
  out(["ok"=>$d["ok"], "sebep"=>$d["ok"] ? "Bağlantı OK" : $d["sebep"], "http"=>$d["http"],
       "bitrix_id"=>$bxId, "ad"=>$ad, "durum"=>$d["durum"]]);

case "bitrix_resync": // seçili kullanıcının bugünkü durumunu Bitrix'e zorla eşitle
  adminAuth();
  $uid = (int)($in["id"] ?? $_GET["user_id"] ?? 0);
  $gun = $in["gun"] ?? $_GET["gun"] ?? date("Y-m-d");
  if ($uid <= 0) err("Kullanıcı seçilmedi");
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun)) err("Geçersiz tarih");
  $s = $db->prepare("SELECT u.*, g.veri FROM users u LEFT JOIN gunler g ON g.user_id=u.id AND g.gun=? WHERE u.id=?");
  $s->execute([$gun, $uid]); $u = $s->fetch(PDO::FETCH_ASSOC);
  if (!$u) err("Kullanıcı bulunamadı");
  $v = json_decode($u["veri"] ?? "{}", true) ?: [];
  $durum = $v["durum"] ?? "hazir";
  if ($durum === "hazir") out(["ok"=>true, "sebep"=>"Bugün için kayıtlı bir mesai durumu yok (hazır)."]);
  $rapor = ($durum === "bitti") ? raporMetni($u["ad"], $v) : "";
  $sonuc = bitrixSenkron($db, $u, $gun, $durum, $rapor);
  if ($sonuc["ok"]) $db->prepare("UPDATE gunler SET bx_hedef=?, bx_zaman=? WHERE user_id=? AND gun=?")
      ->execute([["calisiyor"=>"OPENED","molada"=>"PAUSED","bitti"=>"CLOSED"][$durum] ?? null, time(), $uid, $gun]);
  out(["ok"=>$sonuc["ok"], "durum"=>$durum, "sonuc"=>$sonuc]);

case "bitrix_log": // son senkron denemeleri
  adminAuth();
  $limit = min(500, max(10, (int)($_GET["limit"] ?? 120)));
  $uid = (int)($_GET["user_id"] ?? 0);
  if ($uid > 0) {
    $s = $db->prepare("SELECT * FROM bitrix_log WHERE user_id=? ORDER BY id DESC LIMIT ?");
    $s->bindValue(1, $uid, PDO::PARAM_INT); $s->bindValue(2, $limit, PDO::PARAM_INT); $s->execute();
  } else {
    $s = $db->prepare("SELECT * FROM bitrix_log ORDER BY id DESC LIMIT ?");
    $s->bindValue(1, $limit, PDO::PARAM_INT); $s->execute();
  }
  out(["ok"=>true, "log"=>$s->fetchAll(PDO::FETCH_ASSOC), "sunucu_zaman"=>time()]);

default: err("Bilinmeyen istek");
}
} catch (Throwable $e) {
  // Beklenmeyen hata: boş 500 yerine JSON dön (frontend "çevrimdışı" sanmasın)
  http_response_code(200);
  out(["ok"=>false, "hata"=>"Sunucu hatası", "detay"=>defined("XRE_DEBUG") && XRE_DEBUG ? $e->getMessage() : null]);
}
