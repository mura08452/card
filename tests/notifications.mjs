import {readFileSync} from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const source=readFileSync(new URL('../public/app.js',import.meta.url),'utf8');const a=source.indexOf('let knownApproved=');const b=source.indexOf('setInterval(pollRequests,8000);',a);let rows=[],notifications=[],refreshCount=0;const context=vm.createContext({ready:true,navigator:{onLine:true},document:{hidden:false,querySelector:()=>({textContent:''})},dirty:false,page:'book',api:async()=>({requests:rows}),sync:async()=>{},refresh:async()=>{refreshCount++},render:()=>{},toast:s=>notifications.push(s)});vm.runInContext(source.slice(a,b),context);const poll=()=>vm.runInContext('pollRequests()',context);
for(let i=0;i<5;i++)await poll();assert.equal(notifications.length,0,'空の状態で繰り返し通知しない');
rows=[{id:'one',incoming:true,status:'pending'}];await poll();assert.equal(notifications.length,0);
rows=[{id:'one',incoming:true,status:'approved'}];await poll();assert.equal(notifications.length,1);assert.equal(refreshCount,1);
for(let i=0;i<5;i++)await poll();assert.equal(notifications.length,1,'同じ成立情報で再通知しない');
rows=[];await poll();assert.equal(notifications.length,1,'古い履歴が一覧から消えても通知しない');
console.log('PASS: 空状態の繰り返し・承認待ち・成立時に一度だけ・履歴消失時の通知抑制');
