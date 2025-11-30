<?php
session_id('cli-test');
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'Admin';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['q'] = 'telefoni cellulari 8517';
require __DIR__ . '/../modules/servizi/brt/hs-code-search.php';
