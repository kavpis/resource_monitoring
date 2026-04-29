<?php
/**
 * Firewall.php
 * Базовый фаервол для блокировки подозрительных IP и запросов
 */

namespace Modules\Security;

use Core\Database;

class Firewall
{
    private $db;
    private $whitelist = [];
    private $blacklist = [];
    
    // Паттерны подозрительных URL
    private $suspiciousPatterns = [
        '/\.\.\/|\.\.\\\\/i',  // Directory traversal
        '/etc\/passwd/i',       // Попытка доступа к системным файлам
        '/wp-admin/i',          // Сканирование WordPress
        '/phpmyadmin/i',        // Сканирование phpMyAdmin
        '/\.env/i',             // Попытка доступа к .env
        '/\.git/i',             // Попытка доступа к .git
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loadLists();
        $this->ensureTableExists();
    }

    /**
     * Создание таблицы для фаервола
     */
    private function ensureTableExists()
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS firewall_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                rule_type ENUM('whitelist', 'blacklist') NOT NULL,
                reason TEXT,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_ip_type (ip_address, rule_type),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Загрузка списков из БД
     */
    private function loadLists()
    {
        try {
            $stmt = $this->db->query("SELECT ip_address, rule_type FROM firewall_rules WHERE expires_at IS NULL OR expires_at > NOW()");
            $rules = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($rules as $rule) {
                if ($rule['rule_type'] === 'whitelist') {
                    $this->whitelist[] = $rule['ip_address'];
                } else {
                    $this->blacklist[] = $rule['ip_address'];
                }
            }
        } catch (\Exception $e) {
            SecurityLogger::log('Фаервол', "Ошибка загрузки списков: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Проверка запроса
     * @return array ['allowed' => bool, 'reason' => string]
     */
    public function checkRequest()
    {
        $ip = $this->getClientIp();
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Проверка whitelist
        if (in_array($ip, $this->whitelist)) {
            return ['allowed' => true, 'reason' => 'IP в белом списке'];
        }
        
        // Проверка blacklist
        if (in_array($ip, $this->blacklist)) {
            SecurityLogger::log('Фаервол', "Блокировка IP из черного списка: $ip", 'warning');
            return ['allowed' => false, 'reason' => 'IP заблокирован'];
        }
        
        // Проверка на directory traversal
        if ($this->containsSuspiciousPattern($uri)) {
            SecurityLogger::log('Фаервол', "Обнаружен подозрительный запрос: $uri", 'critical', ['ip' => $ip]);
            $this->addToBlacklist($ip, 'Подозрительный запрос', 3600); // Блокировка на 1 час
            return ['allowed' => false, 'reason' => 'Подозрительный запрос'];
        }
        
        // Проверка User-Agent на ботов
        if ($this->isMaliciousBot($userAgent)) {
            SecurityLogger::log('Фаервол', "Обнаружен вредоносный бот: $userAgent", 'warning', ['ip' => $ip]);
            return ['allowed' => false, 'reason' => 'Вредоносный бот'];
        }
        
        return ['allowed' => true, 'reason' => 'Запрос разрешен'];
    }

    /**
     * Получение реального IP клиента
     */
    private function getClientIp()
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }

    /**
     * Проверка на подозрительные паттерны
     */
    private function containsSuspiciousPattern($uri)
    {
        foreach ($this->suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $uri)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Проверка на вредоносных ботов
     */
    private function isMaliciousBot($userAgent)
    {
        $maliciousBots = [
            'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab',
            'gobuster', 'dirbuster', 'wfuzz', 'hydra'
        ];
        
        $userAgentLower = strtolower($userAgent);
        
        foreach ($maliciousBots as $bot) {
            if (strpos($userAgentLower, $bot) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Добавление IP в черный список
     */
    public function addToBlacklist($ip, $reason = '', $durationSeconds = null)
    {
        if (in_array($ip, $this->whitelist)) {
            SecurityLogger::log('Фаервол', "Нельзя добавить IP из whitelist в blacklist: $ip", 'warning');
            return false;
        }
        
        $expiresAt = $durationSeconds ? date('Y-m-d H:i:s', time() + $durationSeconds) : null;
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO firewall_rules (ip_address, rule_type, reason, expires_at) 
                VALUES (?, 'blacklist', ?, ?)
                ON DUPLICATE KEY UPDATE rule_type = 'blacklist', reason = ?, expires_at = ?
            ");
            $stmt->execute([$ip, $reason, $expiresAt, $reason, $expiresAt]);
            
            if (!in_array($ip, $this->blacklist)) {
                $this->blacklist[] = $ip;
            }
            
            SecurityLogger::log('Фаервол', "IP добавлен в blacklist: $ip | Причина: $reason", 'warning');
            return true;
        } catch (\Exception $e) {
            SecurityLogger::log('Фаервол', "Ошибка добавления в blacklist: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Добавление IP в белый список
     */
    public function addToWhitelist($ip, $reason = '')
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO firewall_rules (ip_address, rule_type, reason) 
                VALUES (?, 'whitelist', ?)
                ON DUPLICATE KEY UPDATE rule_type = 'whitelist', reason = ?
            ");
            $stmt->execute([$ip, $reason, $reason]);
            
            // Удаляем из blacklist если был там
            $this->removeFromBlacklist($ip);
            
            if (!in_array($ip, $this->whitelist)) {
                $this->whitelist[] = $ip;
            }
            
            SecurityLogger::log('Фаервол', "IP добавлен в whitelist: $ip | Причина: $reason", 'info');
            return true;
        } catch (\Exception $e) {
            SecurityLogger::log('Фаервол', "Ошибка добавления в whitelist: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Удаление IP из черного списка
     */
    public function removeFromBlacklist($ip)
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM firewall_rules WHERE ip_address = ? AND rule_type = 'blacklist'");
            $stmt->execute([$ip]);
            
            $key = array_search($ip, $this->blacklist);
            if ($key !== false) {
                unset($this->blacklist[$key]);
            }
            
            SecurityLogger::log('Фаервол', "IP удален из blacklist: $ip", 'info');
            return true;
        } catch (\Exception $e) {
            SecurityLogger::log('Фаервол', "Ошибка удаления из blacklist: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Очистка истекших правил
     */
    public function cleanupExpired()
    {
        try {
            $stmt = $this->db->exec("DELETE FROM firewall_rules WHERE expires_at IS NOT NULL AND expires_at < NOW()");
            $deleted = $stmt;
            
            if ($deleted > 0) {
                SecurityLogger::log('Фаервол', "Очищено $deleted истекших правил фаервола", 'debug');
                $this->loadLists(); // Перезагрузка списков
            }
            
            return $deleted;
        } catch (\Exception $e) {
            SecurityLogger::log('Фаервол', "Ошибка очистки истекших правил: " . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * Получение статистики фаервола
     */
    public function getStats()
    {
        $whitelistCount = count($this->whitelist);
        $blacklistCount = count($this->blacklist);
        
        return [
            'whitelist_count' => $whitelistCount,
            'blacklist_count' => $blacklistCount,
            'whitelist' => $this->whitelist,
            'blacklist' => $this->blacklist
        ];
    }
}
