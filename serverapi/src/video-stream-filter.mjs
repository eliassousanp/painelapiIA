// Remove blocos de raciocínio de uma resposta de vídeo sem atrasar o código útil.
const nomes=['thinking','thought','reasoning','antthinking'];
export function createVideoStreamFilter() {
  let buffer='', oculto='';
  return (chunk='', fim=false)=>{
    buffer+=chunk;let saida='';
    while(buffer) {
      if(oculto) {
        const fechamento=new RegExp(`</\\s*${oculto}\\s*>`,'i');const m=fechamento.exec(buffer);
        if(m) {buffer=buffer.slice(m.index+m[0].length);oculto='';continue;}
        if(fim){buffer='';break;}
        const pos=buffer.lastIndexOf('<');buffer=pos<0?'':buffer.slice(pos);break;
      }
      const pos=buffer.indexOf('<');if(pos<0){saida+=buffer;buffer='';break;}
      saida+=buffer.slice(0,pos);buffer=buffer.slice(pos);
      const match=/^<\s*(thinking|thought|reasoning|antthinking)(?:\s[^>]*)?>/i.exec(buffer);
      if(match){oculto=match[1].toLowerCase();buffer=buffer.slice(match[0].length);continue;}
      const baixo=buffer.toLowerCase().replace(/^<\s*/, '<');
      const candidato=nomes.some(n=>('<'+n).startsWith(baixo) || new RegExp('^<'+n+'\\s').test(baixo));
      if(candidato && !fim)break;
      saida+='<';buffer=buffer.slice(1);
    }
    return saida;
  };
}
