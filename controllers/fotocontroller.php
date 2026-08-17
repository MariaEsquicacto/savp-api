<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../auth/permissao.php';

class FotoController
{
    private PDO $db;
    private string $uploadDir;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
        $this->uploadDir = __DIR__ . '/../uploads/patrimonios/';

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    /**
     * Upload de foto para um patrimônio (Admin e Gestor).
     */
    public function upload(int $patrimonioId): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        // Verifica existência do patrimônio
        $stmtPatr = $this->db->prepare("SELECT id, numero_patrimonio FROM patrimonios WHERE id = :id LIMIT 1");
        $stmtPatr->execute([':id' => $patrimonioId]);
        $patrimonio = $stmtPatr->fetch();

        if (!$patrimonio) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Patrimônio não encontrado.'
            ], 404);
        }

        $tipo = trim($_POST['tipo'] ?? 'foto1');
        if (!in_array($tipo, ['foto1', 'foto2'], true)) {
            resposta([
                'sucesso' => false,
                'mensagem' => "Tipo de foto inválido. Use 'foto1' ou 'foto2'."
            ], 422);
        }

        if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Nenhum arquivo enviado ou ocorreu um erro no upload.'
            ], 422);
        }

        $file = $_FILES['foto'];
        $maxBytes = 5 * 1024 * 1024; // 5 MB

        if ($file['size'] > $maxBytes) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'O arquivo ultrapassa o limite máximo permitido de 5MB.'
            ], 422);
        }

        // Validação de tipo MIME
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        $mimesPermitidos = [
            'image/jpeg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        if (!isset($mimesPermitidos[$mimeType])) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Formato de imagem inválido. Formatos aceitos: JPG, PNG, WEBP.'
            ], 422);
        }

        $extensao = $mimesPermitidos[$mimeType];
        $nomeArquivo = sprintf(
            'patrimonio_%d_%s_%s.%s',
            $patrimonioId,
            $tipo,
            bin2hex(random_bytes(8)),
            $extensao
        );

        $destino = $this->uploadDir . $nomeArquivo;
        $caminhoRelativo = 'uploads/patrimonios/' . $nomeArquivo;

        // Se já existir foto anterior deste tipo, exclui o arquivo antigo
        $stmtFotoAntiga = $this->db->prepare("
            SELECT caminho FROM fotos_patrimonios
            WHERE patrimonio_id = :id AND tipo = :tipo
            LIMIT 1
        ");
        $stmtFotoAntiga->execute([
            ':id' => $patrimonioId,
            ':tipo' => $tipo
        ]);
        $fotoAntiga = $stmtFotoAntiga->fetch();

        if ($fotoAntiga) {
            $arquivoAntigo = __DIR__ . '/../' . $fotoAntiga['caminho'];
            if (file_exists($arquivoAntigo)) {
                @unlink($arquivoAntigo);
            }
        }

        // Move o arquivo
        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Falha ao salvar a imagem no servidor.'
            ], 500);
        }

        // Insere ou atualiza no banco
        $sql = "
            INSERT INTO fotos_patrimonios (
                patrimonio_id,
                tipo,
                caminho,
                nome_arquivo,
                tamanho_bytes
            ) VALUES (
                :patrimonio_id,
                :tipo,
                :caminho,
                :nome_arquivo,
                :tamanho_bytes
            )
            ON DUPLICATE KEY UPDATE
                caminho = VALUES(caminho),
                nome_arquivo = VALUES(nome_arquivo),
                tamanho_bytes = VALUES(tamanho_bytes),
                atualizado_em = NOW()
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':patrimonio_id' => $patrimonioId,
            ':tipo' => $tipo,
            ':caminho' => $caminhoRelativo,
            ':nome_arquivo' => $file['name'],
            ':tamanho_bytes' => $file['size']
        ]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Foto salva com sucesso.',
            'foto' => [
                'patrimonio_id' => $patrimonioId,
                'tipo' => $tipo,
                'caminho' => $caminhoRelativo,
                'nome_arquivo' => $file['name'],
                'tamanho_bytes' => (int) $file['size']
            ]
        ], 201);
    }

    /**
     * Listar fotos de um patrimônio.
     */
    public function listar(int $patrimonioId): void
    {
        autenticar();

        $stmt = $this->db->prepare("
            SELECT
                id,
                patrimonio_id,
                tipo,
                caminho,
                nome_arquivo,
                tamanho_bytes,
                criado_em,
                atualizado_em
            FROM fotos_patrimonios
            WHERE patrimonio_id = :patrimonio_id
            ORDER BY tipo ASC
        ");
        $stmt->execute([':patrimonio_id' => $patrimonioId]);
        $fotos = $stmt->fetchAll();

        foreach ($fotos as &$f) {
            $f['id'] = (int) $f['id'];
            $f['patrimonio_id'] = (int) $f['patrimonio_id'];
            $f['tamanho_bytes'] = (int) $f['tamanho_bytes'];
        }

        resposta([
            'sucesso' => true,
            'patrimonio_id' => $patrimonioId,
            'fotos' => $fotos
        ]);
    }

    /**
     * Deletar foto de um patrimônio (Admin e Gestor).
     */
    public function deletar(int $patrimonioId, string $tipo): void
    {
        $usuario = autenticar();
        exigirCargo($usuario, ['admin', 'gestor']);

        if (!in_array($tipo, ['foto1', 'foto2'], true)) {
            resposta([
                'sucesso' => false,
                'mensagem' => "Tipo de foto inválido. Use 'foto1' ou 'foto2'."
            ], 422);
        }

        $stmt = $this->db->prepare("
            SELECT id, caminho FROM fotos_patrimonios
            WHERE patrimonio_id = :id AND tipo = :tipo
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $patrimonioId,
            ':tipo' => $tipo
        ]);
        $foto = $stmt->fetch();

        if (!$foto) {
            resposta([
                'sucesso' => false,
                'mensagem' => 'Foto não encontrada.'
            ], 404);
        }

        $caminhoFisico = __DIR__ . '/../' . $foto['caminho'];
        if (file_exists($caminhoFisico)) {
            @unlink($caminhoFisico);
        }

        $stmtDel = $this->db->prepare("DELETE FROM fotos_patrimonios WHERE id = :id");
        $stmtDel->execute([':id' => $foto['id']]);

        resposta([
            'sucesso' => true,
            'mensagem' => 'Foto excluída com sucesso.'
        ]);
    }
}
