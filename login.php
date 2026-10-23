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
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= htmlspecialchars(getThemeMode()) ?>" data-scheme="<?= htmlspecialchars(getThemeScheme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar - BRLove API</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?= getAppBaseUrl() ?>/assets/css/theme.css" rel="stylesheet">
    <script>window.BRLOVE_BASE_URL = '<?= getAppBaseUrl() ?>';</script>
    <script src="<?= getAppBaseUrl() ?>/assets/js/theme.js"></script>
    <style>
        .glass {
            background: rgba(17, 24, 39, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 flex items-center justify-center min-h-screen px-4 font-sans">
    <div class="max-w-md w-full glass p-8 rounded-3xl shadow-2xl border border-gray-800 relative">
        <div class="absolute top-6 right-6">
            <button type="button" onclick="toggleThemeMode()" title="Alternar entre modo claro e escuro" class="btn-theme-toggle w-9 h-9 rounded-xl bg-gray-900 hover:bg-gray-800 border border-gray-800 flex items-center justify-center transition">
                <i class="theme-toggle-icon fa-solid fa-sun text-amber-400 text-sm"></i>
            </button>
        </div>
        
        <div class="text-center mb-8">
            <a href="index.php" class="inline-flex items-center space-x-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 via-purple-600 to-cyan-400 flex items-center justify-center font-bold text-xl text-white">
                    <i class="fa-solid fa-heart-pulse"></i>
                </div>
                <span class="text-2xl font-bold text-white">BRLove<span class="text-indigo-400">API</span></span>
            </a>
            <h1 class="text-xl font-bold text-white">Acessar sua Conta</h1>
            <p class="text-xs text-gray-400 mt-1">Digite seu número de WhatsApp e senha</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm flex items-center">
                <i class="fa-solid fa-circle-exclamation mr-3 text-lg"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-5">
            <div>
                <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">WhatsApp (ou E-mail)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-emerald-500">
                        <i class="fa-brands fa-whatsapp text-lg"></i>
                    </span>
                    <input type="text" name="login_input" required placeholder="Ex: 11999999999 ou 5511999999999" class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-900/80 border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">Senha</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-gray-500">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-900/80 border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                </div>
            </div>

            <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-indigo-600 to-cyan-500 hover:from-indigo-500 hover:to-cyan-400 text-white font-bold text-base transition shadow-lg shadow-indigo-600/30">
                Entrar no Painel <i class="fa-solid fa-arrow-right ml-2"></i>
            </button>
        </form>

        <div class="mt-6 text-center text-sm text-gray-400 border-t border-gray-800 pt-6">
            Ainda não tem cadastro? <a href="register.php" class="text-cyan-400 hover:underline font-semibold">Cadastrar revenda</a>
        </div>
    </div>
</body>
</html>
