// A equipe só é acionada quando o cliente pede explicitamente o modo de produção.
export async function produzirEmEquipe({pedido, executar, emitir=()=>{}, signal, anteriores={}}) {
  if (!Array.isArray(pedido) || !pedido.length || typeof executar !== 'function') throw new Error('Pedido da equipe de vídeo inválido.');
  const etapas=['historia','personagens','cenario','direcao','montagem'];
  if (!anteriores || typeof anteriores!=='object' || Array.isArray(anteriores) || Object.entries(anteriores).some(([k,v])=>!etapas.includes(k) || typeof v!=='string' || !v.trim() || v.length>250000)) throw new Error('Etapas anteriores inválidas.');
  if (Object.keys(anteriores).some(k=>k!=='historia') && !anteriores.historia) throw new Error('A história anterior é necessária para retomar as outras etapas.');
  if (anteriores.montagem && !['personagens','cenario','direcao'].every(k=>anteriores[k])) throw new Error('A montagem anterior exige todas as etapas anteriores.');
  const resultados={...anteriores}; let concluidas=Object.keys(resultados).length;
  const evento=(etapa,estado,extra={})=>emitir({etapa,estado,concluidas,total:etapas.length,...extra});
  const trabalhar=async (etapa,instrucao,contexto) => {
    if(signal?.aborted)throw new Error('Produção cancelada.');
    if(resultados[etapa]) {evento(etapa,'recuperada');return resultados[etapa];}
    evento(etapa,'iniciada');
    try {
      let tamanho=0;
      const texto=await executar({etapa,instrucao,pedido,contexto,signal,onChunk:chunk=>{tamanho+=chunk.length;evento(etapa,'gerando',{caracteres:tamanho,trecho:chunk});}});
      if(typeof texto!=='string' || !texto.trim())throw new Error(`A etapa ${etapa} não retornou conteúdo.`);
      resultados[etapa]=texto;concluidas++;evento(etapa,'concluida',{caracteres:texto.length,resultado:texto});return texto;
    } catch(e) {evento(etapa,'erro',{mensagem:String(e.message).slice(0,300)});throw e;}
  };
  const historia=await trabalhar('historia','Crie a história e um plano compartilhado de cenas, continuidade, duração, tom e personagens. Este é um plano interno: registre decisões necessárias sem repetir o pedido nem fazer longas explicações. Não escreva código nesta etapa. Respeite o pedido e o formato desejado pelo cliente.',{});
  const [personagens,cenario,direcao]=await Promise.all([
    trabalhar('personagens','Planeje personagens e elementos próprios consistentes com a história, incluindo aparência, proporções, expressões e ações. Entregue somente decisões úteis à montagem, sem repetir a história. Use as capacidades e o kit fornecidos no pedido. Não invente arquivos ou recursos.',{historia}),
    trabalhar('cenario','Planeje cenários e objetos necessários em cada cena, sua aparência, tamanho, posição e continuidade. Entregue somente decisões úteis à montagem, sem repetir a história. Use as capacidades e o kit fornecidos no pedido. Não invente arquivos ou recursos.',{historia}),
    trabalhar('direcao','Planeje câmera, enquadramento, luz, tempo, som e ações visíveis em cada cena, observando a história e os recursos disponíveis. Entregue somente decisões úteis à montagem, sem repetir a história.',{historia}),
  ]);
  const final=await trabalhar('montagem','Integre os trabalhos anteriores. Responda exatamente no formato solicitado pelo cliente; se pedir código, devolva o código completo compatível com o motor e o kit fornecidos. Confira continuidade, enquadramento e limites. Não alegue ter renderizado ou revisado quadros que não recebeu.',{historia,personagens,cenario,direcao});
  return {resultado:final,etapas:resultados};
}
