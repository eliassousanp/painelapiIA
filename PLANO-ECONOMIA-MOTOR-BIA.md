# Plano: produzir vídeos com o kit da Bia gastando menos

## Objetivo
Usar a API BR Love local com o modelo de vídeo configurado para criar e corrigir vídeos do motor cartoon da Bia, preservando os arquivos úteis e medindo consumo. Fazer primeiro um curta de oito segundos a 540x960. Outros motores e opções do painel ficam para depois.

## Responsabilidades
- O cliente de produção (preparação do painel) coordena tarefas, guarda arquivos/checkpoints, executa testes, captura quadros e renderiza o MP4. A API `/v1/video` entrega orientações, roteiro e código compatível com o kit; não enxerga nem renderiza a cena automaticamente.
- O kit contém desenho, funções de animação, câmera, motor 3D e renderização; esses arquivos são mantidos localmente e versionados. O modelo recebe conteúdo nas chamadas: guardar arquivos no servidor não dá acesso automático a eles.
- O provedor usa o modelo ou roteamento configurado em Vídeo. O sistema troca somente entre opções configuradas quando a cota falha antes de emitir conteúdo; partes geradas nunca são combinadas cegamente.

## Fluxo
1. Pedido do usuário: tema, duração, público, estilo, formato, narração. Para testes, oito segundos e 540x960. Salvar pedido e identificador do kit.
2. História: chamada pequena com pedido e capacidades resumidas do motor. Retornar JSON com título, elenco, aparências, cenários, objetos, ações, tempo e resultado visual desejado. Validar campos, nomes, ordem e duração. Se inválido, reparar só o trecho/JSON inválido e limitar tentativas.
3. Plano técnico: mapear cada personagem, cenário e objeto para recursos reais do kit; decidir o que reutilizar e o que criar. Preparar contrato de funções e nomes, dependências e limites de cada arquivo. Estimar tamanho do contexto. Se a interface exigir dependência integral ou não houver certeza de sua suficiência, incluir o conteúdo necessário; nunca omitir cegamente um módulo essencial.
4. Personagens: reutilizar personagem comprovadamente compatível quando a identidade pedida for a mesma; caso contrário, chamada com descrição visual, funções/trechos reais de desenho e arquivos que interagem com o personagem. Salvar `roman.js` e verificar sintaxe, composição com a biblioteca base, exports e rosto/penteado em imagens de várias poses quando disponível.
5. Linha do tempo e câmera: chamada com roteiro, personagem, contrato real de movimento e funções exigidas. Retornar `story.js`, cobrindo os 240 frames, pontos de ação e cortes. Validar exportações, intervalos, duração e determinismo; projeção e margem conforme capacidades reais do kit. Capturas visuais ainda são obrigatórias.
6. Cenário: chamada com descrição, objetos essenciais, recursos e interface real do motor, além dos dados da linha do tempo de que depende. Retornar `main.js`; preservar o motor base e não inventar imports. Conferir chão, escala, objetos, transparência, contornos, profundidade e continuidade.
7. Montagem e prévia: validar sintaxe e ligação entre módulos antes de executar; renderizar quadros no início, meio, fim, cortes e extremos das ações. Mostrar imagens, verificar rosto, cabelo preso à cabeça, mãos, objetos, enquadramento, luz e artefatos. Prévia local é uma ferramenta de teste, não aprovação automática.
8. Revisão localizada: registrar tempo, imagem, defeito observado e possível arquivo. Pedir correção somente dos arquivos relevantes, fornecendo o conteúdo real e as interfaces dependentes. Revalidar dependências e recapturar cenas afetadas. Não pedir regeneração da história inteira para um defeito isolado. Limitar chamadas, tempo e custo.
9. MP4: se as verificações permitirem, renderizar oito segundos e conferir resolução, duração, movimento e áudio quando houver. Mostrar arquivo com defeitos restantes. Ampliar para histórias diferentes e depois HD, sem prometer perfeição universal por um teste.

## Como reduzir consumo sem perder funcionalidade
- Produzir um manifesto local por versão do kit: arquivo, exports, contratos, dependências e pequenos exemplos reais. Gerar e validar automaticamente; não substituir a fonte com uma descrição inventada. Atualizar o manifesto quando o kit mudar.
- Selecionar contexto por tarefa e dependências. Personagem precisa do contrato e das funções de desenho relevantes; câmera precisa do contrato e linha do tempo; cenário precisa do renderer e pontos de integração. Para reparos fornecer arquivo afetado e interfaces relacionadas, não necessariamente o kit inteiro. Medir se a resposta se mantém correta.
- Não gerar de novo ativos que já foram aprovados e servem à nova cena. Hash do roteiro, kit, entradas e parâmetros associa checkpoints corretos. Uma mudança em dependência invalida etapas afetadas; a mesma etapa pode ser refeita sem apagar outras.
- Capturar `usageMetadata` real quando retornado, separando entrada, saída, raciocínio e cache quando houver; não confundir estimativa com cobrança real. Registrar modelo, tentativa, motivo de término e tempo sem chave nem dados privados desnecessários.
- Avaliar cache real do provedor somente se estiver disponível nessa rota; não presumir desconto, compartilhamento ou persistência. Comparar com/sem contexto reduzido usando a mesma tarefa, modelo e limite. Guardar tokens e tempo, sintaxe, interface e resultados visuais. Uma chamada de 24 mil é teto, não consumo garantido.
- Se `length`, preservar o texto e tentar continuação exata dentro do orçamento. Validar o arquivo concatenado. Se a causa for erro de ambiente ou módulo, não chamar IA novamente até identificar e corrigir a falha.

## Prioridades práticas
A. Documentar o contrato atual e medir o envio real por arquivo.
B. Montar um manifesto por etapa com testes que provam que exports e dependências necessárias estão presentes.
C. Executar um teste A/B econômico no mesmo roteiro, sem mudar modelo, limite ou resolução, contabilizando entradas/saídas e inspecionando os quadros.
D. Aplicar os recortes que passarem nas verificações, preservando fallback seguro ao contexto completo quando necessário.
E. Só então migrar ao painel oficial e preparar VPS após validação e comunicação.

## Critérios de aceite
Um teste completo, sem intervenção manual escondida, gera arquivos válidos, vídeo com duração correta, mostra frames e MP4, permite correção de um defeito sem refazer tudo e registra consumo real. A qualidade deve ser verificada em múltiplos roteiros e poses. Modelo generativo não fornece garantia absoluta de perfeição.

## Teste real: correção de cabelo
Uma chamada com limite 24.000 retornou `length`: entrada 67.188 tokens, resposta 960 e raciocínio 23.036, segundo o provedor. Trecho parcial foi preservado; não foi feita segunda chamada por causa do limite anunciado. Neste caso há evidência de que o raciocínio consumiu praticamente todo o orçamento de saída. Reduzir contexto e avaliar configurações de raciocínio apenas se suportadas pelo modelo/rota; comparar qualidade e consumo antes de fixar padrão. A regra geral de cabelo foi adicionada à API, mas a correção visual ainda não foi validada.

## Teste de contexto reduzido — 16/11/2026
- Recorte experimental opt-in somente na etapa `roman.js`: envia arquivos roman, toonbase, story e main; omite motion e paint. Mantém instruções e conteúdo integral dos arquivos incluídos. Se faltar componente previsto, volta ao conjunto completo. A seleção deve ser validada também para outras histórias, dependências e motores antes de virar padrão.
- Mesmo pedido, modelo e limite de saída da tentativa de cabelo anterior: entrada anterior 67.188 tokens; reduzida 58.571 (diferença 8.617, cerca de 12,8%). Anterior: `length`, 960 tokens de saída e 23.036 de raciocínio; teste reduzido: `stop`, 12.021 tokens de saída; raciocínio não informado pelo provedor. Não concluir causa da diferença nem o valor exato cobrado de um único par de chamadas.
- Arquivos preservados: história e cenário; personagem gerado passou na sintaxe do módulo composto e na ligação. Cinco quadros capturados; coques visíveis, enfeites amarelos ainda aparecem, franja mais baixa e braço próximo da borda. Avaliação visual é humana e continua pendente.
- O modo reduzido está apenas na preparação local, não no painel oficial. A economia no fluxo de roteiro, câmera e cenário, o manifesto completo, teste com outros casos e teste A/B mais amplo seguem pendentes.
