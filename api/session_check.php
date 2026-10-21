<?php
require_once __DIR__ . '/../includes/auth.php';
$u = currentUser();
if (!$u) {
    jsonError('Sin sesión activa.', 401);
}
jsonOk(['user' => $u]);
