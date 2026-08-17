<?php

/**
 * Envia uma resposta JSON padronizada e encerra a execução.
 */
function resposta(array $dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * Obtém os dados enviados no corpo da requisição (JSON ou Form-Data).
 */
function obterDadosRequisicao(): array
{
    $input = file_get_contents('php://input');
    
    if (!empty($input)) {
        $json = json_decode($input, true);
        if (is_array($json)) {
            return $json;
        }
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return [];
}