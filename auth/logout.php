<?php
require_once __DIR__ . '/../includes/auth.php';
auth_session_start();
session_destroy();
header('Location: /');
exit;
