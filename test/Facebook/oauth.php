<?php

set_include_path(__DIR__ . '/../../src/:' . get_include_path());

function loadClass($name)
{
    require_once strtr($name, '\\_', '//') . '.php';
}
spl_autoload_register('loadClass');

use Social\Facebook;

session_start();
require_once '../config.php'; // Excluded from GIT

if (!empty($_GET['logout'])) {
    unset($_SESSION['fb']);
}

$facebook = new Facebook\Connection($config['facebook']['appid'], $config['facebook']['secret'], isset($_SESSION['fb']) ? $_SESSION['fb']->access_token : null);

if (isset($_GET['code'])) {
    $_SESSION['fb'] = $facebook->handleAuthResponse();
}

if (!$facebook->isAuth()) {
    $url = $facebook->getAuthUrl(isset($_GET['scope']) ? explode(',', $_GET['scope']) : array(), Social\Connection::getRequestUrl(array('scope'=>null, 'state'=>null, 'code'=>null, 'logout'=>null)));
    echo "<a href='$url'>$url</a>";
    exit();
}

?>

<h1>Logged in</h1>

<div><a href="?logout=1">Logout</a></div>
