import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const dir=new URL('../public/',import.meta.url);
const sw=readFileSync(new URL('sw.js',dir),'utf8');
const handlers={},removed=[],stored=[],matched=[];
const own='trail-card-php:/card/:';
const cache={addAll:async urls=>stored.push(...urls),put:async()=>{},delete:async()=>{}};
const ctx=vm.createContext({URL,Response,Promise,self:{registration:{scope:'https://over.rulez.jp/card/'},location:{origin:'https://over.rulez.jp'},addEventListener:(type,fn)=>handlers[type]=fn,skipWaiting(){},clients:{claim:async()=>{}}},caches:{open:async()=>cache,keys:async()=>['existing-site-cache','trail-card-php:/other/:shell-v1',own+'shell-v1',own+'images-v3'],delete:async k=>removed.push(k),match:async k=>{matched.push(k);return 'offline-shell'}},fetch:async()=>{throw Error('offline')}});
vm.runInContext(sw,ctx);
let waiting;handlers.install({waitUntil:p=>waiting=p});await waiting;
assert.equal(stored.length,6);assert.ok(stored.every(p=>p.startsWith('/card/')));
handlers.activate({waitUntil:p=>waiting=p});await waiting;
assert.deepEqual(removed,[own+'shell-v1']);
async function get(path,mode='navigate'){
 let intercepted=false,response;
 handlers.fetch({request:{url:'https://over.rulez.jp'+path,method:'GET',mode},respondWith(p){intercepted=true;response=p;}});
 return {intercepted,value:await response};
}
assert.equal((await get('/card/?exchange=token')).value,'offline-shell');assert.equal(matched.at(-1),'/card/shell.html');
for(const p of ['/','/api/image','/other/','/card/api/state','/card/auth/callback'])assert.equal((await get(p)).intercepted,false,p);
assert.equal((await get('/card/api/image?key=test','cors')).intercepted,true);
const source=readFileSync(new URL('app.js',dir),'utf8');
assert.ok(source.includes("new URL('.',document.currentScript.src).pathname"));
assert.ok(source.includes("register(BASE+'sw.js',{scope:BASE})"));
// Execute the actual logout body, preserving unrelated origin data and caches.
const storage={'trail-card:/card/:owner-id':'u','trail-card:/other/:owner-id':'v',existing:'keep'};
Object.defineProperty(storage,'removeItem',{enumerable:false,value:k=>delete storage[k]});
const deleted=[];let destination;
const logoutCtx=vm.createContext({authInfo:{linked:['google']},dirty:false,csrf:'token',STORAGE:'trail-card:/card/:',CACHE_PREFIX:own,BASE:'/card/',window:{caches:true},localStorage:storage,caches:{keys:async()=>[own+'shell-v3','existing-site-cache','trail-card-php:/other/:shell-v1'],delete:async k=>deleted.push(k)},fetch:async(url,opts)=>{assert.equal(url,'/card/auth/logout');assert.equal(opts.headers['X-CSRF-Token'],'token');return {ok:true}},location:{replace:p=>destination=p},clearTimeout(){},syncTimer:null,ready:true,ticket:{},stopScanner(){},toast:msg=>{throw Error(msg)}});
vm.runInContext(source.slice(source.indexOf('async function logout(){'),source.indexOf('async function sync(){')),logoutCtx);
await vm.runInContext('logout()',logoutCtx);
assert.equal(destination,'/card/');assert.equal(storage.existing,'keep');assert.equal(storage['trail-card:/other/:owner-id'],'v');assert.ok(!('trail-card:/card/:owner-id' in storage));assert.deepEqual(deleted,[own+'shell-v3']);
const html=readFileSync(new URL('shell.html',dir),'utf8');assert.ok(!/(?:src|href)="\//.test(html));
const manifest=JSON.parse(readFileSync(new URL('manifest.json',dir),'utf8'));assert.equal(manifest.scope,'./');
console.log('PASS subpath assets, scoped worker, offline home, API/auth bypass, logout and unrelated site data preservation');
