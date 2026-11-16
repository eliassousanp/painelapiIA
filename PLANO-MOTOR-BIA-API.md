# Plano detalhado: API BR Love + motor cartoon da Bia

## Meta e limite
Produzir primeiro um vídeo novo, vertical, com 8 segundos e 540x960 usando `/v1/video` local e o kit cartoon real. Validar cada etapa antes de afirmar que funciona. Outros motores e VPS ficam para depois. Controlar cota: até 6 chamadas para este experimento, inclusive continuações e reparos; preservar arquivos completos e parciais. Uma falha não apaga material anterior.

## Diagnóstico documentado
- O ensaio anterior terminou com 212 caracteres de código incompleto. A anotação `length` não foi registrada, então a causa exata (limite de tokens, encerramento ou outra) ainda não está provada. Registrar motivo de término, modelo efetivo, tamanho, tempo e erro de cada chamada.
- O MP4 anterior teve uma adaptação manual da cena antiga; não demonstrou uma geração da API do começo ao fim. O retângulo escuro era geometria do cenário, não comprovadamente sombra; confirmar cada hipótese em quadros.
- A API depende do cliente para montar o kit, renderizar, capturar e enviar quadros de volta. Prompt, sintaxe e cinco imagens não garantem coerência de movimento ou qualidade final.

## Sequência de implementação e aceite
1. **Contrato e kit:** fornecer ao modelo código real de interfaces, desenho do personagem, câmera e cenário. Pedir somente os arquivos exigidos, um por vez. Cada arquivo deve ser sintaticamente válido, importar apenas módulos existentes e preservar os exports reais. Registrar arquivos e versão do kit usados.
2. **História curta e verificável:** um único personagem, cenário e ação simples; 8 s/240 frames a 30 fps, com começo, ação e consequência. Criar roteiro novo. Teste inicial visual sem narração; áudio entra depois que os quadros estiverem estáveis.
3. **API e limite:** respeitar o modelo ou roteamento configurado em Vídeo, tentativas alternativas só da configuração e somente antes de saída parcial. Registrar `finish_reason`, chamadas e modelo real. Se for `length`, salvar texto e pedir continuação no ponto exato dentro do teto de chamadas. Se houver erro ou syntax error preservar artefatos e informar causa; não gerar novamente tudo.
4. **Geração incremental:** gerar os três arquivos do kit em checkpoints separados; sintaxe de cada arquivo, depois verificar ligação de imports/exports e `TOTAL >= 240`. Uma correção deve atingir só os arquivos relacionados, mantendo os aprovados. Relacionar checkpoint ao roteiro, kit e parâmetros.
5. **Inspeção:** capturar início, ação, meio, fim e cortes, antes do MP4. Conferir rosto, corpo, mãos, objetos, contato com chão, profundidade, câmera e luz. Registrar defeitos com quadro e arquivo provável; não confundir elementos desenhados com sombras. Conferir movimento no MP4 completo.
6. **Render:** gerar MP4 8 s/540x960; medir com ffprobe duração, dimensões e codec. Mostrar vídeo e quadros, apontando defeitos sem declarar aprovação automática.
7. **Ciclo seguinte:** revisar o resultado com evidências; corrigir especificamente a causa na API/direção ou no consumidor quando apropriado e repetir somente cenas afetadas. Então incluir narração e sincronização e medir duração/custo real. Fazer mais de uma história e cenário neste motor; só depois avaliar HD, painel oficial e outros motores.

## Indicadores e pendências
Registrar por ensaio o número de chamadas, motivo de término, erros, tempo por etapa e resultado visual; nunca registrar chave. Aprovação requer MP4 reproduzível, duração correta, quadros e movimento revisados e geração automática de arquivos completos. Não prometer 100% baseado em um único vídeo. Configuração de formato no painel continua para depois.

## Ensaio novo de 16/11: semente luminosa
- Seis chamadas locais, `max_tokens=10000`; `story.js`, `roman.js` e `main.js` receberam `length` e cada um foi continuado até `stop`. Todos passaram na sintaxe e ligação dos módulos. O tempo e os modelos de cada chamada estão no `chamadas.jsonl` do ensaio.
- Foram capturados cinco quadros a partir da geração nova. A semente e a flor aparecem, mas Bia tem a cabeça cortada no meio e no fim; o vídeo não está aprovado.
- Limite oficial documentado para `gemini-3.8-flash`: 1.048.576 entrada e 65.536 saída. A rota local usa o identificador `gemini-3.8-flash-medium` no CloudCode; não se deve presumir que os limites dessa rota sejam idênticos sem teste.
- A API agora preserva e disponibiliza `usageMetadata` de streaming Google quando fornecido pelo provedor. Isso é para medir tokens de entrada, resposta e raciocínio; não há dados retroativos para as seis chamadas deste teste. Confirmar recebimento real com uma chamada local antes de tirar conclusões.
- Comparar um teste limitado com 20.000 ou 32.000 tokens e o mesmo kit, medindo tempo, término, uso real e custo, antes de alterar padrão permanente. Não presumir que limite maior elimina todos os cortes ou melhora o enquadramento.

## Artefato visual relatado: riscos no topo
- Evidência: pequenos sinais escuros ao longo da travessa no quadro 1 da estufa.
- Hipótese testada: sobreposição entre os vidros e a estrutura, inclusive superfícies coplanares. Em cópia local, os vidros foram afastados e cinco quadros recapturados; os sinais persistiram. Portanto a hipótese testada não resolveu o problema e não é causa confirmada.
- Próximo diagnóstico sem chamadas à IA: desligar seletivamente a passada de contorno, sombras e geometrias da estrutura em cópias isoladas, comparando o mesmo frame. Corrigir a causa no contrato/direção da API e conferir a cena original, sem afirmar que está resolvido antes da nova captura.

- Comparação posterior: os pontos persistiram sem sombras e desapareceram na captura com espessura de contorno zero. Evidência indica passagem de contorno; desligá-la globalmente também remove o estilo. A API recebeu regras para evitar superfícies coplanares e diagnosticar contorno, mas a correção localizada ainda precisa ser comprovada.

## Correção localizada com limite maior
- Retomada da estufa com personagem preservado; nova linha do tempo e novo cenário solicitados à API, limite de saída 24.000 por chamada. Ambas terminaram em `stop`, sem continuação.
- Contagem real recebida do provedor: story: entrada 67.289, resposta 2.952, raciocínio 2.936; main: entrada 67.277, resposta 9.112, raciocínio 3.589. A comparação tem mudanças de pedido e não é um experimento controlado; não prova que 24 mil elimina cortes em geral. Entradas grandes repetidas merecem otimização sem remover contexto necessário.
- Quadros revisados: cabeça inteira nos cinco quadros, flor visível; pontos pequenos da travessa desapareceram. Restam linhas escuras no teto e braço próximo/encostando na borda; verificar movimento do MP4. Não declarar aprovação completa.
