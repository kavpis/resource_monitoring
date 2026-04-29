<?php
/**
 * Конфигурация подключения к базе данных
 * Раздел 7.1 — База данных
 */

require_once 'constants.php';

class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Ошибка подключения к базе данных");
        }
    }
    
    // Паттерн Singleton — только одно подключение
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // Защита от клонирования
    private function __clone() {}
    public function __wakeup() {}
}

// Глобальная функция для получения подключения
function getDB() {
    return Database::getInstance()->getConnection();
}
?>