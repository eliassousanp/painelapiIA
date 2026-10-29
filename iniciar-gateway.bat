@echo off
title BRLove API - Gateway Node.js (Com Cloudflare Tunnel para Claude Desktop)
cd /d "%~dp0serverapi"
echo ========================================================
echo Iniciando BRLove API Gateway Node.js na porta 3000...
echo Subindo Cloudflare Tunnel (https://...trycloudflare.com)...
echo Keep-Alive Ativo ^| Caches Zero-Latency ^| Smart Model Routing
echo ========================================================
node src/server.js
pause

