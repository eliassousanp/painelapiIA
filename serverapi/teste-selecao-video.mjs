import assert from 'node:assert/strict';import {selecionarModeloVideo} from './src/roteamento-equipe.mjs';
const mock=rows=>({query:async()=>[rows]});
assert.equal(await selecionarModeloVideo(mock([{model_key:'configurado'}]),'outro'),'configurado');
await assert.rejects(()=>selecionarModeloVideo(mock([]),'modelo-real'),e=>e.code==='VIDEO_MODEL_NOT_CONFIGURED');
await assert.rejects(()=>selecionarModeloVideo(mock([]),'video'),e=>e.code==='VIDEO_MODEL_NOT_CONFIGURED');
await assert.rejects(()=>selecionarModeloVideo(mock([]),''),e=>e.code==='VIDEO_MODEL_NOT_CONFIGURED');
const semColuna={query:async()=>{const e=Error('coluna');e.code='ER_BAD_FIELD_ERROR';throw e;}};
await assert.rejects(()=>selecionarModeloVideo(semColuna,'modelo-real'),e=>e.code==='VIDEO_MODEL_NOT_CONFIGURED');
await assert.rejects(()=>selecionarModeloVideo(semColuna,'video'),e=>e.code==='VIDEO_MODEL_NOT_CONFIGURED');
console.log('Seleção do modelo de vídeo: passou.');
const {tentarRotas}=await import('./src/roteamento-equipe.mjs');
const rotas=[{model:'principal',providerId:2},{model:'reserva',providerId:3}];
let tentadas=[];
const troca=await tentarRotas(rotas,async route=>{
  tentadas.push(route.model);
  if(route.model==='principal'){const error=Error('Cota esgotada');error.status=429;throw error;}
  return {replyText:'resposta completa'};
});
assert.deepEqual(tentadas,['principal','reserva']);assert.equal(troca.route.model,'reserva');
tentadas=[];
await assert.rejects(()=>tentarRotas(rotas,async route=>{
  tentadas.push(route.model);const error=Error('Cota esgotada após conteúdo parcial');error.status=429;error.partialOutput=true;throw error;
}),e=>e.partialOutput===true);
assert.deepEqual(tentadas,['principal'],'Não misturar código parcial de modelos diferentes');
await assert.rejects(()=>tentarRotas(rotas,async()=>{const error=Error('Cota esgotada');error.status=429;throw error;}),/Cota esgotada/);
console.log('Vídeo: troca por cota, esgotamento das opções e proteção de resposta parcial passaram.');
