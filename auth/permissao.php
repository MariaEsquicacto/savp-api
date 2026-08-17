<?php

require_once __DIR__ . '/../utils/response.php';

/**
 * Verifica se o usuário autenticado possui algum dos cargos permitidos.
 * Se não possuir, retorna HTTP 403 Forbidden.
 *
 * @param array $usuario
 * @param array $cargosPermitidos Ex: ['admin'], ['admin', 'gestor']
 */
function exigirCargo(array $usuario, array $cargosPermitidos): void
{
    if (!isset($usuario['cargo']) || !in_array($usuario['cargo'], $cargosPermitidos, true)) {
        resposta([
            'sucesso' => false,
            'mensagem' => 'Você não possui permissão para realizar esta ação.'
        ], 403);
    }
}

/**
 * Retorna se o usuário possui determinado cargo.
 */
function temCargo(array $usuario, array $cargosPermitidos): bool
{
    return isset($usuario['cargo']) && in_array($usuario['cargo'], $cargosPermitidos, true);
}
