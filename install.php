<?php
/**
 * Instalador Automático do Painel Claude API Gateway
 */

if (file_exists(__DIR__ . '/install.lock')) {
    die("<h1>Painel já instalado!</h1><p>Se você precisa reinstalar, remova o arquivo <code>install.lock</code> na raiz da aplicação.</p><p><a href='login.php'>Ir para o Login</a></p>");
}

if (!function_exists('generateApiKey')) {
    function generateApiKey($prefix = 'sk-ant-api03-') {
        return $prefix . bin2hex(random_bytes(24));
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? '127.0.0.1');
    $db_port = trim($_POST['db_port'] ?? '3306');
    $db_name = trim($_POST['db_name'] ?? 'api_gateway');
    $db_user = trim($_POST['db_user'] ?? 'root');
    $db_pass = trim($_POST['db_pass'] ?? '');

    $admin_name = trim($_POST['admin_name'] ?? 'Administrador');
    $admin_whatsapp = trim($_POST['admin_whatsapp'] ?? '5511999999999');
    $admin_email = trim($_POST['admin_email'] ?? 'admin@admin.com');
    $admin_password = $_POST['admin_password'] ?? '';

    $gateway_public_url = trim($_POST['gateway_public_url'] ?? '');
    $gateway_internal_url = trim($_POST['gateway_internal_url'] ?? 'http://127.0.0.1:3000');
    if (empty($gateway_internal_url)) $gateway_internal_url = 'http://127.0.0.1:3000';

    if (empty($admin_password)) {
        $error = 'Por favor, informe a senha do Administrador.';
    } else {
        try {
            // Testar conexão MySQL
            $dsn = "mysql:host={$db_host};port={$db_port};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Criar Banco se não existir
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $pdo->exec("USE `{$db_name}`;");

            // Criar Estrutura de Tabelas
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `users` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `name` VARCHAR(100) NOT NULL,
                  `whatsapp` VARCHAR(30) NOT NULL UNIQUE,
                  `email` VARCHAR(150) NULL UNIQUE,
                  `password` VARCHAR(255) NOT NULL,
                  `password_hash` VARCHAR(255) NULL,
                  `role` ENUM('admin', 'reseller', 'user') DEFAULT 'user',
                  `parent_id` INT NULL DEFAULT NULL,
                  `balance_tokens` BIGINT DEFAULT 10000000,
                  `tokens_used` BIGINT DEFAULT 0,
                  `status` ENUM('active', 'suspended') DEFAULT 'active',
                  `plan_type` VARCHAR(50) DEFAULT 'Pro Dev Ilimitado',
                  `rate_limit_rpm` INT DEFAULT 60,
                  `last_login_at` DATETIME NULL,
                  `is_whatsapp_verified` TINYINT(1) DEFAULT 0,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `whatsapp_otps` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `whatsapp` VARCHAR(30) NOT NULL,
                  `otp_code` VARCHAR(10) NOT NULL,
                  `expires_at` DATETIME NOT NULL,
                  `is_used` TINYINT(1) DEFAULT 0,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `whatsapp_instances` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `instance_key` VARCHAR(100) NOT NULL UNIQUE,
                  `instance_name` VARCHAR(100) DEFAULT 'Instância Principal',
                  `status` VARCHAR(50) DEFAULT 'disconnected',
                  `qr_code` TEXT NULL,
                  `phone_connected` VARCHAR(30) DEFAULT NULL,
                  `last_activity_at` DATETIME NULL,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `instance_key` VARCHAR(100) NOT NULL DEFAULT 'inst_default',
                  `destination` VARCHAR(50) NOT NULL,
                  `message` TEXT NOT NULL,
                  `status` VARCHAR(50) NOT NULL DEFAULT 'sent',
                  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `payments` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `user_id` INT NOT NULL,
                  `plan_id` INT DEFAULT NULL,
                  `transaction_id` VARCHAR(100) NOT NULL UNIQUE,
                  `amount` DECIMAL(10,2) NOT NULL,
                  `tokens_amount` BIGINT NOT NULL,
                  `plan_name` VARCHAR(100) NOT NULL,
                  `status` ENUM('pending', 'paid', 'cancelled') DEFAULT 'pending',
                  `pix_copia_cola` TEXT NULL,
                  `pix_qr_code_base64` LONGTEXT NULL,
                  `payment_method` VARCHAR(30) DEFAULT 'pix',
                  `qr_code` TEXT NULL,
                  `qr_code_base64` TEXT NULL,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `api_keys` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `user_id` INT NOT NULL,
                  `key_name` VARCHAR(100) DEFAULT 'Chave Padrão',
                  `api_key` VARCHAR(100) NOT NULL UNIQUE,
                  `tokens_used` BIGINT DEFAULT 0,
                  `status` ENUM('active', 'revoked') DEFAULT 'active',
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `providers` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `name` VARCHAR(100) NOT NULL,
                  `base_url` VARCHAR(255) NOT NULL,
                  `api_type` VARCHAR(50) DEFAULT 'openai_compatible',
                  `status` ENUM('active', 'inactive') DEFAULT 'active',
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `provider_keys` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `provider_id` INT NOT NULL,
                  `api_key` VARCHAR(255) NOT NULL,
                  `key_label` VARCHAR(100) DEFAULT 'Chave Principal',
                  `status` ENUM('active', 'disabled') DEFAULT 'active',
                  `error_count` INT DEFAULT 0,
                  `last_used_at` TIMESTAMP NULL,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `provider_models` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `provider_id` INT NOT NULL,
                  `upstream_model` VARCHAR(191) NOT NULL,
                  `name` VARCHAR(191) NOT NULL,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  UNIQUE KEY `unique_provider_model` (`provider_id`, `upstream_model`),
                  FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `models` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `model_key` VARCHAR(100) NOT NULL UNIQUE,
                  `name` VARCHAR(100) NOT NULL,
                  `description` VARCHAR(255) DEFAULT NULL,
                  `context_window` VARCHAR(50) DEFAULT '128k',
                  `tags` VARCHAR(255) DEFAULT 'Geral',
                  `provider_id` INT NULL,
                  `upstream_model` VARCHAR(100) NOT NULL DEFAULT 'claude-sonnet-4-6',
                  `multiplier` DECIMAL(5,2) DEFAULT 1.00,
                  `pre_prompt` TEXT NULL,
                  `status` ENUM('active', 'inactive') DEFAULT 'active',
                  `is_admin_only` TINYINT(1) DEFAULT 0,
                  `is_realtime` TINYINT(1) DEFAULT 0,
                  `is_smart_routing` TINYINT(1) DEFAULT 0,
                  `routing_fast_model` VARCHAR(100) DEFAULT 'gemini-2.5-flash',
                  `routing_fast_multiplier` DECIMAL(5,2) DEFAULT 1.00,
                  `routing_medium_model` VARCHAR(100) DEFAULT 'gemini-3.8-flash-medium',
                  `routing_medium_multiplier` DECIMAL(5,2) DEFAULT 1.50,
                  `routing_heavy_model` VARCHAR(100) DEFAULT 'claude-opus-4-6-thinking',
                  `routing_heavy_multiplier` DECIMAL(5,2) DEFAULT 3.00,
                  `sort_order` INT DEFAULT 0,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `antigravity_accounts` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `email` VARCHAR(255) NOT NULL UNIQUE,
                  `name` VARCHAR(255) NULL,
                  `picture` VARCHAR(500) NULL,
                  `refresh_token` TEXT NOT NULL,
                  `access_token` TEXT NULL,
                  `token_expires_at` DATETIME NULL,
                  `project_id` VARCHAR(255) NULL,
                  `is_active` TINYINT(1) DEFAULT 1,
                  `status` VARCHAR(50) DEFAULT 'active',
                  `quotas_json` LONGTEXT NULL,
                  `last_sync_at` DATETIME NULL,
                  `total_requests` INT DEFAULT 0,
                  `total_tokens` BIGINT DEFAULT 0,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `antigravity_model_quotas` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `account_id` INT NOT NULL,
                  `model_name` VARCHAR(255) NOT NULL,
                  `percentage` DECIMAL(5,2) DEFAULT 100.00,
                  `reset_time` TIMESTAMP NULL DEFAULT NULL,
                  `raw_quota_info` LONGTEXT NULL,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  UNIQUE KEY `unique_acc_model` (`account_id`, `model_name`),
                  FOREIGN KEY (`account_id`) REFERENCES `antigravity_accounts`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `settings` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
                  `setting_value` TEXT,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `plans` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `name` VARCHAR(100) NOT NULL,
                  `price` DECIMAL(10,2) NOT NULL,
                  `reseller_price` DECIMAL(10,2) NULL DEFAULT NULL,
                  `tokens_amount` BIGINT NOT NULL,
                  `badge` VARCHAR(100) NULL,
                  `target_audience` ENUM('all', 'client', 'reseller') DEFAULT 'all',
                  `features` TEXT NULL,
                  `is_featured` TINYINT(1) DEFAULT 0,
                  `status` ENUM('active', 'inactive') DEFAULT 'active',
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `usage_logs` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `user_id` INT NOT NULL,
                  `api_key_id` INT NOT NULL,
                  `model` VARCHAR(100) DEFAULT 'claude-3-5-sonnet',
                  `prompt_tokens` INT DEFAULT 0,
                  `completion_tokens` INT DEFAULT 0,
                  `total_tokens` INT DEFAULT 0,
                  `multiplier` DECIMAL(5,2) DEFAULT 1.00,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Planos Padrão Iniciais
            $pdo->exec("
                INSERT IGNORE INTO `plans` (`id`, `name`, `price`, `reseller_price`, `tokens_amount`, `badge`, `target_audience`, `features`, `is_featured`, `status`) VALUES
                (1, 'Starter Pack', 49.00, 25.00, 5000000, 'Starter', 'client', 'Claude 3.5 Sonnet & Haiku\nValidade Indeterminada\nSuporte Básico', 0, 'active'),
                (2, 'Pro Dev Ilimitado', 149.00, 79.00, 50000000, 'Mais Popular', 'client', 'Claude 3.5 Sonnet, Opus & Haiku\nValidade Indeterminada\nSuporte Prioritário VIP\nMúltiplas Chaves Ativas', 1, 'active'),
                (3, 'Team Enterprise', 299.00, 149.00, 200000000, 'Enterprise', 'all', 'Acesso Total a Todos os Modelos\nValidade Indeterminada\nSuporte VIP 24/7\nChaves de API Ilimitadas', 0, 'active'),
                (4, 'Pacote Revenda Gold', 499.00, 299.00, 500000000, '500M Tokens', 'reseller', '500 Milhões de Tokens p/ Revenda\nClientes Ilimitados\nPrioridade em Suporte VIP', 1, 'active');
            ");

            // Configurações Padrão Globais
            $stmtSet = $pdo->prepare("INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");
            $stmtSet->execute(['pre_prompt', '']);
            $stmtSet->execute(['default_model', 'claude-3-5-sonnet']);
            $stmtSet->execute(['gateway_public_url', $gateway_public_url]);
            $stmtSet->execute(['gateway_api_url', $gateway_internal_url]);
            $stmtSet->execute(['whatsapp_api_url', $gateway_internal_url]);
            $stmtSet->execute(['whatsapp_default_instance', 'inst_default']);
            $stmtSet->execute(['whatsapp_bot_active', '0']);
            $stmtSet->execute(['whatsapp_bot_model', 'gemini-3.8-flash-medium']);
            $stmtSet->execute(['whatsapp_bot_prompt', "Você é a atendente virtual de inteligência artificial da BRLove API.\nSeu objetivo é tirar dúvidas sobre a plataforma, explicar como funcionam as recargas de tokens, modelos disponíveis e ajudar o cliente a escolher o melhor plano.\nResponda sempre com simpatia, rapidez, boa formatação e em português do Brasil."]);
            $stmtSet->execute(['whatsapp_msg_otp', "🔐 *Seu Código de Confirmação BRLove API:* *{codigo}*\n\nDigite este código no site para confirmar o seu acesso.\n\n_Válido por 10 minutos._"]);
            $stmtSet->execute(['whatsapp_msg_welcome', "👋 Olá, *{nome}*! Bem-vindo à plataforma *BRLove API* de Inteligência Artificial.\nSua conta foi criada com sucesso e já está pronta para uso! Acesse seu painel e confira sua chave de API."]);
            $stmtSet->execute(['whatsapp_msg_pix', "✅ *Pagamento Confirmado!*\n\nOlá *{nome}*, seu pagamento de *R$ {valor}* via PIX foi aprovado com sucesso!\nForam creditados *{tokens} tokens* em sua conta. Bons usos! 🚀"]);
            $stmtSet->execute(['whatsapp_msg_low_balance', "⚠️ *Alerta de Saldo BRLove API*\n\nOlá *{nome}*, seu saldo de tokens está em *{saldo}*. Para evitar pausas nas suas aplicações de IA, faça uma recarga no seu painel."]);
            $stmtSet->execute(['realtime_access', 'all']);

            // Instância Padrão do WhatsApp
            $pdo->exec("INSERT INTO `whatsapp_instances` (`instance_key`, `instance_name`, `status`, `qr_code`) VALUES ('inst_default', 'WhatsApp Principal', 'disconnected', NULL) ON DUPLICATE KEY UPDATE `instance_name` = VALUES(`instance_name`);");

            // Provedor Padrão Inicial
            $pdo->exec("INSERT IGNORE INTO `providers` (`id`, `name`, `base_url`, `status`) VALUES (1, 'api.b.ai', 'https://api.b.ai/v1', 'active'), (2, 'Google Antigravity Pool', 'https://cloudcode-pa.googleapis.com', 'active'), (3, 'NVIDIA NIM (Build AI)', 'https://integrate.api.nvidia.com/v1', 'active');");

            // Modelos Padrão com Suporte a Roteamento e Google Antigravity Oficial
            $defaultModels = [
                ['claude-3-5-sonnet', 'Claude 3.5 Sonnet', 2, 'claude-sonnet-4-6', 'Você é o Claude 3.5 Sonnet, o modelo de inteligência artificial mais capaz da Anthropic. Responda sempre com clareza, excelente didática e em português.'],
                ['claude-opus-4-6-thinking', 'Claude Opus 4.6 Thinking', 2, 'claude-opus-4-6-thinking', 'Você é o Claude Opus 4.6 Thinking, especialista em raciocínio analítico profundo e arquitetura de software.'],
                ['gemini-3.8-flash-medium', 'Gemini 3.8 Flash Medium', 2, 'gemini-3.8-flash-medium', 'Você é o Gemini 3.8 Flash Medium, um modelo multimodal balanceado para respostas rápidas e precisas.'],
                ['gemini-2.5-flash', 'Gemini 2.5 Flash (Realtime)', 2, 'gemini-2.5-flash', 'Você é o Gemini 2.5 Flash com suporte a Realtime Voice e baixa latência.']
            ];

            $stmtM = $pdo->prepare("INSERT INTO `models` (`model_key`, `name`, `provider_id`, `upstream_model`, `pre_prompt`) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `provider_id` = VALUES(`provider_id`), `upstream_model` = VALUES(`upstream_model`), `pre_prompt` = VALUES(`pre_prompt`)");
            foreach ($defaultModels as $m) {
                $stmtM->execute($m);
            }

            // Criar Usuário Administrador com compatibilidade total (password e password_hash)
            $passwordHash = password_hash($admin_password, PASSWORD_BCRYPT);
            $stmtUser = $pdo->prepare("
                INSERT INTO `users` (`name`, `whatsapp`, `email`, `password`, `password_hash`, `role`, `plan_type`, `balance_tokens`, `is_whatsapp_verified`)
                VALUES (?, ?, ?, ?, ?, 'admin', 'unlimited', 999999999, 1)
                ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `password_hash` = VALUES(`password_hash`), `role` = 'admin';
            ");
            $stmtUser->execute([$admin_name, $admin_whatsapp, $admin_email, $passwordHash, $passwordHash]);

            $adminId = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM users WHERE whatsapp = '{$admin_whatsapp}'")->fetchColumn();

            // Gerar API Key Inicial para o Admin no padrão oficial Claude
            $adminApiKey = generateApiKey();
            $stmtKey = $pdo->prepare("INSERT INTO `api_keys` (`user_id`, `key_name`, `api_key`) VALUES (?, 'Chave Principal Admin', ?)");
            $stmtKey->execute([$adminId, $adminApiKey]);

            // Salvar Configuração de Conexão no config/database.php preservando a classe Database e getDB()
            $configContent = "<?php\n"
                . "// Arquivo gerado pelo Instalador Automático\n"
                . "class Database {\n"
                . "    private static \$instance = null;\n"
                . "    private \$pdo;\n\n"
                . "    private function __construct() {\n"
                . "        \$db_host = '" . addslashes($db_host) . "';\n"
                . "        \$db_port = '" . addslashes($db_port) . "';\n"
                . "        \$db_name = '" . addslashes($db_name) . "';\n"
                . "        \$db_user = '" . addslashes($db_user) . "';\n"
                . "        \$db_pass = '" . addslashes($db_pass) . "';\n\n"
                . "        try {\n"
                . "            \$dsn = \"mysql:host={\$db_host};port={\$db_port};dbname={\$db_name};charset=utf8mb4\";\n"
                . "            \$this->pdo = new PDO(\$dsn, \$db_user, \$db_pass, [\n"
                . "                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
                . "                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
                . "                PDO::ATTR_EMULATE_PREPARES => false,\n"
                . "            ]);\n"
                . "        } catch (PDOException \$e) {\n"
                . "            die(\"Erro ao conectar ao banco de dados MySQL: \" . \$e->getMessage());\n"
                . "        }\n"
                . "    }\n\n"
                . "    public static function getInstance() {\n"
                . "        if (self::\$instance === null) {\n"
                . "            self::\$instance = new Database();\n"
                . "        }\n"
                . "        return self::\$instance->pdo;\n"
                . "    }\n"
                . "}\n\n"
                . "function getDB() {\n"
                . "    return Database::getInstance();\n"
                . "}\n";

            file_put_contents(__DIR__ . '/config/database.php', $configContent);

            // Gerar .env para a pasta serverapi (Gateway Node.js)
            $parsedGwPort = 3000;
            if (preg_match('/:(\d+)/', $gateway_internal_url, $pMatch)) {
                $parsedGwPort = (int)$pMatch[1];
            }
            $envContent = "PORT={$parsedGwPort}\n"
                . "DB_HOST=" . $db_host . "\n"
                . "DB_PORT=" . $db_port . "\n"
                . "DB_USER=" . $db_user . "\n"
                . "DB_PASSWORD=" . $db_pass . "\n"
                . "DB_NAME=" . $db_name . "\n";
            @file_put_contents(__DIR__ . '/serverapi/.env', $envContent);

            // Criar install.lock
            file_put_contents(__DIR__ . '/install.lock', 'INSTALLED_ON_' . date('Y-m-d H:i:s'));

            $success = true;
        } catch (Exception $e) {
            $error = 'Erro durante a instalação: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador do Sistema - BRLove API Gateway</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .glass {
            background: rgba(17, 24, 39, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <div class="glass max-w-2xl w-full p-6 sm:p-10 rounded-3xl border border-gray-800 shadow-2xl space-y-6">

        <div class="text-center space-y-2">
            <div class="inline-flex w-14 h-14 rounded-2xl bg-gradient-to-tr from-indigo-600 to-cyan-500 items-center justify-center text-white text-2xl font-bold shadow-lg shadow-indigo-500/30">
                <i class="fa-solid fa-cloud-bolt"></i>
            </div>
            <h1 class="text-2xl font-extrabold text-white tracking-tight">Instalação da BRLove API Gateway</h1>
            <p class="text-xs text-gray-400">Configure a conexão com seu banco de dados MySQL e crie a conta de Administrador.</p>
        </div>

        <?php if ($success): ?>
            <div class="p-6 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-center space-y-4">
                <i class="fa-solid fa-circle-check text-4xl"></i>
                <h2 class="text-lg font-bold">Instalação Concluída com Sucesso!</h2>
                <p class="text-xs text-gray-300">As tabelas do banco de dados e os modelos padrão foram criados.</p>
                <div class="pt-2">
                    <a href="login.php" class="inline-block px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs transition shadow-lg shadow-emerald-600/30">
                        Ir para a Tela de Login <i class="fa-solid fa-arrow-right ml-1"></i>
                    </a>
                </div>
            </div>
        <?php exit; endif; ?>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-red-500/10 border border-red-500/30 text-red-400 text-xs flex items-center">
                <i class="fa-solid fa-triangle-exclamation text-lg mr-3"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="install.php" class="space-y-6">

            <!-- Seção 1: Banco de Dados -->
            <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800">
                <h2 class="text-sm font-bold text-cyan-400 uppercase tracking-wider mb-4 flex items-center">
                    <i class="fa-solid fa-database mr-2"></i> 1. Conexão com o Banco de Dados (MySQL)
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Host do MySQL</label>
                        <input type="text" name="db_host" value="127.0.0.1" required class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white font-mono">
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Porta do MySQL</label>
                        <input type="text" name="db_port" value="3306" required class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white font-mono">
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Nome do Banco (Database)</label>
                        <input type="text" name="db_name" value="api_gateway" required class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white font-mono">
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Usuário do MySQL</label>
                        <input type="text" name="db_user" value="root" required class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white font-mono">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block font-semibold text-gray-300 mb-1">Senha do MySQL</label>
                        <input type="password" name="db_pass" placeholder="Deixe em branco se não houver" class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white font-mono">
                    </div>
                </div>
            </div>

            <!-- Seção 2: Conta do Administrador -->
            <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800">
                <h2 class="text-sm font-bold text-indigo-400 uppercase tracking-wider mb-4 flex items-center">
                    <i class="fa-solid fa-user-shield mr-2"></i> 2. Conta do Administrador
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Nome do Admin</label>
                        <input type="text" name="admin_name" value="Administrador" required class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white">
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">WhatsApp do Admin (Login)</label>
                        <input type="text" name="admin_whatsapp" value="5511999999999" required placeholder="5511999999999" class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white font-mono">
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">E-mail do Admin</label>
                        <input type="email" name="admin_email" value="admin@admin.com" required class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white">
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Senha do Admin</label>
                        <input type="password" name="admin_password" required placeholder="Sua senha secreta" class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white">
                    </div>
                </div>
            </div>

            <!-- Seção 3: Domínio da API / Gateway (Opcional / Recomendado para VPS) -->
            <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800">
                <h2 class="text-sm font-bold text-emerald-400 uppercase tracking-wider mb-2 flex items-center">
                    <i class="fa-solid fa-globe mr-2"></i> 3. Domínio da API / Gateway (Opcional)
                </h2>
                <p class="text-[11px] text-gray-400 mb-4">Se você já tiver um subdomínio para a API na VPS (ex: <code>api.seudominio.com</code>), informe abaixo. Caso contrário, deixe em branco para autodetectar.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">URL Pública da API (Clientes & Agentes)</label>
                        <input type="text" name="gateway_public_url" value="" placeholder="https://api.seudominio.com" class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white font-mono">
                        <span class="text-[10px] text-gray-500 mt-1 block">Ex: <code>https://api.seudominio.com</code> (sem barra no final)</span>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">URL Interna do Gateway (PHP &rarr; Node.js)</label>
                        <input type="text" name="gateway_internal_url" value="http://127.0.0.1:3000" required class="w-full p-2.5 rounded-lg bg-gray-950 border border-gray-800 text-white font-mono">
                        <span class="text-[10px] text-gray-500 mt-1 block">Comunicação local interna (padrão: <code>http://127.0.0.1:3000</code>)</span>
                    </div>
                </div>
            </div>

            <button type="submit" class="w-full py-4 rounded-xl bg-gradient-to-r from-indigo-600 to-cyan-500 hover:from-indigo-500 hover:to-cyan-400 text-white font-bold text-base transition shadow-xl shadow-indigo-600/30">
                <i class="fa-solid fa-check-circle mr-2"></i> Concluir Instalação e Criar Tabelas
            </button>
        </form>

    </div>
</body>
</html>
