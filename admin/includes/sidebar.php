<?php
$currentPage = basename($_SERVER['PHP_SELF']);

// Buscar contadores rápidos para as badges do menu
try {
    $providersCount = (int)$db->query("SELECT COUNT(*) FROM providers WHERE name NOT LIKE '%Google Antigravity%'")->fetchColumn();
    $modelsCount = (int)$db->query("SELECT COUNT(*) FROM models")->fetchColumn();
    $usersCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $plansCount = (int)$db->query("SELECT COUNT(*) FROM plans")->fetchColumn();
    $accountsCount = (int)$db->query("SELECT COUNT(*) FROM antigravity_accounts")->fetchColumn();
} catch (Exception $e) {
    $providersCount = 0;
    $modelsCount = 0;
    $usersCount = 0;
    $plansCount = 0;
    $accountsCount = 0;
}
?>
<!-- MENU LATERAL ESQUERDO (SIDEBAR VERTICAL) -->
<aside id="sidebar" class="w-64 glass-sidebar fixed inset-y-0 left-0 z-50 flex flex-col justify-between transition-transform duration-300 transform lg:translate-x-0 -translate-x-full overflow-hidden">
    <div class="space-y-4 p-4 sm:p-5 overflow-y-auto flex-1 custom-scrollbar">
        
        <!-- Logo & Brand Header -->
        <div class="flex items-center justify-between pb-3.5 border-b border-gray-800/80">
            <a href="<?= getAppBaseUrl() ?>/index.php" class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-purple-600 via-indigo-600 to-cyan-500 flex items-center justify-center font-extrabold text-xl text-white shadow-lg shadow-purple-500/30">
                    <i class="fa-solid fa-cloud-bolt"></i>
                </div>
                <div>
                    <div class="text-base font-extrabold text-white tracking-tight leading-none">BRLove<span class="text-purple-400">API</span></div>
                    <div class="text-[10px] text-gray-400 font-medium mt-1">Painel Administrativo</div>
                </div>
            </a>
            <button onclick="toggleSidebar()" class="lg:hidden text-gray-400 hover:text-white">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- Navigation Links -->
        <nav class="space-y-4 text-xs font-medium">

            <!-- Grupo 1: Visão Geral -->
            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Geral</div>
                
                <a href="dashboard.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'dashboard.php' || $currentPage === 'index.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-chart-line w-5 text-purple-400 text-sm"></i>
                    <span>Dashboard</span>
                </a>
            </div>

            <!-- Grupo 2: Engine de IA -->
            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Engine de IA</div>
                
                <a href="providers.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'providers.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-network-wired w-5 text-cyan-400 text-sm"></i>
                    <span>Provedores & Keys</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full bg-cyan-500/20 text-cyan-300 text-[10px] font-mono"><?= $providersCount ?></span>
                </a>

                <a href="models.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'models.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-microchip w-5 text-purple-400 text-sm"></i>
                    <span>Modelos & Pré-Prompts</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full bg-purple-500/20 text-purple-300 text-[10px] font-mono"><?= $modelsCount ?></span>
                </a>

                <a href="accounts.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'accounts.php' || $currentPage === 'painel_accounts.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-brands fa-google w-5 text-cyan-400 text-sm"></i>
                    <span>Pool Antigravity</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full bg-cyan-500/20 text-cyan-300 text-[10px] font-mono"><?= $accountsCount ?></span>
                </a>

                <a href="realtime.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:opacity-90 <?= ($currentPage === 'realtime.php') ? 'sidebar-item-active' : 'dark:text-indigo-300 text-indigo-700 dark:bg-indigo-950/40 bg-indigo-50 dark:border-indigo-500/20 border-indigo-200 border' ?>">
                    <i class="fa-solid fa-waveform-lines w-5 text-indigo-500 text-sm"></i>
                    <span class="font-semibold text-xs">Console Realtime (Voz/Chat)</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full dark:bg-indigo-500/30 bg-indigo-200 dark:text-indigo-200 text-indigo-800 text-[10px] font-mono font-bold">LIVE</span>
                </a>
            </div>

            <!-- Grupo WhatsApp & Agente IA -->
            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold text-emerald-500 uppercase tracking-widest flex items-center justify-between">
                    <span>WhatsApp & IA</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                </div>

                <a href="whatsapp.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:opacity-90 <?= ($currentPage === 'whatsapp.php') ? 'sidebar-item-active' : 'dark:text-emerald-300 text-emerald-800 dark:bg-emerald-950/40 bg-emerald-50 dark:border-emerald-500/25 border-emerald-200 border' ?>">
                    <i class="fa-brands fa-whatsapp w-5 text-emerald-500 text-base"></i>
                    <span class="font-bold text-xs">WhatsApp & Agente IA</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full dark:bg-emerald-500/30 bg-emerald-200 dark:text-emerald-200 text-emerald-800 text-[10px] font-mono font-bold">QR + BOT</span>
                </a>
            </div>

            <!-- Grupo 3: Clientes & Financeiro -->
            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Clientes & Finanças</div>

                <a href="users.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'users.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-users-gear w-5 text-indigo-400 text-sm"></i>
                    <span>Usuários & Revenda</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 text-[10px] font-mono"><?= $usersCount ?></span>
                </a>

                <a href="plans.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'plans.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-bolt w-5 text-amber-400 text-sm"></i>
                    <span>Planos & Recargas</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 text-[10px] font-mono"><?= $plansCount ?></span>
                </a>

                <a href="payments.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'payments.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-receipt w-5 text-emerald-400 text-sm"></i>
                    <span>Vendas PIX</span>
                </a>

                
            </div>

            <!-- Grupo 4: Sistema & Auditoria -->
            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Auditoria & Sistema</div>

                <a href="logs.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'logs.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-list-check w-5 text-amber-400 text-sm"></i>
                    <span>Logs de Consumo</span>
                </a>

                <a href="settings.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'settings.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-sliders w-5 text-gray-400 text-sm"></i>
                    <span>Configurações Globais</span>
                </a>
            </div>

        </nav>
    </div>

    <!-- Footer User Profile Sidebar -->
    <div class="p-4 border-t dark:border-gray-800/80 border-slate-200 dark:bg-gray-900/40 bg-slate-50 space-y-3">
        <a href="<?= getAppBaseUrl() ?>/client/index.php" class="w-full flex items-center justify-center space-x-2 py-2.5 rounded-xl dark:bg-gray-800/80 bg-white hover:bg-slate-100 dark:text-gray-300 text-slate-700 font-semibold text-xs border dark:border-transparent border-slate-200 shadow-sm transition">
            <i class="fa-solid fa-user"></i>
            <span>Ir para Área do Cliente</span>
        </a>
        <div class="flex items-center justify-between pt-1">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl bg-purple-600/30 text-purple-300 border border-purple-500/30 flex items-center justify-center font-bold text-xs">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div>
                    <div class="text-xs font-bold dark:text-white text-slate-800 leading-tight">Admin</div>
                    <div class="text-[10px] dark:text-gray-400 text-slate-500">Superusuário</div>
                </div>
            </div>
            <a href="<?= getAppBaseUrl() ?>/logout.php" title="Sair do Painel" class="text-gray-400 hover:text-red-400 text-sm p-1.5 transition">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>
