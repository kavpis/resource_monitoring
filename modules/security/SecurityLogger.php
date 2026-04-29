<?php
/**
 * SecurityLogger.php
 * Логирование событий безопасности с уровнями важности
 */

namespace Modules\Security;

class SecurityLogger
{
    private static $logFile = '/workspace/logs/security.log';
    private static $levels = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4
    ];
    private static $minLevel = 'info'; // Минимальный уровень для записи

    /**
     * Запись события в лог
     */
    public static function log($category, $message, $level = 'info', $context = [])
    {
        if (self::shouldLog($level)) {
            $timestamp = date('Y-m-d H:i:s');
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user = $_SESSION['user_id'] ?? 'guest';
            
            $logEntry = sprintf(
                "[%s] [%s] [%s] [IP: %s] [User: %s] %s",
                $timestamp,
                strtoupper($level),
                $category,
                $ip,
                $user,
                $message
            );

            if (!empty($context)) {
                $logEntry .= ' | Контекст: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            }

            self::writeToFile($logEntry);
        }
    }

    /**
     * Проверка необходимости записи лога
     */
    private static function shouldLog($level)
    {
        if (!isset(self::$levels[$level])) {
            return false;
        }
        return self::$levels[$level] >= self::$levels[self::$minLevel];
    }

    /**
     * Запись в файл
     */
    private static function writeToFile($message)
    {
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents(self::$logFile, $message . PHP_EOL, FILE_APPEND);
    }

    /**
     * Получение логов за период
     */
    public static function getLogs($startDate = null, $endDate = null, $level = null, $limit = 100)
    {
        if (!file_exists(self::$logFile)) {
            return [];
        }

        $logs = file(self::$logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $filtered = [];

        foreach ($logs as $line) {
            // Простая фильтрация
            if ($level && stripos($line, strtoupper($level)) === false) {
                continue;
            }

            if ($startDate || $endDate) {
                preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches);
                if (isset($matches[1])) {
                    $logTime = strtotime($matches[1]);
                    if ($startDate && $logTime < strtotime($startDate)) {
                        continue;
                    }
                    if ($endDate && $logTime > strtotime($endDate)) {
                        continue;
                    }
                }
            }

            $filtered[] = $line;
            if (count($filtered) >= $limit) {
                break;
            }
        }

        return array_reverse($filtered);
    }

    /**
     * Очистка старых логов
     */
    public static function rotate($maxAgeDays = 30)
    {
        if (!file_exists(self::$logFile)) {
            return 0;
        }

        $maxAge = $maxAgeDays * 86400;
        $now = time();
        $lines = file(self::$logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $kept = 0;
        $removed = 0;

        $newContent = [];
        foreach ($lines as $line) {
            preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches);
            if (isset($matches[1])) {
                $logTime = strtotime($matches[1]);
                if ($now - $logTime <= $maxAge) {
                    $newContent[] = $line;
                    $kept++;
                    continue;
                }
            }
            $removed++;
        }

        if ($removed > 0) {
            file_put_contents(self::$logFile, implode(PHP_EOL, $newContent) . PHP_EOL);
            self::log('Система', "Ротация логов: удалено $removed записей, сохранено $kept", 'info');
        }

        return $removed;
    }
}
