// A equipe só é acionada quando o cliente pede explicitamente o modo de produção.
export async function produzirEmEquipe({pedido, executar, emitir=()=>{}, signal}) {
  if (!Array.isArray(pedido) || !pedido.length || typeof executar !== 'function') throw new Error('Pedido da equipe de vídeo inválido.');
  const etapas=['historia','personagens','cenario','direcao','montagem'];
  const resultados={}; let concluidas=0;
  const evento=(etapa,estado,extra={})=>emitir({etapa,estado,concluidas,total:etapas.length,...extra});
  const trabalhar=async (etapa,instrucao,contexto) => {
    if(signal?.aborted)throw new Error('Produção cancelada.');
    evento(etapa,'iniciada');
    try {
      let tamanho=0;
      const texto=await executar({etapa,instrucao,pedido,contexto,signal,onChunk:chunk=>{tamanho+=chunk.length;evento(etapa,'gerando',{caracteres:tamanho,trecho:chunk});}});
      if(typeof texto!=='string' || !texto.trim())throw new Error(`A etapa ${etapa} não retornou conteúdo.`);
      resultados[etapa]=texto;concluidas++;evento(etapa,'concluida',{caracteres:texto.length});return texto;
    } catch(e) {evento(etapa,'erro',{mensagem:String(e.message).slice(0,300)});throw e;}
  };
  const historia=await trabalhar('historia','Crie a história e um plano compartilhado de cenas, continuidade, duração, tom e personagens. Não escreva código nesta etapa. Respeite o pedido e o formato desejado pelo cliente.',{});
  const [personagens,cenario,direcao]=await Promise.all([
    trabalhar('personagens','Planeje personagens e elementos próprios consistentes com a história, incluindo aparência, proporções, expressões e ações. Use as capacidades e o kit fornecidos no pedido. Não invente arquivos ou recursos.',{historia}),
    trabalhar('cenario','Planeje cenários e objetos necessários em cada cena, sua aparência, tamanho, posição e continuidade. Use as capacidades e o kit fornecidos no pedido. Não invente arquivos ou recursos.',{historia}),
    trabalhar('direcao','Planeje câmera, enquadramento, luz, tempo, som e ações visíveis em cada cena, observando a história e os recursos disponíveis.',{historia}),
  ]);
  const final=await trabalhar('montagem','Integre os trabalhos anteriores. Responda exatamente no formato solicitado pelo cliente; se pedir código, devolva o código completo compatível com o motor e o kit fornecidos. Confira continuidade, enquadramento e limites. Não alegue ter renderizado ou revisado quadros que não recebeu.',{historia,personagens,cenario,direcao});
  return {resultado:final,etapas:resultados};
}
