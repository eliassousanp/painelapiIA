<?php
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/helpers.php';
requireAdmin();

$db = getDB();
$currentMode = getThemeMode();
if (isset($_COOKIE['theme_mode']) && in_array($_COOKIE['theme_mode'], ['light', 'dark'])) {
    $currentMode = $_COOKIE['theme_mode'];
}
$isDark = ($currentMode === 'dark');
$currentScheme = getThemeScheme();
if (isset($_COOKIE['theme_scheme'])) {
    $currentScheme = $_COOKIE['theme_scheme'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $isDark ? 'dark' : '' ?>" data-theme="<?= htmlspecialchars($currentMode) ?>" data-scheme="<?= htmlspecialchars($currentScheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title><?= $pageTitle ?? 'Painel Admin' ?> - BRLove API</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fira+Code:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'Fira Code', monospace; }
        .glass-sidebar {
            background: rgba(13, 17, 23, 0.95);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-header {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-card {
            background: rgba(30, 41, 59, 0.4);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }
        .sidebar-item-active {
            background: var(--active-nav-bg, rgba(99, 102, 241, 0.15)) !important;
            border-left: 3.5px solid var(--accent-primary, #6366f1) !important;
            color: var(--accent-primary, #6366f1) !important;
            font-weight: 700 !important;
        }
        html.dark .sidebar-item-active,
        html[data-theme="dark"] .sidebar-item-active {
            color: #ffffff !important;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.35);
        }
    </style>
    <link href="<?= getAppBaseUrl() ?>/assets/css/theme.css?v=<?= time() ?>" rel="stylesheet">
    <script>window.BRLOVE_BASE_URL = '<?= getAppBaseUrl() ?>';</script>
    <script src="<?= getAppBaseUrl() ?>/assets/js/theme.js"></script>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen flex overflow-x-hidden">

<?php require_once __DIR__ . '/sidebar.php'; ?>

<!-- ÁREA PRINCIPAL DE CONTEÚDO -->
<div class="flex-1 lg:pl-64 flex flex-col min-h-screen min-w-0 overflow-x-hidden">

    <!-- Backdrop escuro para fechar a sidebar móvel ao tocar fora -->
    <div id="sidebarBackdrop" onclick="toggleSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden transition-opacity lg:hidden"></div>

    <!-- Header Superior -->
    <header class="glass-header sticky top-0 z-40 h-16 px-3 sm:px-6 flex items-center justify-between">
        <div class="flex items-center space-x-2 sm:space-x-3 min-w-0">
            <button onclick="toggleSidebar()" class="lg:hidden text-gray-400 hover:text-white p-2 rounded-lg hover:bg-gray-800/60 transition shrink-0" aria-label="Abrir Menu">
                <i class="fa-solid fa-bars text-lg"></i>
            </button>
            <h2 class="text-xs sm:text-base font-extrabold text-white tracking-tight flex items-center truncate">
                <span class="shrink-0 mr-1.5 sm:mr-2.5"><?= $pageHeaderIcon ?? '<i class="fa-solid fa-gauge text-purple-400"></i>' ?></span>
                <span class="truncate"><?= $pageTitle ?? 'Painel Admin' ?></span>
            </h2>
        </div>

        <div class="flex items-center space-x-1.5 sm:space-x-3 shrink-0">
            <div class="hidden md:flex items-center space-x-2 px-3 py-1.5 rounded-xl bg-gray-900 border border-gray-800 text-xs font-mono">
                <span class="text-gray-400">Gateway:</span>
                <span class="text-cyan-300 font-bold select-all"><?= htmlspecialchars(getGatewayPublicUrl()) ?>/v1</span>
            </div>
            
            <!-- Botão de Alternar Tema (Claro / Escuro) -->
            <button type="button" title="Alternar entre modo claro e escuro" class="btn-theme-toggle w-9 h-9 rounded-xl bg-gray-900 hover:bg-gray-800 border border-gray-800 flex items-center justify-center transition cursor-pointer shrink-0">
                <i class="theme-toggle-icon fa-solid <?= $isDark ? 'fa-moon text-indigo-400' : 'fa-sun text-amber-400' ?> text-sm pointer-events-none"></i>
            </button>

            <!-- Seletor de Cores de Acento (Paleta do Admin) -->
            <div class="relative shrink-0">
                <button type="button" class="btn-scheme-picker w-9 h-9 rounded-xl bg-gray-900 hover:bg-gray-800 border border-gray-800 flex items-center justify-center transition cursor-pointer" title="Mudar paleta de cor">
                    <i class="fa-solid fa-palette text-sm pointer-events-none" style="color: var(--accent-primary, #818cf8);"></i>
                </button>
                <div class="scheme-dropdown-menu hidden absolute right-0 mt-2 w-56 p-2 rounded-2xl bg-gray-900 border border-gray-800 shadow-2xl z-50 space-y-1">
                    <div class="px-2 py-1 text-[10px] font-bold text-gray-400 uppercase tracking-wider flex items-center justify-between">
                        <span>Escolher Tema Oficial</span>
                        <span class="w-2.5 h-2.5 rounded-full" style="background-color: var(--accent-primary);"></span>
                    </div>
                    <button data-set-scheme="claude_terracotta" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-gray-800 text-amber-400 hover:text-amber-300 font-semibold transition cursor-pointer">
                        <span class="w-3.5 h-3.5 rounded-full bg-[#d97736] shadow-sm shrink-0"></span> <span>Claude Terracotta (Charcoal)</span>
                    </button>
                    <button data-set-scheme="purple_cyan" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-gray-800 text-gray-300 hover:text-white transition cursor-pointer">
                        <span class="w-3.5 h-3.5 rounded-full bg-indigo-500 shadow-sm shrink-0"></span> <span>Cyber Neon (Padrão)</span>
                    </button>
                    <button data-set-scheme="emerald_teal" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-gray-800 text-gray-300 hover:text-white transition cursor-pointer">
                        <span class="w-3.5 h-3.5 rounded-full bg-emerald-500 shadow-sm shrink-0"></span> <span>Midnight Emerald</span>
                    </button>
                    <button data-set-scheme="blue_indigo" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-gray-800 text-gray-300 hover:text-white transition cursor-pointer">
                        <span class="w-3.5 h-3.5 rounded-full bg-blue-500 shadow-sm shrink-0"></span> <span>Ocean Sapphire</span>
                    </button>
                    <button data-set-scheme="amber_orange" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-gray-800 text-gray-300 hover:text-white transition cursor-pointer">
                        <span class="w-3.5 h-3.5 rounded-full bg-amber-500 shadow-sm shrink-0"></span> <span>Sunset Gold</span>
                    </button>
                    <button data-set-scheme="rose_crimson" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-gray-800 text-gray-300 hover:text-white transition cursor-pointer">
                        <span class="w-3.5 h-3.5 rounded-full bg-rose-500 shadow-sm shrink-0"></span> <span>Crimson Rose</span>
                    </button>
                    <button data-set-scheme="titanium_minimal" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-gray-800 text-gray-300 hover:text-white transition cursor-pointer">
                        <span class="w-3.5 h-3.5 rounded-full bg-slate-400 shadow-sm shrink-0"></span> <span>Titanium Minimal</span>
                    </button>
                </div>
            </div>

            <a href="../index.php" target="_blank" title="Ver Landing Page" class="px-2.5 sm:px-3 py-1.5 rounded-xl bg-gray-900 hover:bg-gray-800 border border-gray-800 text-xs text-gray-300 hover:text-white transition flex items-center gap-1.5 shrink-0">
                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                <span class="hidden md:inline">Ver Landing Page</span>
            </a>
        </div>
    </header>

    <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl mx-auto w-full min-w-0">

        <!-- Mensagens de Alerta Flash -->
        <?php if (!empty($msg)): ?>
            <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fa-solid fa-circle-check text-lg mr-3"></i>
                    <span><?php echo htmlspecialchars($msg); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-200"><i class="fa-solid fa-xmark"></i></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="p-4 rounded-2xl bg-red-500/10 border border-red-500/30 text-red-400 text-xs flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fa-solid fa-circle-exclamation text-lg mr-3"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-200"><i class="fa-solid fa-xmark"></i></button>
            </div>
        <?php endif; ?>
