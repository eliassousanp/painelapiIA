// Código parcial pode compilar; o término precisa ser confirmado pelo provedor.
export function validarTerminoVideo(reason, {allowLength=false, hasToolCalls=false}={}) {
  if (hasToolCalls || ['STOP','stop','end_turn'].includes(reason)) return;
  if (allowLength && ['MAX_TOKENS','max_tokens','length'].includes(reason)) return;
  const blocked=['SAFETY','RECITATION','content_filter'].includes(reason);
  const error=new Error(blocked
    ? 'O provedor interrompeu a geração de vídeo por filtro de conteúdo. A resposta não está aprovada.'
    : 'O provedor não confirmou uma resposta de vídeo completa. Preserve as etapas anteriores e revise a etapa interrompida.');
  error.code=blocked ? 'VIDEO_CONTENT_FILTERED' : 'VIDEO_INCOMPLETE_RESPONSE';
  throw error;
}
