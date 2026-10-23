<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/helpers.php';

$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

if ($action === 'get') {
    echo json_encode([
        'mode' => getThemeMode(),
        'current' => getThemeScheme(),
        'theme' => getCurrentTheme(),
        'themes' => getThemeThemes()
    ]);
    exit;
}

if ($action === 'set') {
    $scheme = trim($_POST['scheme'] ?? $_GET['scheme'] ?? '');
    $mode = trim($_POST['mode'] ?? $_GET['mode'] ?? '');
    $themes = getThemeThemes();

    $response = ['success' => true];

    // Alternância de modo Claro/Escuro
    if (!empty($mode) && in_array($mode, ['light', 'dark'])) {
        setcookie('theme_mode', $mode, time() + 31536000, '/');
        // Se for admin, salva como padrão global da plataforma
        if (isAdmin()) {
            setSetting('theme_mode', $mode);
            $response['global_saved'] = true;
        }
        $response['mode'] = $mode;
    }

    // Alteração da Paleta de Cores (Exclusivo do Admin ou preview)
    if (!empty($scheme)) {
        if (!isset($themes[$scheme])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Esquema de tema inválido.']);
            exit;
        }

        setcookie('theme_scheme', $scheme, time() + 31536000, '/');

        if (isAdmin()) {
            setSetting('theme_color_scheme', $scheme);
            $response['global_saved'] = true;
        }

        $response['scheme'] = $scheme;
        $response['theme'] = $themes[$scheme];
    }

    echo json_encode($response);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação não suportada.']);
