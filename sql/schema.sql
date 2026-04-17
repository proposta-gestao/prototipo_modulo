-- ============================================================
-- schema.sql — Estrutura de tabelas relevantes do SEI
-- Módulo: Gestão de Versões de Documentos
-- Apenas as tabelas e índices usados pelo módulo
-- ============================================================

-- Tabela: tipo_documento
-- Armazena os tipos de documentos configurados no SEI
CREATE TABLE IF NOT EXISTS tipo_documento (
    id_tipo_documento INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome              VARCHAR(100) NOT NULL,
    ativo             CHAR(1)      NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_tipo_documento),
    INDEX idx_nome (nome)          -- usado no filtro por tipo
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- Tabela: protocolo_documento
-- Cabeçalho/metadados de cada documento no SEI
CREATE TABLE IF NOT EXISTS protocolo_documento (
    id_documento          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_tipo_documento     INT UNSIGNED    NOT NULL,
    protocolo_formatado   VARCHAR(50)     NOT NULL,
    data_geracao          DATETIME        NOT NULL,
    id_unidade_geradora   INT UNSIGNED,
    PRIMARY KEY (id_documento),

    -- Índices para filtros do módulo
    INDEX idx_data_geracao     (data_geracao),                        -- filtro por janela de data
    INDEX idx_tipo_data        (id_tipo_documento, data_geracao),     -- filtro combinado tipo+data

    CONSTRAINT fk_pd_tipo FOREIGN KEY (id_tipo_documento)
        REFERENCES tipo_documento(id_tipo_documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- Tabela: documento_conteudo
-- Versões do conteúdo de cada documento
CREATE TABLE IF NOT EXISTS documento_conteudo (
    id_conteudo        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_documento       BIGINT UNSIGNED NOT NULL,
    numero_versao      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    conteudo           LONGBLOB,              -- conteúdo binário da versão
    tamanho_bytes      BIGINT UNSIGNED,
    ind_assinado       CHAR(1) NOT NULL DEFAULT 'N',  -- 'S' = assinado, 'N' = não assinado
    data_criacao_versao DATETIME NOT NULL,
    data_ultimo_acesso  DATETIME,             -- data do último acesso/leitura
    PRIMARY KEY (id_conteudo),

    -- Índices críticos para performance do módulo
    INDEX idx_doc_versao       (id_documento, numero_versao),         -- navegação por versões
    INDEX idx_doc_assinado     (id_documento, ind_assinado),          -- filtro de assinadas
    INDEX idx_ultimo_acesso    (data_ultimo_acesso),                  -- filtro por prazo de acesso
    INDEX idx_doc_acesso       (id_documento, data_ultimo_acesso),    -- índice composto otimizado

    CONSTRAINT fk_dc_doc FOREIGN KEY (id_documento)
        REFERENCES protocolo_documento(id_documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CONSULTAS SQL DO MÓDULO
-- ============================================================

-- ── 1. Pesquisa de documentos com versões elegíveis ──────────
--
-- Retorna documentos com mais versões do que o mínimo necessário
-- (mínimo = 2 base + versões assinadas). Usa HAVING para filtrar
-- somente onde existe algo a excluir.
--
SELECT
    pd.id_documento,
    pd.protocolo_formatado,
    td.nome                                              AS tipo_documento,
    pd.data_geracao,
    COUNT(dc.id_conteudo)                                AS total_versoes,
    SUM(CASE WHEN dc.ind_assinado = 'S' THEN 1 ELSE 0 END) AS versoes_assinadas,
    MAX(dc.data_ultimo_acesso)                           AS ultimo_acesso_versao
FROM protocolo_documento pd
INNER JOIN tipo_documento td
    ON pd.id_tipo_documento = td.id_tipo_documento      -- usa idx_nome
INNER JOIN documento_conteudo dc
    ON dc.id_documento = pd.id_documento                -- usa idx_doc_versao
WHERE
    pd.data_geracao BETWEEN :data_ini AND :data_fim     -- usa idx_tipo_data ou idx_data_geracao
    AND dc.data_ultimo_acesso <= DATE_SUB(NOW(), INTERVAL :dias DAY)  -- usa idx_doc_acesso
    AND td.nome LIKE :tipo                              -- filtro opcional, usa idx_nome
GROUP BY
    pd.id_documento,
    pd.protocolo_formatado,
    td.nome,
    pd.data_geracao
HAVING
    total_versoes > (2 + versoes_assinadas)             -- só documentos com versões excedentes
ORDER BY
    pd.data_geracao DESC
LIMIT 500;                                              -- evita full-scan de resultados grandes


-- ── 2. Identificar IDs das versões a PRESERVAR ───────────────
--
-- Para um dado id_documento, retorna os IDs que jamais devem ser excluídos.
-- Usa subqueries de MIN/MAX (rápidas com índice) + filtro de assinatura.
--
SELECT id_conteudo
FROM documento_conteudo
WHERE id_documento = :id_documento
AND (
    id_conteudo = (
        SELECT MIN(id_conteudo)                         -- primeira versão
        FROM documento_conteudo
        WHERE id_documento = :id_documento
    )
    OR
    id_conteudo = (
        SELECT MAX(id_conteudo)                         -- última versão
        FROM documento_conteudo
        WHERE id_documento = :id_documento
    )
    OR ind_assinado = 'S'                               -- versões assinadas
);


-- ── 3. Excluir versões antigas (não preservadas) ─────────────
--
-- Exclui apenas versões intermediárias, não assinadas, que não são a
-- primeira nem a última. O LIMIT previne lock longo de tabela.
--
DELETE FROM documento_conteudo
WHERE id_documento = :id_documento
AND id_conteudo NOT IN (
    -- IDs retornados pela query 2 acima
    :ids_preservar_placeholder
)
LIMIT 50;  -- processa em lotes pequenos via loop na aplicação


-- ── 4. Verificação de integridade pós-exclusão ───────────────
--
-- Confirma que o documento ainda tem ao menos 2 versões.
-- Deve ser executada após cada lote de exclusão como auditoria.
--
SELECT
    id_documento,
    COUNT(*) AS versoes_restantes,
    MIN(numero_versao) AS primeira_versao,
    MAX(numero_versao) AS ultima_versao,
    SUM(CASE WHEN ind_assinado = 'S' THEN 1 ELSE 0 END) AS assinadas
FROM documento_conteudo
WHERE id_documento IN (:ids_documentos)
GROUP BY id_documento
HAVING versoes_restantes < 2;  -- alerta se sobrou menos de 2 versões


-- ── 5. Estatísticas gerais (dashboard) ───────────────────────
--
-- Estimativa do total de versões elegíveis e espaço ocupado.
-- Executar com LIMIT para evitar timeout em bases grandes.
--
SELECT
    td.nome                                             AS tipo_documento,
    COUNT(DISTINCT pd.id_documento)                     AS total_documentos,
    SUM(dc_count.total_versoes)                         AS total_versoes,
    SUM(dc_count.versoes_assinadas + 2)                 AS versoes_preservadas,
    SUM(dc_count.total_versoes - dc_count.versoes_assinadas - 2) AS versoes_excluiveis,
    SUM(dc_size.tamanho_total) / 1024 / 1024            AS tamanho_total_mb
FROM protocolo_documento pd
INNER JOIN tipo_documento td ON pd.id_tipo_documento = td.id_tipo_documento
INNER JOIN (
    SELECT
        id_documento,
        COUNT(*)                                        AS total_versoes,
        SUM(CASE WHEN ind_assinado = 'S' THEN 1 ELSE 0 END) AS versoes_assinadas
    FROM documento_conteudo
    GROUP BY id_documento
) dc_count ON dc_count.id_documento = pd.id_documento
INNER JOIN (
    SELECT id_documento, SUM(tamanho_bytes) AS tamanho_total
    FROM documento_conteudo
    GROUP BY id_documento
) dc_size ON dc_size.id_documento = pd.id_documento
WHERE pd.data_geracao BETWEEN :data_ini AND :data_fim
GROUP BY td.nome
ORDER BY versoes_excluiveis DESC
LIMIT 50;
