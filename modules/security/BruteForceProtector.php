<?php
/**
 * BruteForceProtector.php
 * Защита от подбора паролей и временная блокировка IP
 */

namespace Modules\Security;

use Core\Database;
use Core\Auth;

class BruteForceProtector
{
    private $db;
    private $maxAttempts = 5;
    private $lockoutTime = 900; // 15 минут в секундах
    private $decayTime = 3600; // Сброс счетчика через 1 час

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Проверка, заблокирован ли IP
     */
    public function isBlocked($ip)
    {
        $stmt = $this->db->prepare("SELECT attempts, last_attempt FROM failed_logins WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        $now = time();
        $timeDiff = $now - strtotime($result['last_attempt']);

        // Если прошло больше времени сброса, очищаем запись
        if ($timeDiff > $this->decayTime) {
            $this->clearAttempts($ip);
            return false;
        }

        // Если попыток больше лимита и время блокировки не вышло
        if ($result['attempts'] >= $this->maxAttempts && $timeDiff < $this->lockoutTime) {
            SecurityLogger::log("Критическая безопасность", "Попытка доступа с заблокированного IP: $ip");
            return true;
        }

        return false;
    }

    /**
     * Регистрация неудачной попытки входа
     */
    public function recordFailure($ip, $username = null)
    {
        $now = date('Y-m-d H:i:s');
        
        // Проверяем существующую запись
        $stmt = $this->db->prepare("SELECT attempts FROM failed_logins WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result) {
            $stmt = $this->db->prepare("UPDATE failed_logins SET attempts = attempts + 1, last_attempt = ?, username = ? WHERE ip_address = ?");
            $stmt->execute([$now, $username, $ip]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO failed_logins (ip_address, username, attempts, last_attempt) VALUES (?, ?, 1, ?)");
            $stmt->execute([$ip, $username, $now]);
        }

        $msg = $username ? "Неудачный вход для пользователя '$username'" : "Неудачная попытка входа";
        SecurityLogger::log("Предупреждение безопасности", "$msg с IP: $ip");
    }

    /**
     * Очистка истории при успешном входе
     */
    public function recordSuccess($ip)
    {
        $this->clearAttempts($ip);
        SecurityLogger::log("Информация", "Успешный вход с IP: $ip");
    }

    /**
     * Очистка попыток для конкретного IP
     */
    private function clearAttempts($ip)
    {
        $stmt = $this->db->prepare("DELETE FROM failed_logins WHERE ip_address = ?");
        $stmt->execute([$ip]);
    }

    /**
     * Получение оставшегося времени блокировки
     */
    public function getRemainingLockoutTime($ip)
    {
        $stmt = $this->db->prepare("SELECT last_attempt FROM failed_logins WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result || $result['attempts'] < $this->maxAttempts) {
            return 0;
        }

        $elapsed = time() - strtotime($result['last_attempt']);
        $remaining = $this->lockoutTime - $elapsed;

        return max(0, $remaining);
    }
}
