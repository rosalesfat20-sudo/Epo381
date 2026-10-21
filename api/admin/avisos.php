<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('admin');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

define('AVISOS_DIR', __DIR__ . '/../../uploads/avisos/');
define('AVISOS_URL', 'uploads/avisos/');

function guardarImagenAviso(): ?string {
    if (empty($_FILES['imagen']) || $_FILES['imagen']['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES['imagen']['error'] !== UPLOAD_ERR_OK) jsonError('Error al subir la imagen.');

    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $tipo = mime_content_type($_FILES['imagen']['tmp_name']);
    if (!isset($permitidos[$tipo])) jsonError('Formato de imagen no permitido (usa JPG, PNG, WEBP o GIF).');
    if ($_FILES['imagen']['size'] > 5 * 1024 * 1024) jsonError('La imagen no debe pesar más de 5MB.');

    if (!is_dir(AVISOS_DIR)) mkdir(AVISOS_DIR, 0755, true);
    $nombre = 'aviso_' . bin2hex(random_bytes(8)) . '.' . $permitidos[$tipo];
    if (!move_uploaded_file($_FILES['imagen']['tmp_name'], AVISOS_DIR . $nombre)) {
        jsonError('No se pudo guardar la imagen en el servidor.');
    }
    return AVISOS_URL . $nombre;
}

if ($method === 'GET') {
    jsonOk(['avisos' => $pdo->query('SELECT * FROM avisos ORDER BY orden, created_at DESC')->fetchAll()]);
}

if ($method === 'POST') {
    // multipart/form-data (por la imagen), no JSON
    $accion = $_POST['_accion'] ?? 'crear';

    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonError('Falta id.');
        $img = $pdo->prepare('SELECT imagen FROM avisos WHERE id = :id');
        $img->execute(['id' => $id]);
        $ruta = $img->fetchColumn();
        $pdo->prepare('DELETE FROM avisos WHERE id = :id')->execute(['id' => $id]);
        if ($ruta && file_exists(__DIR__ . '/../../' . $ruta)) @unlink(__DIR__ . '/../../' . $ruta);
        jsonOk(['mensaje' => 'Aviso eliminado.']);
    }

    if ($accion === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $contenido = trim($_POST['contenido'] ?? '');
        $activo = ($_POST['activo'] ?? '1') === '1';
        $orden = (int)($_POST['orden'] ?? 0);
        if (!$id || $titulo === '' || $contenido === '') jsonError('Título y contenido son obligatorios.');

        $nuevaImg = guardarImagenAviso();
        if ($nuevaImg) {
            $old = $pdo->prepare('SELECT imagen FROM avisos WHERE id = :id');
            $old->execute(['id' => $id]);
            $rutaVieja = $old->fetchColumn();
            $pdo->prepare('UPDATE avisos SET titulo=:t, contenido=:c, activo=:a, orden=:o, imagen=:img, updated_at=now() WHERE id=:id')
                ->execute(['t' => $titulo, 'c' => $contenido, 'a' => $activo, 'o' => $orden, 'img' => $nuevaImg, 'id' => $id]);
            if ($rutaVieja && file_exists(__DIR__ . '/../../' . $rutaVieja)) @unlink(__DIR__ . '/../../' . $rutaVieja);
        } else {
            $pdo->prepare('UPDATE avisos SET titulo=:t, contenido=:c, activo=:a, orden=:o, updated_at=now() WHERE id=:id')
                ->execute(['t' => $titulo, 'c' => $contenido, 'a' => $activo, 'o' => $orden, 'id' => $id]);
        }
        jsonOk(['mensaje' => 'Aviso actualizado correctamente.']);
    }

    // crear
    $titulo = trim($_POST['titulo'] ?? '');
    $contenido = trim($_POST['contenido'] ?? '');
    $orden = (int)($_POST['orden'] ?? 0);
    if ($titulo === '' || $contenido === '') jsonError('Título y contenido son obligatorios.');

    $imagen = guardarImagenAviso();
    $pdo->prepare('INSERT INTO avisos (titulo, contenido, imagen, orden, autor_id) VALUES (:t,:c,:img,:o,:a)')
        ->execute(['t' => $titulo, 'c' => $contenido, 'img' => $imagen, 'o' => $orden, 'a' => $u['id']]);
    jsonOk(['mensaje' => 'Aviso publicado correctamente.'], 201);
}

jsonError('Método no permitido.', 405);
