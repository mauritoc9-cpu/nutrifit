<?php
header('Cache-Control: no-store');
// La presentación es pública y no crea ni modifica sesiones.
if (isset($_GET['reset_token']) && is_string($_GET['reset_token'])) {
    header('Location: frontend/?reset_token=' . rawurlencode($_GET['reset_token']));
    exit;
}
require __DIR__ . '/frontend/landing.html';
