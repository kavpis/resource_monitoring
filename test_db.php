<?php
require_once 'config/database.php';

try {
    // Проверка подключения
    $stmt = $conn->query("SELECT DATABASE()");
    $db_name = $stmt->fetchColumn();
    echo "Подключение к базе данных '{$db_name}' успешно!<br><br>";
    
    // Проверка таблиц
    $stmt = $conn->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h3>Найдены таблицы:</h3>";
    echo "<ul>";
    foreach ($tables as $table) {
        // Подсчёт записей в каждой таблице
        $count = $conn->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "<li><strong>$table</strong> — $count записей</li>";
    }
    echo "</ul>";
    
    echo "<hr>";
    echo "<p>База данных готова к работе!</p>";
    
} catch(PDOException $e) {
    echo "Ошибка: " . $e->getMessage();
}
?>