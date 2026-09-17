<?php
session_start();
$_SESSION['usuario_id'] = (int) ($_GET['uid'] ?? 0);
echo 'uid=' . $_SESSION['usuario_id'];
