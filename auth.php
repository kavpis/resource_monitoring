<?php
// C:\xampp\htdocs\resource_monitoring\auth.php

session_start(); // ВАЖНО: всегда первая строка

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';

$coreAuth = new Auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($login) || empty($password)) {
        $_SESSION['login_error'] = 'Логин и пароль обязательны';
        header('Location: /resource_monitoring/templates/login.php');
        exit();
    }
    
    // Попытка аутентификации
    $authResult = $coreAuth->login($login, $password);
    
    if ($authResult['success']) {
        // Успешный вход
        header('Location: /resource_monitoring/');
        exit();
    } else {
        // Ошибка аутентификации
        $_SESSION['login_error'] = $authResult['message'] ?? 'Неверный логин или пароль';
        header('Location: /resource_monitoring/templates/login.php');
        exit();
    }
} else {
    // Прямой доступ к файлу - перенаправление на логин
    header('Location: /resource_monitoring/templates/login.php');
    exit();
}
?>