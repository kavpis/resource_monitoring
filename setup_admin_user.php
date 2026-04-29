<?php
/**
 * Скрипт создания администратора для тестирования
 * Запуск: php setup_admin_user.php
 */

// Установка констант
define('ROOT_PATH', __DIR__);
define('CONFIG_PATH', ROOT_PATH . '/config');
define('LOG_PATH', ROOT_PATH . '/logs');

// Подключение конфигурации базы данных
require_once CONFIG_PATH . '/database.php';

try {
    $pdo = getDB(); // Функция из database.php
    
    // Проверка существования таблицы users
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if (!$stmt->fetch()) {
        echo "❌ Таблица users не найдена. Создайте сначала базу данных.\n";
        exit(1);
    }
    
    // Проверка, не существует ли уже админ
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $checkStmt->execute();
    $adminExists = $checkStmt->fetchColumn();
    
    if ($adminExists > 0) {
        echo "⚠️  Администратор уже существует в системе.\n";
        echo "   Если нужно создать нового - сначала удалите старого.\n";
        exit(0);
    }
    
    // Хеширование пароля 'admin123' с помощью bcrypt
    $passwordHash = password_hash('admin123', PASSWORD_BCRYPT);
    
    // Вставка администратора
    $insertStmt = $pdo->prepare("
        INSERT INTO users (login, password_hash, role, full_name, email, phone, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $insertStmt->execute([
        'admin',
        $passwordHash,
        'admin',
        'Администратор системы',
        'admin@company.local',
        '+71234567890',
        1
    ]);
    
    $adminId = $pdo->lastInsertId();
    
    echo "✅ Администратор успешно создан!\n";
    echo "📋 Логин: admin\n";
    echo "🔑 Пароль: admin123\n";
    echo "👤 ID: {$adminId}\n";
    echo "🏆 Роль: admin\n";
    echo "\n🎉 Готово! Можете войти в систему.\n";
    
} catch (PDOException $e) {
    echo "❌ Ошибка подключения к базе данных: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>