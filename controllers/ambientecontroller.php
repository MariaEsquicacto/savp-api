<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../auth/permissao.php';

class AmbienteController
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    /**
     * Listar todos os ambientes com total de salas vinculadas.
     */
    public function listar(): void
    {
        autenticar();

        $busca = trim($_GET['q'] ?? '');

        $sql = "
            SELECT
                a.id,
                a.nome,
                a.codigo,
                a.descricao,
                a.criado_em,
                a.atualizado_em,
                COUNT(s.id) AS total_salas
            FROM ambientes a
            LEFT JOIN salas s ON s.ambiente_id = a.id
        ";

        $params = [];
        if ($busca !== '') {
            $sql .= " WHERE a.nome LIKE :busca OR a.codigo LIKE :busca";
            $params[':busca'] = "%{$busca}%";
        }

        $sql .= " GROUP BY a.id ORDER BY a.nome ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $ambientes = $stmt->fetchAll();

        foreach ($ambientes as &$amb) {
            $amb['id'] = (int) $amb['id'];
            $amb['total_salas'] = (int) $amb['total_salas'];
        }

        resposta([
            'sucesso' => true,
            'total' => count($ambientes),
            'ambientes' => $ambientes
        ]);
    }

    /**
     * Buscar ambiente por ID com suas salas.
     */
    public function buscarPorId(int $id): void
    {
        autenticar();

        $stmt = $this->db->prepare("
            SELECT
                id,
                nome,
                codigo,
                descricao,
                criado_em,
                atualizado_em
            FROM ambientes
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $ambiente = $stmt->fetch();

        if (!$ambiente) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Ambiente não encontrado.'
            ], 404);
        }

        // Buscar salas deste ambiente
        $stmtSalas = $this->db->prepare("
            SELECT
                s.id,
                s.nome,
                s.codigo,
                s.descricao,
                COUNT(p.id) AS total_patrimonios
            FROM salas s
            LEFT JOIN patrimonios p ON p.sala_id = s.id AND p.ativo = 1
            WHERE s.ambiente_id = :ambiente_id
            GROUP BY s.id
            ORDER BY s.nome ASC
        ");
        $stmtSalas->execute([':ambiente_id' => $id]);
        $salas = $stmtSalas->fetchAll();

        foreach ($salas as &$sala) {
            $sala['id'] = (int) $sala['id'];
            $sala['total_patrimonios'] = (int) $sala['total_patrimonios'];
        }

        $ambiente['id'] = (int) $ambiente['id'];
        $ambiente['salas'] = $salas;

        resposta([
            'sucesso' => true,
            'ambiente' => $ambiente
        ]);
    }

    /**
     * Cadastrar ambiente (Admin e Gestor).
     */
    public function cadastrar(): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        $dados = obterDadosRequisicao();

        $nome = trim($dados['nome'] ?? '');
        $codigo = strtoupper(trim($dados['codigo'] ?? ''));
        $descricao = trim($dados['descricao'] ?? '');

        if ($nome === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O nome do ambiente é obrigatório.'
            ], 422);
        }

        if ($codigo === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O código do ambiente é obrigatório.'
            ], 422);
        }

        // Verifica unicidade do código
        $stmtVerificar = $this->db->prepare("SELECT id FROM ambientes WHERE codigo = :codigo LIMIT 1");
        $stmtVerificar->execute([':codigo' => $codigo]);

        if ($stmtVerificar->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Já existe um ambiente cadastrado com este código.'
            ], 409);
        }

        $sql = "
            INSERT INTO ambientes (
                nome,
                codigo,
                descricao
            ) VALUES (
                :nome,
                :codigo,
                :descricao
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':descricao' => $descricao !== '' ? $descricao : null
        ]);

        $id = (int) $this->db->lastInsertId();

        resposta([
            'sucesso' => true,
            'mensagem' => 'Ambiente cadastrado com sucesso.',
            'ambiente' => [
                'id' => $id,
                'nome' => $nome,
                'codigo' => $codigo,
                'descricao' => $descricao !== '' ? $descricao : null
            ]
        ], 201);
    }

    /**
     * Atualizar ambiente (Admin e Gestor).
     */
    public function atualizar(int $id): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        $stmtCheck = $this->db->prepare("SELECT id FROM ambientes WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $id]);
        if (!$stmtCheck->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Ambiente não encontrado.'
            ], 404);
        }

        $dados = obterDadosRequisicao();

        $nome = trim($dados['nome'] ?? '');
        $codigo = strtoupper(trim($dados['codigo'] ?? ''));
        $descricao = trim($dados['descricao'] ?? '');

        if ($nome === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O nome do ambiente é obrigatório.'
            ], 422);
        }

        if ($codigo === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O código do ambiente é obrigatório.'
            ], 422);
        }

        // Verifica unicidade de código para outro ambiente
        $stmtCodigo = $this->db->prepare("SELECT id FROM ambientes WHERE codigo = :codigo AND id != :id LIMIT 1");
        $stmtCodigo->execute([':codigo' => $codigo, ':id' => $id]);
        if ($stmtCodigo->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Já existe outro ambiente com este código.'
            ], 409);
        }

        $sql = "
            UPDATE ambientes
            SET
                nome = :nome,
                codigo = :codigo,
                descricao = :descricao
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':descricao' => $descricao !== '' ? $descricao : null,
            ':id' => $id
        ]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Ambiente atualizado com sucesso.',
            'ambiente' => [
                'id' => $id,
                'nome' => $nome,
                'codigo' => $codigo,
                'descricao' => $descricao !== '' ? $descricao : null
            ]
        ]);
    }

    /**
     * Excluir ambiente (Admin e Gestor).
     */
    public function deletar(int $id): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        $stmtCheck = $this->db->prepare("SELECT id FROM ambientes WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $id]);
        if (!$stmtCheck->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Ambiente não encontrado.'
            ], 404);
        }

        // Verifica se existem salas vinculadas
        $stmtSalas = $this->db->prepare("SELECT COUNT(*) AS total FROM salas WHERE ambiente_id = :id");
        $stmtSalas->execute([':id' => $id]);
        $totalSalas = (int) $stmtSalas->fetch()['total'];

        if ($totalSalas > 0) {
            resposta([
                'sucesso' => false,
                'mensagem' => "Não é possível excluir este ambiente pois existem {$totalSalas} sala(s) vinculada(s). Exclua ou mova as salas primeiro."
            ], 400);
        }

        $stmtDelete = $this->db->prepare("DELETE FROM ambientes WHERE id = :id");
        $stmtDelete->execute([':id' => $id]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Ambiente excluído com sucesso.'
        ]);
    }

    /**
     * Listar apenas as salas de um ambiente.
     */
    public function listarSalas(int $id): void
    {
        autenticar();

        $stmtCheck = $this->db->prepare("SELECT id, nome FROM ambientes WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $id]);
        $ambiente = $stmtCheck->fetch();

        if (!$ambiente) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Ambiente não encontrado.'
            ], 404);
        }

        $stmt = $this->db->prepare("
            SELECT
                s.id,
                s.ambiente_id,
                s.nome,
                s.codigo,
                s.descricao,
                COUNT(p.id) AS total_patrimonios
            FROM salas s
            LEFT JOIN patrimonios p ON p.sala_id = s.id AND p.ativo = 1
            WHERE s.ambiente_id = :ambiente_id
            GROUP BY s.id
            ORDER BY s.nome ASC
        ");
        $stmt->execute([':ambiente_id' => $id]);
        $salas = $stmt->fetchAll();

        foreach ($salas as &$sala) {
            $sala['id'] = (int) $sala['id'];
            $sala['ambiente_id'] = (int) $sala['ambiente_id'];
            $sala['total_patrimonios'] = (int) $sala['total_patrimonios'];
        }

        resposta([
            'sucesso' => true,
            'ambiente' => [
                'id' => (int) $ambiente['id'],
                'nome' => $ambiente['nome']
            ],
            'total' => count($salas),
            'salas' => $salas
        ]);
    }
}