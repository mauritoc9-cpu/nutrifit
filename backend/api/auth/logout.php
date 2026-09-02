<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

$_SESSION = [];
session_destroy();

respond(true, null, 'Sesión cerrada.');
