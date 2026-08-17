<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../auth/permissao.php';

class SalaController
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    /**
     * Listar salas com filtros opcionais (ambiente_id, busca por nome/código).
     */
    public function listar(): void
    {
        autenticar();

        $ambienteId = isset($_GET['ambiente_id']) ? (int) $_GET['ambiente_id'] : null;
        $busca = trim($_GET['q'] ?? '');

        $sql = "
            SELECT
                s.id,
                s.ambiente_id,
                a.nome AS ambiente_nome,
                a.codigo AS ambiente_codigo,
                s.nome,
                s.codigo,
                s.descricao,
                s.criado_em,
                s.atualizado_em,
                COUNT(p.id) AS total_patrimonios
            FROM salas s
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            LEFT JOIN patrimonios p ON p.sala_id = s.id AND p.ativo = 1
            WHERE 1=1
        ";
        $params = [];

        if ($ambienteId !== null) {
            $sql .= " AND s.ambiente_id = :ambiente_id";
            $params[':ambiente_id'] = $ambienteId;
        }

        if ($busca !== '') {
            $sql .= " AND (s.nome LIKE :busca OR s.codigo LIKE :busca OR a.nome LIKE :busca)";
            $params[':busca'] = "%{$busca}%";
        }

        $sql .= " GROUP BY s.id ORDER BY a.nome ASC, s.nome ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $salas = $stmt->fetchAll();

        foreach ($salas as &$sala) {
            $sala['id'] = (int) $sala['id'];
            $sala['ambiente_id'] = (int) $sala['ambiente_id'];
            $sala['total_patrimonios'] = (int) $sala['total_patrimonios'];
        }

        resposta([
            'sucesso' => true,
            'total' => count($salas),
            'salas' => $salas
        ]);
    }

    /**
     * Buscar sala por ID com detalhes e seus patrimônios.
     */
    public function buscarPorId(int $id): void
    {
        autenticar();

        $stmt = $this->db->prepare("
            SELECT
                s.id,
                s.ambiente_id,
                a.nome AS ambiente_nome,
                a.codigo AS ambiente_codigo,
                s.nome,
                s.codigo,
                s.descricao,
                s.criado_em,
                s.atualizado_em
            FROM salas s
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $sala = $stmt->fetch();

        if (!$sala) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Sala não encontrada.'
            ], 404);
        }

        // Buscar patrimônios da sala
        $stmtPatrimonios = $this->db->prepare("
            SELECT
                p.id,
                p.numero_patrimonio,
                p.denominacao,
                p.codigo_barras,
                p.qrcode,
                p.ativo,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto1' LIMIT 1) AS foto1,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto2' LIMIT 1) AS foto2
            FROM patrimonios p
            WHERE p.sala_id = :sala_id
            ORDER BY p.denominacao ASC
        ");
        $stmtPatrimonios->execute([':sala_id' => $id]);
        $patrimonios = $stmtPatrimonios->fetchAll();

        foreach ($patrimonios as &$p) {
            $p['id'] = (int) $p['id'];
            $p['ativo'] = (bool) $p['ativo'];
        }

        $sala['id'] = (int) $sala['id'];
        $sala['ambiente_id'] = (int) $sala['ambiente_id'];
        $sala['patrimonios'] = $patrimonios;
        $sala['total_patrimonios'] = count($patrimonios);

        resposta([
            'sucesso' => true,
            'sala' => $sala
        ]);
    }

    /**
     * Cadastrar nova sala (Admin e Gestor).
     */
    public function cadastrar(): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        $dados = obterDadosRequisicao();

        $ambienteId = isset($dados['ambiente_id']) ? (int) $dados['ambiente_id'] : 0;
        $nome = trim($dados['nome'] ?? '');
        $codigo = strtoupper(trim($dados['codigo'] ?? ''));
        $descricao = trim($dados['descricao'] ?? '');

        if ($ambienteId <= 0) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O ID do ambiente é obrigatório.'
            ], 422);
        }

        if ($nome === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O nome da sala é obrigatório.'
            ], 422);
        }

        if ($codigo === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O código da sala é obrigatório.'
            ], 422);
        }

        // Verifica se o ambiente existe
        $stmtAmb = $this->db->prepare("SELECT id FROM ambientes WHERE id = :id LIMIT 1");
        $stmtAmb->execute([':id' => $ambienteId]);
        if (!$stmtAmb->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O ambiente informado não existe.'
            ], 404);
        }

        // Verifica duplicidade de código no mesmo ambiente
        $stmtVerificar = $this->db->prepare("
            SELECT id FROM salas
            WHERE ambiente_id = :ambiente_id AND codigo = :codigo
            LIMIT 1
        ");
        $stmtVerificar->execute([
            ':ambiente_id' => $ambienteId,
            ':codigo' => $codigo
        ]);

        if ($stmtVerificar->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Já existe uma sala com este código no ambiente selecionado.'
            ], 409);
        }

        $sql = "
            INSERT INTO salas (
                ambiente_id,
                nome,
                codigo,
                descricao
            ) VALUES (
                :ambiente_id,
                :nome,
                :codigo,
                :descricao
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':ambiente_id' => $ambienteId,
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':descricao' => $descricao !== '' ? $descricao : null
        ]);

        $id = (int) $this->db->lastInsertId();

        resposta([
            'sucesso' => true,
            'mensagem' => 'Sala cadastrada com sucesso.',
            'sala' => [
                'id' => $id,
                'ambiente_id' => $ambienteId,
                'nome' => $nome,
                'codigo' => $codigo,
                'descricao' => $descricao !== '' ? $descricao : null
            ]
        ], 201);
    }

    /**
     * Atualizar sala (Admin e Gestor).
     */
    public function atualizar(int $id): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        $stmtCheck = $this->db->prepare("SELECT id, ambiente_id FROM salas WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $id]);
        $salaAtual = $stmtCheck->fetch();

        if (!$salaAtual) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Sala não encontrada.'
            ], 404);
        }

        $dados = obterDadosRequisicao();

        $ambienteId = isset($dados['ambiente_id']) ? (int) $dados['ambiente_id'] : (int) $salaAtual['ambiente_id'];
        $nome = trim($dados['nome'] ?? '');
        $codigo = strtoupper(trim($dados['codigo'] ?? ''));
        $descricao = trim($dados['descricao'] ?? '');

        if ($nome === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O nome da sala é obrigatório.'
            ], 422);
        }

        if ($codigo === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O código da sala é obrigatório.'
            ], 422);
        }

        // Verifica existência do ambiente
        $stmtAmb = $this->db->prepare("SELECT id FROM ambientes WHERE id = :id LIMIT 1");
        $stmtAmb->execute([':id' => $ambienteId]);
        if (!$stmtAmb->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O ambiente informado não existe.'
            ], 404);
        }

        // Verifica duplicidade de código no mesmo ambiente para outra sala
        $stmtCodigo = $this->db->prepare("
            SELECT id FROM salas
            WHERE ambiente_id = :ambiente_id AND codigo = :codigo AND id != :id
            LIMIT 1
        ");
        $stmtCodigo->execute([
            ':ambiente_id' => $ambienteId,
            ':codigo' => $codigo,
            ':id' => $id
        ]);

        if ($stmtCodigo->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Já existe outra sala com este código no ambiente selecionado.'
            ], 409);
        }

        $sql = "
            UPDATE salas
            SET
                ambiente_id = :ambiente_id,
                nome = :nome,
                codigo = :codigo,
                descricao = :descricao
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':ambiente_id' => $ambienteId,
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':descricao' => $descricao !== '' ? $descricao : null,
            ':id' => $id
        ]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Sala atualizada com sucesso.',
            'sala' => [
                'id' => $id,
                'ambiente_id' => $ambienteId,
                'nome' => $nome,
                'codigo' => $codigo,
                'descricao' => $descricao !== '' ? $descricao : null
            ]
        ]);
    }

    /**
     * Excluir sala (Admin e Gestor).
     */
    public function deletar(int $id): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        $stmtCheck = $this->db->prepare("SELECT id FROM salas WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $id]);
        if (!$stmtCheck->fetch()) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Sala não encontrada.'
            ], 404);
        }

        // Verifica se existem patrimônios vinculados
        $stmtPatr = $this->db->prepare("SELECT COUNT(*) AS total FROM patrimonios WHERE sala_id = :id");
        $stmtPatr->execute([':id' => $id]);
        $totalPatr = (int) $stmtPatr->fetch()['total'];

        if ($totalPatr > 0) {
            resposta([
                'sucesso' => false,
                'mensagem' => "Não é possível excluir esta sala pois existem {$totalPatr} patrimônio(s) vinculado(s). Mova ou exclua os patrimônios primeiro."
            ], 400);
        }

        // Verifica se existem verificações realizadas
        $stmtVerif = $this->db->prepare("SELECT COUNT(*) AS total FROM verificacoes WHERE sala_id = :id");
        $stmtVerif->execute([':id' => $id]);
        $totalVerif = (int) $stmtVerif->fetch()['total'];

        if ($totalVerif > 0) {
            resposta([
                'sucesso' => false,
                'mensagem' => "Não é possível excluir esta sala pois existem {$totalVerif} histórico(s) de verificação associados."
            ], 400);
        }

        $stmtDelete = $this->db->prepare("DELETE FROM salas WHERE id = :id");
        $stmtDelete->execute([':id' => $id]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Sala excluída com sucesso.'
        ]);
    }

    /**
     * Listar os patrimônios de uma sala específica.
     */
    public function listarPatrimonios(int $id): void
    {
        autenticar();

        $stmtCheck = $this->db->prepare("
            SELECT s.id, s.nome, s.codigo, a.nome AS ambiente_nome
            FROM salas s
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmtCheck->execute([':id' => $id]);
        $sala = $stmtCheck->fetch();

        if (!$sala) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Sala não encontrada.'
            ], 404);
        }

        $stmt = $this->db->prepare("
            SELECT
                p.id,
                p.numero_patrimonio,
                p.denominacao,
                p.codigo_barras,
                p.qrcode,
                p.ativo,
                p.criado_em,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto1' LIMIT 1) AS foto1,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto2' LIMIT 1) AS foto2
            FROM patrimonios p
            WHERE p.sala_id = :sala_id
            ORDER BY p.denominacao ASC
        ");
        $stmt->execute([':sala_id' => $id]);
        $patrimonios = $stmt->fetchAll();

        foreach ($patrimonios as &$p) {
            $p['id'] = (int) $p['id'];
            $p['ativo'] = (bool) $p['ativo'];
        }

        resposta([
            'sucesso' => true,
            'sala' => [
                'id' => (int) $sala['id'],
                'nome' => $sala['nome'],
                'codigo' => $sala['codigo'],
                'ambiente' => $sala['ambiente_nome']
            ],
            'total' => count($patrimonios),
            'patrimonios' => $patrimonios
        ]);
    }
}
