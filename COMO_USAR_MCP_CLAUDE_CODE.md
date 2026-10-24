# Guia Oficial: Conectando o Claude Code à sua API via MCP (Model Context Protocol)

Este módulo adiciona suporte nativo a **MCP com transporte SSE (Server-Sent Events)** diretamente no seu servidor (`serverapi`).

Ao rodar o servidor, o MCP é iniciado automaticamente na mesma porta, sem precisar rodar nenhum processo extra.

---

## 🎯 Por que usar essa abordagem?
* **Você usa a sua Conta Pro oficial do Claude**: O modelo principal que programa com você no Claude Code é o Claude oficial da Anthropic (incluso na sua assinatura Pro).
* **Subagente dedicado da sua API**: Quando você quiser que o Claude delegue uma tarefa pesada, execute uma análise profunda ou gere blocos com modelos específicos da sua API (`claude-opus-4-6-thinking`, `claude-sonnet-4-6`, `gemini-3.8-flash-high`), o próprio Claude chama a ferramenta do MCP.
* **Autenticação Segura**: Utiliza a sua chave de API gerada no seu painel (`Bearer sk_...`).
* **Qualquer Domínio / VPS**: Suporta CORS liberado e transporte SSE nativo para qualquer domínio ou IP de VPS com ou sem SSL.

---

## 🚀 Como Conectar no Claude Code

### Passo 1: Iniciar o Servidor
No seu terminal local ou na sua VPS, basta iniciar o servidor normalmente:
```bash
cd serverapi
npm start
```
O servidor exibirá no banner:
```
🤖 Claude Code MCP:   claude mcp add --transport sse painel-subagente http://localhost:3000/mcp/sse --header "Authorization: Bearer SUA_API_KEY"
```

---

### Passo 2: Registrar no Claude Code

Abra o terminal onde você usa o **Claude Code** e execute apenas **um** dos comandos abaixo:

#### A) Se estiver rodando na sua VPS com domínio (Produção):
```bash
claude mcp add --transport sse painel-subagente https://api.seudominio.com/mcp/sse --header "Authorization: Bearer SUA_CHAVE_DO_PAINEL"
```

#### B) Se estiver testando no Localhost:
```bash
claude mcp add --transport sse painel-subagente http://localhost:3000/mcp/sse --header "Authorization: Bearer SUA_CHAVE_DO_PAINEL"
```

> **Dica**: Você também pode passar a chave via parâmetro de URL se preferir:
> `https://api.seudominio.com/mcp/sse?apiKey=SUA_CHAVE_DO_PAINEL`

---

## 🛠️ Ferramentas disponíveis para o Claude Code

Uma vez conectado, o Claude Code passa a ter automaticamente 3 novas ferramentas disponíveis:

### 1. `executar_subagente`
Permite ao Claude delegar uma tarefa para o subagente rodando na sua API.
* **Parâmetros**:
  * `prompt`: A tarefa ou pergunta detalhada a ser resolvida pelo subagente.
  * `modelo` (opcional): O modelo da sua API a ser acionado (ex: `claude-opus-4-6-thinking`, `claude-sonnet-4-6`, `gemini-3.8-flash-high`, `gemini-3.8-flash-medium`). O padrão é `claude-opus-4-6-thinking`.
  * `system_prompt` (opcional): Diretrizes ou persona do subagente.

### 2. `listar_modelos_api`
Consulta os modelos ativos configurados no seu painel.

### 3. `consultar_saldo_api`
Verifica seu saldo de tokens e histórico de consumo diretamente no painel.

---

## 💡 Exemplos de prompts para usar no Claude Code

Você pode conversar normalmente com o Claude Code e pedir:

* *"Claude, use o subagente da minha API com o modelo claude-opus-4-6-thinking para analisar a arquitetura dessa pasta."*
* *"Consulte o saldo da minha API via MCP."*
* *"Liste os modelos que tenho disponíveis na minha API."*
* *"Execute um subagente na minha API para refatorar esta função usando Gemini 3.8 Flash High."*

---

## 🌐 Configuração do Nginx na VPS (Proxy Reverso)

Se você utiliza Nginx na VPS (aaPanel, cPanel, Docker ou VPS limpa), certifique-se de que o Nginx repasse as conexões SSE sem fazer buffer. Basta colocar este bloco na configuração do seu site no Nginx:

```nginx
location /mcp/ {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Connection '';
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    
    # Fundamental para SSE (Server-Sent Events) em tempo real:
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 86400s;
}
```
