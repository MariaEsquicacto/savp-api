<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/permissao.php';

/**
 * Obtém o cabeçalho Authorization da requisição.
 */
function obterHeaderAuthorization(): ?string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $chave => $valor) {
                if (strtolower($chave) === 'authorization') {
                    return $valor;
                }
            }
        }
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $chave => $valor) {
                if (strtolower($chave) === 'authorization') {
                    return $valor;
                }
            }
        }
    }

    return null;
}

/**
 * Autentica o usuário pelo Bearer Token informado no cabeçalho.
 */
function autenticar(): array
{
    $authorization = obterHeaderAuthorization();

    if (!$authorization) {
        resposta([
            'sucesso' => false,
            'mensagem' => 'Token de autorização não informado.'
        ], 401);
    }

    if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
        resposta([
            'sucesso' => false,
            'mensagem' => 'Formato do token inválido. Utilize: Bearer <token>'
        ], 401);
    }

    $token = trim($matches[1]);

    if ($token === '') {
        resposta([
            'sucesso' => false,
            'mensagem' => 'Token vazio.'
        ], 401);
    }

    $tokenHash = hash('sha256', $token);

    $database = new Database();
    $db = $database->connect();

    $sql = "
        SELECT
            u.id,
            u.nome,
            u.email,
            u.cargo,
            u.ativo,
            t.id AS token_id,
            t.expiracao
        FROM tokens t
        INNER JOIN usuarios u ON u.id = t.usuario_id
        WHERE t.token_hash = :token_hash
          AND t.revogado = 0
          AND t.expiracao > NOW()
          AND u.ativo = 1
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':token_hash' => $tokenHash]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        resposta([
            'sucesso' => false,
            'mensagem' => 'Token inválido, revogado ou expirado.'
        ], 401);
    }

    // Atualiza último acesso do token
    $update = $db->prepare("
        UPDATE tokens
        SET ultimo_acesso = NOW()
        WHERE id = :id
    ");
    $update->execute([':id' => $usuario['token_id']]);

    // Converte tipos
    $usuario['id'] = (int) $usuario['id'];
    $usuario['token_id'] = (int) $usuario['token_id'];
    $usuario['ativo'] = (bool) $usuario['ativo'];

    return $usuario;
}

/**
 * Revoga um token específico pelo ID.
 */
function revogarTokenPorId(int $tokenId): bool
{
    $database = new Database();
    $db = $database->connect();

    $stmt = $db->prepare("
        UPDATE tokens
        SET revogado = 1
        WHERE id = :id
    ");

    return $stmt->execute([':id' => $tokenId]);
}