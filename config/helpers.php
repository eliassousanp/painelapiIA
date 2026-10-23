<?php
/**
 * Funções Auxiliares Globais (Helpers)
 */

if (session_status() === PHP_SESSION_NONE) {
    // Configurações rígidas de segurança para Cookies de Sessão
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 86400 * 7, // 7 dias
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/database.php';

function getSetting($key, $default = '') {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : $default;
}

function setSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, $value]);
}

function getAppBaseUrl() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    if (substr($dir, -6) === '/admin' || substr($dir, -7) === '/client' || substr($dir, -9) === '/reseller') {
        $dir = dirname($dir);
    }
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    return $dir;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function isReseller() {
    return isset($_SESSION['user_role']) && ($_SESSION['user_role'] === 'reseller' || $_SESSION['user_role'] === 'admin');
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . getAppBaseUrl() . '/login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . getAppBaseUrl() . '/client/index.php');
        exit;
    }
}

function requireReseller() {
    requireLogin();
    if (!isReseller()) {
        header('Location: ' . getAppBaseUrl() . '/client/index.php');
        exit;
    }
}

/**
 * Gera uma API Key segura no formato oficial do Claude / Anthropic
 * Exemplo: sk-ant-api03-4f8a3c2e109d7b5f6a8e2c1d0b3a4f5e6d7c8b9a0f1e2d3c
 */
function generateApiKey($prefix = 'sk-ant-api03-') {
    return $prefix . bin2hex(random_bytes(24));
}

/**
 * Retorna a Base URL pública oficial da API (ex: https://api.seudominio.com ou http://localhost:3000)
 */
function getGatewayPublicUrl() {
    $custom = rtrim(getSetting('gateway_public_url', ''), '/');
    if (!empty($custom)) {
        return $custom;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $protocol = $isHttps ? "https://" : "http://";
    $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $domainName)[0];
    $port = getSetting('gateway_port', '3000');
    return $protocol . $hostOnly . ':' . $port;
}

/**
 * Retorna a URL pública de WebSocket Realtime (ex: wss://api.seudominio.com/v1/realtime)
 */
function getGatewayWsUrl() {
    $base = getGatewayPublicUrl();
    $isHttps = str_starts_with($base, 'https://');
    $proto = $isHttps ? 'wss://' : 'ws://';
    $hostNoProto = preg_replace('#^https?://#i', '', $base);
    return $proto . $hostNoProto . '/v1/realtime';
}

/**
 * Retorna a URL interna para comunicação do PHP com o Node.js em loopback (ex: http://127.0.0.1:3000)
 */
function getGatewayInternalUrl() {
    $port = getSetting('gateway_port', '3000');
    return rtrim(getSetting('gateway_api_url', "http://127.0.0.1:{$port}"), '/');
}

/**
 * Funções de Gerenciamento de Tema e Identidade Visual
 */
function getAppName() {
    return getSetting('app_name', 'BRLove API');
}

function getThemeMode() {
    return getSetting('theme_mode', 'light');
}

function getThemeScheme() {
    return getSetting('theme_color_scheme', 'purple_cyan');
}

function getThemeThemes() {
    return [
        'purple_cyan' => [
            'id' => 'purple_cyan',
            'name' => 'Cyberpunk Neon',
            'desc' => 'Violeta, Roxo & Ciano Neon (Padrão Oficial)',
            'primary' => '#8b5cf6',
            'primary_gradient' => 'from-indigo-600 via-purple-600 to-cyan-400',
            'accent' => '#06b6d4',
            'bg' => '#030712',
            'card' => 'rgba(15, 23, 42, 0.75)',
            'border' => 'rgba(139, 92, 246, 0.25)',
            'active_border' => '#8b5cf6',
            'glow' => 'rgba(139, 92, 246, 0.25)',
            'badge' => 'bg-purple-500/20 text-purple-300 border-purple-500/30',
            'icon' => 'fa-solid fa-bolt'
        ],
        'emerald_teal' => [
            'id' => 'emerald_teal',
            'name' => 'Midnight Emerald',
            'desc' => 'Verde Esmeralda, Menta & Ciano Hacker',
            'primary' => '#10b981',
            'primary_gradient' => 'from-emerald-600 via-teal-600 to-cyan-400',
            'accent' => '#14b8a6',
            'bg' => '#021512',
            'card' => 'rgba(4, 30, 26, 0.75)',
            'border' => 'rgba(16, 185, 129, 0.25)',
            'active_border' => '#10b981',
            'glow' => 'rgba(16, 185, 129, 0.25)',
            'badge' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
            'icon' => 'fa-solid fa-leaf'
        ],
        'blue_indigo' => [
            'id' => 'blue_indigo',
            'name' => 'Ocean Sapphire',
            'desc' => 'Azul Real Profundo, Índigo & Safira',
            'primary' => '#3b82f6',
            'primary_gradient' => 'from-blue-600 via-indigo-600 to-sky-400',
            'accent' => '#38bdf8',
            'bg' => '#040b1a',
            'card' => 'rgba(8, 20, 48, 0.75)',
            'border' => 'rgba(59, 130, 246, 0.25)',
            'active_border' => '#3b82f6',
            'glow' => 'rgba(59, 130, 246, 0.25)',
            'badge' => 'bg-blue-500/20 text-blue-300 border-blue-500/30',
            'icon' => 'fa-solid fa-water'
        ],
        'amber_orange' => [
            'id' => 'amber_orange',
            'name' => 'Sunset Gold',
            'desc' => 'Dourado Imperial, Âmbar & Ouro Neon',
            'primary' => '#f59e0b',
            'primary_gradient' => 'from-amber-500 via-yellow-400 to-orange-500',
            'accent' => '#fbbf24',
            'bg' => '#140c04',
            'card' => 'rgba(35, 20, 8, 0.75)',
            'border' => 'rgba(245, 158, 11, 0.25)',
            'active_border' => '#f59e0b',
            'glow' => 'rgba(245, 158, 11, 0.25)',
            'badge' => 'bg-amber-500/20 text-amber-300 border-amber-500/30',
            'icon' => 'fa-solid fa-crown'
        ],
        'rose_crimson' => [
            'id' => 'rose_crimson',
            'name' => 'Crimson Rose',
            'desc' => 'Rosa Magenta, Carmim & Púrpura Intenso',
            'primary' => '#f43f5e',
            'primary_gradient' => 'from-rose-600 via-pink-600 to-purple-500',
            'accent' => '#fb7185',
            'bg' => '#150309',
            'card' => 'rgba(38, 7, 18, 0.75)',
            'border' => 'rgba(244, 63, 94, 0.25)',
            'active_border' => '#f43f5e',
            'glow' => 'rgba(244, 63, 94, 0.25)',
            'badge' => 'bg-rose-500/20 text-rose-300 border-rose-500/30',
            'icon' => 'fa-solid fa-gem'
        ],
        'claude_terracotta' => [
            'id' => 'claude_terracotta',
            'name' => 'Claude Terracotta (Warm Charcoal)',
            'desc' => 'Laranja Terracota & Carvão Aveludado (Estilo Claude / InfiniteModel)',
            'primary' => '#d97736',
            'primary_gradient' => 'from-amber-600 via-orange-600 to-amber-700',
            'accent' => '#e27d42',
            'bg' => '#141413',
            'card' => 'rgba(28, 28, 26, 0.85)',
            'border' => 'rgba(217, 119, 54, 0.25)',
            'active_border' => '#d97736',
            'glow' => 'rgba(217, 119, 54, 0.25)',
            'badge' => 'bg-amber-600/20 text-amber-300 border-amber-500/30',
            'icon' => 'fa-solid fa-sparkles'
        ],
        'titanium_minimal' => [
            'id' => 'titanium_minimal',
            'name' => 'Titanium Dark',
            'desc' => 'Preto Absoluto, Cinza Titânio & Minimalista',
            'primary' => '#94a3b8',
            'primary_gradient' => 'from-slate-700 via-zinc-600 to-gray-400',
            'accent' => '#f8fafc',
            'bg' => '#030303',
            'card' => 'rgba(18, 18, 18, 0.85)',
            'border' => 'rgba(255, 255, 255, 0.12)',
            'active_border' => '#ffffff',
            'glow' => 'rgba(255, 255, 255, 0.15)',
            'badge' => 'bg-gray-500/20 text-gray-300 border-gray-500/30',
            'icon' => 'fa-solid fa-circle-half-stroke'
        ]
    ];
}

function getCurrentTheme() {
    $themes = getThemeThemes();
    $current = getThemeScheme();
    return $themes[$current] ?? $themes['purple_cyan'];
}



