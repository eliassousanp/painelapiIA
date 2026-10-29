@echo off
title Claude Code com Kimi Free
echo ========================================================
echo Iniciando Claude Code apontando para o Kimi Free API...
echo ========================================================

:: 1. Isola a sessao para nao conflitar com o login oficial do Claude
if not exist "%USERPROFILE%\.claude-kimi" mkdir "%USERPROFILE%\.claude-kimi"
set CLAUDE_CONFIG_DIR=%USERPROFILE%\.claude-kimi

:: 2. Limpa token OAuth herdado
set ANTHROPIC_AUTH_TOKEN=

:: 3. Aponta para o servidor Kimi Free local (porta 8000)
set ANTHROPIC_BASE_URL=http://localhost:8000
set ANTHROPIC_API_KEY=kimi-free-local
set ANTHROPIC_MODEL=claude-3-5-sonnet-20241022

:: 4. Inicia o Claude com o modelo especificado
claude --model claude-3-5-sonnet-20241022 %*
pause
