<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$resellerId = $_SESSION['user_id'];

// Contagem de Clientes da Revenda
try {
    $stmtClientsCount = $db->prepare("SELECT COUNT(*) FROM users WHERE parent_id = ?");
    $stmtClientsCount->execute([$resellerId]);
    $myClientsCount = (int)$stmtClientsCount->fetchColumn();
} catch (Exception $e) {
    $myClientsCount = 0;
}
?>
<!-- SIDEBAR LATERAL ESQUERDA DA REVENDA -->
<aside id="sidebar" class="w-64 glass-sidebar fixed inset-y-0 left-0 z-50 flex flex-col justify-between transition-transform duration-300 transform lg:translate-x-0 -translate-x-full overflow-hidden border-r">
    <div class="space-y-4 p-4 sm:p-5 overflow-y-auto flex-1 custom-scrollbar">
        
        <!-- Logo & Brand Header -->
        <div class="flex items-center justify-between pb-4 border-b" style="border-color: var(--border-color);">
            <a href="<?= getAppBaseUrl() ?>/reseller/index.php" class="flex items-center space-x-3 group">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 via-amber-400 to-yellow-300 flex items-center justify-center font-extrabold text-xl text-gray-950 shadow-lg shadow-amber-500/20 group-hover:scale-105 transition-transform">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div>
                    <div class="text-base font-extrabold tracking-tight leading-none" style="color: var(--text-heading);">
                        BRLove<span class="text-amber-500">Revenda</span>
                    </div>
                    <div class="text-[10px] font-medium mt-1" style="color: var(--text-muted);">Gestão Comercial</div>
                </div>
            </a>
            <button onclick="toggleSidebar()" class="lg:hidden p-1.5 rounded-lg glass-card" style="color: var(--text-muted);">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- Reseller Status Pill -->
        <div class="p-3 rounded-2xl border flex items-center space-x-2.5" style="background: rgba(245, 158, 11, 0.08); border-color: rgba(245, 158, 11, 0.25);">
            <span class="pulse-indicator bg-amber-500 shrink-0"></span>
            <span class="text-[11px] font-bold text-amber-500">Revenda Autorizada</span>
        </div>

        <!-- Navigation Links -->
        <nav class="space-y-4 text-xs font-medium">

            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold uppercase tracking-widest" style="color: var(--text-muted);">Comercial</div>
                
                <a href="index.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition <?= ($currentPage === 'index.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'index.php') ? 'color: var(--text-body);' : '' ?>">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center mr-2.5 <?= ($currentPage === 'index.php') ? 'bg-amber-500/20 text-amber-500' : 'text-amber-500/80' ?>">
                        <i class="fa-solid fa-users text-sm"></i>
                    </div>
                    <span class="font-semibold">Meus Clientes</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full text-[10px] font-mono font-bold" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;"><?= $myClientsCount ?></span>
                </a>

                <a href="plans.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition <?= ($currentPage === 'plans.php') ? 'sidebar-item-active' : 'hover:bg-black/5 dark:hover:bg-white/5' ?>" style="<?= ($currentPage !== 'plans.php') ? 'color: var(--text-body);' : '' ?>">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center mr-2.5 <?= ($currentPage === 'plans.php') ? 'bg-cyan-500/20 text-cyan-400' : 'text-cyan-400/80' ?>">
                        <i class="fa-solid fa-bolt text-sm"></i>
                    </div>
                    <span class="font-semibold">Recarregar Saldo</span>
                </a>
            </div>

            <div class="space-y-1 pt-2">
                <div class="px-3 text-[10px] font-bold uppercase tracking-widest" style="color: var(--text-muted);">Ferramentas</div>
                
                <a href="<?= getAppBaseUrl() ?>/client/index.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl transition hover:bg-black/5 dark:hover:bg-white/5" style="color: var(--text-body);">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center mr-2.5 text-indigo-400">
                        <i class="fa-solid fa-code text-sm"></i>
                    </div>
                    <span class="font-semibold">Portal do Desenvolvedor</span>
                </a>
            </div>

            <?php if (isAdmin()): ?>
                <div class="space-y-1 pt-3 border-t" style="border-color: var(--border-color);">
                    <div class="px-3 text-[10px] font-bold text-purple-400 uppercase tracking-widest">Master Admin</div>
                    <a href="<?= getAppBaseUrl() ?>/admin/index.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl border transition font-semibold" style="background: rgba(147, 51, 234, 0.12); border-color: rgba(147, 51, 234, 0.3); color: #c084fc;">
                        <i class="fa-solid fa-shield-halved w-5 mr-1 text-purple-400 text-sm"></i>
                        <span>Painel Administrativo</span>
                    </a>
                </div>
            <?php endif; ?>

        </nav>
    </div>

    <!-- Footer Profile -->
    <div class="p-4 border-t space-y-3" style="border-color: var(--border-color); background: rgba(0, 0, 0, 0.05);">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2.5 min-w-0">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center font-bold text-xs shrink-0" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div class="truncate">
                    <div class="text-xs font-bold leading-tight truncate" style="color: var(--text-heading);"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Revendedor') ?></div>
                    <div class="text-[10px]" style="color: var(--text-muted);">Conta Revenda</div>
                </div>
            </div>
            <a href="<?= getAppBaseUrl() ?>/logout.php" title="Sair da Conta" class="p-2 rounded-lg glass-card hover:text-red-500 transition text-xs shrink-0" style="color: var(--text-muted);">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>
