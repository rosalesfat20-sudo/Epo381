<?php

define('DB_HOST', 'localhost');
define('DB_PORT', '8031');
define('DB_NAME', 'epo381');
define('DB_USER', 'administrador');
define('DB_PASS', 'paginaoficial_epo');

define('ENCRYPTION_KEY', getenv('EPO381_ENCRYPTION_KEY') ?: 'df083fec9300a6dc464a538e934c5bbcda2a6029e0dcf9a9994ef16ea43f71b5');

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'No se pudo conectar a la base de datos PostgreSQL. Verifica config/db.php y que el servicio de PostgreSQL esté encendido.',
                'detalle' => $e->getMessage(),
            ]);
            exit;
        }
    }
    return $pdo;
}
