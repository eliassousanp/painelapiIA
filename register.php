<?php
require_once __DIR__ . '/config/helpers.php';

$error = '';

if (isLoggedIn()) {
    header('Location: ' . getAppBaseUrl() . '/client/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Limpar o número de whatsapp (manter apenas números)
    $clean_whatsapp = preg_replace('/[^0-9]/', '', $whatsapp);

    if (!empty($name) && !empty($clean_whatsapp) && !empty($password)) {
        $db = getDB();
        
        // Verifica se o WhatsApp já existe
        $stmt = $db->prepare("SELECT id FROM users WHERE whatsapp = ?");
        $stmt->execute([$clean_whatsapp]);
        if ($stmt->fetch()) {
            $error = 'Este número de WhatsApp já está cadastrado.';
        } else {
            $requestedRole = (isset($_POST['role']) && $_POST['role'] === 'reseller') || (isset($_GET['role']) && $_GET['role'] === 'reseller') ? 'reseller' : 'user';
            $planTitle = ($requestedRole === 'reseller') ? 'Revendedor BRLove' : 'Pro Dev Ilimitado';
            
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (name, whatsapp, email, password, password_hash, role, balance_tokens, plan_type) VALUES (?, ?, ?, ?, ?, ?, 10000000, ?)");
            $stmt->execute([$name, $clean_whatsapp, $email, $hash, $hash, $requestedRole, $planTitle]);
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
    } else {
        $error = 'Preencha seu Nome, WhatsApp e Senha.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= htmlspecialchars(getThemeMode()) ?>" data-scheme="<?= htmlspecialchars(getThemeScheme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Conta - BRLove API</title>
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
<body class="bg-gray-950 text-gray-100 flex items-center justify-center min-h-screen px-4 font-sans py-12">
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
            <h1 class="text-xl font-bold text-white">Criar Nova Conta</h1>
            <p class="text-xs text-gray-400 mt-1">Crie sua conta com seu WhatsApp e obtenha sua API Key</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm flex items-center">
                <i class="fa-solid fa-circle-exclamation mr-3 text-lg"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">Nome Completo</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-gray-500">
                        <i class="fa-solid fa-user"></i>
                    </span>
                    <input type="text" name="name" required placeholder="Seu Nome ou Empresa" class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-900/80 border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">Número do WhatsApp (Seu Login)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-emerald-500">
                        <i class="fa-brands fa-whatsapp text-lg"></i>
                    </span>
                    <input type="text" name="whatsapp" required placeholder="DDD + Número (ex: 11999999999)" class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-900/80 border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">E-mail (Opcional)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-gray-500">
                        <i class="fa-solid fa-envelope"></i>
                    </span>
                    <input type="email" name="email" placeholder="seu@email.com" class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-900/80 border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
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
                Cadastrar & Gerar API Key <i class="fa-solid fa-bolt ml-2"></i>
            </button>
        </form>

        <div class="mt-6 text-center text-sm text-gray-400 border-t border-gray-800 pt-6">
            Já possui cadastro? <a href="login.php" class="text-cyan-400 hover:underline font-semibold">Fazer Login</a>
        </div>
    </div>
</body>
</html>
