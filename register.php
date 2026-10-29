<?php
require_once __DIR__ . '/config/helpers.php';

$error = '';

if (isLoggedIn()) {
    header('Location: ' . getAppBaseUrl() . '/client/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Sessão expirada ou token de segurança inválido. Recarregue a página.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Limpar o número de whatsapp (manter apenas números)
        $clean_whatsapp = preg_replace('/[^0-9]/', '', $whatsapp);

        if (empty($name) || empty($clean_whatsapp) || empty($password)) {
            $error = 'Preencha seu Nome, WhatsApp e Senha.';
        } elseif (strlen($password) < 6) {
            $error = 'A senha deve conter no mínimo 6 caracteres.';
        } elseif (strlen($clean_whatsapp) < 10) {
            $error = 'Informe um número de WhatsApp válido com DDD (mínimo 10 dígitos).';
        } else {
            $db = getDB();
            
            // Verifica se o WhatsApp já existe
            $stmt = $db->prepare("SELECT id FROM users WHERE whatsapp = ?");
            $stmt->execute([$clean_whatsapp]);
            if ($stmt->fetch()) {
                $error = 'Este número de WhatsApp já está cadastrado.';
            } else {
                $requestedRole = (isset($_POST['role']) && $_POST['role'] === 'reseller') || (isset($_GET['role']) && $_GET['role'] === 'reseller') ? 'reseller' : 'user';
                $planTitle = ($requestedRole === 'reseller') ? 'Revendedor BRLove' : 'Pro Dev Ilimitado';
                $starterBalance = ($requestedRole === 'reseller') ? 0 : 5000000;
                
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("INSERT INTO users (name, whatsapp, email, password, password_hash, role, balance_tokens, plan_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $clean_whatsapp, $email ?: null, $hash, $hash, $requestedRole, $starterBalance, $planTitle]);
                $userId = $db->lastInsertId();

                // Gerar primeira API Key para o revendedor / cliente
                $newApiKey = generateApiKey();
                $stmtKey = $db->prepare("INSERT INTO api_keys (user_id, api_key, key_name) VALUES (?, ?, 'Key Inicial')");
                $stmtKey->execute([$userId, $newApiKey]);

                // Auto Login
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_whatsapp'] = $clean_whatsapp;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = $requestedRole;

                if ($requestedRole === 'reseller') {
                    header('Location: ' . getAppBaseUrl() . '/reseller/index.php');
                } else {
                    header('Location: ' . getAppBaseUrl() . '/client/index.php');
                }
                exit;
            }
        }
    }
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
    <title>Criar Conta - BRLove API</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?= getAppBaseUrl() ?>/assets/css/theme.css?v=<?= time() ?>" rel="stylesheet">
    <script>window.BRLOVE_BASE_URL = '<?= getAppBaseUrl() ?>';</script>
    <script src="<?= getAppBaseUrl() ?>/assets/js/theme.js"></script>
</head>
<body class="min-h-screen flex items-center justify-center p-4 antialiased relative overflow-hidden py-12" style="background-color: var(--bg-base); color: var(--text-body);">

    <!-- Efeito de Luz / Glow de Fundo (Mesh Gradient Sutil) -->
    <div class="absolute inset-0 pointer-events-none overflow-hidden flex items-center justify-center">
        <div class="w-[500px] h-[500px] rounded-full blur-[130px] opacity-20 dark:opacity-30" style="background: var(--brand-btn-gradient);"></div>
    </div>

    <div class="max-w-md w-full glass-card p-7 sm:p-9 rounded-3xl border shadow-2xl relative z-10 transition-all">
        <!-- Botão de Alternar Modo Claro / Escuro -->
        <div class="absolute top-6 right-6">
            <button type="button" onclick="toggleThemeMode()" title="Alternar modo claro/escuro" class="btn-theme-toggle w-9 h-9 rounded-xl glass-card flex items-center justify-center transition cursor-pointer">
                <i class="theme-toggle-icon fa-solid <?= $isDark ? 'fa-moon text-indigo-400' : 'fa-sun text-amber-500' ?> text-sm"></i>
            </button>
        </div>
        
        <div class="text-center mb-7">
            <a href="index.php" class="inline-flex items-center space-x-3 mb-4 group">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center font-bold text-2xl text-white shadow-lg transition-transform group-hover:scale-105" style="background: var(--brand-btn-gradient); box-shadow: 0 4px 16px var(--brand-glow);">
                    <i class="fa-solid fa-cloud-bolt"></i>
                </div>
                <span class="text-2xl font-extrabold tracking-tight" style="color: var(--text-heading);">
                    BRLove<span style="color: var(--accent-primary);">API</span>
                </span>
            </a>
            <h1 class="text-xl font-bold tracking-tight" style="color: var(--text-heading);">Criar Nova Conta</h1>
            <p class="text-xs font-medium mt-1.5" style="color: var(--text-muted);">Comece agora e receba sua API Key imediatamente</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-5 p-4 rounded-2xl border flex items-center shadow-sm" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #ef4444;">
                <i class="fa-solid fa-circle-exclamation mr-3 text-lg shrink-0"></i>
                <span class="text-xs font-semibold"><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" class="space-y-4">
            <?= csrf_field() ?>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">Nome Completo</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4" style="color: var(--text-muted);">
                        <i class="fa-solid fa-user"></i>
                    </span>
                    <input type="text" name="name" required placeholder="Seu Nome ou Empresa" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" class="w-full pl-11 pr-4 py-3 text-xs font-medium focus:ring-2">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">WhatsApp (Seu Login Principal)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-emerald-500">
                        <i class="fa-brands fa-whatsapp text-lg"></i>
                    </span>
                    <input type="text" name="whatsapp" required placeholder="DDD + Número (ex: 11999999999)" value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>" class="w-full pl-11 pr-4 py-3 text-xs font-mono font-medium focus:ring-2">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">E-mail (Opcional)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4" style="color: var(--text-muted);">
                        <i class="fa-solid fa-envelope"></i>
                    </span>
                    <input type="email" name="email" placeholder="seu@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="w-full pl-11 pr-4 py-3 text-xs font-medium focus:ring-2">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">Senha de Acesso (Mín. 6 dígitos)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4" style="color: var(--text-muted);">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full pl-11 pr-4 py-3 text-xs focus:ring-2">
                </div>
            </div>

            <button type="submit" class="w-full py-3.5 rounded-xl btn-brand text-white font-bold text-sm transition shadow-lg flex items-center justify-center gap-2 cursor-pointer mt-2">
                <span>Criar Conta & Obter Chave</span>
                <i class="fa-solid fa-bolt text-xs"></i>
            </button>
        </form>

        <div class="mt-7 text-center text-xs border-t pt-5" style="border-color: var(--border-color); color: var(--text-muted);">
            Já possui cadastro? <a href="login.php" class="font-bold hover:underline" style="color: var(--accent-primary);">Fazer login</a>
        </div>
    </div>
</body>
</html>
