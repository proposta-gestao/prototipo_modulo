<?php
/**
 * search.php — Endpoint de Pesquisa de Versões Elegíveis para Exclusão
 *
 * Método: GET
 * Parâmetros:
 *   tipo_documento  string  (opcional) Nome do tipo de documento
 *   ultimo_acesso   int     (obrigatório) Dias desde o último acesso
 *   data_inicial    string  (obrigatório) YYYY-MM-DD
 *   data_final      string  (obrigatório) YYYY-MM-DD
 *   page            int     (opcional, default=1)
 *   per_page        int     (opcional, default=10, max=100)
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validators.php';

// ── 1. Receber e Sanitizar Parâmetros ─────────────────────
$input = [
    'tipo_documento' => trim($_GET['tipo_documento'] ?? ''),
    'ultimo_acesso'  => (int) ($_GET['ultimo_acesso'] ?? 0),
    'data_inicial'   => trim($_GET['data_inicial'] ?? ''),
    'data_final'     => trim($_GET['data_final'] ?? ''),
    'page'           => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page'       => min(100, max(1, (int) ($_GET['per_page'] ?? 10))),
];

// ── 2. Validar ────────────────────────────────────────────
$errors = validateSearchParams($input);
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// ── 3. Conectar ao Banco ──────────────────────────────────
$pdo = getDBConnection();

// ── 4. Montar Query de Pesquisa ───────────────────────────
/**
 * Estratégia de consulta otimizada para o SEI:
 *
 * Tabelas relevantes:
 *   - protocolo_documento  → metadados do documento (tipo, data criação)
 *   - documento_conteudo   → versões do documento (data acesso, assinatura)
 *
 * Índices esperados:
 *   - protocolo_documento: idx_tipo_documento, idx_data_geracao
 *   - documento_conteudo:  idx_id_documento, idx_data_acesso, idx_assinado
 *
 * Lógica de preservação:
 *   - Preservar versão com menor numero_versao (primeira)
 *   - Preservar versão com maior  numero_versao (última)
 *   - Preservar versões onde ind_assinado = 'S'
 *   - Excluir todas as demais (antigas, não assinadas, intermediárias)
 */

try {
    $params = [];

    // ── Subquery: documentos dentro dos filtros ──────────
    $sql_docs = "
        SELECT
            pd.id_documento,
            pd.protocolo_formatado,
            td.nome   AS tipo_documento,
            pd.data_geracao,
            COUNT(dc.id_conteudo)      AS total_versoes,
            SUM(CASE WHEN dc.ind_assinado = 'S' THEN 1 ELSE 0 END) AS versoes_assinadas,
            MAX(dc.data_ultimo_acesso) AS ultimo_acesso_versao
        FROM protocolo_documento pd
        INNER JOIN tipo_documento td
            ON pd.id_tipo_documento = td.id_tipo_documento
        INNER JOIN documento_conteudo dc
            ON dc.id_documento = pd.id_documento
        WHERE
            pd.data_geracao BETWEEN :data_ini AND :data_fim
            AND dc.data_ultimo_acesso <= DATE_SUB(NOW(), INTERVAL :dias DAY)
    ";

    $params[':data_ini'] = $input['data_inicial'] . ' 00:00:00';
    $params[':data_fim']  = $input['data_final']   . ' 23:59:59';
    $params[':dias']      = $input['ultimo_acesso'];

    // Filtro opcional por tipo
    if ($input['tipo_documento'] !== '') {
        $sql_docs .= " AND td.nome LIKE :tipo ";
        $params[':tipo'] = '%' . $input['tipo_documento'] . '%';
    }

    $sql_docs .= "
        GROUP BY pd.id_documento, pd.protocolo_formatado, td.nome, pd.data_geracao
        HAVING total_versoes > (2 + versoes_assinadas)
        ORDER BY pd.data_geracao DESC
        LIMIT " . QUERY_LIMIT;

    // ── Contar total para paginação ──────────────────────
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ($sql_docs) AS sub");
    $stmt_count->execute($params);
    $total_docs = (int) $stmt_count->fetchColumn();

    // ── Paginação ────────────────────────────────────────
    $offset = ($input['page'] - 1) * $input['per_page'];
    $sql_paginated = $sql_docs . " LIMIT {$input['per_page']} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql_paginated);
    $stmt->execute($params);
    $documents = $stmt->fetchAll();

    // ── Calcular versões elegíveis por documento ─────────
    $result = [];
    $total_versoes_excluir = 0;
    $total_versoes_preservar = 0;

    foreach ($documents as $doc) {
        // Versões a preservar: primeira + última + assinadas (evitar duplicata se assinada é a primeira/última)
        $preservar = 2 + (int) $doc['versoes_assinadas'];
        $excluir   = max(0, (int) $doc['total_versoes'] - $preservar);

        $total_versoes_excluir   += $excluir;
        $total_versoes_preservar += $preservar;

        $result[] = [
            'id_documento'       => (int) $doc['id_documento'],
            'protocolo'          => $doc['protocolo_formatado'],
            'tipo_documento'     => $doc['tipo_documento'],
            'data_criacao'       => $doc['data_geracao'],
            'ultimo_acesso'      => $doc['ultimo_acesso_versao'],
            'total_versoes'      => (int) $doc['total_versoes'],
            'versoes_assinadas'  => (int) $doc['versoes_assinadas'],
            'versoes_preservar'  => $preservar,
            'versoes_excluir'    => $excluir,
        ];
    }

    logInfo("Search: {$total_docs} docs found. Filters: " . json_encode($input));

    echo json_encode([
        'success'              => true,
        'total_documentos'     => $total_docs,
        'total_versoes_excluir'   => $total_versoes_excluir,
        'total_versoes_preservar' => $total_versoes_preservar,
        'pagina_atual'         => $input['page'],
        'total_paginas'        => (int) ceil($total_docs / $input['per_page']),
        'por_pagina'           => $input['per_page'],
        'documentos'           => $result,
    ]);

} catch (PDOException $e) {
    logError('search.php query error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno ao consultar o banco de dados.']);
}
