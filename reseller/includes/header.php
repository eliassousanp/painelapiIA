<?php
require_once __DIR__ . '/../../config/helpers.php';
requireReseller();

$db = getDB();
$userId = $_SESSION['user_id'];

// Core User Details
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
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .glass {
            background: rgba(17, 24, 39, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-card {
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(12px);
        }
        .glass-sidebar {
            background: rgba(11, 15, 25, 0.95);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.06);
        }
        .sidebar-item-active {
            background: linear-gradient(90deg, rgba(245, 158, 11, 0.2) 0%, rgba(245, 158, 11, 0.05) 100%);
            border-left: 3px solid #f59e0b;
            color: #fef3c7 !important;
            font-weight: 700;
        }
    </style>
    <link href="<?= getAppBaseUrl() ?>/assets/css/theme.css?v=<?= time() ?>" rel="stylesheet">
    <script>window.BRLOVE_BASE_URL = '<?= getAppBaseUrl() ?>';</script>
    <script src="<?= getAppBaseUrl() ?>/assets/js/theme.js"></script>
</head>
<body class="bg-gray-950 text-gray-100 font-sans antialiased min-h-screen">

    <div class="flex min-h-screen">

        <!-- Include Sidebar -->
        <?php require_once __DIR__ . '/sidebar.php'; ?>

        <!-- Main Wrapper -->
        <div class="flex-1 lg:pl-64 flex flex-col min-w-0 overflow-x-hidden">

            <!-- Backdrop escuro para fechar a sidebar móvel ao tocar fora -->
            <div id="sidebarBackdrop" onclick="toggleSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden transition-opacity lg:hidden"></div>

            <!-- Top Header -->
            <header class="glass sticky top-0 z-40 border-b border-gray-800/80 px-3 sm:px-6 py-3.5 flex items-center justify-between">
                <div class="flex items-center space-x-2 sm:space-x-3 min-w-0">
                    <button onclick="toggleSidebar()" class="lg:hidden text-gray-400 hover:text-white p-2 rounded-lg hover:bg-gray-800/60 transition shrink-0" aria-label="Abrir Menu">
                        <i class="fa-solid fa-bars text-lg"></i>
                    </button>
                    <div class="flex items-center space-x-2 truncate">
                        <span class="text-xs sm:text-base font-bold text-white truncate"><?= $pageHeaderIcon ?? '<i class="fa-solid fa-briefcase text-amber-400 mr-2"></i>' ?> <?= htmlspecialchars($pageTitle ?? 'Painel de Revenda') ?></span>
                    </div>
                </div>

                <div class="flex items-center space-x-3">
                    <!-- Reseller Token Balance Pill -->
                    <div class="hidden sm:flex items-center space-x-2 px-3.5 py-1.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 font-mono text-xs font-bold">
                        <i class="fa-solid fa-coins text-amber-400"></i>
                        <span>Saldo Revenda: <?= number_format($currentUser['balance_tokens'], 0, ',', '.') ?></span>
                    </div>

                    <!-- Botão de Alternar Tema (Claro / Escuro) -->
                    <button type="button" title="Alternar entre modo claro e escuro" class="btn-theme-toggle w-9 h-9 rounded-xl bg-gray-900 hover:bg-gray-800 border border-gray-800 flex items-center justify-center transition cursor-pointer">
                        <i class="theme-toggle-icon fa-solid fa-sun text-amber-400 text-sm"></i>
                    </button>

                    <!-- User Tag -->
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-amber-500 to-amber-300 text-gray-950 font-extrabold flex items-center justify-center text-xs shadow-md">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>
                        <span class="text-xs font-semibold text-gray-200 hidden md:inline"><?= htmlspecialchars($currentUser['name']) ?></span>
                    </div>
                </div>
            </header>

            <!-- Main Content Container -->
            <main class="p-4 sm:p-6 lg:p-8 space-y-6">

                <?php if (!empty($msg)): ?>
                    <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs flex items-center justify-between">
                        <div class="flex items-center">
                            <i class="fa-solid fa-circle-check text-lg mr-2.5"></i>
                            <span><?= htmlspecialchars($msg) ?></span>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-200"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="p-4 rounded-2xl bg-red-500/10 border border-red-500/30 text-red-400 text-xs flex items-center justify-between">
                        <div class="flex items-center">
                            <i class="fa-solid fa-circle-exclamation text-lg mr-2.5"></i>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-200"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                <?php endif; ?>
