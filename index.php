<?php
require_once __DIR__ . '/config/helpers.php';
$db = getDB();

// Buscar planos de clientes (all ou client) ativos
try {
    $stmtClientPlans = $db->query("SELECT * FROM plans WHERE status = 'active' AND target_audience IN ('all', 'client') ORDER BY price ASC");
    $clientPlans = $stmtClientPlans->fetchAll();
} catch (Exception $e) {
    $clientPlans = [];
}

if (empty($clientPlans)) {
    $clientPlans = [
        [
            'id' => 1,
            'name' => 'Starter Dev',
            'price' => 49.00,
            'tokens_amount' => 5000000,
            'badge' => 'Iniciante',
            'features' => "5 Milhões de Tokens de Alta Velocidade\nAcesso Claude Sonnet 5.5 & Claude 5.5 Haiku\nStreaming SSE em Tempo Real (<200ms)\n1 Chave de API Ativa com Estatísticas\nCompatível com Cursor, VS Code e Cline\nSuporte via WhatsApp & Comunidade",
            'is_featured' => 0
        ],
        [
            'id' => 2,
            'name' => 'Pro Engineer',
            'price' => 149.00,
            'tokens_amount' => 50000000,
            'badge' => 'Mais Escolhido',
            'features' => "50 Milhões de Tokens de Alta Capacidade\nClaude Sonnet 5.5 & Claude Opus 5.5 Thinking\nSem Limites de Requisições por Minuto\nProcessamento Otimizado e Zero Timeout\nChaves de API Ilimitadas por Projeto\nIntegração com OpenCode, Dify e LangChain\nPrioridade Máxima de Fila de Execução",
            'is_featured' => 1
        ],
        [
            'id' => 3,
            'name' => 'Team & Enterprise',
            'price' => 299.00,
            'tokens_amount' => 200000000,
            'badge' => 'Alto Volume',
            'features' => "200 Milhões de Tokens Corporativos\nTodos os Modelos Claude 5.5 Desbloqueados (Sonnet, Opus & Haiku)\nCluster Dedicado com SLA de 99.98%\nPré-Prompts Customizáveis por Endpoint\nDashboard de Métricas e Consumo por Equipe\nGerente de Contas & Suporte Técnico VIP 24/7",
            'is_featured' => 0
        ]
    ];
}

$initialThemeMode = getThemeMode();
if (isset($_COOKIE['theme_mode']) && in_array($_COOKIE['theme_mode'], ['light', 'dark'])) {
    $initialThemeMode = $_COOKIE['theme_mode'];
}
$isDark = ($initialThemeMode === 'dark');
$initialThemeScheme = getThemeScheme();
if (isset($_COOKIE['theme_scheme'])) {
    $initialThemeScheme = $_COOKIE['theme_scheme'];
}
$appName = getAppName();
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $isDark ? 'dark' : '' ?>" data-theme="<?= htmlspecialchars($initialThemeMode) ?>" data-scheme="<?= htmlspecialchars($initialThemeScheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> - Infraestrutura de Inteligência Artificial de Alta Performance para Desenvolvedores</title>
    <meta name="description" content="Acesse Claude Sonnet 5.5, Claude Opus 5.5 Thinking e Claude 5.5 Haiku com streaming em tempo real, sem limites geográficos e faturamento em Reais via PIX.">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace']
                    }
                }
            }
        }
    </script>

    <!-- Theme Initialization Script (evita FOUC) -->
    <script>
        (function() {
            const savedMode = localStorage.getItem('theme_mode');
            if (savedMode) {
                document.documentElement.setAttribute('data-theme', savedMode);
            }
        })();
    </script>

    <style>
        :root {
            /* Modo Padrão Claro (Clean White) */
            --bg-base: #f8fafc;
            --bg-surface: #ffffff;
            --bg-surface-elevated: #ffffff;
            --border-color: #e2e8f0;
            --border-highlight: #cbd5e1;
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --nav-bg: rgba(255, 255, 255, 0.85);
            --card-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05), 0 2px 6px -2px rgba(0, 0, 0, 0.03);
            --glow-color: rgba(99, 102, 241, 0.15);
            --code-bg: #090d16;
            --code-text: #e2e8f0;
            --accent-primary: #6366f1;
            --accent-secondary: #06b6d4;
            --badge-bg: #eef2ff;
            --badge-text: #4338ca;
            --badge-border: #c7d2fe;
        }

        /* Modo Escuro (Cyber Titanium) */
        html[data-theme="dark"] {
            --bg-base: #060911;
            --bg-surface: #0c111d;
            --bg-surface-elevated: #131b2e;
            --border-color: rgba(255, 255, 255, 0.08);
            --border-highlight: rgba(255, 255, 255, 0.16);
            --text-heading: #f8fafc;
            --text-body: #94a3b8;
            --text-muted: #64748b;
            --nav-bg: rgba(6, 9, 17, 0.85);
            --card-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.7);
            --glow-color: rgba(99, 102, 241, 0.25);
            --code-bg: #03060d;
            --code-text: #f1f5f9;
            --badge-bg: rgba(99, 102, 241, 0.12);
            --badge-text: #a5b4fc;
            --badge-border: rgba(99, 102, 241, 0.3);
        }

        /* Paletas de Cores de Acento */
        html[data-scheme="emerald_teal"] {
            --accent-primary: #10b981;
            --accent-secondary: #14b8a6;
            --glow-color: rgba(16, 185, 129, 0.2);
            --badge-bg: rgba(16, 185, 129, 0.12);
            --badge-text: #34d399;
            --badge-border: rgba(16, 185, 129, 0.3);
        }
        html[data-scheme="blue_indigo"] {
            --accent-primary: #3b82f6;
            --accent-secondary: #38bdf8;
            --glow-color: rgba(59, 130, 246, 0.2);
            --badge-bg: rgba(59, 130, 246, 0.12);
            --badge-text: #60a5fa;
            --badge-border: rgba(59, 130, 246, 0.3);
        }
        html[data-scheme="amber_orange"] {
            --accent-primary: #f59e0b;
            --accent-secondary: #fbbf24;
            --glow-color: rgba(245, 158, 11, 0.2);
            --badge-bg: rgba(245, 158, 11, 0.12);
            --badge-text: #fbbf24;
            --badge-border: rgba(245, 158, 11, 0.3);
        }
        html[data-scheme="rose_crimson"] {
            --accent-primary: #f43f5e;
            --accent-secondary: #fb7185;
            --glow-color: rgba(244, 63, 94, 0.2);
            --badge-bg: rgba(244, 63, 94, 0.12);
            --badge-text: #fb7185;
            --badge-border: rgba(244, 63, 94, 0.3);
        }
        html[data-scheme="titanium_minimal"] {
            --accent-primary: #64748b;
            --accent-secondary: #94a3b8;
            --glow-color: rgba(148, 163, 184, 0.2);
            --badge-bg: rgba(148, 163, 184, 0.12);
            --badge-text: #cbd5e1;
            --badge-border: rgba(148, 163, 184, 0.3);
        }

        body {
            background-color: var(--bg-base);
            color: var(--text-body);
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        .surface-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            transition: all 0.25s ease;
        }

        .surface-card:hover {
            border-color: var(--border-highlight);
        }

        .glass-nav {
            background-color: var(--nav-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color);
        }

        .text-heading {
            color: var(--text-heading);
        }

        .text-muted-custom {
            color: var(--text-muted);
        }

        .gradient-brand {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
        }

        .gradient-brand-text {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .btn-brand {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            color: #ffffff;
            transition: all 0.2s ease;
        }

        .btn-brand:hover {
            opacity: 0.92;
            transform: translateY(-1px);
            box-shadow: 0 8px 25px -4px var(--glow-color);
        }

        .interactive-tab.active {
            background-color: var(--accent-primary);
            color: #ffffff;
        }

        @keyframes pulse-subtle {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        .pulse-live {
            animation: pulse-subtle 2s infinite ease-in-out;
        }
    </style>
    <link href="<?= getAppBaseUrl() ?>/assets/css/theme.css?v=<?= time() ?>" rel="stylesheet">
    <script>window.BRLOVE_BASE_URL = '<?= getAppBaseUrl() ?>';</script>
    <script src="<?= getAppBaseUrl() ?>/assets/js/theme.js"></script>
</head>
<body class="font-sans antialiased min-h-screen selection:bg-indigo-500 selection:text-white flex flex-col">

    <!-- Top Notification Bar -->
    <div class="bg-gradient-to-r from-indigo-900 via-indigo-800 to-cyan-900 text-white text-[11px] sm:text-xs py-2 px-3 sm:px-4 font-medium text-center flex flex-wrap items-center justify-center gap-1.5 sm:gap-2">
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] sm:text-[10px] font-bold bg-cyan-400 text-cyan-950 uppercase tracking-wider">Novo</span>
        <span>Acesso liberado ao <strong>Claude Sonnet 5.5</strong> e <strong>Claude Opus 5.5 Thinking</strong>!</span>
        <a href="#planos" class="underline hover:text-cyan-200 ml-1 font-bold shrink-0">Ver Planos &rarr;</a>
    </div>

    <!-- Header / Navbar Principal -->
    <header class="sticky top-0 z-50 glass-nav transition-all">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 h-16 sm:h-20 flex items-center justify-between">
            <!-- Brand Logo -->
            <a href="index.php" class="flex items-center gap-2.5 sm:gap-3 group min-w-0">
                <div class="w-9 h-9 sm:w-11 sm:h-11 rounded-2xl gradient-brand flex items-center justify-center text-white text-lg sm:text-xl shadow-md transition-transform group-hover:scale-105 shrink-0">
                    <i class="fa-solid fa-brain"></i>
                </div>
                <div class="min-w-0">
                    <span class="text-base sm:text-xl font-extrabold tracking-tight text-heading flex items-center gap-1">
                        <?= htmlspecialchars($appName) ?>
                        <span class="text-[9px] sm:text-xs px-1.5 sm:px-2 py-0.5 rounded-md font-bold uppercase tracking-wider shrink-0" style="background-color: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border);">Gateway</span>
                    </span>
                    <span class="text-[10px] hidden sm:block font-mono text-muted-custom leading-none mt-0.5">Infraestrutura Neural Claude 5.5 de Alta Disponibilidade</span>
                </div>
            </a>

            <!-- Navigation Links (Desktop) -->
            <nav class="hidden lg:flex items-center space-x-6 text-sm font-semibold">
                <a href="#playground" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Playground</a>
                <a href="#arquitetura" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Arquitetura</a>
                <a href="#modelos" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Modelos</a>
                <a href="#calculadora" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Economia ROI</a>
                <a href="#planos" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Planos & Preços</a>
                <a href="revenda.php" class="btn-reseller-badge flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs transition-all font-bold">
                    <i class="fa-solid fa-briefcase text-xs"></i> <span>Seja Revendedor</span>
                </a>
                <a href="#faq" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">FAQ</a>
            </nav>

            <!-- Actions & Theme Toggle -->
            <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                <!-- Theme Mode Toggle (Sun / Moon) -->
                <button id="themeToggleBtn" type="button" aria-label="Alternar Tema Claro/Escuro" title="Alternar entre modo claro e escuro" class="btn-theme-toggle w-9 h-9 sm:w-10 sm:h-10 rounded-xl surface-card flex items-center justify-center text-heading hover:scale-105 transition active:scale-95 cursor-pointer shrink-0">
                    <i id="themeToggleIcon" class="theme-toggle-icon fa-solid <?= $isDark ? 'fa-moon text-indigo-400' : 'fa-sun text-amber-500' ?> text-sm sm:text-base pointer-events-none"></i>
                </button>

                <!-- Auth Buttons (Desktop e Tablets) -->
                <div class="hidden sm:flex items-center gap-2">
                    <?php if (isLoggedIn()): ?>
                        <?php if (isAdmin()): ?>
                            <a href="admin/index.php" class="px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl btn-brand text-xs font-bold shadow-md flex items-center gap-1.5">
                                <i class="fa-solid fa-shield-halved"></i> <span class="hidden md:inline">Painel</span> Admin
                            </a>
                        <?php elseif (isReseller()): ?>
                            <a href="reseller/index.php" class="px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 text-xs font-extrabold shadow-md flex items-center gap-1.5">
                                <i class="fa-solid fa-briefcase"></i> <span class="hidden md:inline">Painel</span> Revenda
                            </a>
                        <?php else: ?>
                            <a href="client/index.php" class="px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl btn-brand text-xs font-bold shadow-md flex items-center gap-1.5">
                                <i class="fa-solid fa-gauge"></i> Meu Painel
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="login.php" class="text-heading hover:text-indigo-600 dark:hover:text-indigo-400 text-xs font-bold px-2 sm:px-3 py-2 transition">Entrar</a>
                        <a href="register.php" class="px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl btn-brand text-xs font-bold shadow-md flex items-center gap-1.5">
                            <i class="fa-solid fa-key"></i> Obter Chave API
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Botão Menu Mobile (Hamburger) -->
                <button type="button" id="mobileMenuBtn" onclick="toggleMobileMenu()" class="lg:hidden w-9 h-9 sm:w-10 sm:h-10 rounded-xl surface-card flex items-center justify-center text-heading hover:scale-105 transition active:scale-95 cursor-pointer shrink-0" aria-label="Abrir Menu">
                    <i id="mobileMenuIcon" class="fa-solid fa-bars text-sm sm:text-base pointer-events-none"></i>
                </button>
            </div>
        </div>

        <!-- Drawer do Menu Mobile com Glassmorphism Moderno -->
        <div id="mobileMenuDrawer" class="hidden lg:hidden border-b border-[var(--border-color)] bg-[var(--nav-bg)] backdrop-blur-2xl px-4 py-5 space-y-3 transition-all duration-300 shadow-2xl">
            <div class="flex flex-col space-y-1 font-semibold text-sm">
                <a href="#playground" onclick="toggleMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-terminal text-indigo-500 mr-2.5 text-xs"></i> Playground Interativo</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="#arquitetura" onclick="toggleMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-network-wired text-cyan-500 mr-2.5 text-xs"></i> Arquitetura & Clusters</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="#modelos" onclick="toggleMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-microchip text-purple-500 mr-2.5 text-xs"></i> Modelos Claude 5.5</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="#calculadora" onclick="toggleMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-calculator text-emerald-500 mr-2.5 text-xs"></i> Economia & ROI</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="#planos" onclick="toggleMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-tags text-indigo-500 mr-2.5 text-xs"></i> Planos & Preços</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="revenda.php" class="px-3.5 py-2.5 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-600 dark:text-amber-400 font-bold transition flex items-center justify-between">
                    <span class="flex items-center gap-2"><i class="fa-solid fa-briefcase text-xs"></i> Seja Revendedor</span>
                    <span class="text-[10px] px-2 py-0.5 rounded-md bg-amber-500 text-slate-950 font-extrabold uppercase">Até 60% Margem</span>
                </a>
                <a href="#faq" onclick="toggleMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-circle-question text-indigo-500 mr-2.5 text-xs"></i> FAQ (Dúvidas)</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
            </div>

            <!-- Botões de Ação no Drawer Mobile -->
            <div class="pt-3 border-t border-[var(--border-color)] flex flex-col gap-2">
                <?php if (isLoggedIn()): ?>
                    <?php if (isAdmin()): ?>
                        <a href="admin/index.php" class="w-full py-3 rounded-xl btn-brand text-xs font-bold shadow-md flex items-center justify-center gap-2">
                            <i class="fa-solid fa-shield-halved"></i> Painel Administrativo
                        </a>
                    <?php elseif (isReseller()): ?>
                        <a href="reseller/index.php" class="w-full py-3 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 text-xs font-extrabold shadow-md flex items-center justify-center gap-2">
                            <i class="fa-solid fa-briefcase"></i> Painel de Revenda
                        </a>
                    <?php else: ?>
                        <a href="client/index.php" class="w-full py-3 rounded-xl btn-brand text-xs font-bold shadow-md flex items-center justify-center gap-2">
                            <i class="fa-solid fa-gauge"></i> Meu Painel API
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="grid grid-cols-2 gap-2">
                        <a href="login.php" class="w-full py-2.5 rounded-xl surface-card text-heading text-xs font-bold text-center flex items-center justify-center">Entrar</a>
                        <a href="register.php" class="w-full py-2.5 rounded-xl btn-brand text-xs font-bold shadow-md text-center flex items-center justify-center">Criar Conta</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="flex-grow">
        <!-- Hero Section -->
        <section class="relative pt-8 sm:pt-12 pb-16 sm:pb-20 overflow-hidden">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Status Pill -->
                <div class="flex justify-center mb-6">
                    <div class="inline-flex items-center gap-2 px-3 sm:px-4 py-1.5 rounded-full surface-card text-[11px] sm:text-xs font-semibold">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 pulse-live"></span>
                        <span class="text-heading font-medium">Cluster Operacional:</span>
                        <span class="text-emerald-600 dark:text-emerald-400 font-bold">99.98% Uptime</span>
                        <span class="text-muted-custom hidden sm:inline">•</span>
                        <span class="text-muted-custom hidden sm:inline">Latência: <strong class="text-heading">185ms</strong></span>
                    </div>
                </div>

                <!-- Headline -->
                <div class="text-center max-w-4xl mx-auto">
                    <h1 class="text-3xl sm:text-5xl lg:text-7xl font-extrabold tracking-tight text-heading leading-[1.15] sm:leading-[1.1] mb-5 sm:mb-6">
                        A Inteligência Artificial Mais Rápida para o Seu <span class="gradient-brand-text">Código</span>.
                    </h1>
                    <p class="text-base sm:text-xl text-muted-custom max-w-3xl mx-auto leading-relaxed mb-8 sm:mb-10 px-2 sm:px-0">
                        Integre <strong>Claude Sonnet 5.5</strong>, <strong>Claude Opus 5.5 Thinking</strong> e <strong>Claude 5.5 Haiku</strong> diretamente no seu Cursor, VS Code (Cline/Roo) ou Backend. Sem cartão internacional, com pagamento em Reais via PIX instantâneo.
                    </p>

                    <!-- CTAs -->
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-center gap-3 sm:gap-4 mb-10 sm:mb-14 px-2 sm:px-0">
                        <a href="register.php" class="w-full sm:w-auto px-6 sm:px-8 py-3.5 sm:py-4 rounded-2xl btn-brand font-extrabold text-sm sm:text-base shadow-xl flex items-center justify-center gap-2.5">
                            <i class="fa-solid fa-bolt"></i> Começar Agora com PIX
                        </a>
                        <a href="#playground" class="w-full sm:w-auto px-6 sm:px-8 py-3.5 sm:py-4 rounded-2xl surface-card text-heading font-bold text-sm sm:text-base hover:scale-105 transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-terminal text-indigo-500"></i> Testar Streaming ao Vivo
                        </a>
                    </div>

                    <!-- Highlight Metrics Strip -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 max-w-4xl mx-auto pt-6 border-t border-[var(--border-color)]">
                        <div class="p-2.5 sm:p-3">
                            <div class="text-xl sm:text-3xl font-extrabold text-heading">185ms</div>
                            <div class="text-[11px] sm:text-xs text-muted-custom font-medium mt-0.5 sm:mt-1">Tempo Médio 1º Token</div>
                        </div>
                        <div class="p-2.5 sm:p-3">
                            <div class="text-xl sm:text-3xl font-extrabold text-heading">100% SSE</div>
                            <div class="text-[11px] sm:text-xs text-muted-custom font-medium mt-0.5 sm:mt-1">Streaming em Tempo Real</div>
                        </div>
                        <div class="p-2.5 sm:p-3">
                            <div class="text-xl sm:text-3xl font-extrabold text-heading">Até 75% OFF</div>
                            <div class="text-[11px] sm:text-xs text-muted-custom font-medium mt-0.5 sm:mt-1">vs. API em Dólar</div>
                        </div>
                        <div class="p-2.5 sm:p-3">
                            <div class="text-xl sm:text-3xl font-extrabold text-heading">Dual Standard</div>
                            <div class="text-[11px] sm:text-xs text-muted-custom font-medium mt-0.5 sm:mt-1">OpenAI & Anthropic /v1</div>
                        </div>
                    </div>
                </div>

                <!-- Visual Interface Preview Showcase -->
                <div class="mt-12 sm:mt-16 max-w-6xl mx-auto">
                    <div class="relative rounded-2xl sm:rounded-3xl overflow-hidden surface-card p-2 sm:p-4 shadow-2xl border sm:border-2 border-[var(--border-highlight)]">
                        <div class="flex items-center justify-between pb-2.5 px-2 border-b border-[var(--border-color)] text-[10px] sm:text-xs text-muted-custom">
                            <div class="flex items-center gap-1.5 sm:gap-2">
                                <span class="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full bg-rose-500 inline-block"></span>
                                <span class="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full bg-amber-500 inline-block"></span>
                                <span class="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full bg-emerald-500 inline-block"></span>
                                <span class="font-mono ml-1 sm:ml-2 font-semibold text-heading truncate max-w-[150px] sm:max-w-none"><?= htmlspecialchars(getGatewayPublicUrl()) ?>/v1/messages</span>
                            </div>
                            <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                                <span class="font-mono text-emerald-500 flex items-center gap-1 font-bold">
                                    <i class="fa-solid fa-circle text-[6px] sm:text-[8px]"></i> 200 OK
                                </span>
                                <span class="font-mono hidden sm:inline">TTFT: 118ms</span>
                            </div>
                        </div>
                        <img src="assets/images/dashboard_preview.jpg" alt="Painel de Controle e Métricas da BRLove API" class="w-full h-auto rounded-xl sm:rounded-2xl mt-2 sm:mt-3 object-cover shadow-inner">
                    </div>
                </div>
            </div>
        </section>

        <!-- Live Interactive Code Playground & SSE Streamer -->
        <section id="playground" class="py-20 border-t border-[var(--border-color)]" style="background-color: var(--bg-surface);">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-14">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3" style="background-color: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border);">
                        <i class="fa-solid fa-code"></i> Playground Interativo
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-heading">Integre no Seu Código em Segundos</h2>
                    <p class="text-muted-custom mt-3">Compatível 100% com o protocolo Anthropic (`/v1/messages`) e OpenAI (`/v1/chat/completions`). Sem necessidade de SDK proprietário.</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    <!-- Left: Code Snippets & Tabs -->
                    <div class="lg:col-span-7 rounded-3xl overflow-hidden shadow-2xl border" style="background-color: var(--code-bg); border-color: var(--border-color);">
                        <!-- Tab Selector Header -->
                        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800 bg-gray-950/70 overflow-x-auto">
                            <div class="flex items-center gap-2">
                                <button onclick="selectCodeTab('curl')" id="tabBtn_curl" class="code-tab-btn active px-3 py-1.5 rounded-lg text-xs font-mono font-semibold transition bg-indigo-600 text-white">cURL</button>
                                <button onclick="selectCodeTab('python')" id="tabBtn_python" class="code-tab-btn px-3 py-1.5 rounded-lg text-xs font-mono font-semibold transition text-gray-400 hover:text-white">Python</button>
                                <button onclick="selectCodeTab('node')" id="tabBtn_node" class="code-tab-btn px-3 py-1.5 rounded-lg text-xs font-mono font-semibold transition text-gray-400 hover:text-white">Node.js</button>
                                <button onclick="selectCodeTab('cursor')" id="tabBtn_cursor" class="code-tab-btn px-3 py-1.5 rounded-lg text-xs font-mono font-semibold transition text-gray-400 hover:text-white">Cursor & VS Code</button>
                            </div>
                            <button onclick="copyCurrentCodeSnippet()" id="copyCodeBtn" class="text-xs font-mono text-gray-400 hover:text-white flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-gray-900 border border-gray-800 transition">
                                <i class="fa-regular fa-copy"></i> Copiar
                            </button>
                        </div>

                        <!-- Code Content Display -->
                        <div class="p-6 font-mono text-xs leading-relaxed overflow-x-auto text-gray-300 min-h-[300px]">
                            <pre id="codeSnippetBox"><code>curl -X POST "<?= htmlspecialchars(getGatewayPublicUrl()) ?>/v1/messages" \
  -H "x-api-key: brlove_live_sua_chave_aqui" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{
    "model": "claude-sonnet-5-5",
    "max_tokens": 1024,
    "stream": true,
    "messages": [
      {"role": "user", "content": "Escreva uma API REST de alta performance em Go."}
    ]
  }'</code></pre>
                        </div>

                        <!-- Action Bar below code -->
                        <div class="p-4 border-t border-gray-800 bg-gray-950/90 flex flex-col sm:flex-row items-center justify-between gap-3">
                            <div class="text-xs text-gray-400 font-mono flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-cyan-400"></span> Endpoint: <code><?= htmlspecialchars(getGatewayPublicUrl()) ?>/v1</code>
                            </div>
                            <button onclick="simulateLiveSSE()" id="runStreamBtn" class="w-full sm:w-auto px-5 py-2.5 rounded-xl btn-brand text-xs font-bold flex items-center justify-center gap-2 shadow-lg">
                                <i class="fa-solid fa-play text-[10px]"></i> Executar Chamada de Teste
                            </button>
                        </div>
                    </div>

                    <!-- Right: Live Streaming Terminal Simulation -->
                    <div class="lg:col-span-5 rounded-3xl overflow-hidden shadow-2xl border" style="background-color: var(--code-bg); border-color: var(--border-color);">
                        <div class="px-4 py-3 border-b border-gray-800 bg-gray-950/70 flex items-center justify-between text-xs font-mono">
                            <span class="text-gray-300 flex items-center gap-2 font-bold">
                                <i class="fa-solid fa-terminal text-cyan-400"></i> Terminal de Saída SSE
                            </span>
                            <div class="flex items-center gap-2 text-[11px]">
                                <span id="streamStatusBadge" class="text-gray-500 font-mono">Aguardando execução</span>
                            </div>
                        </div>

                        <div id="terminalStreamOutput" class="p-5 font-mono text-xs text-emerald-400 leading-relaxed min-h-[300px] max-h-[360px] overflow-y-auto space-y-2">
                            <div class="text-gray-500 italic">// Clique no botão "Executar Chamada de Teste" ao lado</div>
                            <div class="text-gray-500 italic">// para ver a velocidade real de resposta por streaming (SSE).</div>
                        </div>

                        <div class="p-4 border-t border-gray-800 bg-gray-950/90 grid grid-cols-3 text-center text-xs font-mono">
                            <div>
                                <span class="text-gray-500 block text-[10px]">Velocidade</span>
                                <span id="metricTokensSec" class="text-white font-bold">-- tokens/s</span>
                            </div>
                            <div>
                                <span class="text-gray-500 block text-[10px]">Latência (TTFT)</span>
                                <span id="metricTtft" class="text-white font-bold">-- ms</span>
                            </div>
                            <div>
                                <span class="text-gray-500 block text-[10px]">Status HTTP</span>
                                <span id="metricHttpCode" class="text-white font-bold">200 OK</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Visual Architecture Flowchart & Reliability -->
        <section id="arquitetura" class="py-20 border-t border-[var(--border-color)]">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3" style="background-color: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border);">
                        <i class="fa-solid fa-network-wired"></i> Arquitetura de Alta Disponibilidade
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-heading">Como o BRLove Smart Gateway Funciona</h2>
                    <p class="text-muted-custom mt-3">Roteamento inteligente com balanceamento de carga, auto-retry e contingência de contas em milissegundos.</p>
                </div>

                <!-- Interactive Workflow Diagram -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 relative">
                    <!-- Step 1 -->
                    <div class="surface-card p-6 rounded-3xl relative">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-xl mb-4 font-bold">
                            <i class="fa-solid fa-laptop-code"></i>
                        </div>
                        <div class="text-xs font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider mb-1">01. Sua Aplicação</div>
                        <h3 class="text-lg font-bold text-heading mb-2">Cursor, VS Code ou App</h3>
                        <p class="text-sm text-muted-custom">Seu editor ou microsserviço envia chamadas normais usando a sua chave de API padrão.</p>
                    </div>

                    <!-- Step 2 -->
                    <div class="surface-card p-6 rounded-3xl relative">
                        <div class="w-12 h-12 rounded-2xl bg-cyan-500/10 text-cyan-500 flex items-center justify-center text-xl mb-4 font-bold">
                            <i class="fa-solid fa-server"></i>
                        </div>
                        <div class="text-xs font-bold text-cyan-600 dark:text-cyan-400 uppercase tracking-wider mb-1">02. Gateway Proxy</div>
                        <h3 class="text-lg font-bold text-heading mb-2">BRLove Smart Gateway</h3>
                        <p class="text-sm text-muted-custom">Autenticação instantânea de saldo em cache, validação de tokens e injeção do pré-prompt otimizado.</p>
                    </div>

                    <!-- Step 3 -->
                    <div class="surface-card p-6 rounded-3xl relative">
                        <div class="w-12 h-12 rounded-2xl bg-purple-500/10 text-purple-500 flex items-center justify-center text-xl mb-4 font-bold">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div class="text-xs font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wider mb-1">03. Otimização Neural</div>
                        <h3 class="text-lg font-bold text-heading mb-2">Processamento de Alta Vazão</h3>
                        <p class="text-sm text-muted-custom">Rotas inteligentes e balanceamento dinâmico que garantem estabilidade contínua e 99.98% de disponibilidade.</p>
                    </div>

                    <!-- Step 4 -->
                    <div class="surface-card p-6 rounded-3xl relative">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center text-xl mb-4 font-bold">
                            <i class="fa-solid fa-bolt"></i>
                        </div>
                        <div class="text-xs font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider mb-1">04. Resposta SSE</div>
                        <h3 class="text-lg font-bold text-heading mb-2">Streaming Contínuo</h3>
                        <p class="text-sm text-muted-custom">Os tokens chegam em tempo real no seu editor sem engasgos ou truncamentos de código.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Model Comparison & Matrix -->
        <section id="modelos" class="py-20 border-t border-[var(--border-color)]" style="background-color: var(--bg-surface);">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3" style="background-color: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border);">
                        <i class="fa-solid fa-microchip"></i> Modelos Disponíveis
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-heading">Matriz de Performance dos Modelos</h2>
                    <p class="text-muted-custom mt-3">Escolha a melhor inteligência artificial para o seu caso de uso específico.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <!-- Claude Sonnet 5.5 -->
                    <div class="surface-card p-8 rounded-3xl flex flex-col justify-between border-2 border-indigo-500/30">
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">Recomendado para Devs</span>
                                <span class="text-xs font-mono text-muted-custom">200k Contexto</span>
                            </div>
                            <h3 class="text-2xl font-bold text-heading mb-2">Claude Sonnet 5.5</h3>
                            <p class="text-sm text-muted-custom mb-6">O modelo padrão mais poderoso do mundo para codificação, refatoração e criação de arquiteturas completas de software.</p>
                            
                            <div class="space-y-3 font-mono text-xs border-t border-[var(--border-color)] pt-4 mb-6">
                                <div class="flex justify-between">
                                    <span class="text-muted-custom">Velocidade:</span>
                                    <span class="text-heading font-bold">~75 tokens/s</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-custom">Raciocínio SWE:</span>
                                    <span class="text-emerald-500 font-bold">95.2% (Líder Absoluto)</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-custom">Entrada Visual:</span>
                                    <span class="text-heading font-bold">Suportado (Imagens/Telas)</span>
                                </div>
                            </div>
                        </div>
                        <a href="register.php" class="w-full py-3 rounded-xl btn-brand text-xs font-bold text-center">Usar Claude Sonnet 5.5</a>
                    </div>

                    <!-- Claude Opus 5.5 Thinking -->
                    <div class="surface-card p-8 rounded-3xl flex flex-col justify-between border-2 border-purple-500/30">
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-purple-500/10 text-purple-600 dark:text-purple-400">Raciocínio Profundo</span>
                                <span class="text-xs font-mono text-muted-custom">200k Contexto</span>
                            </div>
                            <h3 class="text-2xl font-bold text-heading mb-2">Claude Opus 5.5</h3>
                            <p class="text-sm text-muted-custom mb-6">Capacidade analítica incomparável com chain-of-thought para resolver bugs complexos em sistemas distribuídos legados.</p>
                            
                            <div class="space-y-3 font-mono text-xs border-t border-[var(--border-color)] pt-4 mb-6">
                                <div class="flex justify-between">
                                    <span class="text-muted-custom">Velocidade:</span>
                                    <span class="text-heading font-bold">~45 tokens/s</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-custom">Raciocínio SWE:</span>
                                    <span class="text-emerald-500 font-bold">97.8% (Ultra Profundo)</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-custom">Visão & Análise:</span>
                                    <span class="text-heading font-bold">Alta Resolução</span>
                                </div>
                            </div>
                        </div>
                        <a href="register.php" class="w-full py-3 rounded-xl btn-brand text-xs font-bold text-center">Usar Claude Opus 5.5</a>
                    </div>

                    <!-- Claude 5.5 Haiku -->
                    <div class="surface-card p-8 rounded-3xl flex flex-col justify-between border-2 border-cyan-500/30">
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-cyan-500/10 text-cyan-600 dark:text-cyan-400">Ultra Rápido & Econômico</span>
                                <span class="text-xs font-mono text-muted-custom">200k Contexto</span>
                            </div>
                            <h3 class="text-2xl font-bold text-heading mb-2">Claude 5.5 Haiku</h3>
                            <p class="text-sm text-muted-custom mb-6">Velocidade extrema para autocompletar código, testes unitários, resumos massivos e scripts rápidos com latência mínima e custo super reduzido.</p>
                            
                            <div class="space-y-3 font-mono text-xs border-t border-[var(--border-color)] pt-4 mb-6">
                                <div class="flex justify-between">
                                    <span class="text-muted-custom">Velocidade:</span>
                                    <span class="text-heading font-bold">~120 tokens/s</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-custom">Performance SWE:</span>
                                    <span class="text-emerald-500 font-bold">Excelente p/ Tarefas Rápidas</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-custom">Entrada:</span>
                                    <span class="text-heading font-bold">Texto & Visão Multimodal</span>
                                </div>
                            </div>
                        </div>
                        <a href="register.php" class="w-full py-3 rounded-xl btn-brand text-xs font-bold text-center">Usar Claude 5.5 Haiku</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Interactive ROI & Token Economy Calculator -->
        <section id="calculadora" class="py-20 border-t border-[var(--border-color)]">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="surface-card p-8 sm:p-12 rounded-3xl border-2 shadow-2xl">
                    <div class="text-center max-w-2xl mx-auto mb-10">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                            <i class="fa-solid fa-calculator"></i> Calculadora de Economia
                        </div>
                        <h2 class="text-3xl font-extrabold text-heading">Veja Quanto Você Economiza por Mês</h2>
                        <p class="text-muted-custom text-sm mt-2">Arraste a barra para selecionar seu volume mensal aproximado de tokens e compare os custos.</p>
                    </div>

                    <!-- Slider Control -->
                    <div class="mb-10">
                        <div class="flex justify-between items-center mb-3">
                            <label class="text-sm font-bold text-heading">Volume Mensal de Tokens:</label>
                            <span id="sliderValueBadge" class="text-lg font-mono font-extrabold text-indigo-600 dark:text-indigo-400">25.000.000 Tokens</span>
                        </div>
                        <input type="range" id="tokensSlider" min="2" max="150" value="25" step="1" class="w-full h-3 bg-gray-200 dark:bg-gray-800 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                        <div class="flex justify-between text-[11px] text-muted-custom font-mono mt-2">
                            <span>2M (Dev Casual)</span>
                            <span>25M (Pro diário)</span>
                            <span>75M (Agência)</span>
                            <span>150M (Enterprise)</span>
                        </div>
                    </div>

                    <!-- Comparison Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-6 border-t border-[var(--border-color)] text-center">
                        <div class="p-4 rounded-2xl bg-rose-500/5 border border-rose-500/20">
                            <span class="text-xs text-rose-500 font-bold uppercase tracking-wider block mb-1">API Oficial Direta (Dólar + IOF)</span>
                            <div id="costOfficial" class="text-2xl sm:text-3xl font-mono font-extrabold text-rose-600 dark:text-rose-400">R$ 1.875</div>
                            <span class="text-[11px] text-muted-custom block mt-1">Sujeito a bloqueio de cartão</span>
                        </div>

                        <div class="p-4 rounded-2xl bg-indigo-500/10 border border-indigo-500/30">
                            <span class="text-xs text-indigo-600 dark:text-indigo-400 font-bold uppercase tracking-wider block mb-1">Na BRLove API (PIX)</span>
                            <div id="costBrlove" class="text-2xl sm:text-3xl font-mono font-extrabold text-heading">R$ 249</div>
                            <span class="text-[11px] text-muted-custom block mt-1">Preço fixo em Reais via PIX</span>
                        </div>

                        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                            <span class="text-xs text-emerald-600 dark:text-emerald-400 font-bold uppercase tracking-wider block mb-1">Sua Economia Real</span>
                            <div id="economySaving" class="text-2xl sm:text-3xl font-mono font-extrabold text-emerald-600 dark:text-emerald-400">R$ 1.626</div>
                            <span class="text-[11px] text-emerald-500 font-bold block mt-1">Economia de ~86%</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Plans & Pricing Section (Dynamically from MySQL) -->
        <section id="planos" class="py-20 border-t border-[var(--border-color)]" style="background-color: var(--bg-surface);">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                <div class="max-w-3xl mx-auto mb-16">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3" style="background-color: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border);">
                        <i class="fa-solid fa-tags"></i> Planos Transparentes
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-heading">Escolha o Pacote Ideal para o Seu Código</h2>
                    <p class="text-muted-custom mt-3">Tokens pré-pagos sem pegadinhas ou cobranças ocultas. Liberação imediata após PIX.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-stretch">
                    <?php foreach ($clientPlans as $p): 
                        $isFeatured = !empty($p['is_featured']);
                        $feats = array_filter(explode("\n", $p['features'] ?? ''));
                    ?>
                        <div class="surface-card rounded-3xl p-8 flex flex-col justify-between relative transition-all duration-300 hover:scale-[1.02] <?= $isFeatured ? 'border-2 border-indigo-500 shadow-2xl' : '' ?>">
                            <?php if ($isFeatured): ?>
                                <div class="absolute -top-4 left-1/2 -translate-x-1/2 px-4 py-1 rounded-full btn-brand text-xs font-extrabold uppercase tracking-wider shadow-lg">
                                    <?= htmlspecialchars($p['badge'] ?: 'Mais Popular') ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h3 class="text-2xl font-bold text-heading mb-1"><?= htmlspecialchars($p['name']) ?></h3>
                                <div class="text-sm font-mono text-muted-custom mb-6 font-semibold"><?= number_format($p['tokens_amount'], 0, ',', '.') ?> Tokens</div>
                                
                                <div class="flex items-baseline justify-center gap-1 mb-8">
                                    <span class="text-base text-muted-custom font-semibold">R$</span>
                                    <span class="text-5xl font-extrabold text-heading"><?= number_format($p['price'], 2, ',', '.') ?></span>
                                    <span class="text-xs text-muted-custom font-medium">/único</span>
                                </div>

                                <ul class="text-left text-sm space-y-3.5 mb-8">
                                    <?php foreach ($feats as $f): ?>
                                        <li class="flex items-start gap-3">
                                            <i class="fa-solid fa-circle-check text-emerald-500 text-sm mt-0.5 shrink-0"></i>
                                            <span class="text-heading text-xs font-medium"><?= htmlspecialchars(trim($f)) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <a href="register.php?plan_id=<?= (int)$p['id'] ?>" class="w-full py-4 rounded-xl text-xs font-bold text-center shadow-lg transition <?= $isFeatured ? 'btn-brand' : 'surface-card hover:border-indigo-500 text-heading' ?>">
                                <i class="fa-solid fa-qrcode mr-1.5"></i> Comprar via PIX Instantâneo
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Reseller Banner Callout -->
                <div class="mt-16 surface-card p-8 rounded-3xl border-2 border-amber-500/30 flex flex-col md:flex-row items-center justify-between gap-6 text-left">
                    <div class="flex items-center gap-5">
                        <div class="w-16 h-16 rounded-2xl bg-amber-500/10 text-amber-500 flex items-center justify-center text-3xl shrink-0 font-bold">
                            <i class="fa-solid fa-briefcase"></i>
                        </div>
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-amber-500">Programa de Parcerias & Agências</span>
                            <h3 class="text-xl font-bold text-heading mt-0.5">Quer faturar revendendo tokens de IA com sua própria margem?</h3>
                            <p class="text-sm text-muted-custom mt-1">Compre tokens no atacado, cadastre clientes e gerencie chaves no seu painel de revendedor.</p>
                        </div>
                    </div>
                    <a href="revenda.php" class="px-6 py-3.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-extrabold text-xs shrink-0 shadow-lg transition flex items-center gap-2">
                        Acessar Portal da Revenda <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </section>

        <!-- FAQ Section -->
        <section id="faq" class="py-20 border-t border-[var(--border-color)]">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3" style="background-color: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border);">
                        <i class="fa-solid fa-circle-question"></i> Dúvidas Frequentes
                    </div>
                    <h2 class="text-3xl font-extrabold text-heading">Perguntas Frequentes de Desenvolvedores</h2>
                </div>

                <div class="space-y-4">
                    <!-- FAQ 1 -->
                    <details class="surface-card rounded-2xl p-6 group cursor-pointer transition">
                        <summary class="font-bold text-heading text-base flex justify-between items-center list-none">
                            <span>Como configuro a BRLove API no Cursor, Cline e VS Code?</span>
                            <i class="fa-solid fa-chevron-down text-muted-custom group-open:rotate-180 transition-transform"></i>
                        </summary>
                        <p class="text-muted-custom text-sm mt-4 leading-relaxed">
                            É imediato: acesse o seu painel de cliente, gere sua chave de API e copie a URL Base (<code><?= htmlspecialchars(getGatewayPublicUrl()) ?>/v1</code>). No Cursor ou na extensão Cline/Roo Code do VS Code, escolha o provedor "OpenAI Compatible" ou "Anthropic Compatible" e cole sua URL e Chave. Pronto, você já começa a codificar com streaming em tempo real!
                        </p>
                    </details>

                    <!-- FAQ 2 -->
                    <details class="surface-card rounded-2xl p-6 group cursor-pointer transition">
                        <summary class="font-bold text-heading text-base flex justify-between items-center list-none">
                            <span>Quais modelos exatamente estão disponíveis?</span>
                            <i class="fa-solid fa-chevron-down text-muted-custom group-open:rotate-180 transition-transform"></i>
                        </summary>
                        <p class="text-muted-custom text-sm mt-4 leading-relaxed">
                            Oferecemos acesso oficial de alta velocidade ao <strong>Claude Sonnet 5.5</strong>, <strong>Claude Opus 5.5 Thinking</strong> e <strong>Claude 5.5 Haiku</strong>. Nossa arquitetura gerencia otimização de latência contínua para garantir que suas requisições nunca travem.
                        </p>
                    </details>

                    <!-- FAQ 3 -->
                    <details class="surface-card rounded-2xl p-6 group cursor-pointer transition">
                        <summary class="font-bold text-heading text-base flex justify-between items-center list-none">
                            <span>Os tokens expiram com o tempo?</span>
                            <i class="fa-solid fa-chevron-down text-muted-custom group-open:rotate-180 transition-transform"></i>
                        </summary>
                        <p class="text-muted-custom text-sm mt-4 leading-relaxed">
                            Não! Os tokens adquiridos não possuem prazo de validade rígido mensal. Você utiliza no seu próprio ritmo até o consumo total do saldo adquirido.
                        </p>
                    </details>

                    <!-- FAQ 4 -->
                    <details class="surface-card rounded-2xl p-6 group cursor-pointer transition">
                        <summary class="font-bold text-heading text-base flex justify-between items-center list-none">
                            <span>Como funciona a ativação do pagamento via PIX?</span>
                            <i class="fa-solid fa-chevron-down text-muted-custom group-open:rotate-180 transition-transform"></i>
                        </summary>
                        <p class="text-muted-custom text-sm mt-4 leading-relaxed">
                            O processamento é 100% automatizado através do nosso gateway de pagamento instantâneo via QR Code e Copia-e-Cola. Assim que o banco confirma o PIX (geralmente entre 2 a 5 segundos), o saldo de tokens é creditado na hora em sua conta.
                        </p>
                    </details>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="py-12 border-t border-[var(--border-color)]" style="background-color: var(--bg-surface);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-6 text-center md:text-left">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl gradient-brand flex items-center justify-center text-white text-lg font-bold">
                        <i class="fa-solid fa-brain"></i>
                    </div>
                    <div>
                        <span class="text-base font-extrabold text-heading"><?= htmlspecialchars($appName) ?></span>
                        <span class="text-xs block text-muted-custom">A infraestrutura de IA preferida dos desenvolvedores</span>
                    </div>
                </div>

                <div class="flex flex-wrap justify-center gap-6 text-xs text-muted-custom font-semibold">
                    <a href="#playground" class="hover:text-heading transition">Playground</a>
                    <a href="#modelos" class="hover:text-heading transition">Modelos</a>
                    <a href="#planos" class="hover:text-heading transition">Preços</a>
                    <a href="revenda.php" class="text-amber-500 hover:text-amber-400 transition font-bold">Programa de Revenda</a>
                    <a href="login.php" class="hover:text-heading transition">Login</a>
                    <a href="register.php" class="hover:text-heading transition">Criar Conta</a>
                </div>

                <div class="text-xs text-muted-custom">
                    &copy; <?= date('Y') ?> <?= htmlspecialchars($appName) ?>. Todos os direitos reservados.
                </div>
            </div>
        </div>
    </footer>

    <!-- Client Scripts for Code Simulation -->
    <script>
        const snippets = {
            curl: `curl -X POST "<?= htmlspecialchars(getGatewayPublicUrl()) ?>/v1/messages" \\
  -H "x-api-key: brlove_live_sua_chave_aqui" \\
  -H "anthropic-version: 2023-06-01" \\
  -H "content-type: application/json" \\
  -d '{
    "model": "claude-sonnet-5-5",
    "max_tokens": 1024,
    "stream": true,
    "messages": [
      {"role": "user", "content": "Escreva uma API REST de alta performance em Go."}
    ]
  }'`,
            python: `import anthropic

client = anthropic.Anthropic(
    api_key="brlove_live_sua_chave_aqui",
    base_url="<?= htmlspecialchars(getGatewayPublicUrl()) ?>/v1"
)

response = client.messages.create(
    model="claude-sonnet-5-5",
    max_tokens=1024,
    messages=[
        {"role": "user", "content": "Crie um algoritmo distribuído de rate limit em Redis."}
    ]
)

print(response.content[0].text)`,
            node: `import OpenAI from "openai";

const client = new OpenAI({
    apiKey: "brlove_live_sua_chave_aqui",
    baseURL: "<?= htmlspecialchars(getGatewayPublicUrl()) ?>/v1"
});

const stream = await client.chat.completions.create({
    model: "claude-sonnet-5-5",
    messages: [{ role: "user", content: "Refatore esta função TypeScript com Zero Allocation." }],
    stream: true
});

for await (const chunk of stream) {
    process.stdout.write(chunk.choices[0]?.delta?.content || "");
} `,
            cursor: `// Configuração no Cursor ou no VS Code (Cline / Continue.dev)
{
  "apiProvider": "anthropic",
  "apiKey": "brlove_live_sua_chave_aqui",
  "anthropicBaseUrl": "<?= htmlspecialchars(getGatewayPublicUrl()) ?>/v1",
  "model": "claude-sonnet-5-5",
  "temperature": 0.2
}`
        };

        let currentActiveTab = 'curl';

        function selectCodeTab(tab) {
            currentActiveTab = tab;
            document.querySelectorAll('.code-tab-btn').forEach(btn => {
                btn.className = 'code-tab-btn px-3 py-1.5 rounded-lg text-xs font-mono font-semibold transition text-gray-400 hover:text-white';
            });
            const activeBtn = document.getElementById(`tabBtn_${tab}`);
            if (activeBtn) {
                activeBtn.className = 'code-tab-btn active px-3 py-1.5 rounded-lg text-xs font-mono font-semibold transition bg-indigo-600 text-white';
            }
            const box = document.getElementById('codeSnippetBox');
            if (box && snippets[tab]) {
                box.innerHTML = `<code>${escapeHtml(snippets[tab])}</code>`;
            }
        }

        function copyCurrentCodeSnippet() {
            const text = snippets[currentActiveTab] || '';
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.getElementById('copyCodeBtn');
                btn.innerHTML = '<i class="fa-solid fa-check text-emerald-400"></i> Copiado!';
                setTimeout(() => {
                    btn.innerHTML = '<i class="fa-regular fa-copy"></i> Copiar';
                }, 2000);
            });
        }

        function escapeHtml(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        // Live Simulated SSE Streamer
        let isStreaming = false;
        function simulateLiveSSE() {
            if (isStreaming) return;
            isStreaming = true;

            const term = document.getElementById('terminalStreamOutput');
            const statusBadge = document.getElementById('streamStatusBadge');
            const metricTokensSec = document.getElementById('metricTokensSec');
            const metricTtft = document.getElementById('metricTtft');
            const runBtn = document.getElementById('runStreamBtn');

            term.innerHTML = '<div class="text-cyan-400 font-bold">&gt; Conectando ao gateway neural BRLove...</div>';
            statusBadge.innerHTML = '<span class="text-amber-400 pulse-live"><i class="fa-solid fa-circle text-[8px]"></i> Conectando...</span>';
            runBtn.disabled = true;
            runBtn.classList.add('opacity-50');

            setTimeout(() => {
                statusBadge.innerHTML = '<span class="text-emerald-400"><i class="fa-solid fa-circle text-[8px]"></i> Streaming 200 OK</span>';
                metricTtft.innerText = '142 ms';
                metricTokensSec.innerText = '64 tokens/s';
                term.innerHTML += '<div class="text-gray-400 text-[11px] mb-2">// Modelo: claude-sonnet-5-5 | Protocolo: Anthropic SSE v2</div>';

                const sampleCode = [
                    'package main',
                    '',
                    'import (',
                    '    "fmt"',
                    '    "net/http"',
                    ')',
                    '',
                    'func handleStream(w http.ResponseWriter, r *http.Request) {',
                    '    w.Header().Set("Content-Type", "text/event-stream")',
                    '    w.Header().Set("Cache-Control", "no-cache")',
                    '    w.Header().Set("Connection", "keep-alive")',
                    '    fmt.Fprintf(w, "data: {\\\"status\\\": \\\"ultra_rapido\\\"}\\n\\n")',
                    '}',
                    '',
                    '// Requisição processada com sucesso em 185ms!'
                ];

                let lineIndex = 0;
                const streamInterval = setInterval(() => {
                    if (lineIndex < sampleCode.length) {
                        const line = sampleCode[lineIndex];
                        const div = document.createElement('div');
                        div.className = line.startsWith('//') ? 'text-cyan-400 font-bold' : (line.includes('func') || line.includes('package') ? 'text-indigo-300' : 'text-emerald-400');
                        div.textContent = line || ' ';
                        term.appendChild(div);
                        term.scrollTop = term.scrollHeight;
                        lineIndex++;
                    } else {
                        clearInterval(streamInterval);
                        isStreaming = false;
                        runBtn.disabled = false;
                        runBtn.classList.remove('opacity-50');
                        statusBadge.innerHTML = '<span class="text-emerald-400 font-bold"><i class="fa-solid fa-check"></i> Concluído</span>';
                    }
                }, 85);
            }, 300);
        }

        // Token Economy Calculator Slider Logic
        const tokensSlider = document.getElementById('tokensSlider');
        const sliderValueBadge = document.getElementById('sliderValueBadge');
        const costOfficial = document.getElementById('costOfficial');
        const costBrlove = document.getElementById('costBrlove');
        const economySaving = document.getElementById('economySaving');

        if (tokensSlider) {
            tokensSlider.addEventListener('input', function() {
                const millions = parseInt(this.value, 10);
                sliderValueBadge.innerText = (millions * 1000000).toLocaleString('pt-BR') + ' Tokens';

                // Official Anthropic API: ~$15/1M tokens = ~R$ 75 por milhão + IOF 6.38% + Spread = ~R$ 82/1M
                const officialVal = Math.round(millions * 82);
                // BRLove API: ~R$ 10 a R$ 12 por milhão
                const brloveVal = Math.round(millions * 10.5);
                const savingsVal = Math.max(0, officialVal - brloveVal);

                costOfficial.innerText = 'R$ ' + officialVal.toLocaleString('pt-BR');
                costBrlove.innerText = 'R$ ' + brloveVal.toLocaleString('pt-BR');
                economySaving.innerText = 'R$ ' + savingsVal.toLocaleString('pt-BR');
            });
        }

        // Toggle do Menu Mobile da Landing Page
        function toggleMobileMenu() {
            const drawer = document.getElementById('mobileMenuDrawer');
            const icon = document.getElementById('mobileMenuIcon');
            if (!drawer) return;
            drawer.classList.toggle('hidden');
            if (icon) {
                if (drawer.classList.contains('hidden')) {
                    icon.className = 'fa-solid fa-bars text-sm sm:text-base pointer-events-none';
                } else {
                    icon.className = 'fa-solid fa-xmark text-sm sm:text-base pointer-events-none';
                }
            }
        }
    </script>
</body>
</html>
