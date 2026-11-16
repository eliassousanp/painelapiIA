import assert from 'node:assert/strict';
import https from 'node:https';
import {EventEmitter} from 'node:events';
import {streamGenerateContent} from './src/antigravity.js';
const original=https.request;
const event=(text,finishReason)=>`data: ${JSON.stringify({response:{candidates:[{content:{parts:[{text}]},...(finishReason?{finishReason}:{})}]}})}`;
async function simulate(chunks,{callback,disconnect,reqError,status=200,signal}={}) {
 https.request=(_opts,onResponse)=>{
   const req=new EventEmitter();req.setTimeout=()=>{};req.write=()=>{};req.destroy=()=>{};
   req.end=()=>queueMicrotask(()=>{
     if(reqError){req.emit('error',reqError);return;}
     const res=new EventEmitter();res.statusCode=status;onResponse(res);
     for(const chunk of chunks)res.emit('data',chunk);
     if(disconnect==='aborted')res.emit('aborted');else if(disconnect==='error')res.emit('error',new Error('corte'));else res.emit('end');
   });return req;
 };
 try{return await streamGenerateContent('token','project','model',[],null,null,callback,signal);}finally{https.request=original;}
}
const utf=Buffer.from(event('olá 🌟','STOP'));const pos=utf.indexOf(Buffer.from('🌟'))+2;let recebidos='';
const r=await simulate([utf.subarray(0,pos),utf.subarray(pos)],{callback:t=>{if(t)recebidos+=t;}});
assert.equal(recebidos,'olá 🌟');assert.equal(r.replyText,'olá 🌟');assert.equal(r.candidates[0].finishReason,'STOP');
const multiplo=await simulate([Buffer.from(event('primeiro')+'\n'+event('final','STOP'))]);assert.equal(multiplo.replyText,'primeirofinal');
const usage=await simulate([Buffer.from(event('ok','STOP')+'\n'+'data: '+JSON.stringify({response:{usageMetadata:{promptTokenCount:120,candidatesTokenCount:30,thoughtsTokenCount:70,totalTokenCount:220}}})+'\n')]);
assert.equal(usage.usageMetadata.thoughtsTokenCount,70);
await assert.rejects(()=>simulate([Buffer.from(event('ok')+'\n')],{callback:()=>{throw Error('callback quebrou');}}),e=>e.message==='callback quebrou' && e.partialOutput===true);
await assert.rejects(()=>simulate([Buffer.from(event('ok')+'\n'+'data: {invalid}\n')]),e=>/JSON inválido/.test(e.message) && e.partialOutput===true);
const originalReq=https.request;
https.request=(_opts,onResponse)=>{const req=new EventEmitter();req.setTimeout=()=>{};req.write=()=>{};req.destroy=()=>{};req.end=()=>queueMicrotask(()=>{const res=new EventEmitter();res.statusCode=200;onResponse(res);res.emit('data',Buffer.from('data: '+JSON.stringify({response:{candidates:[{content:{parts:[{thought:true,text:'pensamento'},{text:'resposta'},{functionCall:{name:'abrir',args:{x:1},id:'c1',thoughtSignature:'sig'}}]},finishReason:'STOP'}]}})));res.emit('end');});return req;};
try {const events=[];const rich=await streamGenerateContent('token','project','model',[],null,null,(...args)=>events.push(args));assert.equal(rich.replyText,'resposta');assert.equal(rich.toolCalls[0].name,'abrir');assert.equal(rich.toolCalls[0].thoughtSignature,'sig');assert.equal(events[0][2].thinking,true);assert.equal(events[1][0],'resposta');assert.equal(events[2][1].id,'c1');}finally{https.request=originalReq;}
await assert.rejects(()=>simulate([Buffer.from(event('ok')+'\n')],{disconnect:'aborted'}),e=>/interrompida/.test(e.message) && e.partialOutput===true);
await assert.rejects(()=>simulate([Buffer.from(event('ok')+'\n')],{disconnect:'error'}),e=>e.message==='corte' && e.partialOutput===true);
await assert.rejects(()=>simulate([],{reqError:new Error('falha inicial')}),e=>e.message==='falha inicial' && !e.partialOutput);
const controller=new AbortController();controller.abort();const cancelled=await simulate([],{signal:controller.signal});assert.equal(cancelled.aborted,true);
console.log('Streaming Google: UTF-8, linha residual e falhas passaram.');
