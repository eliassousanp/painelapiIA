import assert from 'node:assert/strict';import {createVideoStreamFilter} from './src/video-stream-filter.mjs';
const f=createVideoStreamFilter();let out='';for(const s of ['const x=1;<thi','nking type="x">secret</think','ing>\nconst y=2;'])out+=f(s);out+=f('',true);assert.equal(out,'const x=1;\nconst y=2;');
const g=createVideoStreamFilter();assert.equal(g('<reasoning>secret',true),'');
const h=createVideoStreamFilter();assert.equal(h('a < b && c <thing;',true),'a < b && c <thing;');
const i=createVideoStreamFilter();assert.equal(i('<thought>x</thought><antThinking>y</antThinking>ok',true),'ok');
console.log('Filtro de vídeo em streaming: passou.');
// Qualquer separação de pacote precisa produzir a mesma saída, sem colar tokens.
const input='<thinking\n type="x">oculto</thinking>const a = "ação";\n<thought>oculto</thought>export { a };';
const expected='const a = "ação";\nexport { a };';
for(let cut=0;cut<=input.length;cut++) {
  const filter=createVideoStreamFilter();
  assert.equal(filter(input.slice(0,cut))+filter(input.slice(cut))+filter('',true),expected,`corte ${cut}`);
}
const single=createVideoStreamFilter();
assert.equal([...input].map(c=>single(c)).join('')+single('',true),expected);
assert.equal(createVideoStreamFilter()('const x = 1; <thinking>incompleto',true),'const x = 1; ');
console.log('Fragmentação em todos os pontos e espaços de código: passou.');
