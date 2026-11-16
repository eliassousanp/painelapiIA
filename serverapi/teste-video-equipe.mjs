import assert from 'node:assert/strict';
import {produzirEmEquipe} from './src/video-equipe.mjs';
const pedido=[{role:'user',content:'Teste de produção'}];
const previous={historia:'história',personagens:'personagem',cenario:'cenário',direcao:'direção',montagem:'montagem antiga'};
let calls=[];
const executar=async ({etapa})=>{calls.push(etapa);return etapa+' nova';};
const repaired=await produzirEmEquipe({pedido,anteriores:previous,refazer:['cenario'],executar});
assert.deepEqual(calls,['cenario','montagem']);
assert.equal(repaired.etapas.personagens,previous.personagens);
assert.equal(repaired.etapas.montagem,'montagem nova');
assert.equal(previous.montagem,'montagem antiga');
calls=[];
await produzirEmEquipe({pedido,anteriores:previous,refazer:['historia'],executar});
assert.equal(calls.length,5);
assert.equal(calls[0],'historia');assert.equal(calls.at(-1),'montagem');

const events=[];let partial;
try {
  await produzirEmEquipe({pedido,emitir:e=>events.push(e),executar:async ({etapa,onChunk})=>{
    if(etapa==='cenario')throw new Error('recurso indisponível');
    if(etapa==='direcao')await new Promise(r=>setTimeout(r,15));
    onChunk('conteúdo');return etapa;
  }});
  assert.fail('Deveria falhar');
} catch(error) {assert.match(error.message,/recurso indisponível/);partial=error.etapas;}
assert.deepEqual(Object.keys(partial).sort(),['direcao','historia','personagens']);
assert.ok(!events.some(e=>e.etapa==='montagem'));
assert.ok(events.filter(e=>['concluida','erro'].includes(e.estado)).every(e=>e.duracao_ms>=0));
calls=[];
await produzirEmEquipe({pedido,anteriores:partial,executar});
assert.deepEqual(calls,['cenario','montagem']);

const controller=new AbortController();
await assert.rejects(()=>produzirEmEquipe({pedido,signal:controller.signal,executar:async ()=>{controller.abort();return 'resposta tardia';}}),e=>/cancelada/.test(e.message) && Object.keys(e.etapas).length===0);
await assert.rejects(()=>produzirEmEquipe({pedido,refazer:['desconhecida'],executar}),/inválidas/);
await assert.rejects(()=>produzirEmEquipe({pedido,anteriores:{cenario:'sem história'},executar}),/história anterior/);
console.log('Equipe: preservação parcial, retomada, invalidação dependente, tempos e cancelamento verificados.');
