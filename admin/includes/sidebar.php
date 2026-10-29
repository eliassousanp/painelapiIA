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
<!-- MENU LATERAL ESQUERDO (SIDEBAR VERTICAL MODERNA) -->
<aside id="sidebar" class="w-64 glass-sidebar fixed inset-y-0 left-0 z-50 flex flex-col justify-between transition-transform duration-300 transform lg:translate-x-0 -translate-x-full overflow-hidden shadow-xl">
    <div class="space-y-5 p-4 sm:p-5 overflow-y-auto flex-1 custom-scrollbar">
        
        <!-- Logo & Brand Header -->
        <div class="flex items-center justify-between pb-4 border-b" style="border-color: var(--border-color);">
            <a href="<?= getAppBaseUrl() ?>/index.php" class="flex items-center space-x-3 group">
                <div class="w-10 h-10 rounded-2xl flex items-center justify-center font-extrabold text-xl text-white shadow-lg transition-transform group-hover:scale-105" style="background: var(--brand-btn-gradient); box-shadow: 0 4px 14px var(--brand-glow);">
                    <i class="fa-solid fa-cloud-bolt"></i>
                </div>
                <div>
                    <div class="text-base font-extrabold tracking-tight leading-none" style="color: var(--text-heading);">
                        BRLove<span style="color: var(--accent-primary);">API</span>
                    </div>
                    <div class="text-[10px] font-semibold tracking-wider uppercase mt-1" style="color: var(--text-muted);">Painel Admin</div>
                </div>
            </a>
            <button onclick="toggleSidebar()" class="lg:hidden p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition" style="color: var(--text-muted);">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- Navigation Links -->
        <nav class="space-y-4 text-xs font-semibold">

            <!-- Grupo 1: Visão Geral -->
            <div class="space-y-1">
                <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-widest" style="color: var(--text-muted);">Visão Geral</div>
                
                <a href="dashboard.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'dashboard.php' || $currentPage === 'index.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'dashboard.php' && $currentPage !== 'index.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-solid fa-chart-line w-5 text-sm" style="color: var(--accent-primary);"></i>
                    <span>Dashboard</span>
                </a>
            </div>

            <!-- Grupo 2: Engine de IA -->
            <div class="space-y-1">
                <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-widest" style="color: var(--text-muted);">Engine de IA</div>
                
                <a href="providers.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'providers.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'providers.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-solid fa-network-wired w-5 text-sm text-cyan-500"></i>
                    <span>Provedores & Keys</span>
                    <span class="ml-auto badge-brand text-[10px] font-mono"><?= $providersCount ?></span>
                </a>

                <a href="models.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'models.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'models.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-solid fa-microchip w-5 text-sm" style="color: var(--accent-primary);"></i>
                    <span>Modelos & Prompts</span>
                    <span class="ml-auto badge-brand text-[10px] font-mono"><?= $modelsCount ?></span>
                </a>

                <a href="accounts.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'accounts.php' || $currentPage === 'painel_accounts.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'accounts.php' && $currentPage !== 'painel_accounts.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-brands fa-google w-5 text-sm text-cyan-400"></i>
                    <span>Pool Antigravity</span>
                    <span class="ml-auto badge-brand text-[10px] font-mono"><?= $accountsCount ?></span>
                </a>

                <a href="realtime.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'realtime.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'realtime.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-solid fa-waveform-lines w-5 text-sm text-indigo-400"></i>
                    <span>Console Realtime</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full text-[9px] font-mono font-extrabold flex items-center gap-1" style="background: rgba(99, 102, 241, 0.2); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.3);">
                        <span class="pulse-indicator bg-indigo-500"></span> LIVE
                    </span>
                </a>
            </div>

            <!-- Grupo WhatsApp & Agente IA -->
            <div class="space-y-1">
                <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-emerald-500 flex items-center justify-between">
                    <span>WhatsApp & IA</span>
                    <span class="pulse-indicator bg-emerald-500"></span>
                </div>

                <a href="whatsapp.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'whatsapp.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'whatsapp.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-brands fa-whatsapp w-5 text-sm text-emerald-500"></i>
                    <span>WhatsApp & Bot</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full text-[9px] font-mono font-extrabold" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                        QR + BOT
                    </span>
                </a>
            </div>

            <!-- Grupo 3: Clientes & Financeiro -->
            <div class="space-y-1">
                <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-widest" style="color: var(--text-muted);">Clientes & Vendas</div>

                <a href="users.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'users.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'users.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-solid fa-users-gear w-5 text-sm" style="color: var(--accent-primary);"></i>
                    <span>Usuários & Revenda</span>
                    <span class="ml-auto badge-brand text-[10px] font-mono"><?= $usersCount ?></span>
                </a>

                <a href="plans.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'plans.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'plans.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-solid fa-bolt w-5 text-sm text-amber-500"></i>
                    <span>Planos & Recargas</span>
                    <span class="ml-auto badge-brand text-[10px] font-mono"><?= $plansCount ?></span>
                </a>

                <a href="payments.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'payments.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'payments.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-solid fa-receipt w-5 text-sm text-emerald-500"></i>
                    <span>Vendas PIX</span>
                </a>
            </div>

            <!-- Grupo 4: Sistema & Auditoria -->
            <div class="space-y-1">
                <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-widest" style="color: var(--text-muted);">Auditoria & Sistema</div>

                <a href="logs.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'logs.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'logs.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-solid fa-list-check w-5 text-sm text-amber-500"></i>
                    <span>Logs de Consumo</span>
                </a>

                <a href="settings.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition <?= ($currentPage === 'settings.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'settings.php') ? 'color: var(--text-body);' : '' ?>">
                    <i class="fa-solid fa-sliders w-5 text-sm" style="color: var(--text-muted);"></i>
                    <span>Configurações</span>
                </a>
            </div>

        </nav>
    </div>

    <!-- Rodapé da Barra Lateral: Usuário & Acesso Cliente -->
    <div class="p-4 border-t space-y-3" style="border-color: var(--border-color); background: var(--bg-surface-elevated);">
        <a href="<?= getAppBaseUrl() ?>/client/index.php" class="w-full flex items-center justify-center space-x-2 py-2.5 rounded-xl glass-card font-bold text-xs shadow-sm transition hover:scale-[1.02]" style="color: var(--text-heading);">
            <i class="fa-solid fa-arrow-up-right-from-square text-[11px]" style="color: var(--accent-primary);"></i>
            <span>Área do Cliente</span>
        </a>
        <div class="flex items-center justify-between pt-1">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center font-bold text-xs text-white shadow-sm" style="background: var(--brand-btn-gradient);">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div>
                    <div class="text-xs font-bold leading-tight" style="color: var(--text-heading);">Administrador</div>
                    <div class="text-[10px] font-medium" style="color: var(--text-muted);">Superusuário</div>
                </div>
            </div>
            <a href="<?= getAppBaseUrl() ?>/logout.php" title="Sair do Painel" class="text-gray-400 hover:text-red-500 text-sm p-1.5 transition">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>
