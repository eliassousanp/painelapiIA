-- =============================================================
-- BANCO DE DADOS COMPLETO E UNIFICADO - API GATEWAY & PAINEL DE REVENDA
-- Compatível com Google Antigravity Pool, Provedores Genéricos, WhatsApp OTP & Disparos,
-- Pagamentos PIX Mistic Pay e Roteamento Inteligente (Smart Routing).
-- =============================================================

CREATE DATABASE IF NOT EXISTS `api_gateway` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `api_gateway`;

-- 1. Tabela de Usuários (Admin, Revendedores e Clientes)
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

-- 2. Tabela de Códigos OTP (WhatsApp)
CREATE TABLE IF NOT EXISTS `whatsapp_otps` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `whatsapp` VARCHAR(30) NOT NULL,
  `otp_code` VARCHAR(10) NOT NULL,
  `is_used` TINYINT(1) DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tabela de Instâncias de WhatsApp (Baileys)
CREATE TABLE IF NOT EXISTS `whatsapp_instances` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `instance_key` VARCHAR(100) NOT NULL UNIQUE,
  `instance_name` VARCHAR(100) DEFAULT 'Instância Principal',
  `status` VARCHAR(50) DEFAULT 'disconnected',
  `qr_code` TEXT NULL,
  `phone_connected` VARCHAR(30) DEFAULT NULL,
  `last_activity_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tabela de Mensagens e Disparos de WhatsApp
CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `instance_key` VARCHAR(100) NOT NULL DEFAULT 'inst_default',
  `destination` VARCHAR(50) NOT NULL,
  `message` TEXT NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'sent',
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Provedores de IA (Upstream)
CREATE TABLE IF NOT EXISTS `providers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `base_url` VARCHAR(255) NOT NULL,
  `api_type` VARCHAR(50) DEFAULT 'openai_compatible',
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Chaves de API dos Provedores (Pool de Chaves por Provedor)
CREATE TABLE IF NOT EXISTS `provider_keys` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `provider_id` INT NOT NULL,
  `api_key` VARCHAR(255) NOT NULL,
  `total_requests` INT DEFAULT 0,
  `key_label` VARCHAR(100) DEFAULT 'Chave Principal',
  `status` ENUM('active', 'disabled') DEFAULT 'active',
  `error_count` INT DEFAULT 0,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Modelos Sincronizados de Provedores
CREATE TABLE IF NOT EXISTS `provider_models` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `provider_id` INT NOT NULL,
  `upstream_model` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_provider_model` (`provider_id`, `upstream_model`),
  FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Modelos de IA e Mapeamento de Roteamento Inteligente
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

-- 9. Chaves de Acesso dos Usuários e Clientes
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `key_name` VARCHAR(100) DEFAULT 'Chave Principal',
  `api_key` VARCHAR(100) NOT NULL UNIQUE,
  `tokens_used` BIGINT DEFAULT 0,
  `status` ENUM('active', 'revoked') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Logs de Consumo de Tokens
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

-- 11. Pagamentos e Vendas PIX
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

-- 12. Planos de Assinatura e Recarga
CREATE TABLE IF NOT EXISTS `plans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `reseller_price` DECIMAL(10,2) DEFAULT NULL,
  `tokens_amount` BIGINT NOT NULL,
  `badge` VARCHAR(100) DEFAULT NULL,
  `target_audience` ENUM('all', 'client', 'reseller') DEFAULT 'all',
  `features` TEXT NULL,
  `is_featured` TINYINT(1) DEFAULT 0,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Pool de Contas Google Antigravity
CREATE TABLE IF NOT EXISTS `antigravity_accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `name` VARCHAR(255) DEFAULT NULL,
  `picture` VARCHAR(500) DEFAULT NULL,
  `refresh_token` TEXT NOT NULL,
  `access_token` TEXT DEFAULT NULL,
  `token_expires_at` DATETIME DEFAULT NULL,
  `project_id` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `status` VARCHAR(50) DEFAULT 'active',
  `quotas_json` LONGTEXT DEFAULT NULL,
  `last_sync_at` DATETIME DEFAULT NULL,
  `total_requests` INT DEFAULT 0,
  `total_tokens` BIGINT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Cotas por Modelo no Pool Antigravity
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

-- 15. Configurações Globais do Sistema
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================
-- INSERÇÃO DE DADOS PADRÃO E CONFIGURAÇÕES INICIAIS
-- =============================================================

-- Provedores Padrão
INSERT INTO `providers` (`id`, `name`, `base_url`, `status`) VALUES
(1, 'api.b.ai', 'https://api.b.ai/v1', 'active'),
(2, 'Google Antigravity Pool', 'https://cloudcode-pa.googleapis.com', 'active'),
(3, 'NVIDIA NIM (Build AI)', 'https://integrate.api.nvidia.com/v1', 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `base_url` = VALUES(`base_url`);

-- Configurações Globais (Gateway e WhatsApp em 127.0.0.1:3000)
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('default_model', 'claude-3-5-sonnet'),
('pre_prompt', ''),
('gateway_api_url', 'http://127.0.0.1:3000'),
('gateway_port', '3000'),
('whatsapp_api_url', 'http://127.0.0.1:3000'),
('whatsapp_default_instance', 'inst_default'),
('whatsapp_bot_active', '0'),
('whatsapp_bot_model', 'gemini-3.8-flash-medium'),
('whatsapp_bot_prompt', 'Você é a atendente virtual de inteligência artificial da BRLove API.\nSeu objetivo é tirar dúvidas sobre a plataforma, explicar como funcionam as recargas de tokens, modelos disponíveis e ajudar o cliente a escolher o melhor plano.\nResponda sempre com simpatia, rapidez, boa formatação e em português do Brasil.'),
('whatsapp_msg_otp', '🔐 *Seu Código de Confirmação BRLove API:* *{codigo}*\n\nDigite este código no site para confirmar o seu acesso.\n\n_Válido por 10 minutos._'),
('whatsapp_msg_welcome', '👋 Olá, *{nome}*! Bem-vindo à plataforma *BRLove API* de Inteligência Artificial.\nSua conta foi criada com sucesso e já está pronta para uso! Acesse seu painel e confira sua chave de API.'),
('whatsapp_msg_pix', '✅ *Pagamento Confirmado!*\n\nOlá *{nome}*, seu pagamento de *R$ {valor}* via PIX foi aprovado com sucesso!\nForam creditados *{tokens} tokens* em sua conta. Bons usos! 🚀'),
('whatsapp_msg_low_balance', '⚠️ *Alerta de Saldo BRLove API*\n\nOlá *{nome}*, seu saldo de tokens está em *{saldo}*. Para evitar pausas nas suas aplicações de IA, faça uma recarga no seu painel.'),
('realtime_access', 'all')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Instância Padrão do WhatsApp
INSERT INTO `whatsapp_instances` (`instance_key`, `instance_name`, `status`, `qr_code`) VALUES
('inst_default', 'WhatsApp Principal', 'disconnected', NULL)
ON DUPLICATE KEY UPDATE `instance_name` = VALUES(`instance_name`);

-- Planos Iniciais
INSERT IGNORE INTO `plans` (`id`, `name`, `price`, `reseller_price`, `tokens_amount`, `badge`, `target_audience`, `features`, `is_featured`, `status`) VALUES
(1, 'Starter Pack', 49.00, 25.00, 5000000, 'Starter', 'client', 'Claude 3.5 Sonnet & Haiku\nValidade Indeterminada\nSuporte Básico', 0, 'active'),
(2, 'Dev Master', 99.00, 50.00, 15000000, 'Mais Popular', 'client', 'Todos os Modelos Claude & Gemini\nAlta Prioridade\nFailover Multi-Contas', 1, 'active'),
(3, 'Revenda Bronze', 250.00, 150.00, 50000000, 'Revenda', 'reseller', 'Sub-painel para revender\nTokens redistribuíveis\nMargem de 100% de lucro', 1, 'active');

-- Modelos Padrão com Suporte a Roteamento Inteligente e Upstream correto do Google Antigravity
INSERT INTO `models` (`id`, `model_key`, `name`, `provider_id`, `upstream_model`, `multiplier`, `status`, `is_smart_routing`, `routing_fast_model`, `routing_medium_model`, `routing_heavy_model`) VALUES
(1, 'claude-3-5-sonnet', 'Claude 3.5 Sonnet', 2, 'claude-sonnet-4-6', 1.00, 'active', 0, 'gemini-2.5-flash', 'gemini-3.8-flash-medium', 'claude-opus-4-6-thinking'),
(2, 'claude-opus-4-6-thinking', 'Claude Opus 4.6 Thinking', 2, 'claude-opus-4-6-thinking', 1.50, 'active', 0, 'gemini-2.5-flash', 'gemini-3.8-flash-medium', 'claude-opus-4-6-thinking'),
(3, 'gemini-3.8-flash-medium', 'Gemini 3.8 Flash Medium', 2, 'gemini-3.8-flash-medium', 0.80, 'active', 0, 'gemini-2.5-flash', 'gemini-3.8-flash-medium', 'claude-opus-4-6-thinking'),
(4, 'gemini-2.5-flash', 'Gemini 2.5 Flash (Realtime)', 2, 'gemini-2.5-flash', 0.50, 'active', 0, 'gemini-2.5-flash', 'gemini-3.8-flash-medium', 'claude-opus-4-6-thinking')
ON DUPLICATE KEY UPDATE `upstream_model` = VALUES(`upstream_model`), `name` = VALUES(`name`), `provider_id` = VALUES(`provider_id`);
