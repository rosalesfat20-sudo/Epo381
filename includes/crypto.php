<?php
/**
 * Cifrado simétrico (AES-256-GCM) para datos personales sensibles
 * (CURP, teléfono, correo, domicilio) que se guardan en PostgreSQL.
 *
 * Los datos se cifran ANTES de guardarse en la base de datos y se
 * descifran automáticamente al leerse, de modo que el resto del
 * sistema (PHP, JS, PDFs, Excel) siempre trabaja con el valor plano.
 *
 * La llave se define en ENCRYPTION_KEY (config/db.php). En producción
 * debes cambiarla por una propia y NUNCA subirla a un repositorio público;
 * lo ideal es definirla como variable de entorno del servidor:
 *   putenv/getenv('EPO381_ENCRYPTION_KEY') si está disponible, si no,
 *   usa el valor de respaldo definido en config/db.php.
 */

if (!defined('ENCRYPTION_KEY')) {
    // Respaldo de desarrollo. Sobrescribe en config/db.php o variable de entorno.
    define('ENCRYPTION_KEY', 'CAMBIA-ESTA-LLAVE-POR-UNA-PROPIA-DE-32-BYTES!!');
}

const CRYPTO_CIPHER = 'aes-256-gcm';

/** Deriva una llave binaria de 32 bytes a partir de ENCRYPTION_KEY (string). */
function cryptoKey(): string {
    return hash('sha256', ENCRYPTION_KEY, true);
}

/**
 * Cifra un valor de texto plano. Devuelve NULL si el valor es NULL/"" (para
 * no forzar campos opcionales vacíos), o una cadena base64 con el formato
 * iv(12) + tag(16) + ciphertext, lista para guardarse en una columna TEXT.
 */
function encryptField(?string $plain): ?string {
    if ($plain === null || $plain === '') return null;
    $iv  = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt($plain, CRYPTO_CIPHER, cryptoKey(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipherText === false) return null;
    return base64_encode($iv . $tag . $cipherText);
}

/**
 * Descifra un valor guardado con encryptField(). Si el valor no tiene el
 * formato esperado (por ejemplo, datos antiguos sin cifrar de una instalación
 * previa), se devuelve tal cual para no romper la compatibilidad.
 */
function decryptField(?string $encoded): ?string {
    if ($encoded === null || $encoded === '') return $encoded;
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) return $encoded; // no parece estar cifrado
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $plain = openssl_decrypt($ct, CRYPTO_CIPHER, cryptoKey(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? $encoded : $plain;
}

/** Aplica encryptField a un subconjunto de llaves de un arreglo asociativo. */
function encryptFields(array $data, array $campos): array {
    foreach ($campos as $c) {
        if (array_key_exists($c, $data)) {
            $data[$c] = encryptField($data[$c] !== null ? (string)$data[$c] : null);
        }
    }
    return $data;
}

/** Aplica decryptField a un subconjunto de llaves de un registro (fila de BD). */
function decryptFields(array $row, array $campos): array {
    foreach ($campos as $c) {
        if (array_key_exists($c, $row)) {
            $row[$c] = decryptField($row[$c]);
        }
    }
    return $row;
}

/** Campos de datos personales del alumno que se guardan cifrados en la tabla alumnos. */
function camposPersonalesAlumno(): array {
    return ['curp', 'telefono', 'email_personal', 'tutor_telefono', 'calle', 'numero', 'colonia', 'cp'];
}
