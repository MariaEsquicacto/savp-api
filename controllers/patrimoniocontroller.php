<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../auth/permissao.php';

class PatrimonioController
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    /**
     * Listar patrimônios com filtros flexíveis.
     */
    public function listar(): void
    {
        autenticar();

        $salaId = isset($_GET['sala_id']) ? (int) $_GET['sala_id'] : null;
        $ambienteId = isset($_GET['ambiente_id']) ? (int) $_GET['ambiente_id'] : null;
        $ativo = isset($_GET['ativo']) ? (int) $_GET['ativo'] : null;
        $busca = trim($_GET['q'] ?? '');

        $sql = "
            SELECT
                p.id,
                p.numero_patrimonio,
                p.denominacao,
                p.sala_id,
                s.nome AS sala_nome,
                s.codigo AS sala_codigo,
                s.ambiente_id,
                a.nome AS ambiente_nome,
                a.codigo AS ambiente_codigo,
                p.codigo_barras,
                p.qrcode,
                p.ativo,
                p.criado_em,
                p.atualizado_em,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto1' LIMIT 1) AS foto1,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto2' LIMIT 1) AS foto2
            FROM patrimonios p
            INNER JOIN salas s ON s.id = p.sala_id
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE 1=1
        ";
        $params = [];

        if ($salaId !== null) {
            $sql .= " AND p.sala_id = :sala_id";
            $params[':sala_id'] = $salaId;
        }

        if ($ambienteId !== null) {
            $sql .= " AND s.ambiente_id = :ambiente_id";
            $params[':ambiente_id'] = $ambienteId;
        }

        if ($ativo !== null) {
            $sql .= " AND p.ativo = :ativo";
            $params[':ativo'] = $ativo;
        }

        if ($busca !== '') {
            $sql .= " AND (
                p.numero_patrimonio LIKE :busca
                OR p.denominacao LIKE :busca
                OR p.codigo_barras LIKE :busca
                OR p.qrcode LIKE :busca
                OR s.nome LIKE :busca
                OR a.nome LIKE :busca
            )";
            $params[':busca'] = "%{$busca}%";
        }

        $sql .= " ORDER BY p.denominacao ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $patrimonios = $stmt->fetchAll();

        foreach ($patrimonios as &$p) {
            $p['id'] = (int) $p['id'];
            $p['sala_id'] = (int) $p['sala_id'];
            $p['ambiente_id'] = (int) $p['ambiente_id'];
            $p['ativo'] = (bool) $p['ativo'];
        }

        resposta([
            'sucesso' => true,
            'total' => count($patrimonios),
            'patrimonios' => $patrimonios
        ]);
    }

    /**
     * Buscar patrimônio por ID.
     */
    public function buscarPorId(int $id): void
    {
        autenticar();

        $stmt = $this->db->prepare("
            SELECT
                p.id,
                p.numero_patrimonio,
                p.denominacao,
                p.sala_id,
                s.nome AS sala_nome,
                s.codigo AS sala_codigo,
                s.ambiente_id,
                a.nome AS ambiente_nome,
                a.codigo AS ambiente_codigo,
                p.codigo_barras,
                p.qrcode,
                p.ativo,
                p.criado_em,
                p.atualizado_em,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto1' LIMIT 1) AS foto1,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto2' LIMIT 1) AS foto2
            FROM patrimonios p
            INNER JOIN salas s ON s.id = p.sala_id
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE p.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $patrimonio = $stmt->fetch();

        if (!$patrimonio) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Patrimônio não encontrado.'
            ], 404);
        }

        $patrimonio['id'] = (int) $patrimonio['id'];
        $patrimonio['sala_id'] = (int) $patrimonio['sala_id'];
        $patrimonio['ambiente_id'] = (int) $patrimonio['ambiente_id'];
        $patrimonio['ativo'] = (bool) $patrimonio['ativo'];

        resposta([
            'sucesso' => true,
            'patrimonio' => $patrimonio
        ]);
    }

    /**
     * Buscar patrimônio por código (número de patrimônio, código de barras ou qrcode).
     */
    public function buscarPorCodigo(string $codigo): void
    {
        autenticar();

        $codigoLimpo = trim($codigo);

        if ($codigoLimpo === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Código não informado.'
            ], 422);
        }

        $stmt = $this->db->prepare("
            SELECT
                p.id,
                p.numero_patrimonio,
                p.denominacao,
                p.sala_id,
                s.nome AS sala_nome,
                s.codigo AS sala_codigo,
                s.ambiente_id,
                a.nome AS ambiente_nome,
                a.codigo AS ambiente_codigo,
                p.codigo_barras,
                p.qrcode,
                p.ativo,
                p.criado_em,
                p.atualizado_em,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto1' LIMIT 1) AS foto1,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto2' LIMIT 1) AS foto2
            FROM patrimonios p
            INNER JOIN salas s ON s.id = p.sala_id
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE p.numero_patrimonio = :c1
               OR p.codigo_barras = :c2
               OR p.qrcode = :c3
            LIMIT 1
        ");

        $stmt->execute([
            ':c1' => $codigoLimpo,
            ':c2' => $codigoLimpo,
            ':c3' => $codigoLimpo
        ]);

        $patrimonio = $stmt->fetch();

        if (!$patrimonio) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Patrimônio não encontrado com o código fornecido.'
            ], 404);
        }

        $patrimonio['id'] = (int) $patrimonio['id'];
        $patrimonio['sala_id'] = (int) $patrimonio['sala_id'];
        $patrimonio['ambiente_id'] = (int) $patrimonio['ambiente_id'];
        $patrimonio['ativo'] = (bool) $patrimonio['ativo'];

        resposta([
            'sucesso' => true,
            'patrimonio' => $patrimonio
        ]);
    }

    /**
     * Cadastrar novo patrimônio (Admin e Gestor).
     */
    public function cadastrar(): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        $dados = obterDadosRequisicao();

        $numeroPatrimonio = trim($dados['numero_patrimonio'] ?? '');
        $denominacao = trim($dados['denominacao'] ?? '');
        $salaId = isset($dados['sala_id']) ? (int) $dados['sala_id'] : 0;
        $codigoBarras = trim($dados['codigo_barras'] ?? '');
        $qrcode = trim($dados['qrcode'] ?? '');

        if ($numeroPatrimonio === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O número do patrimônio é obrigatório.'
            ], 422);
        }

        if ($denominacao === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'A denominação do patrimônio é obrigatória.'
            ], 422);
        }

        if ($salaId <= 0) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O ID da sala é obrigatório.'
            ], 422);
        }

        // Verifica existência da sala
        $stmtSala = $this->db->prepare("SELECT id FROM salas WHERE id = :id LIMIT 1");
        $stmtSala->execute([':id' => $salaId]);
        if (!$stmtSala->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'A sala informada não existe.'
            ], 404);
        }

        // Verifica unicidade de numero_patrimonio
        $stmtCheck = $this->db->prepare("SELECT id FROM patrimonios WHERE numero_patrimonio = :numero LIMIT 1");
        $stmtCheck->execute([':numero' => $numeroPatrimonio]);
        if ($stmtCheck->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Já existe um patrimônio cadastrado com este número.'
            ], 409);
        }

        $sql = "
            INSERT INTO patrimonios (
                numero_patrimonio,
                denominacao,
                sala_id,
                codigo_barras,
                qrcode,
                ativo
            ) VALUES (
                :numero_patrimonio,
                :denominacao,
                :sala_id,
                :codigo_barras,
                :qrcode,
                1
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':numero_patrimonio' => $numeroPatrimonio,
            ':denominacao' => $denominacao,
            ':sala_id' => $salaId,
            ':codigo_barras' => $codigoBarras !== '' ? $codigoBarras : null,
            ':qrcode' => $qrcode !== '' ? $qrcode : null
        ]);

        $id = (int) $this->db->lastInsertId();

        resposta([
            'sucesso' => true,
            'mensagem' => 'Patrimônio cadastrado com sucesso.',
            'patrimonio' => [
                'id' => $id,
                'numero_patrimonio' => $numeroPatrimonio,
                'denominacao' => $denominacao,
                'sala_id' => $salaId,
                'codigo_barras' => $codigoBarras !== '' ? $codigoBarras : null,
                'qrcode' => $qrcode !== '' ? $qrcode : null,
                'ativo' => true
            ]
        ], 201);
    }

    /**
     * Atualizar patrimônio (Admin e Gestor).
     */
    public function atualizar(int $id): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        $stmtCheck = $this->db->prepare("SELECT id FROM patrimonios WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $id]);
        if (!$stmtCheck->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Patrimônio não encontrado.'
            ], 404);
        }

        $dados = obterDadosRequisicao();

        $numeroPatrimonio = trim($dados['numero_patrimonio'] ?? '');
        $denominacao = trim($dados['denominacao'] ?? '');
        $salaId = isset($dados['sala_id']) ? (int) $dados['sala_id'] : 0;
        $codigoBarras = trim($dados['codigo_barras'] ?? '');
        $qrcode = trim($dados['qrcode'] ?? '');
        $ativo = isset($dados['ativo']) ? (int) (bool) $dados['ativo'] : 1;

        if ($numeroPatrimonio === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O número do patrimônio é obrigatório.'
            ], 422);
        }

        if ($denominacao === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'A denominação do patrimônio é obrigatória.'
            ], 422);
        }

        if ($salaId <= 0) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O ID da sala é obrigatório.'
            ], 422);
        }

        // Verifica existência da sala
        $stmtSala = $this->db->prepare("SELECT id FROM salas WHERE id = :id LIMIT 1");
        $stmtSala->execute([':id' => $salaId]);
        if (!$stmtSala->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'A sala informada não existe.'
            ], 404);
        }

        // Verifica duplicidade de número para outro patrimônio
        $stmtNum = $this->db->prepare("SELECT id FROM patrimonios WHERE numero_patrimonio = :num AND id != :id LIMIT 1");
        $stmtNum->execute([':num' => $numeroPatrimonio, ':id' => $id]);
        if ($stmtNum->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Já existe outro patrimônio cadastrado com este número.'
            ], 409);
        }

        $sql = "
            UPDATE patrimonios
            SET
                numero_patrimonio = :numero_patrimonio,
                denominacao = :denominacao,
                sala_id = :sala_id,
                codigo_barras = :codigo_barras,
                qrcode = :qrcode,
                ativo = :ativo
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':numero_patrimonio' => $numeroPatrimonio,
            ':denominacao' => $denominacao,
            ':sala_id' => $salaId,
            ':codigo_barras' => $codigoBarras !== '' ? $codigoBarras : null,
            ':qrcode' => $qrcode !== '' ? $qrcode : null,
            ':ativo' => $ativo,
            ':id' => $id
        ]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Patrimônio atualizado com sucesso.',
            'patrimonio' => [
                'id' => $id,
                'numero_patrimonio' => $numeroPatrimonio,
                'denominacao' => $denominacao,
                'sala_id' => $salaId,
                'codigo_barras' => $codigoBarras !== '' ? $codigoBarras : null,
                'qrcode' => $qrcode !== '' ? $qrcode : null,
                'ativo' => (bool) $ativo
            ]
        ]);
    }

    /**
     * Excluir ou inativar patrimônio (Admin e Gestor).
     */
    public function deletar(int $id): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        $stmtCheck = $this->db->prepare("SELECT id FROM patrimonios WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $id]);
        if (!$stmtCheck->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Patrimônio não encontrado.'
            ], 404);
        }

        // Tenta excluir do banco. Se houver histórico de verificação, inativa.
        try {
            $stmtDel = $this->db->prepare("DELETE FROM patrimonios WHERE id = :id");
            $stmtDel->execute([':id' => $id]);

            resposta([
                'sucesso' => true,
                'mensagem' => 'Patrimônio excluído com sucesso.'
            ]);
        } catch (PDOException $e) {
            $stmtInativar = $this->db->prepare("UPDATE patrimonios SET ativo = 0 WHERE id = :id");
            $stmtInativar->execute([':id' => $id]);

            resposta([
                'sucesso' => true,
                'mensagem' => 'Patrimônio possui histórico de verificações e foi inativado com sucesso.'
            ]);
        }
    }
}
