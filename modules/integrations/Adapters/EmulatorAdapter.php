<?php
/**
 * Адаптер для работы с эмулятором счётчиков ресурсов
 * 
 * Этот класс имитирует подключение к реальным устройствам через Modbus TCP,
 * но фактически получает данные от эмулятора. Позволяет разрабатывать и
 * тестировать систему без физического подключения к счётчикам.
 * 
 * В будущем может быть заменён на реальный ModbusAdapter для работы
 * с физическими устройствами.
 * 
 * @package Modules\Integrations\Adapters
 */

namespace Modules\Integrations\Adapters;

use Modules\Integrations\Interfaces\DeviceAdapterInterface;
use Modules\Integrations\Logs\IntegrationLogger;

class EmulatorAdapter implements DeviceAdapterInterface
{
    private ?string $host = null;
    private ?int $port = null;
    private bool $connected = false;
    private ?string $lastError = null;
    
    /**
     * Статистика работы адаптера
     */
    private array $statistics = [
        'total_requests' => 0,
        'successful_requests' => 0,
        'failed_requests' => 0,
        'total_response_time_ms' => 0,
        'connection_attempts' => 0,
        'successful_connections' => 0,
        'failed_connections' => 0
    ];

    /**
     * Конфигурация эмулятора
     */
    private array $config = [
        'timeout_ms' => 5000,
        'retry_count' => 3,
        'emulator_url' => 'http://localhost:8080', // URL API эмулятора
        'simulate_latency' => true,
        'min_latency_ms' => 50,
        'max_latency_ms' => 200
    ];

    /**
     * Кэш последних прочитанных значений
     */
    private array $readCache = [];

    /**
     * Подключение к эмулятору
     * 
     * @param string $host IP-адрес эмулятора
     * @param int $port Порт эмулятора
     * @param array $config Дополнительные параметры
     * @return bool Успешность подключения
     */
    public function connect(string $host, int $port, array $config = []): bool
    {
        $this->statistics['connection_attempts']++;
        
        IntegrationLogger::debug(
            "Попытка подключения к эмулятору",
            'EmulatorAdapter',
            ['хост' => $host, 'порт' => $port]
        );

        $this->config = array_merge($this->config, $config);
        $this->host = $host;
        $this->port = $port;

        // Имитация задержки подключения
        if ($this->config['simulate_latency']) {
            usleep(rand($this->config['min_latency_ms'], $this->config['max_latency_ms']) * 1000);
        }

        // Проверка доступности эмулятора (ping)
        $isAvailable = $this->checkEmulatorAvailability();

        if ($isAvailable) {
            $this->connected = true;
            $this->lastError = null;
            $this->statistics['successful_connections']++;
            
            IntegrationLogger::logConnection($host, $port, true, 'EmulatorAdapter');
            return true;
        } else {
            $this->connected = false;
            $this->lastError = "Эмулятор недоступен по адресу {$host}:{$port}";
            $this->statistics['failed_connections']++;
            
            IntegrationLogger::logConnection($host, $port, false, 'EmulatorAdapter');
            return false;
        }
    }

    /**
     * Отключение от эмулятора
     */
    public function disconnect(): void
    {
        if ($this->connected) {
            IntegrationLogger::info(
                "Отключение от эмулятора",
                'EmulatorAdapter',
                ['хост' => $this->host, 'порт' => $this->port]
            );
            
            $this->connected = false;
            $this->host = null;
            $this->port = null;
            $this->readCache = [];
        }
    }

    /**
     * Проверка состояния подключения
     * 
     * @return bool Статус подключения
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->checkEmulatorAvailability();
    }

    /**
     * Чтение данных из регистров эмулятора
     * 
     * @param int $slaveId ID ведомого устройства
     * @param int $registerAddress Адрес регистра
     * @param int $quantity Количество регистров
     * @return array|false Массив данных или false при ошибке
     */
    public function readRegisters(int $slaveId, int $registerAddress, int $quantity)
    {
        $startTime = microtime(true);
        $this->statistics['total_requests']++;

        if (!$this->connected) {
            $this->lastError = "Нет подключения к эмулятору";
            $this->statistics['failed_requests']++;
            
            IntegrationLogger::error(
                "Чтение невозможно: нет подключения",
                'EmulatorAdapter',
                ['ID_устройства' => $slaveId]
            );
            
            return false;
        }

        // Имитация задержки чтения
        if ($this->config['simulate_latency']) {
            usleep(rand($this->config['min_latency_ms'], $this->config['max_latency_ms']) * 1000);
        }

        // Получение данных от эмулятора
        $data = $this->fetchDataFromEmulator($slaveId, $registerAddress, $quantity);

        $responseTime = (microtime(true) - $startTime) * 1000;
        $this->statistics['total_response_time_ms'] += $responseTime;

        if ($data !== false) {
            $this->statistics['successful_requests']++;
            $this->lastError = null;
            
            // Кэширование последних данных
            $cacheKey = "{$slaveId}_{$registerAddress}";
            $this->readCache[$cacheKey] = [
                'data' => $data,
                'timestamp' => time()
            ];

            IntegrationLogger::logReadOperation(
                $slaveId, 
                $registerAddress, 
                $quantity, 
                $data, 
                'EmulatorAdapter'
            );
            
            return $data;
        } else {
            $this->statistics['failed_requests']++;
            
            IntegrationLogger::error(
                "Не удалось получить данные от эмулятора",
                'EmulatorAdapter',
                [
                    'ID_устройства' => $slaveId,
                    'адрес_регистра' => $registerAddress,
                    'количество' => $quantity
                ]
            );
            
            return false;
        }
    }

    /**
     * Запись данных в регистры эмулятора
     * 
     * @param int $slaveId ID ведомого устройства
     * @param int $registerAddress Адрес регистра
     * @param array $data Данные для записи
     * @return bool Успешность операции
     */
    public function writeRegisters(int $slaveId, int $registerAddress, array $data): bool
    {
        if (!$this->connected) {
            $this->lastError = "Нет подключения к эмулятору";
            
            IntegrationLogger::error(
                "Запись невозможна: нет подключения",
                'EmulatorAdapter'
            );
            
            return false;
        }

        // Имитация задержки записи
        if ($this->config['simulate_latency']) {
            usleep(rand($this->config['min_latency_ms'], $this->config['max_latency_ms']) * 1000);
        }

        // Отправка данных эмулятору
        $success = $this->sendDataToEmulator($slaveId, $registerAddress, $data);

        IntegrationLogger::logWriteOperation(
            $slaveId, 
            $registerAddress, 
            $data, 
            $success, 
            'EmulatorAdapter'
        );

        return $success;
    }

    /**
     * Получение последней ошибки
     * 
     * @return string|null Текст ошибки
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Получение статистики работы адаптера
     * 
     * @return array Массив статистики
     */
    public function getStatistics(): array
    {
        $avgResponseTime = $this->statistics['total_requests'] > 0
            ? $this->statistics['total_response_time_ms'] / $this->statistics['total_requests']
            : 0;

        return array_merge($this->statistics, [
            'average_response_time_ms' => round($avgResponseTime, 2),
            'success_rate' => $this->statistics['total_requests'] > 0
                ? round(($this->statistics['successful_requests'] / $this->statistics['total_requests']) * 100, 2)
                : 0,
            'connection_success_rate' => $this->statistics['connection_attempts'] > 0
                ? round(($this->statistics['successful_connections'] / $this->statistics['connection_attempts']) * 100, 2)
                : 0,
            'is_connected' => $this->connected,
            'host' => $this->host,
            'port' => $this->port
        ]);
    }

    /**
     * Тестирование соединения с устройством
     * 
     * @param int $slaveId ID ведомого устройства
     * @return bool Результат теста
     */
    public function testConnection(int $slaveId): bool
    {
        if (!$this->connected) {
            return false;
        }

        // Попытка чтения одного регистра для проверки связи
        $result = $this->readRegisters($slaveId, 0, 1);
        
        return $result !== false;
    }

    /**
     * Проверка доступности эмулятора
     * 
     * @return bool Доступен ли эмулятор
     */
    private function checkEmulatorAvailability(): bool
    {
        // В реальной реализации здесь будет проверка HTTP/Socket соединения
        // Для демонстрации считаем эмулятор доступным если хост localhost
        
        if ($this->host === 'localhost' || $this->host === '127.0.0.1') {
            return true;
        }

        // Проверка через socket
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 2);
        if ($socket) {
            fclose($socket);
            return true;
        }

        return false;
    }

    /**
     * Получение данных от эмулятора
     * 
     * В реальной системе здесь будет HTTP запрос к API эмулятора
     * или прямое чтение из базы данных эмулятора
     * 
     * @param int $slaveId ID устройства
     * @param int $registerAddress Адрес регистра
     * @param int $quantity Количество регистров
     * @return array|false Массив данных
     */
    private function fetchDataFromEmulator(int $slaveId, int $registerAddress, int $quantity)
    {
        // ПРИМЕЧАНИЕ: Это имитация получения данных от эмулятора
        // В реальной системе здесь должен быть вызов API эмулятора
        
        // Пример URL для запроса к эмулятору:
        // $url = "{$this->config['emulator_url']}/api/read?slave_id={$slaveId}&address={$registerAddress}&quantity={$quantity}";
        // $response = file_get_contents($url);
        // $data = json_decode($response, true);
        
        // Генерация тестовых данных для демонстрации
        $data = [];
        for ($i = 0; $i < $quantity; $i++) {
            // Имитация различных типов данных в зависимости от адреса регистра
            switch ($registerAddress + $i) {
                case 0: // Потребление воды (м³)
                    $data[] = rand(1000, 5000) / 10;
                    break;
                case 1: // Температура (°C)
                    $data[] = rand(400, 900) / 10;
                    break;
                case 2: // Давление (бар)
                    $data[] = rand(20, 60) / 10;
                    break;
                case 3: // Электропотребление (кВт·ч)
                    $data[] = rand(5000, 50000);
                    break;
                case 4: // Напряжение (В)
                    $data[] = rand(2150, 2250) / 10;
                    break;
                default:
                    $data[] = rand(0, 1000);
            }
        }

        return $data;
    }

    /**
     * Отправка данных в эмулятор
     * 
     * @param int $slaveId ID устройства
     * @param int $registerAddress Адрес регистра
     * @param array $data Данные для отправки
     * @return bool Успешность операции
     */
    private function sendDataToEmulator(int $slaveId, int $registerAddress, array $data): bool
    {
        // В реальной реализации здесь будет POST запрос к API эмулятора
        // $url = "{$this->config['emulator_url']}/api/write";
        // $payload = json_encode([
        //     'slave_id' => $slaveId,
        //     'address' => $registerAddress,
        //     'data' => $data
        // ]);
        // $context = stream_context_create([...]);
        // $result = file_get_contents($url, false, $context);
        
        // Для демонстрации всегда возвращаем успех
        return true;
    }

    /**
     * Получение данных из кэша
     * 
     * @param int $slaveId ID устройства
     * @param int $registerAddress Адрес регистра
     * @param int $maxAgeSeconds Максимальный возраст кэша в секундах
     * @return array|null Данные из кэша или null если кэш устарел
     */
    public function getCachedData(int $slaveId, int $registerAddress, int $maxAgeSeconds = 60): ?array
    {
        $cacheKey = "{$slaveId}_{$registerAddress}";
        
        if (!isset($this->readCache[$cacheKey])) {
            return null;
        }

        $cached = $this->readCache[$cacheKey];
        
        if (time() - $cached['timestamp'] > $maxAgeSeconds) {
            unset($this->readCache[$cacheKey]);
            return null;
        }

        return $cached['data'];
    }

    /**
     * Очистка кэша
     */
    public function clearCache(): void
    {
        $this->readCache = [];
        IntegrationLogger::debug("Кэш очищен", 'EmulatorAdapter');
    }
}
