# TASK — Enquadramento do diretor de vídeo

**Objetivo (por enquanto, só isto):** corrigir o erro de enquadramento do `/v1/video`
— o personagem principal saindo de quadro / ficando atrás de elemento do cenário —
afinando o **diretor** (`serverapi/src/video-diretor.md`) e a **equipe**
(`serverapi/src/video-equipe.mjs`). Fora de escopo agora: propagar pros outros
motores do original (`C:\ai-lab\hub`).

**Restrições:** Não ler/expor `.env`, chaves ou segredos. Deploy na VPS é pelo
caminho sancionado (pasta `brlove.online` → `git pull` → `pm2 restart src`).
_(Atualizado: você liberou subir o gateway local p/ teste — e confirmou-se que o
local NÃO tem sessão WhatsApp de produção: `[Baileys] Nenhuma sessão ativa`.)_

---

## Checklist

- [x] **T1 — Diagnóstico**
      Confirmado: o enquadramento era só prosa ("confira os limites visíveis"),
      sem exigir verificação no código gerado. Essa era a brecha.

- [x] **T2 — Diretor: enquadramento vira exigência de saída**
      `video-diretor.md`: a bala "Enquadre de verdade" agora exige que o código
      gerado projete o bounding box do personagem ativo na tela e reposicione a
      câmera/alvo (afastar, recuar, recentrar) até caber com folga ANTES de
      entregar — "não deixe a conferência como promessa em comentário; ela é
      código que executa".

- [x] **T3 — Diretor: linha de visão obrigatória**
      `video-diretor.md`: "Linha de visão livre (e testada)" agora manda testar no
      código o raio câmera→personagem e, se houver obstrução, afastar a câmera,
      mudar o ângulo ou tirar o objeto da frente antes de entregar.

- [x] **T4 — Equipe: alinhar `direcao` e `montagem`**
      `video-equipe.mjs`: a etapa `direcao` manda posicionar a câmera com o
      personagem inteiro no quadro e linha de visão livre; a `montagem` manda
      incluir no código a verificação de enquadramento que roda.

- [x] **T5 — Revisão**
      Diff mínimo (4 linhas de cada lado), `node --check` passou. Sem bloat, sem
      mexer no resto do prompt.

- [x] **T6 — Commit + push** (`origin/main`)

- [ ] **T7 — Deploy na VPS** — ⚠️ SÓ VOCÊ CONSEGUE (cole no Bitvise que já está aberto)
      Bloqueado pra mim: acesso ao Bitvise foi negado 2× e, mesmo liberado, o
      computer-use não deixa digitar em terminal (tier "click"). No terminal da
      VPS, rode:
      ```
      cd ~/brlove.online && git pull && pm2 restart src
      ```
      (ajuste `~/brlove.online` se a pasta estiver em outro caminho). Commit a
      puxar: `1e1e97d`. Quando terminar, me avisa que eu sigo pro T8.

- [x] **T8b — Bug de bloqueio achado e corrigido NA API (`server.js`)**
      Ao rodar o teste, o render quebrava antes mesmo do enquadramento: o modelo
      devolvia um bloco `<thinking>…</thinking>` na frente do código, então o
      `story.js` gerado começava com `<` → `SyntaxError: Unexpected token '<'`.
      Causa raiz: a rota `/v1/video` **nunca chamava** o `stripThinking()` que já
      existe no gateway (ele só limpava o chat normal). Correção mínima: ligar o
      `stripThinking` **só na saída de vídeo** (escopo por `isVideo`), sem tocar em
      `/v1/chat/completions`. `node --check` passou; gateway reiniciado. Isso é
      correção **na API brlove**, não no painel. _(Falta commitar como commit
      próprio e subir junto no deploy — ver T7.)_

- [~] **T8 — Teste de render + análise** (RODANDO LIMPO no localhost)
      Teste contra o diretor corrigido no **localhost:3000** (mesma API da VPS).
      Setup: chave local do banco + hub apontado pro localhost (com backup,
      reversível). "A Caixa de Ferro" (13 cenas): narração recuperada do cache, e
      agora o `story.js` **gera limpo** (sem `<thinking>`, sem erro de sintaxe) —
      prova de que o bug de bloqueio sumiu. Aguardando concluir roman.js/main.js →
      render mp4 → conferir enquadramento/linha de visão. Ao fim, restaurar hub.db.

---

## Log

- _(início)_ Task criada.
- T1–T5 feitos. Enquadramento virou exigência de código no diretor e na equipe.
- T6: commit `1e1e97d` enviado (`9945585..1e1e97d`, `origin/main`).
- T8b: achado o bug que travava o render — `/v1/video` não chamava `stripThinking`.
  Corrigido no `server.js` (só na saída de vídeo). Render passou a gerar código limpo.
- T7/T8 aguardando: deploy na VPS (precisa de você) e conclusão do render de teste.
