const CACHE = "xre-mesai-v15";
const ASSETS = ["./", "./index.html", "./manifest.json", "./logo.png", "./icon-192-v2.png", "./icon-512-v2.png"];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)));
  self.skipWaiting();
});

self.addEventListener("activate", (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener("fetch", (e) => {
  // API çağrıları ve tüm POST istekleri: cache'e ASLA girme, doğrudan ağa git.
  if (e.request.method !== "GET" || e.request.url.includes("api.php")) {
    e.respondWith(fetch(e.request));
    return;
  }
  // Statik dosyalar: önce cache, yoksa ağdan al ve cache'le.
  e.respondWith(
    caches.match(e.request).then((hit) => hit || fetch(e.request).then((res) => {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put(e.request, copy));
      return res;
    }).catch(() => caches.match("./index.html")))
  );
});