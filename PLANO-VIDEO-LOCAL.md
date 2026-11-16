# Plano: melhorar /v1/video da BR Love local

## Objetivo
Receber o pedido do painel e as instruções do motor escolhido, gerar história e código válido, informar o progresso e permitir correção localizada sem perder etapas aprovadas. Primeiro validar com o motor que produziu o vídeo da Bia; adaptar os demais motores depois.

## 1. Modelo e roteamento
- A rota `/v1/video` usa exclusivamente o modelo de vídeo ativo configurado no painel da BR Love. Se nenhum estiver ativo, retornar erro claro. A configuração de Vídeo pode usar modelo fixo ou roteamento inteligente conforme escolha do usuário; a API ignora o modelo arbitrário do pedido e só tenta modelos/provedores cadastrados nessa configuração. Ao esgotar a cota, tenta a próxima opção configurada se ainda não houve saída parcial.
- Registrar modelo selecionado, provedor, tentativa, fallback e tempos, sem gravar chaves.
- Testar modelo válido, ausente, inativo, erros e fallback sem conteúdo parcial.

## 2. Contrato do pedido e streaming
- Receber pedido, duração, formato, kit, interfaces e etapa desejada; responder no formato da etapa.
- Transmitir conteúdo em SSE compatível com OpenAI e separar sinal de espera de conteúdo gerado.
- Filtrar raciocínio marcado entre blocos, inclusive com marcas divididas entre pacotes; não afirmar progresso de geração quando só existe heartbeat.
- Informar erro específico, terminar a transmissão corretamente e não reiniciar automaticamente depois de emitir conteúdo parcial.

## 3. Regras gerais e específicas de motor
- Manter identidade, escala e continuidade. Garantir rosto legível, ordem das camadas, contraste, enquadramento com margem, linha de visão e objetos necessários.
- Para cada motor usar apenas instruções, recursos e contratos fornecidos. Não presumir as mesmas funções para todos.
- Diferenciar plano de cena, código entregue e resultado realmente examinado.

## 4. Correção incremental
- Preservar roteiro, narração, arquivos e partes aprovadas; em falha, registrar etapa, código e causa.
- A revisão deve referenciar instantes/quadros e defeitos objetivos. Identificar arquivos afetados e alterar somente esses; se a causa for incerta ou houver dependências, preservar o restante e revisar o conjunto necessário.
- Não retomar código reconhecidamente inválido. Validar sintaxe, interfaces e imports antes de executar.
- Limitar tentativas e registrar motivo de cada uma; não reiniciar automaticamente por falhas de ambiente.

## 5. Prévia e revisão
- Capturar quadros representativos antes do render longo; incluir cenas diferentes, momentos de ação e enquadramento. Usar sequências para julgar movimento e sincronização quando necessário.
- Revisar quadro e relatório, corrigir o trecho afetado e recapturar as cenas alteradas. A prévia não substitui a revisão do vídeo final e das legendas/cartelas.
- Avaliar custo e duração reais; nunca afirmar que a cena está pronta sem teste e revisão.

## 6. Verificação local e entrega
- Testar /v1/video com chave e provedor locais; não usar Docker nem VPS nessa fase.
- Executar testes unitários e teste real ponta a ponta: pedido, roteiro, código, prévia, correção, vídeo em 540x960 para teste e revisão; depois repetir em HD.
- Configuração de API no painel: endereço, chave no banco, modelo e rota BR Love ou OpenAI compatível. Vertical é padrão; escolha de formato/resolução no painel fica para etapa posterior.
- Antes de atualizar a VPS, informar arquivos alterados, resultado dos testes e necessidade de atualização/reinício; não enviar nem reiniciar sem deixar o resultado claro.

## Evidências já observadas
- O banco local tinha modelos ativos, mas nenhum marcado como vídeo; `model=video` levou a fallback após 404.
- Uma resposta em streaming trouxe raciocínio e código começando no meio de expressão.
- A prévia detectou rosto coberto por cabelo, personagem cortado e objeto ausente. Uma correção diagnóstica enquadrou e mostrou o rosto, mas ainda havia defeitos.
- O último teste completo terminou sem vídeo. A API local recebeu ajustes de instruções e filtro de streaming; ainda é necessário validar novamente o fluxo ponta a ponta.

## Rodada de execução — 16/11/2026

### Diagnóstico confirmado
- A última correção de câmera produziu cinco JPGs em `painel-preparacao/dados-teste-local/correcao-camera-api/quadros`: rosto e corpo visíveis, porém retângulo escuro sob os pés e ausência de comprovação de todos os planos. Não há aprovação visual final.
- A API convertia término desconhecido em `stop`; respostas bloqueadas por filtro podiam seguir como etapa concluída.
- Provedores externos acumulavam todo o vídeo antes de enviar o conteúdo; modo equipe transmitia trechos sem filtrar marcadores de raciocínio.
- Falha em uma etapa paralela não devolvia um conjunto completo de etapas reutilizáveis no evento de erro. Não existia invalidação explícita da montagem quando uma etapa anterior precisava mudar.
- O diretor exigia recuar a câmera “até caber”, sem limite ou garantia de não atravessar paredes; também exigia corpo inteiro em conflito com close-ups.

### Plano e critérios de aceite
1. **Integridade da resposta:** exigir término conhecido na rota de vídeo. `length` continua explícito para o cliente; bloqueio, ausência de término e motivo desconhecido não viram sucesso. Testar STOP, length, bloqueio e EOF sem motivo.
2. **Streaming e cancelamento:** mesmo filtro para todas as saídas de vídeo; preservar espaços nas continuações. Cancelamento não abre segunda conexão de fallback. Verificar fragmentação dos marcadores e fluxo completo via HTTP local.
3. **Retomada incremental:** devolver `etapas` concluídas no erro de equipe; aguardar as etapas independentes já em execução, reaproveitando seus resultados. `video_team_refazer` invalida a etapa indicada e a montagem dependente; refazer história invalida tudo. Testar falha parcial, cancelamento e retomada sem gerar novamente etapas aprovadas. O cliente continua responsável por persistir checkpoints e associá-los ao pedido/kit corretos.
4. **Direção universal:** ajustar instruções de câmera com tentativas limitadas, determinismo, limites físicos, close-ups e revisão por evidência. Nenhum prompt substitui validação de código e inspeção de quadros.
5. **Validação local:** executar testes unitários, sintaxe e requisições reais com o modelo de vídeo configurado e chave local sem imprimi-la. Registrar tempos e resultados. Manter correção de cena separada de mudanças no painel oficial.
6. **Próxima validação visual:** recapturar planos alterados em 540x960 e verificar cenário, sombra, enquadramento e ação; renderizar MP4 após aprovação dos quadros. Repetir com ao menos três situações (interior, exterior e explicação com objetos/textos) e dois motores, usando kits reais. Não declarar qualidade universal por um vídeo ou cinco quadros.

### Contrato de retomada da equipe
- `video_team_resume`: objeto de resultados anteriores, preservado sem mutação.
- `video_team_refazer`: lista opcional entre `historia`, `personagens`, `cenario`, `direcao`, `montagem`.
- Eventos `concluida`/`erro` incluem `duracao_ms`; evento final de erro inclui as `etapas` aproveitáveis, sem considerar conteúdo parcial como concluído.
- Se uma etapa independente falhar, as demais já iniciadas podem terminar; a montagem não começa. Desconectar cancela as chamadas em andamento.

### Limites desta rodada
A API gera planos e código. Ela não executa por si só os kits nem recebe automaticamente todos os quadros: validação sintática, montagem, captura e revisão continuam no consumidor. A universalidade visual exige testes reais por motor. Não publicar na VPS nesta rodada.

### Ajuste de escopo confirmado pelo usuário nesta rodada
A configuração de **Vídeo** é a referência permanente, mas os modelos internos podem mudar. Respeitar modo fixo OU roteamento inteligente; reler a configuração em cada nova requisição. No roteamento, permitir fallback por cota/indisponibilidade somente para as alternativas cadastradas; não usar um modelo padrão não configurado. Uma produção já em andamento mantém a configuração lida no começo daquela requisição. Conteúdo parcial não pode ser concatenado ao começo de outro modelo: emitir erro identificável, manter etapas concluídas e permitir retomada/correção da parte interrompida.

### Resultado da execução (a atualizar após o teste final)
- Implementados: término explícito; streaming filtrado também em provedores externos e equipe; cancelamento respeitado no fallback de conexão; preservação de espaços em continuação; decodificação UTF-8 e propagação de erros do streaming Google.
- Implementados: checkpoints retornados no erro da equipe, retomada seletiva com invalidação de dependentes e tempos por etapa.
- Implementados: instruções de câmera determinística/limitada, close-ups, oclusão e revisão por evidência; correção do exemplo de 60,5s a 30fps para 1815 quadros.
- Implementados: leitura atual da configuração Vídeo, modo fixo e roteamento configurado, metadados do modelo efetivo e teste de troca por cota.
- Teste HTTP real inicial na porta 3018: Gemini configurado, código válido com streaming (3,7s; primeiro conteúdo 3,6s) e sem streaming (14,8s). Esse teste é de transporte/configuração/sintaxe, não mede qualidade ou velocidade de um vídeo completo.
- Pendente: nova revisão visual e render MP4; matriz de cenários e motores reais. As imagens revisadas nesta rodada são da geração anterior. Não foi feito deploy na VPS nem alterado o painel oficial.
