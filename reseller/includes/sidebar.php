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
<!-- MENU LATERAL ESQUERDO DA REVENDA -->
<aside id="sidebar" class="w-64 glass-sidebar fixed inset-y-0 left-0 z-50 flex flex-col justify-between transition-transform duration-300 transform lg:translate-x-0 -translate-x-full overflow-hidden">
    <div class="space-y-4 p-4 sm:p-5 overflow-y-auto flex-1 custom-scrollbar">
        
        <!-- Logo & Brand Header -->
        <div class="flex items-center justify-between pb-4 border-b border-gray-800/80">
            <a href="<?= getAppBaseUrl() ?>/reseller/index.php" class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 via-amber-400 to-yellow-300 flex items-center justify-center font-extrabold text-xl text-gray-950 shadow-lg shadow-amber-500/20">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div>
                    <div class="text-base font-extrabold text-white tracking-tight leading-none">Painel<span class="text-amber-400">Revenda</span></div>
                    <div class="text-[10px] text-gray-400 font-medium mt-1">Gestão de Clientes</div>
                </div>
            </a>
            <button onclick="toggleSidebar()" class="lg:hidden text-gray-400 hover:text-white">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- Reseller Status Pill -->
        <div class="p-3 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center space-x-2.5">
            <div class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></div>
            <span class="text-[11px] font-semibold text-amber-300">Revenda Autorizada</span>
        </div>

        <!-- Navigation Links -->
        <nav class="space-y-4 text-xs font-medium">

            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Navegação</div>
                
                <a href="index.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'index.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-users w-5 text-amber-400 text-sm"></i>
                    <span>Meus Clientes</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 text-[10px] font-mono"><?= $myClientsCount ?></span>
                </a>

                <a href="plans.php" class="w-full flex items-center px-3.5 py-3 rounded-xl transition hover:bg-gray-800/50 <?= ($currentPage === 'plans.php') ? 'sidebar-item-active' : 'text-gray-400 hover:text-white' ?>">
                    <i class="fa-solid fa-bolt w-5 text-cyan-400 text-sm"></i>
                    <span>Recarregar Saldo</span>
                </a>
            </div>

            <?php if (isAdmin()): ?>
                <div class="space-y-1 pt-4 border-t border-gray-800">
                    <div class="px-3 text-[10px] font-bold text-purple-400 uppercase tracking-widest">Modo Super Admin</div>
                    <a href="<?= getAppBaseUrl() ?>/admin/index.php" class="w-full flex items-center px-3.5 py-2.5 rounded-xl bg-purple-600/30 text-purple-200 border border-purple-500/30 hover:bg-purple-600/50 transition font-semibold">
                        <i class="fa-solid fa-shield-halved w-5 text-purple-300 text-sm"></i>
                        <span>Ir para Painel Admin</span>
                    </a>
                </div>
            <?php endif; ?>

        </nav>
    </div>

    <!-- Footer Profile -->
    <div class="p-4 border-t border-gray-800/80 bg-gray-900/40 space-y-3">
        <a href="<?= getAppBaseUrl() ?>/client/index.php" class="w-full flex items-center justify-center space-x-2 py-2.5 rounded-xl bg-gray-800/80 hover:bg-gray-700 text-xs text-gray-300 font-semibold transition">
            <i class="fa-solid fa-code"></i>
            <span>Usar API no VS Code</span>
        </a>
        <div class="flex items-center justify-between pt-1">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-300 border border-amber-500/30 flex items-center justify-center font-bold text-xs">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div>
                    <div class="text-xs font-bold text-white leading-tight">Revendedor</div>
                    <div class="text-[10px] text-gray-400">Conta Ativa</div>
                </div>
            </div>
            <a href="<?= getAppBaseUrl() ?>/logout.php" title="Sair da Conta" class="text-gray-400 hover:text-red-400 text-sm p-1.5 transition">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>
