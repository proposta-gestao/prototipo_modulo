<?php
/**
 * config.php — Configurações do Módulo SEI Gestão de Versões
 * Conecta ao banco de dados MySQL do SEI
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'sei');          // Nome do banco SEI
define('DB_USER', 'sei_user');     // Usuário com permissões restritas
define('DB_PASS', 'sua_senha');    // Senha do banco
define('DB_CHARSET', 'utf8mb4');

// Limites de performance
define('QUERY_LIMIT', 500);        // Máximo de registros por query
define('BATCH_SIZE', 50);          // Tamanho do lote para exclusão
define('MAX_MONTHS', 6);           // Janela máxima de data (meses)
define('QUERY_TIMEOUT', 30);       // Timeout em segundos

// Ambiente
define('ENV', 'development');      // 'development' | 'production'
define('LOG_FILE', __DIR__ . '/logs/module.log');

/**
 * Cria e retorna uma conexão PDO com o banco SEI.
 * Usa atributos de performance e segurança recomendados.
 */
function getDBConnection(): PDO {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => QUERY_TIMEOUT,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION wait_timeout=30, SESSION net_read_timeout=30",
    ];

    try {
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        logError('DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        die(json_encode(['error' => 'Falha na conexão com o banco de dados.']));
    }
}

/**
 * Registra erros em arquivo de log com timestamp.
 */
function logError(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[{$timestamp}] ERROR: {$message}" . PHP_EOL;
    @file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

function logInfo(string $message): void {
    if (ENV !== 'development') return;
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[{$timestamp}] INFO: {$message}" . PHP_EOL;
    @file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}
