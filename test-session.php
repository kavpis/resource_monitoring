<?php
session_start();
$_SESSION['test'] = 'OK';
echo "Session ID: " . session_id() . "<br>";
echo "Test value: " . ($_SESSION['test'] ?? 'not set');
?>