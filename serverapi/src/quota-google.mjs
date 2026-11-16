// Não estima tokens: guarda os indicadores que a resposta do Google fornecer.
export function mergeGoogleQuotaBuckets(catalog, response, now = new Date().toISOString()) {
  if (!response || !Array.isArray(response.buckets)) throw new Error('Resposta de cotas do Google inválida.');
  const result=structuredClone(catalog && typeof catalog==='object' ? catalog : {});
  if (!result.models || typeof result.models!=='object' || Array.isArray(result.models)) result.models={};
  const byModel=new Map();
  for(const bucket of response.buckets) {
    if(!bucket || typeof bucket.modelId!=='string' || !bucket.modelId.trim())continue;
    const fraction=typeof bucket.remainingFraction==='number' && Number.isFinite(bucket.remainingFraction) && bucket.remainingFraction>=0 && bucket.remainingFraction<=1 ? bucket.remainingFraction : null;
    if(fraction===null)continue;
    const entry={remainingFraction:fraction,tokenType:typeof bucket.tokenType==='string'?bucket.tokenType:null,resetTime:typeof bucket.resetTime==='string'?bucket.resetTime:null};
    if(typeof bucket.remainingAmount==='string' || typeof bucket.remainingAmount==='number')entry.remainingAmount=String(bucket.remainingAmount);
    const list=byModel.get(bucket.modelId) || [];list.push(entry);byModel.set(bucket.modelId,list);
  }
  for(const [id,buckets] of byModel) {
    const model=result.models[id] || {displayName:id};const q=model.quotaInfo && typeof model.quotaInfo==='object' ? model.quotaInfo : {};
    // Se o serviço retorna mais de uma janela, usar a mais restritiva e manter todas visíveis.
    const strictest=buckets.reduce((a,b)=>b.remainingFraction<a.remainingFraction?b:a);
    model.quotaInfo={...q,remainingFraction:strictest.remainingFraction,resetTime:strictest.resetTime,tokenType:strictest.tokenType,buckets,quotaSource:'retrieveUserQuota',checkedAt:now};
    result.models[id]=model;
  }
  result.quotaBucketsCheckedAt=now;
  return {catalog:result,modelsWithQuota:byModel.size};
}
