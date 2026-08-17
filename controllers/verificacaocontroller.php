<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../auth/permissao.php';

class VerificacaoController
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    /**
     * Iniciar uma nova sessão de verificação/auditoria para uma sala.
     */
    public function iniciar(): void
    {
        $usuario = autenticar();
        $dados = obterDadosRequisicao();

        $salaId = isset($dados['sala_id']) ? (int) $dados['sala_id'] : 0;
        $observacao = trim($dados['observacao'] ?? '');

        if ($salaId <= 0) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O ID da sala é obrigatório.'
            ], 422);
        }

        // Verifica existência da sala
        $stmtSala = $this->db->prepare("
            SELECT s.id, s.nome, s.codigo, a.nome AS ambiente_nome, a.codigo AS ambiente_codigo
            FROM salas s
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmtSala->execute([':id' => $salaId]);
        $sala = $stmtSala->fetch();

        if (!$sala) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'A sala informada não existe.'
            ], 404);
        }

        // Verifica se já existe verificação em andamento para a sala
        $stmtAndamento = $this->db->prepare("
            SELECT id, usuario_id, inicio_em
            FROM verificacoes
            WHERE sala_id = :sala_id AND status = 'em_andamento'
            LIMIT 1
        ");
        $stmtAndamento->execute([':sala_id' => $salaId]);
        $emAndamento = $stmtAndamento->fetch();

        if ($emAndamento) {
            resposta([
                'sucesso' => false,
                'mensagem' => "Já existe uma verificação em andamento para esta sala (ID da verificação: {$emAndamento['id']}). Finalize-a ou cancele-a antes de iniciar outra.",
                'verificacao_id' => (int) $emAndamento['id']
            ], 409);
        }

        // Conta patrimônios esperados na sala
        $stmtContagem = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM patrimonios
            WHERE sala_id = :sala_id AND ativo = 1
        ");
        $stmtContagem->execute([':sala_id' => $salaId]);
        $totalEsperado = (int) $stmtContagem->fetch()['total'];

        $sql = "
            INSERT INTO verificacoes (
                usuario_id,
                sala_id,
                inicio_em,
                status,
                observacao
            ) VALUES (
                :usuario_id,
                :sala_id,
                NOW(),
                'em_andamento',
                :observacao
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':usuario_id' => $usuario['id'],
            ':sala_id' => $salaId,
            ':observacao' => $observacao !== '' ? $observacao : null
        ]);

        $id = (int) $this->db->lastInsertId();

        resposta([
            'sucesso' => true,
            'mensagem' => 'Verificação iniciada com sucesso.',
            'verificacao' => [
                'id' => $id,
                'sala_id' => $salaId,
                'sala_nome' => $sala['nome'],
                'sala_codigo' => $sala['codigo'],
                'ambiente_nome' => $sala['ambiente_nome'],
                'usuario' => [
                    'id' => $usuario['id'],
                    'nome' => $usuario['nome']
                ],
                'status' => 'em_andamento',
                'inicio_em' => date('Y-m-d H:i:s'),
                'total_esperado' => $totalEsperado
            ]
        ], 201);
    }

    /**
     * Escanear/Ler um patrimônio durante a verificação.
     */
    public function escanear(int $verificacaoId): void
    {
        $usuario = autenticar();
        $dados = obterDadosRequisicao();

        $codigo = trim($dados['codigo'] ?? $dados['numero'] ?? '');
        $observacao = trim($dados['observacao'] ?? '');

        if ($codigo === '') {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O código ou número do patrimônio é obrigatório.'
            ], 422);
        }

        // Busca verificação
        $stmtVerif = $this->db->prepare("
            SELECT v.id, v.sala_id, v.status, s.nome AS sala_nome, a.nome AS ambiente_nome
            FROM verificacoes v
            INNER JOIN salas s ON s.id = v.sala_id
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE v.id = :id
            LIMIT 1
        ");
        $stmtVerif->execute([':id' => $verificacaoId]);
        $verificacao = $stmtVerif->fetch();

        if (!$verificacao) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Verificação não encontrada.'
            ], 404);
        }

        if ($verificacao['status'] !== 'em_andamento') {
            resposta([
                'sucesso' => false,
                'mensagem' => "Esta verificação já está {$verificacao['status']} e não aceita novas leituras."
            ], 400);
        }

        $salaVerificadaId = (int) $verificacao['sala_id'];

        // Busca patrimônio no banco por número, código de barras ou QRCode
        $stmtPatr = $this->db->prepare("
            SELECT
                p.id,
                p.numero_patrimonio,
                p.denominacao,
                p.sala_id,
                s.nome AS sala_nome,
                a.nome AS ambiente_nome,
                p.ativo
            FROM patrimonios p
            INNER JOIN salas s ON s.id = p.sala_id
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE p.numero_patrimonio = :c1
               OR p.codigo_barras = :c2
               OR p.qrcode = :c3
            LIMIT 1
        ");
        $stmtPatr->execute([
            ':c1' => $codigo,
            ':c2' => $codigo,
            ':c3' => $codigo
        ]);
        $patrimonio = $stmtPatr->fetch();

        $statusLeitura = 'nao_cadastrado';
        $patrimonioId = null;
        $mensagemStatus = 'Patrimônio NÃO CADASTRADO no sistema.';

        if ($patrimonio) {
            $patrimonioId = (int) $patrimonio['id'];

            if ((int) $patrimonio['sala_id'] === $salaVerificadaId) {
                $statusLeitura = 'correto';
                $mensagemStatus = 'Patrimônio verificado com SUCESSO (pertence a esta sala).';
            } else {
                $statusLeitura = 'ambiente_incorreto';
                $mensagemStatus = "Patrimônio pertence a OUTRA SALA ({$patrimonio['sala_nome']} - {$patrimonio['ambiente_nome']}).";
            }
        }

        // Verifica se este patrimônio/código já foi registrado nesta sessão
        $stmtExiste = $this->db->prepare("
            SELECT id FROM verificacao_patrimonios
            WHERE verificacao_id = :v_id AND (
                numero_lido = :num
                " . ($patrimonioId ? "OR patrimonio_id = :p_id" : "") . "
            )
            LIMIT 1
        ");

        $paramsExiste = [
            ':v_id' => $verificacaoId,
            ':num' => $codigo
        ];
        if ($patrimonioId) {
            $paramsExiste[':p_id'] = $patrimonioId;
        }
        $stmtExiste->execute($paramsExiste);
        $itemExistente = $stmtExiste->fetch();

        if ($itemExistente) {
            // Atualiza registro existente
            $stmtUpdate = $this->db->prepare("
                UPDATE verificacao_patrimonios
                SET
                    patrimonio_id = :patrimonio_id,
                    numero_lido = :numero_lido,
                    status = :status,
                    escaneado_em = NOW(),
                    observacao = :observacao
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':patrimonio_id' => $patrimonioId,
                ':numero_lido' => $codigo,
                ':status' => $statusLeitura,
                ':observacao' => $observacao !== '' ? $observacao : null,
                ':id' => $itemExistente['id']
            ]);
        } else {
            // Insere novo registro
            $stmtInsert = $this->db->prepare("
                INSERT INTO verificacao_patrimonios (
                    verificacao_id,
                    patrimonio_id,
                    numero_lido,
                    status,
                    escaneado_em,
                    observacao
                ) VALUES (
                    :verificacao_id,
                    :patrimonio_id,
                    :numero_lido,
                    :status,
                    NOW(),
                    :observacao
                )
            ");
            $stmtInsert->execute([
                ':verificacao_id' => $verificacaoId,
                ':patrimonio_id' => $patrimonioId,
                ':numero_lido' => $codigo,
                ':status' => $statusLeitura,
                ':observacao' => $observacao !== '' ? $observacao : null
            ]);
        }

        // Progresso atual da sala
        $stmtProgresso = $this->db->prepare("
            SELECT
                COUNT(CASE WHEN status = 'correto' THEN 1 END) AS total_corretos,
                COUNT(CASE WHEN status = 'ambiente_incorreto' THEN 1 END) AS total_ambiente_incorreto,
                COUNT(CASE WHEN status = 'nao_cadastrado' THEN 1 END) AS total_nao_cadastrado,
                COUNT(*) AS total_lidos
            FROM verificacao_patrimonios
            WHERE verificacao_id = :v_id
        ");
        $stmtProgresso->execute([':v_id' => $verificacaoId]);
        $progresso = $stmtProgresso->fetch();

        $stmtEsperado = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM patrimonios
            WHERE sala_id = :sala_id AND ativo = 1
        ");
        $stmtEsperado->execute([':sala_id' => $salaVerificadaId]);
        $totalEsperado = (int) $stmtEsperado->fetch()['total'];

        resposta([
            'sucesso' => true,
            'status_leitura' => $statusLeitura,
            'mensagem' => $mensagemStatus,
            'item' => [
                'patrimonio_id' => $patrimonioId,
                'numero_lido' => $codigo,
                'denominacao' => $patrimonio['denominacao'] ?? 'Não cadastrado',
                'sala_esperada' => $patrimonio ? ($patrimonio['sala_nome'] . ' (' . $patrimonio['ambiente_nome'] . ')') : 'Nenhuma',
                'escaneado_em' => date('Y-m-d H:i:s')
            ],
            'progresso' => [
                'total_esperado' => $totalEsperado,
                'total_corretos' => (int) $progresso['total_corretos'],
                'total_ambiente_incorreto' => (int) $progresso['total_ambiente_incorreto'],
                'total_nao_cadastrado' => (int) $progresso['total_nao_cadastrado'],
                'total_lidos' => (int) $progresso['total_lidos']
            ]
        ]);
    }

    /**
     * Listar verificações com filtros.
     */
    public function listar(): void
    {
        $usuario = autenticar();

        $salaId = isset($_GET['sala_id']) ? (int) $_GET['sala_id'] : null;
        $usuarioId = isset($_GET['usuario_id']) ? (int) $_GET['usuario_id'] : null;
        $status = $_GET['status'] ?? null;
        $dataInicio = $_GET['data_inicio'] ?? null;
        $dataFim = $_GET['data_fim'] ?? null;

        $sql = "
            SELECT
                v.id,
                v.usuario_id,
                u.nome AS usuario_nome,
                v.sala_id,
                s.nome AS sala_nome,
                s.codigo AS sala_codigo,
                a.nome AS ambiente_nome,
                v.inicio_em,
                v.finalizado_em,
                v.status,
                v.observacao,
                (SELECT COUNT(*) FROM patrimonios WHERE sala_id = v.sala_id AND ativo = 1) AS total_esperado,
                (SELECT COUNT(*) FROM verificacao_patrimonios WHERE verificacao_id = v.id AND status = 'correto') AS total_corretos,
                (SELECT COUNT(*) FROM verificacao_patrimonios WHERE verificacao_id = v.id AND status = 'nao_escaneado') AS total_nao_escaneados,
                (SELECT COUNT(*) FROM verificacao_patrimonios WHERE verificacao_id = v.id AND status = 'ambiente_incorreto') AS total_ambiente_incorreto,
                (SELECT COUNT(*) FROM verificacao_patrimonios WHERE verificacao_id = v.id AND status = 'nao_cadastrado') AS total_nao_cadastrado
            FROM verificacoes v
            INNER JOIN usuarios u ON u.id = v.usuario_id
            INNER JOIN salas s ON s.id = v.sala_id
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE 1=1
        ";
        $params = [];

        // Colaboradores veem apenas as suas verificações, a menos que sejam admin ou gestor
        if ($usuario['cargo'] === 'colaborador') {
            $sql .= " AND v.usuario_id = :auth_user_id";
            $params[':auth_user_id'] = $usuario['id'];
        } elseif ($usuarioId !== null) {
            $sql .= " AND v.usuario_id = :usuario_id";
            $params[':usuario_id'] = $usuarioId;
        }

        if ($salaId !== null) {
            $sql .= " AND v.sala_id = :sala_id";
            $params[':sala_id'] = $salaId;
        }

        if ($status && in_array($status, ['em_andamento', 'finalizada', 'cancelada'], true)) {
            $sql .= " AND v.status = :status";
            $params[':status'] = $status;
        }

        if ($dataInicio) {
            $sql .= " AND v.inicio_em >= :data_inicio";
            $params[':data_inicio'] = $dataInicio . ' 00:00:00';
        }

        if ($dataFim) {
            $sql .= " AND v.inicio_em <= :data_fim";
            $params[':data_fim'] = $dataFim . ' 23:59:59';
        }

        $sql .= " ORDER BY v.inicio_em DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $verificacoes = $stmt->fetchAll();

        foreach ($verificacoes as &$v) {
            $v['id'] = (int) $v['id'];
            $v['usuario_id'] = (int) $v['usuario_id'];
            $v['sala_id'] = (int) $v['sala_id'];
            $v['total_esperado'] = (int) $v['total_esperado'];
            $v['total_corretos'] = (int) $v['total_corretos'];
            $v['total_nao_escaneados'] = (int) $v['total_nao_escaneados'];
            $v['total_ambiente_incorreto'] = (int) $v['total_ambiente_incorreto'];
            $v['total_nao_cadastrado'] = (int) $v['total_nao_cadastrado'];

            $taxa = $v['total_esperado'] > 0
                ? round(($v['total_corretos'] / $v['total_esperado']) * 100, 1)
                : 0.0;
            $v['taxa_conformidade_porcentagem'] = $taxa;
        }

        resposta([
            'sucesso' => true,
            'total' => count($verificacoes),
            'verificacoes' => $verificacoes
        ]);
    }

    /**
     * Buscar detalhes de uma verificação e todos os itens lidos / ausentes.
     */
    public function buscarPorId(int $id): void
    {
        autenticar();

        $stmt = $this->db->prepare("
            SELECT
                v.id,
                v.usuario_id,
                u.nome AS usuario_nome,
                u.email AS usuario_email,
                v.sala_id,
                s.nome AS sala_nome,
                s.codigo AS sala_codigo,
                a.nome AS ambiente_nome,
                a.codigo AS ambiente_codigo,
                v.inicio_em,
                v.finalizado_em,
                v.status,
                v.observacao
            FROM verificacoes v
            INNER JOIN usuarios u ON u.id = v.usuario_id
            INNER JOIN salas s ON s.id = v.sala_id
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE v.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $verificacao = $stmt->fetch();

        if (!$verificacao) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Verificação não encontrada.'
            ], 404);
        }

        // Buscar todos os itens da verificação
        $stmtItens = $this->db->prepare("
            SELECT
                vp.id,
                vp.patrimonio_id,
                vp.numero_lido,
                vp.status,
                vp.escaneado_em,
                vp.observacao,
                p.denominacao,
                p.sala_id AS sala_original_id,
                s_orig.nome AS sala_original_nome,
                a_orig.nome AS ambiente_original_nome,
                (SELECT caminho FROM fotos_patrimonios WHERE patrimonio_id = p.id AND tipo = 'foto1' LIMIT 1) AS foto1
            FROM verificacao_patrimonios vp
            LEFT JOIN patrimonios p ON p.id = vp.patrimonio_id
            LEFT JOIN salas s_orig ON s_orig.id = p.sala_id
            LEFT JOIN ambientes a_orig ON a_orig.id = s_orig.ambiente_id
            WHERE vp.verificacao_id = :verificacao_id
            ORDER BY vp.id ASC
        ");
        $stmtItens->execute([':verificacao_id' => $id]);
        $itens = $stmtItens->fetchAll();

        // Totalizadores
        $totalCorretos = 0;
        $totalNaoEscaneados = 0;
        $totalAmbienteIncorreto = 0;
        $totalNaoCadastrado = 0;

        foreach ($itens as &$item) {
            $item['id'] = (int) $item['id'];
            $item['patrimonio_id'] = $item['patrimonio_id'] ? (int) $item['patrimonio_id'] : null;

            switch ($item['status']) {
                case 'correto':
                    $totalCorretos++;
                    break;
                case 'nao_escaneado':
                    $totalNaoEscaneados++;
                    break;
                case 'ambiente_incorreto':
                    $totalAmbienteIncorreto++;
                    break;
                case 'nao_cadastrado':
                    $totalNaoCadastrado++;
                    break;
            }
        }

        // Total esperado da sala
        $stmtEsperado = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM patrimonios
            WHERE sala_id = :sala_id AND ativo = 1
        ");
        $stmtEsperado->execute([':sala_id' => $verificacao['sala_id']]);
        $totalEsperado = (int) $stmtEsperado->fetch()['total'];

        $verificacao['id'] = (int) $verificacao['id'];
        $verificacao['usuario_id'] = (int) $verificacao['usuario_id'];
        $verificacao['sala_id'] = (int) $verificacao['sala_id'];

        $taxaConformidade = $totalEsperado > 0
            ? round(($totalCorretos / $totalEsperado) * 100, 1)
            : 0.0;

        resposta([
            'sucesso' => true,
            'verificacao' => $verificacao,
            'resumo' => [
                'total_esperado' => $totalEsperado,
                'total_corretos' => $totalCorretos,
                'total_nao_escaneados' => $totalNaoEscaneados,
                'total_ambiente_incorreto' => $totalAmbienteIncorreto,
                'total_nao_cadastrado' => $totalNaoCadastrado,
                'taxa_conformidade_porcentagem' => $taxaConformidade
            ],
            'itens' => $itens
        ]);
    }

    /**
     * Finalizar a verificação.
     * Detecta e registra automaticamente todos os patrimônios da sala não escaneados.
     */
    public function finalizar(int $id): void
    {
        $usuario = autenticar();
        $dados = obterDadosRequisicao();

        $stmtVerif = $this->db->prepare("SELECT id, sala_id, status FROM verificacoes WHERE id = :id LIMIT 1");
        $stmtVerif->execute([':id' => $id]);
        $verificacao = $stmtVerif->fetch();

        if (!$verificacao) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Verificação não encontrada.'
            ], 404);
        }

        if ($verificacao['status'] !== 'em_andamento') {
            resposta([
                'sucesso' => false,
                'mensagem' => "Esta verificação não pode ser finalizada pois já está {$verificacao['status']}."
            ], 400);
        }

        $salaId = (int) $verificacao['sala_id'];
        $observacao = trim($dados['observacao'] ?? '');

        // 1. Busca todos os patrimônios cadastrados e ativos na sala que NÃO foram escaneados nesta verificação
        $stmtFaltantes = $this->db->prepare("
            SELECT p.id, p.numero_patrimonio
            FROM patrimonios p
            WHERE p.sala_id = :sala_id
              AND p.ativo = 1
              AND p.id NOT IN (
                  SELECT patrimonio_id
                  FROM verificacao_patrimonios
                  WHERE verificacao_id = :v_id AND patrimonio_id IS NOT NULL
              )
        ");
        $stmtFaltantes->execute([
            ':sala_id' => $salaId,
            ':v_id' => $id
        ]);
        $faltantes = $stmtFaltantes->fetchAll();

        // 2. Insere itens ausentes como 'nao_escaneado'
        $stmtInsertFaltante = $this->db->prepare("
            INSERT INTO verificacao_patrimonios (
                verificacao_id,
                patrimonio_id,
                numero_lido,
                status,
                escaneado_em,
                observacao
            ) VALUES (
                :v_id,
                :p_id,
                :num,
                'nao_escaneado',
                NULL,
                'Item não localizado durante a conferência na sala'
            )
        ");

        foreach ($faltantes as $itemFaltante) {
            $stmtInsertFaltante->execute([
                ':v_id' => $id,
                ':p_id' => $itemFaltante['id'],
                ':num' => $itemFaltante['numero_patrimonio']
            ]);
        }

        // 3. Atualiza status da verificação para 'finalizada'
        if ($observacao !== '') {
            $stmtFin = $this->db->prepare("
                UPDATE verificacoes
                SET
                    status = 'finalizada',
                    finalizado_em = NOW(),
                    observacao = :obs
                WHERE id = :id
            ");
            $stmtFin->execute([
                ':obs' => $observacao,
                ':id' => $id
            ]);
        } else {
            $stmtFin = $this->db->prepare("
                UPDATE verificacoes
                SET
                    status = 'finalizada',
                    finalizado_em = NOW()
                WHERE id = :id
            ");
            $stmtFin->execute([
                ':id' => $id
            ]);
        }

        // Retorna relatório e resumo da verificação
        $this->relatorio($id);
    }

    /**
     * Cancelar uma verificação em andamento.
     */
    public function cancelar(int $id): void
    {
        $usuario = autenticar();
        $dados = obterDadosRequisicao();
        $motivo = trim($dados['motivo'] ?? $dados['observacao'] ?? 'Cancelada pelo operador');

        $stmtVerif = $this->db->prepare("SELECT id, status FROM verificacoes WHERE id = :id LIMIT 1");
        $stmtVerif->execute([':id' => $id]);
        $verificacao = $stmtVerif->fetch();

        if (!$verificacao) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Verificação não encontrada.'
            ], 404);
        }

        if ($verificacao['status'] !== 'em_andamento') {
            resposta([
                'sucesso' => false,
                'mensagem' => "Apenas verificações em andamento podem ser canceladas."
            ], 400);
        }

        $stmtCancel = $this->db->prepare("
            UPDATE verificacoes
            SET
                status = 'cancelada',
                finalizado_em = NOW(),
                observacao = CONCAT(COALESCE(observacao, ''), ' [Cancelada: ', :motivo, ']')
            WHERE id = :id
        ");
        $stmtCancel->execute([
            ':motivo' => $motivo,
            ':id' => $id
        ]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Verificação cancelada com sucesso.'
        ]);
    }

    /**
     * Gerar relatório consolidado de auditoria da verificação.
     */
    public function relatorio(int $id): void
    {
        autenticar();

        $stmt = $this->db->prepare("
            SELECT
                v.id,
                v.usuario_id,
                u.nome AS auditor_nome,
                u.email AS auditor_email,
                v.sala_id,
                s.nome AS sala_nome,
                s.codigo AS sala_codigo,
                a.nome AS ambiente_nome,
                a.codigo AS ambiente_codigo,
                v.inicio_em,
                v.finalizado_em,
                v.status,
                v.observacao
            FROM verificacoes v
            INNER JOIN usuarios u ON u.id = v.usuario_id
            INNER JOIN salas s ON s.id = v.sala_id
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            WHERE v.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $verificacao = $stmt->fetch();

        if (!$verificacao) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Verificação não encontrada.'
            ], 404);
        }

        // Itens
        $stmtItens = $this->db->prepare("
            SELECT
                vp.id,
                vp.patrimonio_id,
                vp.numero_lido,
                vp.status,
                vp.escaneado_em,
                vp.observacao,
                p.denominacao,
                s_orig.nome AS sala_original_nome,
                a_orig.nome AS ambiente_original_nome
            FROM verificacao_patrimonios vp
            LEFT JOIN patrimonios p ON p.id = vp.patrimonio_id
            LEFT JOIN salas s_orig ON s_orig.id = p.sala_id
            LEFT JOIN ambientes a_orig ON a_orig.id = s_orig.ambiente_id
            WHERE vp.verificacao_id = :id
            ORDER BY vp.status ASC, vp.numero_lido ASC
        ");
        $stmtItens->execute([':id' => $id]);
        $itens = $stmtItens->fetchAll();

        $agrupados = [
            'corretos' => [],
            'nao_escaneados' => [],
            'ambiente_incorreto' => [],
            'nao_cadastrados' => []
        ];

        foreach ($itens as $item) {
            $formatado = [
                'id' => (int) $item['id'],
                'patrimonio_id' => $item['patrimonio_id'] ? (int) $item['patrimonio_id'] : null,
                'numero' => $item['numero_lido'],
                'denominacao' => $item['denominacao'] ?? 'Não cadastrado',
                'sala_original' => $item['sala_original_nome'] ? ($item['sala_original_nome'] . ' - ' . $item['ambiente_original_nome']) : null,
                'escaneado_em' => $item['escaneado_em'],
                'observacao' => $item['observacao']
            ];

            if ($item['status'] === 'correto') {
                $agrupados['corretos'][] = $formatado;
            } elseif ($item['status'] === 'nao_escaneado') {
                $agrupados['nao_escaneados'][] = $formatado;
            } elseif ($item['status'] === 'ambiente_incorreto') {
                $agrupados['ambiente_incorreto'][] = $formatado;
            } elseif ($item['status'] === 'nao_cadastrado') {
                $agrupados['nao_cadastrados'][] = $formatado;
            }
        }

        // Total esperado
        $stmtEsperado = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM patrimonios
            WHERE sala_id = :sala_id AND ativo = 1
        ");
        $stmtEsperado->execute([':sala_id' => $verificacao['sala_id']]);
        $totalEsperado = (int) $stmtEsperado->fetch()['total'];

        $totalCorretos = count($agrupados['corretos']);
        $totalNaoEscaneados = count($agrupados['nao_escaneados']);
        $totalAmbienteIncorreto = count($agrupados['ambiente_incorreto']);
        $totalNaoCadastrados = count($agrupados['nao_cadastrados']);

        $taxaConformidade = $totalEsperado > 0
            ? round(($totalCorretos / $totalEsperado) * 100, 1)
            : 0.0;

        resposta([
            'sucesso' => true,
            'relatorio' => [
                'auditoria_id' => (int) $verificacao['id'],
                'status' => $verificacao['status'],
                'inicio_em' => $verificacao['inicio_em'],
                'finalizado_em' => $verificacao['finalizado_em'],
                'observacao' => $verificacao['observacao'],
                'auditor' => [
                    'id' => (int) $verificacao['usuario_id'],
                    'nome' => $verificacao['auditor_nome'],
                    'email' => $verificacao['auditor_email']
                ],
                'sala' => [
                    'id' => (int) $verificacao['sala_id'],
                    'nome' => $verificacao['sala_nome'],
                    'codigo' => $verificacao['sala_codigo'],
                    'ambiente' => $verificacao['ambiente_nome']
                ],
                'metricas' => [
                    'patrimonios_esperados' => $totalEsperado,
                    'patrimonios_corretos' => $totalCorretos,
                    'patrimonios_ausentes_nao_escaneados' => $totalNaoEscaneados,
                    'patrimonios_de_outra_sala' => $totalAmbienteIncorreto,
                    'patrimonios_nao_cadastrados' => $totalNaoCadastrados,
                    'taxa_conformidade_porcentagem' => $taxaConformidade
                ],
                'detalhes' => $agrupados
            ]
        ]);
    }

    /**
     * Dashboard com métricas consolidadas do sistema (Admin e Gestor).
     */
    public function dashboard(): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        // Contagens gerais
        $totalPatrimonios = (int) $this->db->query("SELECT COUNT(*) FROM patrimonios WHERE ativo = 1")->fetchColumn();
        $totalAmbientes = (int) $this->db->query("SELECT COUNT(*) FROM ambientes")->fetchColumn();
        $totalSalas = (int) $this->db->query("SELECT COUNT(*) FROM salas")->fetchColumn();
        $totalUsuarios = (int) $this->db->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn();

        // Contagem de auditorias
        $totalFinalizadas = (int) $this->db->query("SELECT COUNT(*) FROM verificacoes WHERE status = 'finalizada'")->fetchColumn();
        $totalEmAndamento = (int) $this->db->query("SELECT COUNT(*) FROM verificacoes WHERE status = 'em_andamento'")->fetchColumn();
        $totalCanceladas = (int) $this->db->query("SELECT COUNT(*) FROM verificacoes WHERE status = 'cancelada'")->fetchColumn();

        // Últimas 5 verificações
        $stmtUltimas = $this->db->query("
            SELECT
                v.id,
                v.status,
                v.inicio_em,
                v.finalizado_em,
                s.nome AS sala_nome,
                a.nome AS ambiente_nome,
                u.nome AS auditor_nome
            FROM verificacoes v
            INNER JOIN salas s ON s.id = v.sala_id
            INNER JOIN ambientes a ON a.id = s.ambiente_id
            INNER JOIN usuarios u ON u.id = v.usuario_id
            ORDER BY v.inicio_em DESC
            LIMIT 5
        ");
        $ultimasVerificacoes = $stmtUltimas->fetchAll();

        resposta([
            'sucesso' => true,
            'dashboard' => [
                'totais' => [
                    'patrimonios' => $totalPatrimonios,
                    'ambientes' => $totalAmbientes,
                    'salas' => $totalSalas,
                    'usuarios' => $totalUsuarios
                ],
                'auditorias' => [
                    'finalizadas' => $totalFinalizadas,
                    'em_andamento' => $totalEmAndamento,
                    'canceladas' => $totalCanceladas,
                    'total' => $totalFinalizadas + $totalEmAndamento + $totalCanceladas
                ],
                'ultimas_auditorias' => $ultimasVerificacoes
            ]
        ]);
    }
}
