// Cache names and routes are isolated to this installation's /card/ scope.
const BASE=new URL(self.registration.scope).pathname;
const PREFIX='trail-card-php:'+BASE+':',SHELL=PREFIX+'shell-v5',IMAGES=PREFIX+'images-v3';
const ASSETS=['shell.html','styles.css','app.js','qr.js','manifest.json','favicon.ico'].map(p=>BASE+p);
self.addEventListener('install',e=>{e.waitUntil(caches.open(SHELL).then(c=>c.addAll(ASSETS)));self.skipWaiting()});
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k.startsWith(PREFIX)&&![SHELL,IMAGES].includes(k)).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{
 const u=new URL(e.request.url);if(e.request.method!=='GET'||u.origin!==self.location.origin||!u.pathname.startsWith(BASE))return;
 if(u.pathname===BASE+'api/image'){
  e.respondWith(fetch(e.request).then(async r=>{const c=await caches.open(IMAGES);if(r.ok)await c.put(e.request,r.clone());else await c.delete(e.request);return r}).catch(()=>caches.match(e.request).then(r=>r||Response.error())));return;
 }
 if(u.pathname.startsWith(BASE+'api/')||u.pathname.startsWith(BASE+'auth/'))return;
 if(e.request.mode==='navigate'&&(u.pathname===BASE||u.pathname===BASE+'index.php')){e.respondWith(fetch(e.request).catch(()=>caches.match(BASE+'shell.html')));return;}
 if(ASSETS.includes(u.pathname))e.respondWith(caches.match(e.request).then(r=>r||fetch(e.request)));
});
