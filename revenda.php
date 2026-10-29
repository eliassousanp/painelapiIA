<?php
require_once __DIR__ . '/config/helpers.php';
$db = getDB();

// Buscar planos de revenda ativos do banco MySQL
try {
    $stmtResellerPlans = $db->query("SELECT * FROM plans WHERE status = 'active' AND target_audience IN ('all', 'reseller') ORDER BY price ASC");
    $resellerPlans = $stmtResellerPlans->fetchAll();
} catch (Exception $e) {
    $resellerPlans = [];
}

if (empty($resellerPlans)) {
    $resellerPlans = [
        [
            'id' => 10,
            'name' => 'Pacote Atacado Silver',
            'price' => 199.00,
            'tokens_amount' => 100000000,
            'badge' => '100 Milhões',
            'features' => "100 Milhões de Tokens para Distribuir\nCadastro Ilimitado de Clientes Finais\nPainel White-Label com Sua Própria Marca\nSem Prazo Rígido de Expiração dos Tokens\nMargem Média de Lucro: 300% a 400%\nSuporte Prioritário para Revendedores",
            'is_featured' => 0
        ],
        [
            'id' => 11,
            'name' => 'Pacote Atacado Gold',
            'price' => 499.00,
            'tokens_amount' => 500000000,
            'badge' => 'Mais Vendido (500M)',
            'features' => "500 Milhões de Tokens para Alta Demanda\nClientes Ilimitados com Chaves Individuais\nControle de Cota e Limites por Sub-Chave\nMargem Média de Lucro Estimada: 500%\nPrioridade em Suporte VIP 24/7 com Engenheiros\nAcesso a Modelos Claude Sonnet 5.5 & Opus 5.5 Thinking",
            'is_featured' => 1
        ],
        [
            'id' => 12,
            'name' => 'Pacote Atacado Titanium',
            'price' => 999.00,
            'tokens_amount' => 1200000000,
            'badge' => '1.2 Bilhão (Master)',
            'features' => "1.2 Bilhão de Tokens com Maior Desconto\nCusto por Milhão de Tokens Imbatível\nGerente de Contas Dedicado no WhatsApp\nSLA Garantido de 99.98% e Fila Exclusiva\nSubdomínio Customizado para sua Empresa\nChaves de API Ilimitadas e Relatórios em CSV",
            'is_featured' => 0
        ]
    ];
}

$initialThemeMode = getThemeMode();
$initialThemeScheme = getThemeScheme();
$appName = getAppName();
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= htmlspecialchars($initialThemeMode) ?>" data-scheme="<?= htmlspecialchars($initialThemeScheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programa de Revendedores de IA - <?= htmlspecialchars($appName) ?></title>
    <meta name="description" content="Seja dono do seu próprio negócio de Inteligência Artificial. Compre tokens de Claude Sonnet 5.5, Opus 5.5 e Haiku 5.5 no atacado e revenda com margem de 300% a 500%.">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '[data-theme="dark"]'],
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

    <!-- Theme Initialization Script -->
    <script>
        (function() {
            const savedMode = localStorage.getItem('theme_mode');
            const savedScheme = localStorage.getItem('theme_scheme');
            if (savedMode) {
                document.documentElement.setAttribute('data-theme', savedMode);
            }
            if (savedScheme) {
                document.documentElement.setAttribute('data-scheme', savedScheme);
            }
        })();
    </script>

    <style>
        :root {
            /* Modo Claro (Clean White) */
            --bg-base: #f8fafc;
            --bg-surface: #ffffff;
            --bg-surface-elevated: #ffffff;
            --border-color: #e2e8f0;
            --border-highlight: #cbd5e1;
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --nav-bg: rgba(255, 255, 255, 0.88);
            --card-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05), 0 2px 6px -2px rgba(0, 0, 0, 0.03);
            --accent-gold: #d97706;
            --accent-gold-light: #f59e0b;
            --badge-bg: #fef3c7;
            --badge-text: #92400e;
            --badge-border: #fde68a;
        }

        /* Modo Escuro (Cyber Gold / Obsidian) */
        html[data-theme="dark"] {
            --bg-base: #07090e;
            --bg-surface: #0e131f;
            --bg-surface-elevated: #151d30;
            --border-color: rgba(255, 255, 255, 0.08);
            --border-highlight: rgba(245, 158, 11, 0.3);
            --text-heading: #f8fafc;
            --text-body: #94a3b8;
            --text-muted: #64748b;
            --nav-bg: rgba(7, 9, 14, 0.88);
            --card-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.7);
            --accent-gold: #f59e0b;
            --accent-gold-light: #fbbf24;
            --badge-bg: rgba(245, 158, 11, 0.15);
            --badge-text: #fbbf24;
            --badge-border: rgba(245, 158, 11, 0.3);
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

        .gradient-gold {
            background: linear-gradient(135deg, #f59e0b 0%, #eab308 50%, #d97706 100%);
        }

        .gradient-gold-text {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 50%, #eab308 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .btn-gold {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff;
            transition: all 0.2s ease;
        }

        .btn-gold:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: 0 8px 25px -4px rgba(245, 158, 11, 0.4);
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
<body class="font-sans antialiased min-h-screen selection:bg-amber-500 selection:text-slate-950 flex flex-col">

    <!-- Top Reseller Banner -->
    <div class="bg-gradient-to-r from-amber-950 via-amber-900 to-yellow-950 text-amber-200 text-[12px] py-2 px-4 font-semibold text-center flex items-center justify-center gap-2 border-b border-amber-800/40">
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-slate-950 uppercase tracking-wider">Oportunidade B2B</span>
        <span>Revenda tokens de IA com seu próprio preço e fature com pagamentos recorrentes no Brasil.</span>
        <a href="#simulador" class="underline hover:text-white ml-1 font-bold">Simular Lucros &rarr;</a>
    </div>

    <!-- Header / Navbar Exclusivo da Revenda -->
    <header class="sticky top-0 z-50 glass-nav transition-all">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 h-16 sm:h-20 flex items-center justify-between">
            <!-- Brand Logo -->
            <a href="revenda.php" class="flex items-center gap-2.5 sm:gap-3 group min-w-0">
                <div class="w-9 h-9 sm:w-11 sm:h-11 rounded-2xl gradient-gold flex items-center justify-center text-slate-950 text-lg sm:text-xl font-black shadow-md transition-transform group-hover:scale-105 shrink-0">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div class="min-w-0">
                    <span class="text-base sm:text-xl font-extrabold tracking-tight text-heading flex items-center gap-1.5">
                        <?= htmlspecialchars($appName) ?>
                        <span class="text-[9px] sm:text-xs px-1.5 sm:px-2 py-0.5 rounded-md font-extrabold uppercase tracking-wider shrink-0" style="background-color: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border);">Revenda</span>
                    </span>
                    <span class="text-[10px] hidden sm:block font-mono text-muted-custom leading-none mt-0.5">Programa Oficial de Parceiros & Agências</span>
                </div>
            </a>

            <!-- Nav Links (Desktop) -->
            <nav class="hidden lg:flex items-center space-x-6 text-sm font-semibold">
                <a href="#simulador" class="hover:text-amber-500 transition-colors">Simulador de Lucro</a>
                <a href="#vantagens" class="hover:text-amber-500 transition-colors">Vantagens</a>
                <a href="#painel-preview" class="hover:text-amber-500 transition-colors">Seu Painel</a>
                <a href="#planos-revenda" class="hover:text-amber-500 transition-colors">Pacotes Atacado</a>
                <a href="#faq" class="hover:text-amber-500 transition-colors">FAQ</a>
                <a href="index.php" class="text-muted-custom hover:text-heading transition-colors text-xs flex items-center gap-1">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i> Ver Portal Dev
                </a>
            </nav>

            <!-- Actions & Theme Toggle -->
            <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                <!-- Theme Mode Toggle -->
                <button id="themeToggleBtn" type="button" aria-label="Alternar Tema Claro/Escuro" title="Alternar entre modo claro e escuro" class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl surface-card flex items-center justify-center text-heading hover:scale-105 transition cursor-pointer shrink-0">
                    <i id="themeToggleIcon" class="fa-solid fa-sun text-amber-500 text-sm sm:text-base pointer-events-none"></i>
                </button>

                <!-- Auth Buttons (Desktop/Tablet) -->
                <div class="hidden sm:flex items-center gap-2">
                    <?php if (isLoggedIn()): ?>
                        <?php if (isReseller()): ?>
                            <a href="reseller/index.php" class="px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl btn-gold text-xs font-black shadow-md flex items-center gap-1.5">
                                <i class="fa-solid fa-gauge"></i> Meu Painel
                            </a>
                        <?php elseif (isAdmin()): ?>
                            <a href="admin/index.php" class="px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl surface-card text-heading text-xs font-bold shadow-md flex items-center gap-1.5">
                                <i class="fa-solid fa-shield-halved"></i> Admin
                            </a>
                        <?php else: ?>
                            <a href="client/index.php" class="px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl surface-card text-heading text-xs font-bold shadow-md flex items-center gap-1.5">
                                <i class="fa-solid fa-gauge"></i> Área do Cliente
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="login.php" class="text-heading hover:text-amber-500 text-xs font-bold px-2 sm:px-3 py-2 transition">Entrar</a>
                        <a href="register.php" class="px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl btn-gold text-xs font-black shadow-md flex items-center gap-1.5">
                            <i class="fa-solid fa-arrow-right-to-bracket"></i> Criar Conta
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Botão Menu Mobile (Hamburger) -->
                <button type="button" id="mobileMenuBtn" onclick="toggleResellerMobileMenu()" class="lg:hidden w-9 h-9 sm:w-10 sm:h-10 rounded-xl surface-card flex items-center justify-center text-heading hover:scale-105 transition active:scale-95 cursor-pointer shrink-0" aria-label="Abrir Menu">
                    <i id="mobileMenuIcon" class="fa-solid fa-bars text-sm sm:text-base pointer-events-none"></i>
                </button>
            </div>
        </div>

        <!-- Drawer Mobile Revenda -->
        <div id="resellerMobileMenuDrawer" class="hidden lg:hidden border-b border-[var(--border-color)] bg-[var(--nav-bg)] backdrop-blur-2xl px-4 py-5 space-y-3 transition-all duration-300 shadow-2xl">
            <div class="flex flex-col space-y-1 font-semibold text-sm">
                <a href="#simulador" onclick="toggleResellerMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-calculator text-amber-500 mr-2.5 text-xs"></i> Simulador de Lucro</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="#vantagens" onclick="toggleResellerMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-star text-amber-500 mr-2.5 text-xs"></i> Vantagens Exclusivas</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="#painel-preview" onclick="toggleResellerMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-laptop text-amber-500 mr-2.5 text-xs"></i> Demonstração do Painel</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="#planos-revenda" onclick="toggleResellerMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-boxes-stacked text-amber-500 mr-2.5 text-xs"></i> Pacotes de Tokens Atacado</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="#faq" onclick="toggleResellerMobileMenu()" class="px-3.5 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-gray-800/60 text-heading transition flex items-center justify-between">
                    <span><i class="fa-solid fa-circle-question text-amber-500 mr-2.5 text-xs"></i> FAQ da Revenda</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-muted-custom"></i>
                </a>
                <a href="index.php" class="px-3.5 py-2.5 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-500 font-bold transition flex items-center justify-between">
                    <span><i class="fa-solid fa-code text-xs mr-2"></i> Portal do Desenvolvedor</span>
                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </a>
            </div>

            <!-- Botões de Ação no Drawer Mobile -->
            <div class="pt-3 border-t border-[var(--border-color)] flex flex-col gap-2">
                <?php if (isLoggedIn()): ?>
                    <a href="reseller/index.php" class="w-full py-3 rounded-xl btn-gold text-slate-950 text-xs font-black shadow-md flex items-center justify-center gap-2">
                        <i class="fa-solid fa-gauge"></i> Acessar Meu Painel de Revenda
                    </a>
                <?php else: ?>
                    <div class="grid grid-cols-2 gap-2">
                        <a href="login.php" class="w-full py-2.5 rounded-xl surface-card text-heading text-xs font-bold text-center flex items-center justify-center">Entrar</a>
                        <a href="register.php" class="w-full py-2.5 rounded-xl btn-gold text-slate-950 text-xs font-black shadow-md text-center flex items-center justify-center">Criar Conta</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="flex-grow">
        <!-- Hero Section -->
        <section class="relative pt-12 pb-20 overflow-hidden">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Status Badge -->
                <div class="flex justify-center mb-6">
                    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full surface-card text-xs font-semibold">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500 pulse-live"></span>
                        <span class="text-heading font-medium">Margem Líquida Média:</span>
                        <span class="text-amber-500 font-extrabold">+300% a +600%</span>
                        <span class="text-muted-custom">•</span>
                        <span class="text-muted-custom">Ativação Instantânea via PIX</span>
                    </div>
                </div>

                <!-- Headline -->
                <div class="text-center max-w-4xl mx-auto">
                    <h1 class="text-4xl sm:text-6xl lg:text-7xl font-extrabold tracking-tight text-heading leading-[1.1] mb-6">
                        Construa Sua Própria Empresa de <span class="gradient-gold-text">Inteligência Artificial</span>.
                    </h1>
                    <p class="text-lg sm:text-xl text-muted-custom max-w-3xl mx-auto leading-relaxed mb-10">
                        Compre pacotes de tokens de alta velocidade no atacado e revenda para empresas, desenvolvedores e agências pelo preço que você quiser. Tenha seu próprio painel de gestão de clientes e fature em Reais.
                    </p>

                    <!-- CTAs -->
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-14">
                        <a href="#planos-revenda" class="w-full sm:w-auto px-8 py-4 rounded-2xl btn-gold font-black text-base shadow-xl flex items-center justify-center gap-2.5">
                            <i class="fa-solid fa-coins"></i> Ver Pacotes de Atacado
                        </a>
                        <a href="#simulador" class="w-full sm:w-auto px-8 py-4 rounded-2xl surface-card text-heading font-bold text-base hover:scale-105 transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-calculator text-amber-500"></i> Simular Seu Lucro Mensal
                        </a>
                    </div>
                </div>

                <!-- Reseller Dashboard Mockup Visual -->
                <div id="painel-preview" class="mt-12 max-w-6xl mx-auto">
                    <div class="surface-card p-3 sm:p-4 rounded-3xl border-2 shadow-2xl">
                        <div class="flex items-center justify-between pb-3 px-2 border-b border-[var(--border-color)] text-xs text-muted-custom font-mono">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                                <span class="font-bold text-heading ml-2">Painel de Gestão de Sub-Contas & Faturamento</span>
                            </div>
                            <span class="text-amber-500 font-bold flex items-center gap-1.5">
                                <i class="fa-solid fa-chart-line"></i> Margem de Retorno: 450% ROI
                            </span>
                        </div>
                        <img src="assets/images/reseller_portal_preview.jpg" alt="Mockup do Painel de Revendedor de Inteligência Artificial" class="w-full h-auto rounded-2xl mt-3 object-cover shadow-inner">
                    </div>
                </div>
            </div>
        </section>

        <!-- Interactive Reseller Profit Simulator -->
        <section id="simulador" class="py-20 border-t border-[var(--border-color)]" style="background-color: var(--bg-surface);">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="surface-card p-8 sm:p-12 rounded-3xl border-2 shadow-2xl">
                    <div class="text-center max-w-2xl mx-auto mb-10">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3 bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                            <i class="fa-solid fa-calculator"></i> Simulador de Faturamento
                        </div>
                        <h2 class="text-3xl font-extrabold text-heading">Calcule Seu Lucro Líquido Mensal</h2>
                        <p class="text-muted-custom text-sm mt-2">Ajuste os controles deslizantes abaixo e veja quanto você coloca no bolso todos os meses.</p>
                    </div>

                    <!-- Sliders Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
                        <!-- Slider 1: Clientes -->
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <label class="text-xs font-bold text-heading">Número de Clientes Ativos:</label>
                                <span id="clientsCountDisplay" class="text-base font-mono font-extrabold text-amber-500">20 Clientes</span>
                            </div>
                            <input type="range" id="resellerClientsSlider" min="2" max="100" value="20" step="1" class="w-full h-3 bg-gray-200 dark:bg-gray-800 rounded-lg appearance-none cursor-pointer accent-amber-500">
                            <div class="flex justify-between text-[11px] text-muted-custom font-mono mt-1">
                                <span>2 clientes</span>
                                <span>50 clientes</span>
                                <span>100 clientes</span>
                            </div>
                        </div>

                        <!-- Slider 2: Mensalidade cobrada -->
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <label class="text-xs font-bold text-heading">Mensalidade Média Cobrada por Cliente:</label>
                                <span id="pricePerClientDisplay" class="text-base font-mono font-extrabold text-amber-500">R$ 97 / mês</span>
                            </div>
                            <input type="range" id="resellerPriceSlider" min="39" max="299" value="97" step="5" class="w-full h-3 bg-gray-200 dark:bg-gray-800 rounded-lg appearance-none cursor-pointer accent-amber-500">
                            <div class="flex justify-between text-[11px] text-muted-custom font-mono mt-1">
                                <span>R$ 39 (Básico)</span>
                                <span>R$ 150 (Médio)</span>
                                <span>R$ 299 (Avançado)</span>
                            </div>
                        </div>
                    </div>

                    <!-- Real-time Financial Breakdown -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-6 border-t border-[var(--border-color)] text-center">
                        <div class="p-4 rounded-2xl bg-gray-100 dark:bg-gray-900 border border-[var(--border-color)]">
                            <span class="text-xs text-muted-custom font-bold uppercase tracking-wider block mb-1">Faturamento Bruto Mensal</span>
                            <div id="simRevenue" class="text-2xl sm:text-3xl font-mono font-extrabold text-heading">R$ 1.940</div>
                            <span class="text-[11px] text-muted-custom block mt-1">Recebido dos seus clientes</span>
                        </div>

                        <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20">
                            <span class="text-xs text-amber-600 dark:text-amber-400 font-bold uppercase tracking-wider block mb-1">Seu Custo em Tokens no Atacado</span>
                            <div id="simCost" class="text-2xl sm:text-3xl font-mono font-extrabold text-heading">R$ 199</div>
                            <span class="text-[11px] text-muted-custom block mt-1">Pacote BRLove no atacado</span>
                        </div>

                        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                            <span class="text-xs text-emerald-600 dark:text-emerald-400 font-bold uppercase tracking-wider block mb-1">Seu Lucro Líquido no Bolso</span>
                            <div id="simProfit" class="text-2xl sm:text-3xl font-mono font-extrabold text-emerald-600 dark:text-emerald-400">R$ 1.741</div>
                            <span id="simRoiBadge" class="text-[11px] text-emerald-500 font-bold block mt-1">Retorno de +875%</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Por que Revender Tokens de IA? -->
        <section id="vantagens" class="py-20 border-t border-[var(--border-color)]">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3 bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                        <i class="fa-solid fa-crown"></i> Por Que Revender?
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-heading">O Maior Mercado dos Próximos 10 Anos</h2>
                    <p class="text-muted-custom mt-3">Todas as empresas de software, agências de marketing e programadores precisam de IA diária para produzir código e conteúdo.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="surface-card p-8 rounded-3xl relative">
                        <div class="w-12 h-12 rounded-2xl bg-amber-500/10 text-amber-500 flex items-center justify-center text-xl mb-6 font-bold">
                            <i class="fa-solid fa-credit-card"></i>
                        </div>
                        <h3 class="text-xl font-bold text-heading mb-3">Elimina a Barreira do Dólar</h3>
                        <p class="text-muted-custom text-sm leading-relaxed">
                            Muitos desenvolvedores no Brasil não possuem cartão internacional corporativo ou têm compras negadas na Anthropic e OpenAI. Você cobra via PIX em Reais e resolve a dor do seu cliente.
                        </p>
                    </div>

                    <div class="surface-card p-8 rounded-3xl relative">
                        <div class="w-12 h-12 rounded-2xl bg-amber-500/10 text-amber-500 flex items-center justify-center text-xl mb-6 font-bold">
                            <i class="fa-solid fa-sliders"></i>
                        </div>
                        <h3 class="text-xl font-bold text-heading mb-3">Controle Total de Cotas</h3>
                        <p class="text-muted-custom text-sm leading-relaxed">
                            Defina quantos tokens cada cliente pode gastar por dia ou por mês. Crie planos Starter, Pro e VIP personalizados com suas próprias regras de cobrança.
                        </p>
                    </div>

                    <div class="surface-card p-8 rounded-3xl relative">
                        <div class="w-12 h-12 rounded-2xl bg-amber-500/10 text-amber-500 flex items-center justify-center text-xl mb-6 font-bold">
                            <i class="fa-solid fa-arrows-rotate"></i>
                        </div>
                        <h3 class="text-xl font-bold text-heading mb-3">Receita Recorrente Previsível</h3>
                        <p class="text-muted-custom text-sm leading-relaxed">
                            Clientes corporativos continuam consumindo tokens mês após mês. Você cria uma carteira estável de assinaturas e constrói faturamento recorrente (MRR).
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Wholesale Reseller Plans -->
        <section id="planos-revenda" class="py-20 border-t border-[var(--border-color)]" style="background-color: var(--bg-surface);">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                <div class="max-w-3xl mx-auto mb-16">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3 bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                        <i class="fa-solid fa-boxes-stacked"></i> Pacotes no Atacado
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-heading">Escolha Seu Pacote de Atacado</h2>
                    <p class="text-muted-custom mt-3">Compre com desconto agressivo de volume e ative seu painel de revendedor na hora.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-stretch">
                    <?php foreach ($resellerPlans as $p): 
                        $isFeatured = !empty($p['is_featured']);
                        $feats = array_filter(explode("\n", $p['features'] ?? ''));
                    ?>
                        <div class="surface-card rounded-3xl p-8 flex flex-col justify-between relative transition-all duration-300 hover:scale-[1.02] <?= $isFeatured ? 'border-2 border-amber-500 shadow-2xl' : '' ?>">
                            <?php if ($isFeatured): ?>
                                <div class="absolute -top-4 left-1/2 -translate-x-1/2 px-4 py-1 rounded-full btn-gold text-xs font-black uppercase tracking-wider shadow-lg">
                                    <?= htmlspecialchars($p['badge'] ?: 'Mais Vendido') ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h3 class="text-2xl font-bold text-heading mb-1"><?= htmlspecialchars($p['name']) ?></h3>
                                <div class="text-sm font-mono text-amber-500 mb-6 font-bold"><?= number_format($p['tokens_amount'], 0, ',', '.') ?> Tokens</div>

                                <div class="flex items-baseline justify-center gap-1 mb-8">
                                    <span class="text-base text-muted-custom font-semibold">R$</span>
                                    <span class="text-5xl font-extrabold text-heading"><?= number_format($p['price'], 2, ',', '.') ?></span>
                                    <span class="text-xs text-muted-custom font-medium">/único</span>
                                </div>

                                <ul class="text-left text-sm space-y-3.5 mb-8">
                                    <?php foreach ($feats as $f): ?>
                                        <li class="flex items-start gap-3">
                                            <i class="fa-solid fa-circle-check text-amber-500 text-sm mt-0.5 shrink-0"></i>
                                            <span class="text-heading text-xs font-medium"><?= htmlspecialchars(trim($f)) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <a href="register.php?plan_id=<?= (int)$p['id'] ?>" class="w-full py-4 rounded-xl text-xs font-black text-center shadow-lg transition <?= $isFeatured ? 'btn-gold' : 'surface-card hover:border-amber-500 text-heading' ?>">
                                <i class="fa-solid fa-briefcase mr-1.5"></i> Comprar Pacote Atacado
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Reseller FAQ -->
        <section id="faq" class="py-20 border-t border-[var(--border-color)]">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3 bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                        <i class="fa-solid fa-circle-question"></i> Perguntas dos Revendedores
                    </div>
                    <h2 class="text-3xl font-extrabold text-heading">Dúvidas Frequentes sobre a Revenda</h2>
                </div>

                <div class="space-y-4">
                    <details class="surface-card rounded-2xl p-6 group cursor-pointer transition">
                        <summary class="font-bold text-heading text-base flex justify-between items-center list-none">
                            <span>Como funciona a gestão dos meus clientes?</span>
                            <i class="fa-solid fa-chevron-down text-muted-custom group-open:rotate-180 transition-transform"></i>
                        </summary>
                        <p class="text-muted-custom text-sm mt-4 leading-relaxed">
                            No seu painel de revendedor, você tem uma interface completa para cadastrar clientes, atribuir um saldo em tokens para cada um e gerar chaves de API exclusivas. Seu cliente usará a chave dele sem saber o seu custo de atacado.
                        </p>
                    </details>

                    <details class="surface-card rounded-2xl p-6 group cursor-pointer transition">
                        <summary class="font-bold text-heading text-base flex justify-between items-center list-none">
                            <span>Eu posso definir meus próprios preços para os clientes?</span>
                            <i class="fa-solid fa-chevron-down text-muted-custom group-open:rotate-180 transition-transform"></i>
                        </summary>
                        <p class="text-muted-custom text-sm mt-4 leading-relaxed">
                            Sim, 100%! Você tem total liberdade comercial para cobrar o que quiser dos seus clientes (mensalidade fixa, cobrança por token ou valor embutido nos seus serviços de desenvolvimento/software). O lucro da diferença é 100% seu.
                        </p>
                    </details>

                    <details class="surface-card rounded-2xl p-6 group cursor-pointer transition">
                        <summary class="font-bold text-heading text-base flex justify-between items-center list-none">
                            <span>Existe prazo de validade para os tokens do pacote de atacado?</span>
                            <i class="fa-solid fa-chevron-down text-muted-custom group-open:rotate-180 transition-transform"></i>
                        </summary>
                        <p class="text-muted-custom text-sm mt-4 leading-relaxed">
                            Não! Os pacotes de tokens de atacado adquiridos na BRLove API não expiram ao fim do mês. Eles ficam no seu saldo global até que seus clientes consumam.
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
                    <div class="w-9 h-9 rounded-xl gradient-gold flex items-center justify-center text-slate-950 text-lg font-black">
                        <i class="fa-solid fa-briefcase"></i>
                    </div>
                    <div>
                        <span class="text-base font-extrabold text-heading"><?= htmlspecialchars($appName) ?> Revenda</span>
                        <span class="text-xs block text-muted-custom">Seja o provedor de IA da sua região</span>
                    </div>
                </div>

                <div class="flex flex-wrap justify-center gap-6 text-xs text-muted-custom font-semibold">
                    <a href="index.php" class="hover:text-heading transition">Portal Desenvolvedor</a>
                    <a href="#simulador" class="hover:text-heading transition">Simulador de Lucro</a>
                    <a href="#planos-revenda" class="hover:text-heading transition">Pacotes Atacado</a>
                    <a href="login.php" class="hover:text-heading transition">Login</a>
                    <a href="register.php" class="text-amber-500 font-bold hover:text-amber-400 transition">Cadastrar Revenda</a>
                </div>

                <div class="text-xs text-muted-custom">
                    &copy; <?= date('Y') ?> <?= htmlspecialchars($appName) ?> Revenda. Todos os direitos reservados.
                </div>
            </div>
        </div>
    </footer>

    <!-- Theme and Simulator Scripts -->
    <script>
        // Theme Management
        const themeToggleBtn = document.getElementById('themeToggleBtn');
        const themeToggleIcon = document.getElementById('themeToggleIcon');

        function updateToggleIcon(mode) {
            if (mode === 'dark') {
                themeToggleIcon.className = 'fa-solid fa-moon text-amber-400 text-base';
            } else {
                themeToggleIcon.className = 'fa-solid fa-sun text-amber-500 text-base';
            }
        }

        updateToggleIcon(document.documentElement.getAttribute('data-theme') || 'light');

        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', () => {
                const currentMode = document.documentElement.getAttribute('data-theme') || 'light';
                const newMode = (currentMode === 'dark') ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', newMode);
                localStorage.setItem('theme_mode', newMode);
                document.cookie = `theme_mode=${newMode}; path=/; max-age=31536000`;
                updateToggleIcon(newMode);

                fetch('api/theme.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=set&mode=${encodeURIComponent(newMode)}`
                }).catch(() => {});
            });
        }

        // Reseller Profit Simulator Logic
        const clientsSlider = document.getElementById('resellerClientsSlider');
        const priceSlider = document.getElementById('resellerPriceSlider');
        const clientsDisplay = document.getElementById('clientsCountDisplay');
        const priceDisplay = document.getElementById('pricePerClientDisplay');
        const simRevenue = document.getElementById('simRevenue');
        const simCost = document.getElementById('simCost');
        const simProfit = document.getElementById('simProfit');
        const simRoiBadge = document.getElementById('simRoiBadge');

        function updateResellerSim() {
            const clients = parseInt(clientsSlider.value, 10);
            const price = parseInt(priceSlider.value, 10);

            clientsDisplay.innerText = clients + (clients === 1 ? ' Cliente' : ' Clientes');
            priceDisplay.innerText = 'R$ ' + price + ' / mês';

            const grossRevenue = clients * price;

            // Estimated tokens per client ~ 5M tokens/mo
            // Total monthly token demand = clients * 5M
            const totalTokensM = clients * 5;
            // Wholesale price per 100M is ~R$ 199 (R$ 1.99 per 1M)
            const wholesaleCost = Math.max(199, Math.round(totalTokensM * 1.99));
            const netProfit = Math.max(0, grossRevenue - wholesaleCost);
            const roiPercent = Math.round((netProfit / wholesaleCost) * 100);

            simRevenue.innerText = 'R$ ' + grossRevenue.toLocaleString('pt-BR');
            simCost.innerText = 'R$ ' + wholesaleCost.toLocaleString('pt-BR');
            simProfit.innerText = 'R$ ' + netProfit.toLocaleString('pt-BR');
            simRoiBadge.innerText = 'Retorno estimado: +' + roiPercent + '% ROI';
        }

        if (clientsSlider && priceSlider) {
            clientsSlider.addEventListener('input', updateResellerSim);
            priceSlider.addEventListener('input', updateResellerSim);
            updateResellerSim();
        }

        // Toggle do Menu Mobile do Portal da Revenda
        function toggleResellerMobileMenu() {
            const drawer = document.getElementById('resellerMobileMenuDrawer');
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
