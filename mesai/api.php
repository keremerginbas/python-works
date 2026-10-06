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

/* config.php'de tanımlı değilse varsayılanlar (eski config dosyaları da çalışsın) */
$GUNLUK_RAPOR_SAAT = $GUNLUK_RAPOR_SAAT ?? 20;  // her akşam bu saatte o günün toplu raporu (false = kapalı)
$CRON_ANAHTAR      = $CRON_ANAHTAR ?? "";       // URL ile cron tetiklemek için gizli anahtar (boş = yalnız CLI)
$CLI = (PHP_SAPI === "cli");
ignore_user_abort(true); // yanıt gittikten sonraki arka plan işleri yarıda kalmasın

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
/* Zamanlanmış işler (günlük toplu rapor vb.) — aynı işin iki kez çalışmasını engeller */
$db->exec("CREATE TABLE IF NOT EXISTS gorevler(
  anahtar TEXT PRIMARY KEY, durum TEXT NOT NULL, zaman INTEGER, notlar TEXT)");

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
  return $h ? ($m ? "$h sa $m dk" : "$h sa") : "$m dk";
}
/* Mola tipleri — index.html'deki MOLA ile aynı anahtarlar.
   sayilir=true olanlar (online randevu) çalışma süresine eklenir. */
const MOLA_TIPLERI = [
  "cay"     => ["ad"=>"Çay",            "emoji"=>"☕", "sayilir"=>false,
                "cikis"=>"çay molasına çıktı",       "donus"=>"çay molasından döndü",     "ozet"=>"çay molası verdi"],
  "yemek"   => ["ad"=>"Yemek",          "emoji"=>"🍽", "sayilir"=>false,
                "cikis"=>"yemek molasına çıktı",     "donus"=>"yemek molasından döndü",   "ozet"=>"yemek molası verdi"],
  "tuvalet" => ["ad"=>"Tuvalet",        "emoji"=>"🚻", "sayilir"=>false,
                "cikis"=>"tuvalet molasına çıktı",   "donus"=>"tuvalet molasından döndü", "ozet"=>"tuvalet molası verdi"],
  "randevu" => ["ad"=>"Online randevu", "emoji"=>"🎥", "sayilir"=>true,
                "cikis"=>"online randevuya başladı", "donus"=>"online randevuyu bitirdi", "ozet"=>"online randevu yaptı"],
];

function saatMs($ms){ return date("H:i", (int)($ms / 1000)); }

/* Bir günün özetini çıkarır. $acikBitisMs verilirse hâlâ açık olan segment o ana kadar sayılır. */
function gunOzet($v, $acikBitisMs = null){
  $segs = is_array($v["segs"] ?? null) ? $v["segs"] : [];
  $acik = in_array($v["durum"] ?? "", ["calisiyor","molada"], true) && !empty($v["bas"]);
  if ($acik && $acikBitisMs && $acikBitisMs > (int)$v["bas"])
    $segs[] = ["tip"=>($v["tip"] ?: "calisma"), "bas"=>(int)$v["bas"], "bit"=>(int)$acikBitisMs];
  $o = ["calisma"=>0, "mola"=>0, "molalar"=>[], "adet"=>[], "liste"=>[], "ilk"=>null, "son"=>null, "acik"=>$acik];
  foreach ($segs as $s) {
    $a = (int)($s["bas"] ?? 0); $b = (int)($s["bit"] ?? 0); $t = (string)($s["tip"] ?? "");
    if (!$a || !$b || $b <= $a) continue;
    $d = $b - $a;
    if ($t === "calisma" || $t === "onhazirlik") $o["calisma"] += $d;
    elseif (isset(MOLA_TIPLERI[$t])) {
      $o["molalar"][$t] = ($o["molalar"][$t] ?? 0) + $d;
      $o["adet"][$t] = ($o["adet"][$t] ?? 0) + 1;
      $o["liste"][] = ["tip"=>$t, "bas"=>$a, "bit"=>$b];
      if (MOLA_TIPLERI[$t]["sayilir"]) $o["calisma"] += $d; else $o["mola"] += $d;
    } else continue;
    $o["ilk"] = $o["ilk"] === null ? $a : min($o["ilk"], $a);
    $o["son"] = $o["son"] === null ? $b : max($o["son"], $b);
  }
  return $o;
}

/* Mesai bitiş raporu. $detay=true → Bitrix rapor alanı için her molayı saatleriyle listeler. */
function raporMetni($ad, $veri, $detay = false){
  $o = gunOzet($veri);
  $ilk = explode(" ", trim($ad))[0];
  $m = "📋 $ilk bugün " . fmtSaatPHP($o["calisma"]) . " çalıştı";
  foreach (["yemek","cay","tuvalet"] as $t)
    if (!empty($o["molalar"][$t])) $m .= ", " . fmtSaatPHP($o["molalar"][$t]) . " " . MOLA_TIPLERI[$t]["cikis"];
  if (!empty($o["molalar"]["randevu"])) $m .= ", " . fmtSaatPHP($o["molalar"]["randevu"]) . " online randevuda kaldı";
  $m .= ".";
  if ($o["ilk"]) $m .= "\n🕘 " . saatMs($o["ilk"]) . " – " . saatMs($o["son"])
                   . ($o["mola"] ? " · toplam mola " . fmtSaatPHP($o["mola"]) : " · mola yok");
  if ($detay && $o["liste"]) {
    $m .= "\nMolalar:";
    foreach ($o["liste"] as $l)
      $m .= "\n  " . MOLA_TIPLERI[$l["tip"]]["emoji"] . " " . MOLA_TIPLERI[$l["tip"]]["ad"] . " "
          . saatMs($l["bas"]) . "–" . saatMs($l["bit"]) . " (" . fmtSaatPHP($l["bit"] - $l["bas"]) . ")";
  }
  $g = $veri["girisKonum"] ?? null; $c = $veri["cikisKonum"] ?? null;
  $m .= "\n" . konumSatiri("Giriş", $g);
  $m .= "\n" . konumSatiri("Çıkış", $c);
  return $m;
}

/* ---------------- Günlük toplu rapor ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); }
function tarihUzun($gun){
  $ay = ["Ocak","Şubat","Mart","Nisan","Mayıs","Haziran","Temmuz","Ağustos","Eylül","Ekim","Kasım","Aralık"];
  $gn = ["Pazar","Pazartesi","Salı","Çarşamba","Perşembe","Cuma","Cumartesi"];
  $t = strtotime($gun . " 12:00:00");
  return (int)date("j",$t) . " " . $ay[(int)date("n",$t)-1] . " " . date("Y",$t) . ", " . $gn[(int)date("w",$t)];
}

/* Varsayılan rapor günü: rapor saati geçtiyse bugün, değilse dün */
function varsayilanRaporGunu(){
  global $GUNLUK_RAPOR_SAAT;
  $saat = $GUNLUK_RAPOR_SAAT === false ? 20 : (int)$GUNLUK_RAPOR_SAAT;
  return (int)date("G") >= $saat ? date("Y-m-d") : date("Y-m-d", strtotime("-1 day"));
}

/* Telegram HTML metin parçaları (kişi blokları) döner; o gün hiç kayıt yoksa null. */
function gunlukRaporParcalari($db, $gun){
  $bugun = ($gun === date("Y-m-d"));
  $nowMs = (int)round(microtime(true) * 1000);
  $q = $db->prepare("SELECT g.user_id, g.veri, g.guncelleme, u.ad FROM gunler g
                     JOIN users u ON u.id=g.user_id WHERE g.gun=? AND u.aktif=1");
  $q->execute([$gun]);
  $kisiler = []; $calisanIds = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $v = json_decode($r["veri"], true) ?: [];
    // Açık mesai: bugünse şu ana kadar, geçmiş günse uygulamanın son bildirdiği ana kadar say
    $o = gunOzet($v, $bugun ? $nowMs : (int)$r["guncelleme"] * 1000);
    if ($o["calisma"] + $o["mola"] <= 0) continue;
    $kisiler[] = ["ad"=>$r["ad"], "o"=>$o];
    $calisanIds[(int)$r["user_id"]] = 1;
  }
  if (!$kisiler) return null;
  usort($kisiler, fn($a,$b) => $b["o"]["calisma"] <=> $a["o"]["calisma"]);

  $parcalar = ["📊 <b>GÜNLÜK MESAİ RAPORU</b>\n🗓 " . h(tarihUzun($gun))];
  $toplam = 0; $kapatmayan = [];
  foreach ($kisiler as $i => $k) {
    $o = $k["o"]; $toplam += $o["calisma"];
    $sira = $i < 3 ? ["🥇","🥈","🥉"][$i] : ($i + 1) . ".";
    $b = "$sira <b>" . h($k["ad"]) . "</b> — " . fmtSaatPHP($o["calisma"]) . " çalıştı";
    $molalar = [];
    foreach (["cay","yemek","tuvalet","randevu"] as $t) if (!empty($o["molalar"][$t])) {
      $adet = $o["adet"][$t] > 1 ? " ({$o["adet"][$t]}×)" : "";
      $molalar[] = MOLA_TIPLERI[$t]["emoji"] . " " . MOLA_TIPLERI[$t]["ad"] . " " . fmtSaatPHP($o["molalar"][$t]) . $adet;
    }
    $b .= "\n      " . ($molalar ? implode(" · ", $molalar) : "Mola yok")
        . ($o["mola"] && count($molalar) > 1 ? " → toplam mola " . fmtSaatPHP($o["mola"]) : "");
    $b .= "\n      🕘 " . saatMs($o["ilk"]) . " – " . ($o["acik"] && $bugun ? "şu an" : saatMs($o["son"]))
        . ($o["acik"] ? ($bugun ? " 🟢 hâlâ mesaide" : " ⚠️ mesai kapatılmadı") : "");
    if ($o["acik"]) $kapatmayan[] = h(explode(" ", trim($k["ad"]))[0]);
    $parcalar[] = $b;
  }

  $hic = [];
  foreach ($db->query("SELECT id, ad FROM users WHERE aktif=1 ORDER BY ad")->fetchAll(PDO::FETCH_ASSOC) as $u)
    if (empty($calisanIds[(int)$u["id"]])) $hic[] = h($u["ad"]);

  $son = "━━━━━━━━━━━━\n👥 " . count($kisiler) . " kişi · ⏱ toplam " . fmtSaatPHP($toplam)
       . " · ort. " . fmtSaatPHP((int)round($toplam / count($kisiler))) . "/kişi";
  if ($kapatmayan) $son .= $bugun
    ? "\n🟢 <b>Hâlâ mesaide:</b> " . implode(", ", $kapatmayan) . " (süre rapor anına kadar sayıldı)"
    : "\n⚠️ <b>Mesaiyi kapatmayan:</b> " . implode(", ", $kapatmayan) . " (süre son bildirime kadar sayıldı)";
  if ($hic) $son .= "\n🚫 <b>Kayıt yok:</b> " . implode(", ", $hic);
  $parcalar[] = $son;
  return $parcalar;
}

/* Parçaları Telegram'ın 4096 karakter sınırına göre mesajlara böler */
function parcalariBirlestir($parcalar, $sinir = 3500){
  $mesajlar = []; $cur = "";
  foreach ($parcalar as $p) {
    if ($cur !== "" && mb_strlen($cur . "\n\n" . $p) > $sinir) { $mesajlar[] = $cur; $cur = $p; }
    else $cur = $cur === "" ? $p : $cur . "\n\n" . $p;
  }
  if ($cur !== "") $mesajlar[] = $cur;
  return $mesajlar;
}

/* Aynı işin (ör. "gunluk:2026-10-05") bir kez çalışmasını sağlar.
   Başarısız/yarıda kalmış iş $tekrarSn sonra yeniden alınabilir. */
function gorevAl($db, $anahtar, $tekrarSn = 600){
  $now = time();
  $db->prepare("INSERT OR IGNORE INTO gorevler(anahtar,durum,zaman) VALUES(?,'bekliyor',0)")->execute([$anahtar]);
  $s = $db->prepare("UPDATE gorevler SET durum='calisiyor', zaman=? WHERE anahtar=?
                     AND (durum='bekliyor' OR (durum<>'tamam' AND zaman < ?))");
  $s->execute([$now, $anahtar, $now - $tekrarSn]);
  return $s->rowCount() === 1;
}
function gorevBitir($db, $anahtar, $ok, $not = ""){
  $db->prepare("UPDATE gorevler SET durum=?, zaman=?, notlar=? WHERE anahtar=?")
     ->execute([$ok ? "tamam" : "hata", time(), mb_substr((string)$not, 0, 400), $anahtar]);
}

/* $zorla=false: o gün için zaten gönderildiyse tekrar göndermez (cron + yedek tetik çakışmasın). */
function gunlukRaporGonder($db, $gun, $zorla = false){
  $anahtar = "gunluk:$gun";
  if ($zorla) {
    $db->prepare("INSERT OR IGNORE INTO gorevler(anahtar,durum,zaman) VALUES(?,'bekliyor',0)")->execute([$anahtar]);
  } elseif (!gorevAl($db, $anahtar)) {
    return ["ok"=>true, "atla"=>true, "sebep"=>"$gun raporu zaten gönderildi"];
  }
  $parcalar = gunlukRaporParcalari($db, $gun);
  if (!$parcalar) { gorevBitir($db, $anahtar, true, "kayıt yok"); return ["ok"=>true, "atla"=>true, "sebep"=>"$gun için mesai kaydı yok"]; }
  $ok = true; $sebep = "";
  foreach (parcalariBirlestir($parcalar) as $metin) {
    $r = telegramGonder($metin, true);
    if (!$r["ok"]) { $ok = false; $sebep = $r["sebep"]; break; }
  }
  gorevBitir($db, $anahtar, $ok, $sebep);
  return ["ok"=>$ok, "sebep"=>$ok ? "$gun raporu gönderildi" : $sebep];
}

/* Mesai bitiş raporunu bir kez gönderir (rapor_gitti: 0=gitmedi, 2=gönderiliyor, 1=gitti) */
function bitisRaporuGonder($db, $uid, $gun, $ad, $veriArr){
  $s = $db->prepare("UPDATE gunler SET rapor_gitti=2 WHERE user_id=? AND gun=? AND rapor_gitti=0");
  $s->execute([$uid, $gun]);
  if ($s->rowCount() !== 1) return null; // başka bir istek gönderdi / gönderiyor
  $r = telegramGonder(raporMetni($ad, $veriArr));
  if ($r["ok"]) $db->prepare("UPDATE gunler SET rapor_gitti=1 WHERE user_id=? AND gun=?")->execute([$uid, $gun]);
  else $db->prepare("UPDATE gunler SET rapor_gitti=0, guncelleme=? WHERE user_id=? AND gun=?")->execute([time(), $uid, $gun]);
  return $r;
}

/* Çıkış konumu beklenirken ertelenen ya da gönderilemeyen bitiş raporlarını gönder */
function bekleyenRaporlar($db){
  $s = $db->prepare("SELECT g.user_id, g.gun, g.veri, u.ad FROM gunler g JOIN users u ON u.id=g.user_id
                     WHERE g.gun=? AND g.rapor_gitti=0 AND g.guncelleme BETWEEN ? AND ?
                     AND g.veri LIKE '%\"durum\":\"bitti\"%'");
  $s->execute([date("Y-m-d"), time() - 6*3600, time() - 45]);
  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r)
    bitisRaporuGonder($db, (int)$r["user_id"], $r["gun"], $r["ad"], json_decode($r["veri"], true) ?: []);
}

/* Yanıt kullanıcıya gittikten sonra çalışan işler. Cron kurulmamış olsa bile
   saat 20:00'den sonraki ilk istekte o günün toplu raporu gider (yedek tetik).
   20:00'den sonra hiç istek gelmediyse, ertesi sabah 12:00'ye kadar gelen ilk
   istekte bir önceki günün raporu gönderilir. */
function arkaPlanIsleri(){
  global $db, $GUNLUK_RAPOR_SAAT, $CLI;
  if ($CLI || !isset($db)) return;
  if (function_exists("fastcgi_finish_request")) @fastcgi_finish_request();
  elseif (function_exists("litespeed_finish_request")) @litespeed_finish_request();
  try {
    bekleyenRaporlar($db);
    if ($GUNLUK_RAPOR_SAAT !== false) {
      $saat = (int)date("G");
      $gun = null;
      if ($saat >= (int)$GUNLUK_RAPOR_SAAT) $gun = date("Y-m-d");
      elseif ($saat < 12) $gun = date("Y-m-d", strtotime("-1 day"));
      if ($gun) {
        $s = $db->prepare("SELECT durum FROM gorevler WHERE anahtar=?");
        $s->execute(["gunluk:$gun"]);
        if ($s->fetchColumn() !== "tamam") gunlukRaporGonder($db, $gun);
      }
    }
  } catch (Throwable $e) {}
}
register_shutdown_function("arkaPlanIsleri");
function konumSatiri($ad, $k){
  if (!$k || !empty($k["hata"]) || !isset($k["lat"])) {
    $neden = is_array($k) && !empty($k["neden"]) ? " (" . mb_substr((string)$k["neden"], 0, 40) . ")" : "";
    return "⚠️ $ad konumu alınamadı$neden";
  }
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
function telegramGonder($metin, $html = false){
  global $TG_TOKEN, $TG_CHAT;
  if (empty($TG_TOKEN) || empty($TG_CHAT)) return ["ok"=>false, "sebep"=>"token/chat ayarlanmamış"];
  $url = "https://api.telegram.org/bot$TG_TOKEN/sendMessage";
  $alanlar = ["chat_id"=>$TG_CHAT, "text"=>$metin, "disable_web_page_preview"=>1];
  if ($html) $alanlar["parse_mode"] = "HTML";
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
$ham = $CLI ? "" : file_get_contents("php://input");
$in = json_decode($ham, true) ?: [];
$act = $_GET["action"] ?? $in["action"] ?? "";
/* Komut satırı (cron) yalnızca günlük raporu çalıştırır:
   php api.php            → 20:00'den sonra bugünün, önce ise dünün raporu
   php api.php 2026-10-05 → belirli günün raporu (o gün zaten gönderildiyse atlar) */
if ($CLI) {
  $act = "gunluk_rapor_cron";
  foreach (array_slice($argv ?? [], 1) as $arg) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) $_GET["gun"] = $arg;
}

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

  // Önceki durumu oku + yeni durumu yaz: tek kilit altında. Böylece aynı anda gelen
  // iki istek (ör. normal kayıt + sekme kapanırken beacon) aynı geçişi iki kez
  // görmez ve Telegram'a mükerrer bildirim gitmez.
  $db->exec("BEGIN IMMEDIATE");
  try {
    $s = $db->prepare("SELECT veri, bx_hedef, bx_zaman FROM gunler WHERE user_id=? AND gun=?");
    $s->execute([$u["id"], $gun]);
    $satir = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    // Kayıt kaynağı DB'dir; Bitrix/Telegram yan etkidir.
    $db->prepare("INSERT INTO gunler(user_id,gun,veri,guncelleme) VALUES(?,?,?,?)
      ON CONFLICT(user_id,gun) DO UPDATE SET veri=excluded.veri, guncelleme=excluded.guncelleme")
      ->execute([$u["id"], $gun, $veri, time()]);
    $db->exec("COMMIT");
  } catch (Throwable $e) { try { $db->exec("ROLLBACK"); } catch (Throwable $e2) {} throw $e; }
  $eski = json_decode($satir["veri"] ?? "{}", true) ?: [];
  $eskiDurum = $eski["durum"] ?? "hazir";
  $yeniDurum = $veriArr["durum"] ?? "hazir";
  $bxHedefEski = $satir["bx_hedef"] ?? null;
  $bxZaman = (int)($satir["bx_zaman"] ?? 0);

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
        $bxRapor = ($yeniDurum === "bitti") ? raporMetni($u["ad"], $veriArr, true) : "";
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
  // Geçişler hem durum hem segment kimliğiyle (bas zaman damgası) tespit edilir;
  // böylece çevrimdışıyken birleşen olaylar da (ör. mola+dönüş) kaybolmaz.
  $tgSonuc = null;
  try {
    $ad = $u["ad"];
    $nowMs = (int)round(microtime(true) * 1000);
    $segsYeni = is_array($veriArr["segs"] ?? null) ? $veriArr["segs"] : [];
    $acikDurum = ["calisiyor","molada"];
    $yakinGun = $gun >= date("Y-m-d", strtotime("-1 day"));

    if ($bugunMu) {
      // 1) Mesai başlangıcı (günün ilk başlatması bir kez bildirilir)
      if ($eskiDurum === "hazir" && $yeniDurum !== "hazir") {
        $s = $db->prepare("UPDATE gunler SET basla_gitti=1 WHERE user_id=? AND gun=? AND basla_gitti=0");
        $s->execute([$u["id"], $gun]);
        if ($s->rowCount() === 1) {
          $basMs = (int)($segsYeni[0]["bas"] ?? ($veriArr["bas"] ?? 0));
          $tgSonuc = telegramGonder("🟢 $ad — " . ($basMs ? saatMs($basMs) : date("H:i")) . ": mesaisini başlattı.");
          if (!$tgSonuc["ok"]) $db->prepare("UPDATE gunler SET basla_gitti=0 WHERE user_id=? AND gun=?")->execute([$u["id"], $gun]);
        }
      }
      // 1b) Bitirdikten sonra yeniden başladı
      if ($eskiDurum === "bitti" && in_array($yeniDurum, $acikDurum, true)) {
        $db->prepare("UPDATE gunler SET rapor_gitti=0 WHERE user_id=? AND gun=?")->execute([$u["id"], $gun]);
        $basMs = (int)($veriArr["bas"] ?? 0);
        $tgSonuc = telegramGonder("🔄 $ad — " . ($basMs ? saatMs($basMs) : date("H:i")) . ": mesaisine yeniden başladı.");
      }

      // 2) Moladan dönüş: sunucunun daha önce görmediği, kapanmış mola segmentleri
      if ($yeniDurum !== "bitti") {
        $eskiBaslar = [];
        foreach (($eski["segs"] ?? []) as $es) $eskiBaslar[(int)($es["bas"] ?? 0)] = 1;
        $yeniMolalar = array_values(array_filter($segsYeni, fn($sg) =>
          isset(MOLA_TIPLERI[$sg["tip"] ?? ""]) && !empty($sg["bas"]) && !empty($sg["bit"])
          && empty($eskiBaslar[(int)$sg["bas"]])));
        foreach (array_slice($yeniMolalar, -3) as $sg) {
          $m = MOLA_TIPLERI[$sg["tip"]];
          $bas = (int)$sg["bas"]; $bit = (int)$sg["bit"]; $sure = fmtSaatPHP($bit - $bas);
          if ($eskiDurum === "molada" && (int)($eski["bas"] ?? 0) === $bas) {
            $tgSonuc = telegramGonder("▶️ $ad — " . saatMs($bit) . ": $sure {$m["donus"]}, çalışmaya devam ediyor.");
          } elseif ($bit > $nowMs - 30 * 60000) {
            // Başlangıcı hiç bildirilmemiş (çevrimdışı/hızlı geçiş) mola: tek özet mesaj
            $tgSonuc = telegramGonder("{$m["emoji"]} $ad — " . saatMs($bas) . "–" . saatMs($bit) . ": $sure {$m["ozet"]}, çalışmaya döndü.");
          }
        }
      }

      // 3) Mola başlangıcı (yeni bir mola segmenti açıldıysa)
      if ($yeniDurum === "molada" &&
          ($eskiDurum !== "molada" || (int)($eski["bas"] ?? 0) !== (int)($veriArr["bas"] ?? 0))) {
        $molaTipi = $veriArr["tip"] ?? "";
        if (is_string($molaTipi) && isset(MOLA_TIPLERI[$molaTipi])) {
          $m = MOLA_TIPLERI[$molaTipi];
          $molaBas = $veriArr["bas"] ?? null;
          $saat = is_numeric($molaBas) && $molaBas > 0 ? saatMs($molaBas) : date("H:i");
          $tgSonuc = telegramGonder("{$m["emoji"]} $ad — $saat: {$m["cikis"]}.");
        }
      }
    }

    // 4) Mesai bitiş raporu. Uygulama çıkış konumunu hâlâ alıyorsa (konumBekliyor) bekle:
    //    konum gelince uygulama tekrar kaydeder; uygulama kapanırsa arka plan işi 45 sn sonra gönderir.
    if ($yeniDurum === "bitti" && $yakinGun) {
      if (empty($veriArr["konumBekliyor"])) $tgSonuc = bitisRaporuGonder($db, $u["id"], $gun, $ad, $veriArr) ?? $tgSonuc;
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

/* ---- Günlük toplu rapor ----
   Önizleme (GET) veya elle gönderim (POST {gun, gonder:true}). */
case "gunluk_rapor":
  adminAuth();
  $gun = $in["gun"] ?? $_GET["gun"] ?? varsayilanRaporGunu();
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun)) err("Geçersiz tarih");
  if (!empty($in["gonder"])) out(gunlukRaporGonder($db, $gun, true));
  $p = gunlukRaporParcalari($db, $gun);
  $s = $db->prepare("SELECT durum, zaman, notlar FROM gorevler WHERE anahtar=?");
  $s->execute(["gunluk:$gun"]); $g = $s->fetch(PDO::FETCH_ASSOC) ?: null;
  out(["ok"=>true, "gun"=>$gun, "mesajlar"=>$p ? parcalariBirlestir($p) : [], "gorev"=>$g,
       "saat"=>$GUNLUK_RAPOR_SAAT, "cron_url"=>$CRON_ANAHTAR !== ""]);

/* Cron: "php api.php" (CLI)  ya da  api.php?action=cron&anahtar=GIZLI (URL)
   20:00'den sonra çalışırsa bugünün, önce çalışırsa dünün raporu; aynı gün için ikinci kez göndermez. */
case "cron":
case "gunluk_rapor_cron":
  $yetkili = $CLI || ($act === "cron" && $CRON_ANAHTAR !== "" && hash_equals((string)$CRON_ANAHTAR, (string)($_GET["anahtar"] ?? "")));
  if (!$yetkili) err("Yetkisiz");
  $gun = $_GET["gun"] ?? varsayilanRaporGunu();
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun)) err("Geçersiz tarih");
  out(gunlukRaporGonder($db, $gun));

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
  $rapor = ($durum === "bitti") ? raporMetni($u["ad"], $v, true) : "";
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
