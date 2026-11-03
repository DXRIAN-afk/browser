<?php
require __DIR__ . '/lib/app.php';
start_session();
if (!empty($_SESSION['web_token'])) delete_token($_SESSION['web_token']);
$_SESSION = [];
session_destroy();
redirect('login.php');
