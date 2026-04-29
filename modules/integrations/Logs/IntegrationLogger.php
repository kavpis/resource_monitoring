<?php
/**
 * Логгер для модуля интеграций
 * 
 * Обеспечивает логирование всех событий, связанных с подключением
 * к устройствам и обменом данными. Все сообщения на русском языке.
 * 
 * @package Modules\Integrations\Logs
 */

namespace Modules\Integrations\Logs;

class IntegrationLogger
{
    private const LOG_FILE = '/workspace/logs/integration.log';
    
    /**
     * Уровни логирования
     */
    private const LEVEL_DEBUG = 'DEBUG';
    private const LEVEL_INFO = 'INFO';
    private const LEVEL_WARNING = 'WARNING';
    private const LEVEL_ERROR = 'ERROR';
    private const LEVEL_CRITICAL = 'CRITICAL';

    /**
     * Запись сообщения в лог
     * 
     * @param string $message Текст сообщения
     * @param string $level Уровень важности
     * @param string $component Компонент-источник (например, "ModbusAdapter")
     * @param array $context Дополнительный контекст
     * @return void
     */
    public static function log(string $message, string $level = self::LEVEL_INFO, 
                               string $component = 'Integration', array $context = []): void
    {
        // Создаем директорию для логов если не существует
        $logDir = dirname(self::LOG_FILE);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | Контекст: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        
        $logEntry = sprintf(
            "[%s] [%s] [%s] %s%s\n",
            $timestamp,
            $level,
            $component,
            $message,
            $contextStr
        );

        file_put_contents(self::LOG_FILE, $logEntry, FILE_APPEND);
    }

    /**
     * Логирование отладочной информации
     */
    public static function debug(string $message, string $component = 'Integration', array $context = []): void
    {
        self::log($message, self::LEVEL_DEBUG, $component, $context);
    }

    /**
     * Логирование информационного сообщения
     */
    public static function info(string $message, string $component = 'Integration', array $context = []): void
    {
        self::log($message, self::LEVEL_INFO, $component, $context);
    }

    /**
     * Логирование предупреждения
     */
    public static function warning(string $message, string $component = 'Integration', array $context = []): void
    {
        self::log($message, self::LEVEL_WARNING, $component, $context);
    }

    /**
     * Логирование ошибки
     */
    public static function error(string $message, string $component = 'Integration', array $context = []): void
    {
        self::log($message, self::LEVEL_ERROR, $component, $context);
    }

    /**
     * Логирование критической ошибки
     */
    public static function critical(string $message, string $component = 'Integration', array $context = []): void
    {
        self::log($message, self::LEVEL_CRITICAL, $component, $context);
    }

    /**
     * Логирование подключения к устройству
     */
    public static function logConnection(string $host, int $port, bool $success, string $adapterType): void
    {
        if ($success) {
            self::info(
                "Успешное подключение к устройству",
                $adapterType,
                ['хост' => $host, 'порт' => $port]
            );
        } else {
            self::error(
                "Не удалось подключиться к устройству",
                $adapterType,
                ['хост' => $host, 'порт' => $port]
            );
        }
    }

    /**
     * Логирование операции чтения данных
     */
    public static function logReadOperation(int $slaveId, int $register, int $quantity, 
                                           $result, string $adapterType): void
    {
        if ($result !== false) {
            self::debug(
                "Успешное чтение регистров",
                $adapterType,
                [
                    'ID_устройства' => $slaveId,
                    'адрес_регистра' => $register,
                    'количество' => $quantity,
                    'данные' => $result
                ]
            );
        } else {
            self::error(
                "Ошибка чтения регистров",
                $adapterType,
                [
                    'ID_устройства' => $slaveId,
                    'адрес_регистра' => $register,
                    'количество' => $quantity
                ]
            );
        }
    }

    /**
     * Логирование операции записи данных
     */
    public static function logWriteOperation(int $slaveId, int $register, array $data, 
                                            bool $success, string $adapterType): void
    {
        if ($success) {
            self::info(
                "Успешная запись в регистры",
                $adapterType,
                [
                    'ID_устройства' => $slaveId,
                    'адрес_регистра' => $register,
                    'данные' => $data
                ]
            );
        } else {
            self::error(
                "Ошибка записи в регистры",
                $adapterType,
                [
                    'ID_устройства' => $slaveId,
                    'адрес_регистра' => $register,
                    'данные' => $data
                ]
            );
        }
    }

    /**
     * Получение содержимого лога
     * 
     * @param int $lines Количество последних строк для получения
     * @return array Массив строк лога
     */
    public static function getRecentLogs(int $lines = 100): array
    {
        if (!file_exists(self::LOG_FILE)) {
            return [];
        }

        $file = file(self::LOG_FILE);
        if ($file === false) {
            return [];
        }

        return array_slice(array_map('trim', $file), -$lines);
    }

    /**
     * Очистка старого лога
     * 
     * @param int $days Хранить логи за последние N дней
     * @return int Количество удаленных записей
     */
    public static function cleanupOldLogs(int $days = 30): int
    {
        if (!file_exists(self::LOG_FILE)) {
            return 0;
        }

        $cutoffDate = date('Y-m-d', strtotime("-{$days} days"));
        $lines = file(self::LOG_FILE, FILE_IGNORE_NEW_LINES);
        $removed = 0;
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2})/', $line, $matches)) {
                if ($matches[1] >= $cutoffDate) {
                    $kept[] = $line;
                } else {
                    $removed++;
                }
            } else {
                $kept[] = $line;
            }
        }

        file_put_contents(self::LOG_FILE, implode("\n", $kept) . "\n");
        
        self::info("Очистка старых записей лога", 'IntegrationLogger', [
            'удалено_записей' => $removed,
            'период_хранения_дней' => $days
        ]);

        return $removed;
    }
}
