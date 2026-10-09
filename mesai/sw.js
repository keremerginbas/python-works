const CACHE = "xre-mesai-v20";
const ASSETS = ["./", "./index.html", "./manifest.json", "./logo.png", "./icon-192-v2.png", "./icon-512-v2.png"];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS.map((u) => new Request(u, { cache: "reload" })))));
  self.skipWaiting();
});

self.addEventListener("activate", (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (e) => {
  const req = e.request;
  // API çağrıları ve tüm POST istekleri: cache'e ASLA girme, doğrudan ağa git.
  if (req.method !== "GET" || req.url.includes("api.php")) {
    e.respondWith(fetch(req));
    return;
  }
  // Sayfanın kendisi (HTML): ÖNCE AĞ. Eski sürüm "önce önbellek" idi; yeni sürüm ancak
  // uygulama ikinci kez açılınca görünüyordu. Artık internet varken her açılışta güncel
  // sayfa gelir, yalnızca çevrimdışıyken önbellekteki kopya kullanılır.
  const html = req.mode === "navigate" || req.destination === "document" || /\/(index\.html)?(\?.*)?$/.test(new URL(req.url).pathname);
  if (html) {
    e.respondWith(
      fetch(req, { cache: "no-store" }).then((res) => {
        if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put("./index.html", copy)); }
        return res;
      }).catch(() => caches.match("./index.html").then((hit) => hit || caches.match("./")))
    );
    return;
  }
  // Statik dosyalar (logo, ikonlar): önce cache, yoksa ağdan al ve cache'le.
  e.respondWith(
    caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put(req, copy));
      return res;
    }))
  );
});
