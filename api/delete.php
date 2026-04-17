<?php
/**
 * delete.php — Endpoint de Exclusão de Versões Antigas
 *
 * Método: POST (JSON body)
 * Body:
 *   {
 *     "ids_documentos": [1001, 1002, ...],
 *     "confirmado": true
 *   }
 *
 * Estratégia de exclusão em lotes (BATCH) para evitar lock excessivo.
 * Preserva sempre: primeira versão, última versão e versões assinadas.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// Aceitar apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido. Use POST.']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validators.php';

// ── 1. Ler e Validar Body JSON ─────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Body JSON inválido.']);
    exit;
}

$ids_documentos = array_filter(
    array_map('intval', (array) ($body['ids_documentos'] ?? [])),
    fn($id) => $id > 0
);
$confirmado = (bool) ($body['confirmado'] ?? false);

if (empty($ids_documentos)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Nenhum ID de documento informado.']);
    exit;
}

if (!$confirmado) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Confirmação obrigatória não recebida.']);
    exit;
}

if (count($ids_documentos) > 500) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Máximo de 500 documentos por operação.']);
    exit;
}

// ── 2. Conectar ────────────────────────────────────────
$pdo = getDBConnection();

// ── 3. Executar Exclusão em Lotes ─────────────────────
/**
 * Algoritmo de exclusão segura:
 *
 * Para cada documento, identifica os IDs das versões a PRESERVAR:
 *   - MIN(id_conteudo) → primeira versão
 *   - MAX(id_conteudo) → última versão
 *   - WHERE ind_assinado = 'S' → versões assinadas
 *
 * Exclui todas as versões do documento que NÃO estejam na lista de preservados.
 *
 * Usa transação por lote para evitar lock prolongado de tabela.
 */

$total_excluidas   = 0;
$total_preservadas = 0;
$documentos_processados = 0;
$erros = [];

// Processar em lotes de BATCH_SIZE
$batches = array_chunk(array_values($ids_documentos), BATCH_SIZE);

foreach ($batches as $batch) {
    try {
        $pdo->beginTransaction();

        foreach ($batch as $id_doc) {
            // 3.1 — Buscar IDs a preservar para este documento
            $stmt_preserve = $pdo->prepare("
                SELECT id_conteudo FROM documento_conteudo
                WHERE id_documento = :id
                AND (
                    id_conteudo = (SELECT MIN(id_conteudo) FROM documento_conteudo WHERE id_documento = :id)
                    OR
                    id_conteudo = (SELECT MAX(id_conteudo) FROM documento_conteudo WHERE id_documento = :id)
                    OR
                    ind_assinado = 'S'
                )
            ");
            $stmt_preserve->execute([':id' => $id_doc]);
            $preserve_ids = $stmt_preserve->fetchAll(PDO::FETCH_COLUMN);

            if (empty($preserve_ids)) {
                // Nenhuma versão encontrada — pular
                continue;
            }

            // 3.2 — Contar versões a excluir
            $placeholders = implode(',', array_fill(0, count($preserve_ids), '?'));
            $stmt_count = $pdo->prepare("
                SELECT COUNT(*) FROM documento_conteudo
                WHERE id_documento = ?
                AND id_conteudo NOT IN ($placeholders)
            ");
            $stmt_count->execute(array_merge([$id_doc], $preserve_ids));
            $count_excluir = (int) $stmt_count->fetchColumn();

            if ($count_excluir === 0) continue;

            // 3.3 — Excluir versões antigas (NÃO preservadas)
            $stmt_delete = $pdo->prepare("
                DELETE FROM documento_conteudo
                WHERE id_documento = ?
                AND id_conteudo NOT IN ($placeholders)
                LIMIT " . BATCH_SIZE . "
            ");
            $stmt_delete->execute(array_merge([$id_doc], $preserve_ids));
            $deleted = $stmt_delete->rowCount();

            $total_excluidas   += $deleted;
            $total_preservadas += count($preserve_ids);
            $documentos_processados++;
        }

        $pdo->commit();

        // Pequena pausa entre lotes para reduzir pressão no banco
        usleep(100000); // 100ms

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erros[] = 'Erro no lote: ' . $e->getMessage();
        logError('delete.php batch error: ' . $e->getMessage());
    }
}

logInfo("Delete completed: {$total_excluidas} versions deleted, {$documentos_processados} docs processed.");

$response = [
    'success'                => empty($erros),
    'documentos_processados' => $documentos_processados,
    'versoes_excluidas'      => $total_excluidas,
    'versoes_preservadas'    => $total_preservadas,
    'mensagem'               => empty($erros)
        ? "Exclusão concluída com sucesso. {$total_excluidas} versões excluídas de {$documentos_processados} documentos."
        : "Exclusão parcialmente concluída com {$total_excluidas} versões excluídas.",
];

if (!empty($erros)) {
    $response['erros'] = $erros;
    http_response_code(207); // Multi-status
}

echo json_encode($response);
