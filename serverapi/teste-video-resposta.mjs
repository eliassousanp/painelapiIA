import assert from 'node:assert/strict';
import {validarTerminoVideo} from './src/video-resposta.mjs';
for(const reason of ['STOP','stop','end_turn']) assert.doesNotThrow(()=>validarTerminoVideo(reason));
for(const reason of ['MAX_TOKENS','max_tokens','length']) {
  assert.doesNotThrow(()=>validarTerminoVideo(reason,{allowLength:true}));
  assert.throws(()=>validarTerminoVideo(reason),{code:'VIDEO_INCOMPLETE_RESPONSE'});
}
for(const reason of ['SAFETY','RECITATION','content_filter']) assert.throws(()=>validarTerminoVideo(reason,{allowLength:true}),{code:'VIDEO_CONTENT_FILTERED'});
for(const reason of [undefined,null,'','OTHER','tool_calls']) assert.throws(()=>validarTerminoVideo(reason),{code:'VIDEO_INCOMPLETE_RESPONSE'});
assert.doesNotThrow(()=>validarTerminoVideo('tool_calls',{hasToolCalls:true}));
console.log('Vídeo: término, truncamento, bloqueio e resposta incompleta verificados.');
