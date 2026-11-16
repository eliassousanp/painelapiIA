# TASK — Enquadramento do diretor de vídeo

**Objetivo (por enquanto, só isto):** corrigir o erro de enquadramento do `/v1/video`
— o personagem principal saindo de quadro / ficando atrás de elemento do cenário —
afinando o **diretor** (`serverapi/src/video-diretor.md`) e a **equipe**
(`serverapi/src/video-equipe.mjs`). Fora de escopo agora: propagar pros outros
motores do original (`C:\ai-lab\hub`).

**Restrições:** não subir o gateway local (reconecta WhatsApp de produção).
Não ler/expor `.env`, chaves ou segredos. Deploy na VPS é pelo caminho sancionado
(pasta `brlove.online` → `git pull` → `pm2 restart src`).

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

- [ ] **T7 — Deploy na VPS** — ⚠️ depende do usuário
      `brlove.online` → `git pull` → `pm2 restart src`.
      Bloqueado aqui: acesso ao Bitvise foi negado e, mesmo liberado, o
      computer-use não deixa digitar em terminal (tier "click").

- [ ] **T8 — Teste de render + análise** — depende do T7
      Gerar vídeo ("A Caixa de Ferro"), conferir se o personagem fica enquadrado
      e com linha de visão livre. Se bom, não mexe; se ruim, volta e ensina o diretor.

---

## Log

- _(início)_ Task criada.
- T1–T5 feitos. Enquadramento virou exigência de código no diretor e na equipe.
- T6: commit + push pra `origin/main`.
- T7/T8 aguardando: deploy na VPS (precisa de você) e render de teste.
