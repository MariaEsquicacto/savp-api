<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../auth/auth.php';

class AuthController
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    /**
     * Realiza o login do usuário gerando um token de acesso.
     */
    public function login(): void
    {
        $dados = obterDadosRequisicao();

        $email = trim($dados['email'] ?? '');
        $senha = (string) ($dados['senha'] ?? '');

        if ($email === '' || $senha === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Email e senha são obrigatórios.'
            ], 422);
        }

        $sql = "
            SELECT
                id,
                nome,
                email,
                senha_hash,
                cargo,
                ativo
            FROM usuarios
            WHERE email = :email
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch();

        if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Email ou senha inválidos.'
            ], 401);
        }

        if (!(bool) $usuario['ativo']) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Usuário desativado pelo administrador.'
            ], 403);
        }

        // Token aleatório seguro
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Token válido por 8 horas
        $expiracao = date('Y-m-d H:i:s', time() + (8 * 60 * 60));

        $sqlToken = "
            INSERT INTO tokens (
                usuario_id,
                token_hash,
                expiracao
            )
            VALUES (
                :usuario_id,
                :token_hash,
                :expiracao
            )
        ";

        $stmtToken = $this->db->prepare($sqlToken);
        $stmtToken->execute([
            ':usuario_id' => $usuario['id'],
            ':token_hash' => $tokenHash,
            ':expiracao' => $expiracao
        ]);

        unset($usuario['senha_hash']);
        $usuario['id'] = (int) $usuario['id'];
        $usuario['ativo'] = (bool) $usuario['ativo'];

        resposta([
            'sucesso' => true,
            'mensagem' => 'Login realizado com sucesso.',
            'token' => $token,
            'expira_em' => $expiracao,
            'usuario' => $usuario
        ]);
    }

    /**
     * Encerra a sessão revogando o token ativo.
     */
    public function logout(): void
    {
        $usuario = autenticar();

        revogarTokenPorId($usuario['token_id']);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Logout realizado com sucesso. Sessão encerrada.'
        ]);
    }

    /**
     * Retorna os dados do usuário atualmente autenticado.
     */
    public function me(): void
    {
        $usuario = autenticar();

        resposta([
            'sucesso' => true,
            'usuario' => [
                'id' => $usuario['id'],
                'nome' => $usuario['nome'],
                'email' => $usuario['email'],
                'cargo' => $usuario['cargo'],
                'ativo' => $usuario['ativo']
            ]
        ]);
    }

    /**
     * Permite que o usuário autenticado altere sua própria senha.
     */
    public function alterarSenha(): void
    {
        $usuario = autenticar();
        $dados = obterDadosRequisicao();

        $senhaAtual = (string) ($dados['senha_atual'] ?? '');
        $novaSenha = (string) ($dados['nova_senha'] ?? '');

        if ($senhaAtual === '' || $novaSenha === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Senha atual e nova senha são obrigatórias.'
            ], 422);
        }

        if (strlen($novaSenha) < 6) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'A nova senha deve ter no mínimo 6 caracteres.'
            ], 422);
        }

        // Busca o hash atual no banco
        $stmt = $this->db->prepare("SELECT senha_hash FROM usuarios WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $usuario['id']]);
        $registro = $stmt->fetch();

        if (!$registro || !password_verify($senhaAtual, $registro['senha_hash'])) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Senha atual incorreta.'
            ], 400);
        }

        $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);

        $update = $this->db->prepare("UPDATE usuarios SET senha_hash = :hash WHERE id = :id");
        $update->execute([
            ':hash' => $novoHash,
            ':id' => $usuario['id']
        ]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Senha alterada com sucesso.'
        ]);
    }
}