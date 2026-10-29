<?php
require_once __DIR__ . '/config/helpers.php';

$error = '';

if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: ' . getAppBaseUrl() . '/admin/index.php');
    } else {
        header('Location: ' . getAppBaseUrl() . '/client/index.php');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Sessão expirada ou token de segurança inválido. Recarregue a página.';
    } else {
        $login_input = trim($_POST['login_input'] ?? '');
        $password = $_POST['password'] ?? '';

        // Limpar o número de whatsapp digitado (manter apenas números)
        $clean_whatsapp = preg_replace('/[^0-9]/', '', $login_input);

        if (!empty($login_input) && !empty($password)) {
            $db = getDB();
            // Permite logar pelo número de WhatsApp ou E-mail
            $stmt = $db->prepare("SELECT * FROM users WHERE whatsapp = ? OR whatsapp = ? OR email = ?");
            $stmt->execute([$login_input, $clean_whatsapp, $login_input]);
            $user = $stmt->fetch();

            $userPass = !empty($user['password']) ? $user['password'] : ($user['password_hash'] ?? '');
            if ($user && password_verify($password, $userPass)) {
                if ($user['status'] === 'suspended') {
                    $error = 'Sua conta está suspensa. Entre em contato com o suporte.';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_whatsapp'] = $user['whatsapp'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];

                    if ($user['role'] === 'admin') {
                        header('Location: ' . getAppBaseUrl() . '/admin/index.php');
                    } elseif ($user['role'] === 'reseller') {
                        header('Location: ' . getAppBaseUrl() . '/reseller/index.php');
                    } else {
                        header('Location: ' . getAppBaseUrl() . '/client/index.php');
                    }
                    exit;
                }
            } else {
                $error = 'WhatsApp/E-mail ou senha incorretos.';
            }
        } else {
            $error = 'Preencha todos os campos.';
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
    <title>Entrar - BRLove API</title>
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
<body class="min-h-screen flex items-center justify-center p-4 antialiased relative overflow-hidden" style="background-color: var(--bg-base); color: var(--text-body);">

    <!-- Efeito de Luz / Glow de Fundo (Mesh Gradient Sutil) -->
    <div class="absolute inset-0 pointer-events-none overflow-hidden flex items-center justify-center">
        <div class="w-[500px] h-[500px] rounded-full blur-[120px] opacity-20 dark:opacity-30" style="background: var(--brand-btn-gradient);"></div>
    </div>

    <div class="max-w-md w-full glass-card p-7 sm:p-9 rounded-3xl border shadow-2xl relative z-10 transition-all">
        <!-- Botão de Alternar Modo Claro / Escuro -->
        <div class="absolute top-6 right-6">
            <button type="button" onclick="toggleThemeMode()" title="Alternar modo claro/escuro" class="btn-theme-toggle w-9 h-9 rounded-xl glass-card flex items-center justify-center transition cursor-pointer">
                <i class="theme-toggle-icon fa-solid <?= $isDark ? 'fa-moon text-indigo-400' : 'fa-sun text-amber-500' ?> text-sm"></i>
            </button>
        </div>
        
        <div class="text-center mb-8">
            <a href="index.php" class="inline-flex items-center space-x-3 mb-4 group">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center font-bold text-2xl text-white shadow-lg transition-transform group-hover:scale-105" style="background: var(--brand-btn-gradient); box-shadow: 0 4px 16px var(--brand-glow);">
                    <i class="fa-solid fa-cloud-bolt"></i>
                </div>
                <span class="text-2xl font-extrabold tracking-tight" style="color: var(--text-heading);">
                    BRLove<span style="color: var(--accent-primary);">API</span>
                </span>
            </a>
            <h1 class="text-xl font-bold tracking-tight" style="color: var(--text-heading);">Acessar sua Conta</h1>
            <p class="text-xs font-medium mt-1.5" style="color: var(--text-muted);">Entre com seu WhatsApp e senha para gerenciar seus tokens</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-2xl border flex items-center shadow-sm" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #ef4444;">
                <i class="fa-solid fa-circle-exclamation mr-3 text-lg shrink-0"></i>
                <span class="text-xs font-semibold"><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-5">
            <?= csrf_field() ?>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider mb-2" style="color: var(--text-muted);">WhatsApp ou E-mail</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-emerald-500">
                        <i class="fa-brands fa-whatsapp text-lg"></i>
                    </span>
                    <input type="text" name="login_input" required placeholder="Ex: 11999999999 ou 5511999999999" class="w-full pl-11 pr-4 py-3 text-xs font-mono font-medium focus:ring-2">
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-xs font-bold uppercase tracking-wider" style="color: var(--text-muted);">Senha de Acesso</label>
                </div>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4" style="color: var(--text-muted);">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                    <input type="password" id="loginPassInput" name="password" required placeholder="••••••••" class="w-full pl-11 pr-11 py-3 text-xs focus:ring-2">
                    <button type="button" onclick="togglePassVisibility()" class="absolute inset-y-0 right-0 flex items-center pr-4 text-gray-400 hover:text-white transition" title="Exibir/Ocultar senha">
                        <i id="eyeIcon" class="fa-solid fa-eye text-xs"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="w-full py-3.5 rounded-xl btn-brand text-white font-bold text-sm transition shadow-lg flex items-center justify-center gap-2 cursor-pointer">
                <span>Entrar no Painel</span>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </button>
        </form>

        <div class="mt-8 text-center text-xs border-t pt-6" style="border-color: var(--border-color); color: var(--text-muted);">
            Ainda não tem uma conta? <a href="register.php" class="font-bold hover:underline" style="color: var(--accent-primary);">Criar conta ou revenda</a>
        </div>
    </div>

    <script>
        function togglePassVisibility() {
            const input = document.getElementById('loginPassInput');
            const icon = document.getElementById('eyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-solid fa-eye-slash text-xs';
            } else {
                input.type = 'password';
                icon.className = 'fa-solid fa-eye text-xs';
            }
        }
    </script>
</body>
</html>
