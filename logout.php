<?php
;
require_once __DIR__ . '/core/Auth.php';

$auth = new Auth();
$auth->logout();

header('Location: /resource_monitoring/templates/login.php');
exit();
?>