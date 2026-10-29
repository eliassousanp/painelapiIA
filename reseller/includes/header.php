<?php
require_once __DIR__ . '/../../config/helpers.php';
requireReseller();

$db = getDB();
$userId = $_SESSION['user_id'];

// Detalhes do Usuário Atual
$stmtUser = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$currentUser = $stmtUser->fetch();

if (!$currentUser || $currentUser['status'] === 'suspended') {
    session_destroy();
    header('Location: ' . getAppBaseUrl() . '/login.php');
    exit;
}

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
    <title><?= htmlspecialchars($pageTitle ?? 'Painel de Revenda') ?> - BRLove API</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Theme CSS Oficial -->
    <link href="<?= getAppBaseUrl() ?>/assets/css/theme.css?v=<?= time() ?>" rel="stylesheet">
    <script>window.BRLOVE_BASE_URL = '<?= getAppBaseUrl() ?>';</script>
    <script src="<?= getAppBaseUrl() ?>/assets/js/theme.js"></script>
</head>
<body class="font-sans antialiased min-h-screen flex flex-col" style="background-color: var(--bg-base); color: var(--text-body);">

    <div class="flex min-h-screen flex-1">

        <!-- Sidebar da Revenda -->
        <?php require_once __DIR__ . '/sidebar.php'; ?>

        <!-- Wrapper Principal -->
        <div class="flex-1 lg:pl-64 flex flex-col min-w-0 overflow-x-hidden">

            <!-- Backdrop para fechar a sidebar móvel -->
            <div id="sidebarBackdrop" onclick="toggleSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden transition-opacity lg:hidden"></div>

            <!-- Top Header Glass -->
            <header class="glass-header sticky top-0 z-40 px-4 sm:px-6 py-3.5 flex items-center justify-between border-b">
                <div class="flex items-center space-x-3 min-w-0">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl glass-card transition shrink-0" aria-label="Abrir Menu" style="color: var(--text-heading);">
                        <i class="fa-solid fa-bars text-base"></i>
                    </button>
                    <div class="flex items-center space-x-2 truncate">
                        <span class="text-sm sm:text-base font-extrabold truncate" style="color: var(--text-heading);">
                            <?= $pageHeaderIcon ?? '<i class="fa-solid fa-briefcase text-amber-500 mr-2"></i>' ?> <?= htmlspecialchars($pageTitle ?? 'Painel de Revenda') ?>
                        </span>
                    </div>
                </div>

                <div class="flex items-center space-x-2 sm:space-x-3 shrink-0">
                    <!-- Reseller Token Balance Pill -->
                    <div class="hidden sm:flex items-center space-x-2 px-3.5 py-1.5 rounded-xl glass-card text-xs font-mono font-bold border" style="border-color: rgba(245, 158, 11, 0.3); color: #f59e0b;">
                        <i class="fa-solid fa-coins animate-pulse"></i>
                        <span>Banca: <?= number_format($currentUser['balance_tokens'], 0, ',', '.') ?> tokens</span>
                    </div>

                    <!-- Botão de Alternar Tema (Claro / Escuro) -->
                    <button type="button" title="Alternar entre modo claro e escuro" class="btn-theme-toggle w-9 h-9 rounded-xl glass-card flex items-center justify-center transition cursor-pointer shrink-0">
                        <i class="theme-toggle-icon fa-solid <?= $isDark ? 'fa-moon text-indigo-400' : 'fa-sun text-amber-500' ?> text-sm pointer-events-none"></i>
                    </button>

                    <!-- Seletor de Paletas de Cores -->
                    <div class="relative shrink-0">
                        <button type="button" class="btn-scheme-picker w-9 h-9 rounded-xl glass-card flex items-center justify-center transition cursor-pointer" title="Mudar paleta de cor">
                            <i class="fa-solid fa-palette text-sm pointer-events-none" style="color: var(--accent-primary);"></i>
                        </button>
                        <div class="scheme-dropdown-menu hidden absolute right-0 mt-2 w-64 p-2.5 rounded-2xl glass-card shadow-2xl z-50 space-y-1.5 border">
                            <div class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider flex items-center justify-between" style="color: var(--text-muted);">
                                <span>Paleta de Cores</span>
                                <span class="w-2.5 h-2.5 rounded-full shadow-sm" style="background-color: var(--accent-primary);"></span>
                            </div>
                            <button data-set-scheme="purple_cyan" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 font-semibold transition cursor-pointer" style="color: var(--text-heading);">
                                <span class="w-3.5 h-3.5 rounded-full bg-indigo-500 shadow-sm shrink-0"></span> <span>Cyber Neon (Padrão)</span>
                            </button>
                            <button data-set-scheme="claude_terracotta" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 font-semibold transition cursor-pointer" style="color: var(--text-heading);">
                                <span class="w-3.5 h-3.5 rounded-full bg-[#d97736] shadow-sm shrink-0"></span> <span>Claude Terracotta</span>
                            </button>
                            <button data-set-scheme="emerald_teal" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 font-semibold transition cursor-pointer" style="color: var(--text-heading);">
                                <span class="w-3.5 h-3.5 rounded-full bg-emerald-500 shadow-sm shrink-0"></span> <span>Midnight Emerald</span>
                            </button>
                            <button data-set-scheme="blue_indigo" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 font-semibold transition cursor-pointer" style="color: var(--text-heading);">
                                <span class="w-3.5 h-3.5 rounded-full bg-blue-500 shadow-sm shrink-0"></span> <span>Ocean Sapphire</span>
                            </button>
                            <button data-set-scheme="amber_orange" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 font-semibold transition cursor-pointer" style="color: var(--text-heading);">
                                <span class="w-3.5 h-3.5 rounded-full bg-amber-500 shadow-sm shrink-0"></span> <span>Sunset Gold</span>
                            </button>
                            <button data-set-scheme="rose_crimson" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 font-semibold transition cursor-pointer" style="color: var(--text-heading);">
                                <span class="w-3.5 h-3.5 rounded-full bg-rose-500 shadow-sm shrink-0"></span> <span>Crimson Rose</span>
                            </button>
                            <button data-set-scheme="titanium_minimal" class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 font-semibold transition cursor-pointer" style="color: var(--text-heading);">
                                <span class="w-3.5 h-3.5 rounded-full bg-slate-400 shadow-sm shrink-0"></span> <span>Titanium Minimal</span>
                            </button>
                        </div>
                    </div>

                    <!-- User Pill -->
                    <div class="flex items-center space-x-2 pl-1">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-amber-500 to-amber-300 text-gray-950 font-extrabold flex items-center justify-center text-xs shadow-md">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>
                        <span class="text-xs font-semibold hidden md:inline truncate max-w-[120px]" style="color: var(--text-heading);"><?= htmlspecialchars($currentUser['name']) ?></span>
                    </div>
                </div>
            </header>

            <!-- Main Content Container -->
            <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl mx-auto w-full min-w-0">

                <?php if (!empty($msg)): ?>
                    <div class="p-4 rounded-2xl border flex items-center justify-between shadow-lg" style="background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3); color: #10b981;">
                        <div class="flex items-center">
                            <i class="fa-solid fa-circle-check text-lg mr-3"></i>
                            <span class="text-xs font-semibold"><?= htmlspecialchars($msg) ?></span>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-400 p-1"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="p-4 rounded-2xl border flex items-center justify-between shadow-lg" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #ef4444;">
                        <div class="flex items-center">
                            <i class="fa-solid fa-circle-exclamation text-lg mr-3"></i>
                            <span class="text-xs font-semibold"><?= htmlspecialchars($error) ?></span>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-red-500 hover:text-red-400 p-1"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                <?php endif; ?>
