import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {ordenarRotas} from './src/roteamento-equipe.mjs';
import {createVideoStreamFilter} from './src/video-stream-filter.mjs';

// O servidor inicia sockets no import; exercitamos as funções reais sem iniciar serviços.
const source=fs.readFileSync(new URL('./src/server.js',import.meta.url),'utf8').replace(/\r\n/g,'\n');
const resolver=source.slice(source.indexOf('async function resolveModelConfig('),source.indexOf('\nasync function logUsage('));
const configured={model_key:'video',upstream_model:'modelo-fixo',provider_id:2,is_video:1,is_smart_routing:0,routing_fast_model:'outro-modelo'};
let row=configured,queries=0;
const context=vm.createContext({
  pool:{query:async()=>{queries++;return [row?[row]:[]];}},
  modelConfigCache:new Map([['video',{row:{...configured,upstream_model:'cache-antigo'},expiresAt:Date.now()+60000}]]),
  MODEL_CONFIG_CACHE_TTL:30000,getSetting:async()=>'',
  classifyRequestComplexity:text=>text==='curto'?'fast':'heavy',ordenarRotas,
});
vm.runInContext(resolver+';globalThis.resolve=resolveModelConfig;',context);
for(const text of ['curto','história longa com muitas cenas']) {
  const actual=await context.resolve('video',[{role:'user',parts:[{text}]}],{videoOnly:true});
  assert.equal(actual.targetEngine,'modelo-fixo');assert.equal(actual.providerId,2);assert.equal(actual.routeCandidates,null);
}
assert.equal(queries,2,'Vídeo deve reler a configuração atual');
row={...configured,is_smart_routing:1,routing_fast_model:'rapido',routing_fast_provider_id:2,routing_heavy_model:'detalhado',routing_heavy_provider_id:3};
const smartFast=await context.resolve('video',[{role:'user',parts:[{text:'curto'}]}],{videoOnly:true});
assert.equal(smartFast.targetEngine,'rapido');assert.equal(smartFast.routeCandidates.length,2);
const smartHeavy=await context.resolve('video',[{role:'user',parts:[{text:'longo'}]}],{videoOnly:true});
assert.equal(smartHeavy.targetEngine,'detalhado');assert.equal(smartHeavy.providerId,3);
row={...configured,is_smart_routing:1,routing_fast_model:''};
await assert.rejects(()=>context.resolve('video',[],{videoOnly:true}),/não possui modelos/);

row={...configured,upstream_model:''};await assert.rejects(()=>context.resolve('video',[],{videoOnly:true}),/upstream/);
row=null;await assert.rejects(()=>context.resolve('video',[],{videoOnly:true}),/não está mais disponível/);
row={...configured,is_video:0};await assert.rejects(()=>context.resolve('video',[],{videoOnly:true}),/não está mais disponível/);

const extract=source.slice(source.indexOf('function extractGeminiResponse('),source.indexOf('\n/**\n * Normaliza qualquer formato'));
const responseContext=vm.createContext({createVideoStreamFilter});
vm.runInContext(extract+';globalThis.extract=extractGeminiResponse;',responseContext);
const parsed=responseContext.extract({candidates:[{content:{parts:[{text:' x = 1;\n'}]}}]},{preserveWhitespace:true});
assert.equal(parsed.replyText,' x = 1;\n','Uma continuação não pode colar const + x');
console.log('Vídeo: modelo fixo, roteamento configurado, troca imediata, configuração ausente e espaços verificados.');
