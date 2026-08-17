<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../auth/permissao.php';

class UserController
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    /**
     * Listar usuários com filtros opcionais.
     */
    public function listar(): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin']);

        $cargo = $_GET['cargo'] ?? null;
        $ativo = isset($_GET['ativo']) ? (int) $_GET['ativo'] : null;
        $busca = trim($_GET['q'] ?? '');

        $sql = "
            SELECT
                id,
                nome,
                email,
                cargo,
                ativo,
                criado_em,
                atualizado_em
            FROM usuarios
            WHERE 1=1
        ";
        $params = [];

        if ($cargo && in_array($cargo, ['admin', 'gestor', 'colaborador'], true)) {
            $sql .= " AND cargo = :cargo";
            $params[':cargo'] = $cargo;
        }

        if ($ativo !== null) {
            $sql .= " AND ativo = :ativo";
            $params[':ativo'] = $ativo;
        }

        if ($busca !== '') {
            $sql .= " AND (nome LIKE :busca OR email LIKE :busca)";
            $params[':busca'] = "%{$busca}%";
        }

        $sql .= " ORDER BY nome ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll();

        // Converte tipos
        foreach ($usuarios as &$u) {
            $u['id'] = (int) $u['id'];
            $u['ativo'] = (bool) $u['ativo'];
        }

        resposta([
            'sucesso' => true,
            'total' => count($usuarios),
            'usuarios' => $usuarios
        ]);
    }

    /**
     * Buscar usuário por ID.
     */
    public function buscarPorId(int $id): void
    {
        $usuarioLogado = autenticar();

        // Apenas admin ou o próprio usuário pode consultar
        if ($usuarioLogado['cargo'] !== 'admin' && $usuarioLogado['id'] !== $id) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Você não tem permissão para visualizar este usuário.'
            ], 403);
        }

        $stmt = $this->db->prepare("
            SELECT
                id,
                nome,
                email,
                cargo,
                ativo,
                criado_em,
                atualizado_em
            FROM usuarios
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Usuário não encontrado.'
            ], 404);
        }

        $usuario['id'] = (int) $usuario['id'];
        $usuario['ativo'] = (bool) $usuario['ativo'];

        resposta([
            'sucesso' => true,
            'usuario' => $usuario
        ]);
    }

    /**
     * Cadastrar novo usuário (Apenas Admin).
     */
    public function cadastrar(): void
    {
        $usuarioLogado = autenticar();
        exigirCargo($usuarioLogado, ['admin']);

        $dados = obterDadosRequisicao();

        $nome = trim($dados['nome'] ?? '');
        $email = trim($dados['email'] ?? '');
        $senha = (string) ($dados['senha'] ?? '');
        $cargo = trim($dados['cargo'] ?? 'colaborador');

        if ($nome === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O nome é obrigatório.'
            ], 422);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Informe um email válido.'
            ], 422);
        }

        if ($senha === '' || strlen($senha) < 6) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'A senha é obrigatória e deve ter pelo menos 6 caracteres.'
            ], 422);
        }

        if (!in_array($cargo, ['admin', 'gestor', 'colaborador'], true)) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Cargo inválido. Valores aceitos: admin, gestor, colaborador.'
            ], 422);
        }

        // Verifica se email já existe
        $stmtCheck = $this->db->prepare("SELECT id FROM usuarios WHERE email = :email LIMIT 1");
        $stmtCheck->execute([':email' => $email]);
        if ($stmtCheck->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Já existe um usuário cadastrado com este email.'
            ], 409);
        }

        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

        $sql = "
            INSERT INTO usuarios (
                nome,
                email,
                senha_hash,
                cargo,
                ativo
            ) VALUES (
                :nome,
                :email,
                :senha_hash,
                :cargo,
                1
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':email' => $email,
            ':senha_hash' => $senhaHash,
            ':cargo' => $cargo
        ]);

        $id = (int) $this->db->lastInsertId();

        resposta([
            'sucesso' => true,
            'mensagem' => 'Usuário cadastrado com sucesso.',
            'usuario' => [
                'id' => $id,
                'nome' => $nome,
                'email' => $email,
                'cargo' => $cargo,
                'ativo' => true
            ]
        ], 201);
    }

    /**
     * Atualizar dados de um usuário (Apenas Admin).
     */
    public function atualizar(int $id): void
    {
        $usuarioLogado = autenticar();
        exigirCargo($usuarioLogado, ['admin']);

        // Verifica existência do usuário
        $stmtExiste = $this->db->prepare("SELECT id FROM usuarios WHERE id = :id LIMIT 1");
        $stmtExiste->execute([':id' => $id]);
        if (!$stmtExiste->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Usuário não encontrado.'
            ], 404);
        }

        $dados = obterDadosRequisicao();

        $nome = trim($dados['nome'] ?? '');
        $email = trim($dados['email'] ?? '');
        $cargo = trim($dados['cargo'] ?? '');
        $ativo = isset($dados['ativo']) ? (int) (bool) $dados['ativo'] : 1;

        if ($nome === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O nome é obrigatório.'
            ], 422);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Informe um email válido.'
            ], 422);
        }

        if (!in_array($cargo, ['admin', 'gestor', 'colaborador'], true)) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Cargo inválido. Valores aceitos: admin, gestor, colaborador.'
            ], 422);
        }

        // Verifica duplicidade de email para outro usuário
        $stmtEmail = $this->db->prepare("SELECT id FROM usuarios WHERE email = :email AND id != :id LIMIT 1");
        $stmtEmail->execute([':email' => $email, ':id' => $id]);
        if ($stmtEmail->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Este email já está em uso por outro usuário.'
            ], 409);
        }

        // Se estiver desativando a si próprio
        if ($usuarioLogado['id'] === $id && $ativo === 0) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Você não pode desativar seu próprio usuário.'
            ], 400);
        }

        $sql = "
            UPDATE usuarios
            SET
                nome = :nome,
                email = :email,
                cargo = :cargo,
                ativo = :ativo
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':email' => $email,
            ':cargo' => $cargo,
            ':ativo' => $ativo,
            ':id' => $id
        ]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Usuário atualizado com sucesso.',
            'usuario' => [
                'id' => $id,
                'nome' => $nome,
                'email' => $email,
                'cargo' => $cargo,
                'ativo' => (bool) $ativo
            ]
        ]);
    }

    /**
     * Redefinir senha de um usuário pelo Admin.
     */
    public function redefinirSenha(int $id): void
    {
        $usuarioLogado = autenticar();
        exigirCargo($usuarioLogado, ['admin']);

        $dados = obterDadosRequisicao();
        $novaSenha = (string) ($dados['nova_senha'] ?? '');

        if ($novaSenha === '' || strlen($novaSenha) < 6) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'A nova senha deve ter no mínimo 6 caracteres.'
            ], 422);
        }

        $stmtCheck = $this->db->prepare("SELECT id FROM usuarios WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $id]);
        if (!$stmtCheck->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Usuário não encontrado.'
            ], 404);
        }

        $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $stmtUpdate = $this->db->prepare("UPDATE usuarios SET senha_hash = :hash WHERE id = :id");
        $stmtUpdate->execute([':hash' => $novoHash, ':id' => $id]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Senha do usuário redefinida com sucesso.'
        ]);
    }

    /**
     * Deletar ou desativar usuário (Apenas Admin).
     */
    public function deletar(int $id): void
    {
        $usuarioLogado = autenticar();
        exigirCargo($usuarioLogado, ['admin']);

        if ($usuarioLogado['id'] === $id) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Você não pode excluir sua própria conta.'
            ], 400);
        }

        $stmtCheck = $this->db->prepare("SELECT id FROM usuarios WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $id]);
        if (!$stmtCheck->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Usuário não encontrado.'
            ], 404);
        }

        // Tenta excluir fisicamente; se houver chave estrangeira em verificacoes, desativa
        try {
            $stmtDel = $this->db->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmtDel->execute([':id' => $id]);
            resposta([
                'sucesso' => true,
                'mensagem' => 'Usuário excluído com sucesso.'
            ]);
        } catch (PDOException $e) {
            // Em caso de FK, faz soft-delete
            $stmtDesativar = $this->db->prepare("UPDATE usuarios SET ativo = 0 WHERE id = :id");
            $stmtDesativar->execute([':id' => $id]);
            resposta([
                'sucesso' => true,
                'mensagem' => 'Usuário possui registros associados e foi desativado com sucesso.'
            ]);
        }
    }
}
