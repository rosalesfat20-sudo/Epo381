<?php
/**
 * Utilidades comunes de sesión / autenticación / respuesta JSON
 * usadas por todos los endpoints en /api.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/crypto.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('epo381_sesion');
    // Detecta HTTPS automáticamente (incluye el caso de un proxy Apache que
    // termina el TLS, típico en un subdominio como mguadarrama.stackteam.online)
    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $esHttps,   // solo se envía por HTTPS cuando el sitio corre en HTTPS
        'httponly' => true,       // no accesible desde JavaScript
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function jsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function jsonOk($data = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => true], is_array($data) ? $data : ['data' => $data]), JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $mensaje, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Devuelve el usuario en sesión o null */
function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Exige que exista sesión activa y opcionalmente valida el rol.
 * $roles puede ser string o array de roles permitidos.
 */
function requireLogin($roles = null): array {
    $u = currentUser();
    if (!$u) {
        jsonError('No has iniciado sesión.', 401);
    }
    if ($roles !== null) {
        $roles = is_array($roles) ? $roles : [$roles];
        if (!in_array($u['rol'], $roles, true)) {
            jsonError('No tienes permiso para acceder a este recurso.', 403);
        }
    }
    return $u;
}

function nombreCompleto(array $u): string {
    return trim($u['nombre'] . ' ' . $u['apellido_paterno'] . ' ' . ($u['apellido_materno'] ?? ''));
}

/** Verifica que un orientador tenga asignado un grupo específico */
function orientadorTieneGrupo(PDO $pdo, int $orientadorId, int $grupoId): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM orientador_grupos WHERE orientador_id = :o AND grupo_id = :g');
    $stmt->execute(['o' => $orientadorId, 'g' => $grupoId]);
    return (bool)$stmt->fetch();
}
