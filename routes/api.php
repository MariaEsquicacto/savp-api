<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../auth/permissao.php';
require_once __DIR__ . '/../controllers/authcontroller.php';
require_once __DIR__ . '/../controllers/usercontroller.php';
require_once __DIR__ . '/../controllers/ambientecontroller.php';
require_once __DIR__ . '/../controllers/salacontroller.php';
require_once __DIR__ . '/../controllers/patrimoniocontroller.php';
require_once __DIR__ . '/../controllers/fotocontroller.php';
require_once __DIR__ . '/../controllers/verificacaocontroller.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Normalização de caminho base para suportar subpastas (ex: /savp-api)
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptDir !== '/' && $scriptDir !== '\\' && strpos($uri, $scriptDir) === 0) {
    $uri = substr($uri, strlen($scriptDir));
}

// Fallback direto para remoção de /savp-api caso não detectado pelo scriptDir
$uri = preg_replace('#^/savp-api#i', '', $uri);
$uri = rtrim($uri, '/');

if ($uri === '') {
    $uri = '/';
}

// Rota raiz de status da API
if ($uri === '/' || $uri === '/api') {
    resposta([
        'sucesso' => true,
        'nome' => 'SAVP API - Sistema de Auditoria e Verificação Patrimonial',
        'versao' => '1.0.0',
        'status' => 'online',
        'horario' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Função utilitária para registrar e casar rotas com parâmetros dinâmicos.
 */
function rotear(string $metodoEsperado, string $padraoRota, callable $handler): void
{
    global $method, $uri;

    if ($method !== strtoupper($metodoEsperado)) {
        return;
    }

    // Converte {param} em regex capture group
    $patternRegex = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $padraoRota);
    $patternRegex = '#^' . $patternRegex . '$#';

    if (preg_match($patternRegex, $uri, $matches)) {
        $params = [];
        foreach ($matches as $chave => $valor) {
            if (!is_int($chave)) {
                $params[] = is_numeric($valor) ? (int) $valor : urldecode($valor);
            }
        }

        call_user_func_array($handler, $params);
        exit;
    }
}

// ==========================================
// 1. AUTENTICAÇÃO & CONTA
// ==========================================
rotear('POST', '/api/login', function() {
    (new AuthController())->login();
});

rotear('POST', '/api/logout', function() {
    (new AuthController())->logout();
});

rotear('GET', '/api/me', function() {
    (new AuthController())->me();
});

rotear('POST', '/api/alterar-senha', function() {
    (new AuthController())->alterarSenha();
});

// ==========================================
// 2. USUÁRIOS (ADMIN)
// ==========================================
rotear('GET', '/api/usuarios', function() {
    (new UserController())->listar();
});

rotear('POST', '/api/usuarios', function() {
    (new UserController())->cadastrar();
});

rotear('GET', '/api/usuarios/{id}', function($id) {
    (new UserController())->buscarPorId((int) $id);
});

rotear('PUT', '/api/usuarios/{id}', function($id) {
    (new UserController())->atualizar((int) $id);
});

rotear('POST', '/api/usuarios/{id}/redefinir-senha', function($id) {
    (new UserController())->redefinirSenha((int) $id);
});

rotear('DELETE', '/api/usuarios/{id}', function($id) {
    (new UserController())->deletar((int) $id);
});

// ==========================================
// 3. AMBIENTES
// ==========================================
rotear('GET', '/api/ambientes', function() {
    (new AmbienteController())->listar();
});

rotear('POST', '/api/ambientes', function() {
    (new AmbienteController())->cadastrar();
});

rotear('GET', '/api/ambientes/{id}', function($id) {
    (new AmbienteController())->buscarPorId((int) $id);
});

rotear('PUT', '/api/ambientes/{id}', function($id) {
    (new AmbienteController())->atualizar((int) $id);
});

rotear('DELETE', '/api/ambientes/{id}', function($id) {
    (new AmbienteController())->deletar((int) $id);
});

rotear('GET', '/api/ambientes/{id}/salas', function($id) {
    (new AmbienteController())->listarSalas((int) $id);
});

// ==========================================
// 4. SALAS
// ==========================================
rotear('GET', '/api/salas', function() {
    (new SalaController())->listar();
});

rotear('POST', '/api/salas', function() {
    (new SalaController())->cadastrar();
});

rotear('GET', '/api/salas/{id}', function($id) {
    (new SalaController())->buscarPorId((int) $id);
});

rotear('PUT', '/api/salas/{id}', function($id) {
    (new SalaController())->atualizar((int) $id);
});

rotear('DELETE', '/api/salas/{id}', function($id) {
    (new SalaController())->deletar((int) $id);
});

rotear('GET', '/api/salas/{id}/patrimonios', function($id) {
    (new SalaController())->listarPatrimonios((int) $id);
});

// ==========================================
// 5. PATRIMÔNIOS
// ==========================================
rotear('GET', '/api/patrimonios', function() {
    (new PatrimonioController())->listar();
});

rotear('POST', '/api/patrimonios', function() {
    (new PatrimonioController())->cadastrar();
});

rotear('GET', '/api/patrimonios/buscar/{codigo}', function($codigo) {
    (new PatrimonioController())->buscarPorCodigo((string) $codigo);
});

rotear('GET', '/api/patrimonios/{id}', function($id) {
    (new PatrimonioController())->buscarPorId((int) $id);
});

rotear('PUT', '/api/patrimonios/{id}', function($id) {
    (new PatrimonioController())->atualizar((int) $id);
});

rotear('DELETE', '/api/patrimonios/{id}', function($id) {
    (new PatrimonioController())->deletar((int) $id);
});

// ==========================================
// 6. FOTOS DE PATRIMÔNIOS
// ==========================================
rotear('POST', '/api/patrimonios/{id}/fotos', function($id) {
    (new FotoController())->upload((int) $id);
});

rotear('GET', '/api/patrimonios/{id}/fotos', function($id) {
    (new FotoController())->listar((int) $id);
});

rotear('DELETE', '/api/patrimonios/{id}/fotos/{tipo}', function($id, $tipo) {
    (new FotoController())->deletar((int) $id, (string) $tipo);
});

// ==========================================
// 7. VERIFICAÇÕES / AUDITORIAS PATRIMONIAIS
// ==========================================
rotear('GET', '/api/verificacoes', function() {
    (new VerificacaoController())->listar();
});

rotear('POST', '/api/verificacoes', function() {
    (new VerificacaoController())->iniciar();
});

rotear('GET', '/api/verificacoes/{id}', function($id) {
    (new VerificacaoController())->buscarPorId((int) $id);
});

rotear('POST', '/api/verificacoes/{id}/escanear', function($id) {
    (new VerificacaoController())->escanear((int) $id);
});

rotear('POST', '/api/verificacoes/{id}/finalizar', function($id) {
    (new VerificacaoController())->finalizar((int) $id);
});

rotear('POST', '/api/verificacoes/{id}/cancelar', function($id) {
    (new VerificacaoController())->cancelar((int) $id);
});

rotear('GET', '/api/verificacoes/{id}/relatorio', function($id) {
    (new VerificacaoController())->relatorio((int) $id);
});

// ==========================================
// 8. DASHBOARD (ADMIN E GESTOR)
// ==========================================
rotear('GET', '/api/dashboard', function() {
    (new VerificacaoController())->dashboard();
});

// ==========================================
// ROTA NÃO ENCONTRADA (404)
// ==========================================
resposta([
    'sucesso' => false,
    'mensagem' => 'Rota não encontrada.',
    'metodo' => $method,
    'uri_recebida' => $uri
], 404);