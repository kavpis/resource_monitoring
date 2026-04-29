<?php
/**
 * RateLimiter.php
 * Ограничение количества запросов (Rate Limiting)
 */

namespace Modules\Security;

use Core\Database;

class RateLimiter
{
    private $db;
    private $limits = [
        'default' => ['requests' => 100, 'period' => 60], // 100 запросов в минуту
        'api' => ['requests' => 60, 'period' => 60],      // 60 API запросов в минуту
        'login' => ['requests' => 5, 'period' => 300],    // 5 попыток входа за 5 минут
        'export' => ['requests' => 10, 'period' => 300],  // 10 экспортов за 5 минут
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTableExists();
    }

    /**
     * Создание таблицы для rate limiting
     */
    private function ensureTableExists()
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(64) NOT NULL,
                endpoint VARCHAR(128) NOT NULL,
                request_count INT DEFAULT 1,
                first_request TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_request TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_identifier_endpoint (identifier, endpoint),
                INDEX idx_last_request (last_request)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Проверка лимита запросов
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => int]
     */
    public function checkLimit($identifier, $endpoint = 'default')
    {
        if (!isset($this->limits[$endpoint])) {
            $endpoint = 'default';
        }

        $limit = $this->limits[$endpoint];
        $now = time();
        
        // Получаем текущую статистику
        $stmt = $this->db->prepare("SELECT request_count, first_request FROM rate_limits WHERE identifier = ? AND endpoint = ?");
        $stmt->execute([$identifier, $endpoint]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result) {
            // Новая запись
            $stmt = $this->db->prepare("INSERT INTO rate_limits (identifier, endpoint, request_count) VALUES (?, ?, 1)");
            $stmt->execute([$identifier, $endpoint]);
            
            SecurityLogger::log('Rate Limit', "Новый лимит для $identifier на эндпоинте $endpoint", 'debug');
            
            return [
                'allowed' => true,
                'remaining' => $limit['requests'] - 1,
                'reset' => $now + $limit['period']
            ];
        }

        $firstRequest = strtotime($result['first_request']);
        $elapsed = $now - $firstRequest;

        // Если период истек, сбрасываем счетчик
        if ($elapsed >= $limit['period']) {
            $stmt = $this->db->prepare("UPDATE rate_limits SET request_count = 1, first_request = NOW() WHERE identifier = ? AND endpoint = ?");
            $stmt->execute([$identifier, $endpoint]);
            
            return [
                'allowed' => true,
                'remaining' => $limit['requests'] - 1,
                'reset' => $now + $limit['period']
            ];
        }

        // Проверяем лимит
        if ($result['request_count'] >= $limit['requests']) {
            $resetTime = $firstRequest + $limit['period'];
            
            SecurityLogger::log('Rate Limit', "Превышен лимит запросов для $identifier на эндпоинте $endpoint", 'warning', [
                'limit' => $limit['requests'],
                'period' => $limit['period']
            ]);
            
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => $resetTime
            ];
        }

        // Увеличиваем счетчик
        $stmt = $this->db->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE identifier = ? AND endpoint = ?");
        $stmt->execute([$identifier, $endpoint]);

        return [
            'allowed' => true,
            'remaining' => $limit['requests'] - $result['request_count'] - 1,
            'reset' => $firstRequest + $limit['period']
        ];
    }

    /**
     * Сброс лимита для идентификатора
     */
    public function resetLimit($identifier, $endpoint = null)
    {
        if ($endpoint) {
            $stmt = $this->db->prepare("DELETE FROM rate_limits WHERE identifier = ? AND endpoint = ?");
            $stmt->execute([$identifier, $endpoint]);
        } else {
            $stmt = $this->db->prepare("DELETE FROM rate_limits WHERE identifier = ?");
            $stmt->execute([$identifier]);
        }
        
        SecurityLogger::log('Rate Limit', "Сброс лимита для $identifier", 'info');
    }

    /**
     * Очистка старых записей
     */
    public function cleanup($maxAgeHours = 24)
    {
        $stmt = $this->db->prepare("DELETE FROM rate_limits WHERE last_request < DATE_SUB(NOW(), INTERVAL ? HOUR)");
        $stmt->execute([$maxAgeHours]);
        
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            SecurityLogger::log('Rate Limit', "Очищено $deleted устаревших записей rate limiting", 'debug');
        }
        
        return $deleted;
    }

    /**
     * Получение статистики по лимитам
     */
    public function getStats($identifier = null)
    {
        if ($identifier) {
            $stmt = $this->db->prepare("SELECT * FROM rate_limits WHERE identifier = ?");
            $stmt->execute([$identifier]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        $stmt = $this->db->query("SELECT * FROM rate_limits ORDER BY last_request DESC LIMIT 100");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
