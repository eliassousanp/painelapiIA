@echo off
title BRLove API - Gateway Node.js (localhost:3000 para Claude Desktop)
cd /d "%~dp0serverapi"
echo ========================================================
echo Iniciando BRLove API Gateway Node.js na porta 3000...
echo Acesso local: node mcp-remote http://localhost:3000/mcp/sse?apiKey=SUA_CHAVE
echo Keep-Alive Ativo ^| Caches Zero-Latency ^| Smart Model Routing
echo ========================================================
node src/server.js
pause
