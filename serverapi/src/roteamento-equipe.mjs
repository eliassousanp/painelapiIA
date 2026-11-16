// A ordem parte da complexidade escolhida e tenta as alternativas configuradas.
export async function selecionarModeloVideo(pool,requested) {
  try {const [rows]=await pool.query("SELECT model_key FROM models WHERE is_video=1 AND status='active' ORDER BY id LIMIT 1");return rows[0]?.model_key || requested;}
  catch(error) {if(error.code==='ER_BAD_FIELD_ERROR')return requested;throw error;}
}

export function ordenarRotas(routes,complexity) {
  const order={heavy:['heavy','medium','fast'],medium:['medium','fast','heavy'],fast:['fast','medium','heavy']}[complexity] || ['medium','fast','heavy'];
  const seen=new Set();return order.map(key=>routes[key]).filter(route=>{
    if(!route?.model)return false;
    const id=`${route.providerId}:${route.model}`;if(seen.has(id))return false;seen.add(id);return true;
  });
}

export async function tentarRotas(routes,execute,{onFallback=()=>{},signal}={}) {
  if(!routes.length)throw new Error('Nenhum modelo configurado para o roteamento inteligente.');
  let last;
  for(const [index,route] of routes.entries()) {
    if(signal?.aborted)throw new Error('Solicitação cancelada.');
    try {return {...await execute(route),route};}
    catch(error) {
      last=error;
      if(error?.partialOutput || signal?.aborted)throw error;
      const status=error?.status || error?.statusCode || Number(String(error?.message || '').match(/(?:HTTP|Status)\s+(\d{3})/i)?.[1]);
      const retryable=[401,402,403,404,408,429,500,502,503,504].includes(status) || /quota|cota|limite|disponível|nenhuma conta|nenhuma chave|provedor.*não identificado|inativ|timeout|timed out|fetch failed|econn|conexão|network/i.test(String(error?.message || ''));
      if(!retryable || index===routes.length-1)throw error;
      onFallback(route,error,routes[index+1]);
    }
  }
  throw last;
}
