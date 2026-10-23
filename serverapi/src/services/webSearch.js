import axios from 'axios';

/**
 * Detecta se a mensagem do usuário requer busca na internet em tempo real
 */
export function shouldSearchWeb(text) {
  if (!text || typeof text !== 'string') return false;
  const lower = text.toLowerCase().trim();

  // Saudações e conversa cotidiana não devem disparar busca web
  const conversational = /^(ol[aá]|oi|bom dia|boa tarde|boa noite|tudo bem|como vai|como voc[eê] est[aá]|quem [eé] voc[eê]|o que voc[eê] faz|valeu|obrigad[oa]|tchau|at[eé] logo|teste|al[oô])/i;
  if (conversational.test(lower) && lower.split(/\s+/).length <= 7) {
    return false;
  }

  // Padrões de busca por atualidades, fatos em tempo real, cotações, etc.
  const searchTriggers = [
    /\b(not[ií]cias?|tempo|clima|previs[aã]o|temperatura)\b/i,
    /\b(cota[cç][aã]o|d[oó]lar|euro|bitcoin|ibovespa|a[cç][oõ]es)\b/i,
    /\b(quem ganhou|quem venceu|resultado|jogo de|campeonato|partida|placar)\b/i,
    /\b(quem [eé] o atual|lan[cç]amento|pre[cç]o de)\b/i,
    /\b(pesquise|busca|procure na (?:internet|web))\b/i,
    /\b(aconteceu hoje|not[ií]cia de hoje|tempo hoje|dolar hoje)\b/i
  ];

  return searchTriggers.some(rgx => rgx.test(lower));
}

/**
 * Realiza uma busca web rápida via DuckDuckGo Lite
 * @param {string} query Termo de busca
 * @param {number} maxResults Quantidade máxima de resultados
 * @returns {Promise<Array<{title: string, snippet: string, url: string}>>}
 */
export async function searchWeb(query, maxResults = 4) {
  if (!query || typeof query !== 'string') return [];

  const cleanQuery = query.replace(/[^\w\s\u00C0-\u00FF\-\+\.]/gi, ' ').trim();
  if (!cleanQuery) return [];

  try {
    const postData = 'q=' + encodeURIComponent(cleanQuery) + '&kl=br-pt';
    const resp = await axios.post('https://lite.duckduckgo.com/lite/', postData, {
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'text/html,application/xhtml+xml',
        'Accept-Language': 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7'
      },
      timeout: 5000
    });

    const html = String(resp.data || '');
    const results = [];

    // Extração flexível de títulos e links
    const titleRegex = /<a[^>]*class=['"]result-link['"][^>]*href=['"]([^'"]*)['"][^>]*>([\s\S]*?)<\/a>/gi;
    const titles = [];
    let tMatch;
    while ((tMatch = titleRegex.exec(html)) !== null) {
      titles.push({
        url: tMatch[1] || '',
        title: tMatch[2].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim()
      });
    }

    // Extração flexível de snippets
    const snippetRegex = /<td[^>]*class=['"]result-snippet['"][^>]*>([\s\S]*?)<\/td>/gi;
    const snippets = [];
    let sMatch;
    while ((sMatch = snippetRegex.exec(html)) !== null) {
      const clean = sMatch[1].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
      if (clean && clean.length > 10) {
        snippets.push(clean);
      }
    }

    const count = Math.min(snippets.length, maxResults);
    for (let i = 0; i < count; i++) {
      results.push({
        title: titles[i]?.title || 'Informação da Web',
        snippet: snippets[i],
        url: titles[i]?.url || ''
      });
    }

    return results;
  } catch (err) {
    console.warn('[WebSearch Error]', err.message || err);
    return [];
  }
}

/**
 * Formata os resultados da busca web para inclusão no System Instruction
 */
export function formatSearchResultsForPrompt(results, query) {
  if (!results || results.length === 0) return '';

  const now = new Date();
  const dateStr = now.toLocaleDateString('pt-BR', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric'
  });
  const timeStr = now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

  let text = `\n\n=== INFORMAÇÕES PESQUISADAS NA WEB EM TEMPO REAL ===\n`;
  text += `Data e Hora Oficial: ${dateStr} às ${timeStr} (Horário de Brasília)\n`;
  text += `Termo Pesquisado: "${query}"\n`;
  text += `Resultados Verificados na Internet:\n`;

  results.forEach((r, i) => {
    text += `[${i + 1}] ${r.title}\n`;
    text += `    Resumo: ${r.snippet}\n`;
    if (r.url) text += `    Link: ${r.url}\n`;
  });

  text += `\nINSTRUÇÃO DE RESPOSTA:\n`;
  text += `- Use as informações pesquisadas acima para responder com total precisão e atualidade.\n`;
  text += `- Se a resposta for falada por voz neural, seja conciso, empático e direto ao ponto (1 a 3 frases claras), sem ler links ou URLs em voz alta.`;
  text += `\n====================================================\n`;

  return text;
}
