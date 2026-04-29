<?php
/**
 * Адаптер для работы с устройствами по протоколу Modbus TCP
 * 
 * Реализует полноценное взаимодействие с физическими счётчиками ресурсов
 * через протокол Modbus TCP. Поддерживает чтение и запись регистров,
 * работу с несколькими устройствами, обработку ошибок и повторные попытки.
 * 
 * Для работы требуется PHP расширение php-modbus или использование
 * сокетеов для прямого взаимодействия по протоколу.
 * 
 * @package Modules\Integrations\Adapters
 */

namespace Modules\Integrations\Adapters;

use Modules\Integrations\Interfaces\DeviceAdapterInterface;
use Modules\Integrations\Logs\IntegrationLogger;

class ModbusAdapter implements DeviceAdapterInterface
{
    private ?string $host = null;
    private ?int $port = null;
    private $socket = null;
    private bool $connected = false;
    private ?string $lastError = null;
    
    /**
     * Таймаут соединения в секундах
     */
    private int $timeout = 5;

    /**
     * Количество попыток при ошибке
     */
    private int $retryCount = 3;

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
        'failed_connections' => 0,
        'retries_count' => 0
    ];

    /**
     * Transaction ID для Modbus TCP
     */
    private int $transactionId = 0;

    /**
     * Подключение к устройству Modbus TCP
     * 
     * @param string $host IP-адрес устройства
     * @param int $port Порт устройства (обычно 502)
     * @param array $config Дополнительные параметры
     * @return bool Успешность подключения
     */
    public function connect(string $host, int $port = 502, array $config = []): bool
    {
        $this->statistics['connection_attempts']++;
        
        IntegrationLogger::info(
            "Подключение к устройству Modbus TCP",
            'ModbusAdapter',
            ['хост' => $host, 'порт' => $port]
        );

        $this->host = $host;
        $this->port = $port;
        
        // Применение дополнительных настроек
        if (isset($config['timeout'])) {
            $this->timeout = (int)$config['timeout'];
        }
        if (isset($config['retry_count'])) {
            $this->retryCount = (int)$config['retry_count'];
        }

        try {
            // Создание сокета
            $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            
            if ($this->socket === false) {
                throw new \Exception("Не удалось создать сокет: " . socket_strerror(socket_last_error()));
            }

            // Установка таймаута
            socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
            socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);

            // Подключение к устройству
            $result = socket_connect($this->socket, $host, $port);
            
            if ($result === false) {
                $errorCode = socket_last_error($this->socket);
                $errorMsg = socket_strerror($errorCode);
                throw new \Exception("Ошибка подключения: {$errorMsg} (код: {$errorCode})");
            }

            $this->connected = true;
            $this->lastError = null;
            $this->statistics['successful_connections']++;
            
            IntegrationLogger::logConnection($host, $port, true, 'ModbusAdapter');
            
            return true;

        } catch (\Exception $e) {
            $this->connected = false;
            $this->lastError = $e->getMessage();
            $this->statistics['failed_connections']++;
            
            IntegrationLogger::error(
                "Ошибка подключения к Modbus устройству: {$e->getMessage()}",
                'ModbusAdapter',
                ['хост' => $host, 'порт' => $port]
            );
            
            if ($this->socket) {
                socket_close($this->socket);
                $this->socket = null;
            }
            
            return false;
        }
    }

    /**
     * Отключение от устройства
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            IntegrationLogger::info(
                "Отключение от устройства Modbus",
                'ModbusAdapter',
                ['хост' => $this->host, 'порт' => $this->port]
            );
            
            socket_shutdown($this->socket);
            socket_close($this->socket);
            $this->socket = null;
            $this->connected = false;
        }
    }

    /**
     * Проверка состояния подключения
     * 
     * @return bool Статус подключения
     */
    public function isConnected(): bool
    {
        if (!$this->connected || $this->socket === null) {
            return false;
        }

        // Проверка активности сокета
        $read = [$this->socket];
        $write = null;
        $except = null;
        
        $result = socket_select($read, $write, $except, 0);
        
        // Если socket_select вернул false или сокет в except - подключение разорвано
        if ($result === false || (is_array($except) && count($except) > 0)) {
            $this->connected = false;
            return false;
        }
        
        return true;
    }

    /**
     * Чтение данных из регистров устройства
     * 
     * @param int $slaveId ID ведомого устройства
     * @param int $registerAddress Адрес регистра
     * @param int $quantity Количество регистров для чтения
     * @return array|false Массив прочитанных данных или false при ошибке
     */
    public function readRegisters(int $slaveId, int $registerAddress, int $quantity)
    {
        return $this->executeWithRetry(function() use ($slaveId, $registerAddress, $quantity) {
            $startTime = microtime(true);
            $this->statistics['total_requests']++;

            if (!$this->connected) {
                $this->lastError = "Нет подключения к устройству Modbus";
                $this->statistics['failed_requests']++;
                
                IntegrationLogger::error(
                    "Чтение невозможно: нет подключения",
                    'ModbusAdapter',
                    ['ID_устройства' => $slaveId]
                );
                
                return false;
            }

            // Формирование Modbus TCP запроса (Function Code 03 - Read Holding Registers)
            $request = $this->buildReadRequest($slaveId, $registerAddress, $quantity);
            
            // Отправка запроса
            $bytesWritten = socket_write($this->socket, $request, strlen($request));
            
            if ($bytesWritten === false || $bytesWritten !== strlen($request)) {
                $this->lastError = "Ошибка отправки запроса";
                $this->statistics['failed_requests']++;
                
                IntegrationLogger::error(
                    "Ошибка отправки Modbus запроса",
                    'ModbusAdapter',
                    ['записано_байт' => $bytesWritten, 'ожидалось' => strlen($request)]
                );
                
                return false;
            }

            // Чтение ответа
            $response = $this->readResponse();
            
            if ($response === false) {
                $this->statistics['failed_requests']++;
                return false;
            }

            // Парсинг ответа
            $data = $this->parseReadResponse($response, $quantity);
            
            $responseTime = (microtime(true) - $startTime) * 1000;
            $this->statistics['total_response_time_ms'] += $responseTime;

            if ($data !== false) {
                $this->statistics['successful_requests']++;
                $this->lastError = null;
                
                IntegrationLogger::logReadOperation(
                    $slaveId, 
                    $registerAddress, 
                    $quantity, 
                    $data, 
                    'ModbusAdapter'
                );
                
                return $data;
            } else {
                $this->statistics['failed_requests']++;
                return false;
            }
        });
    }

    /**
     * Запись данных в регистр устройства
     * 
     * @param int $slaveId ID ведомого устройства
     * @param int $registerAddress Адрес регистра
     * @param array $data Данные для записи
     * @return bool Успешность записи
     */
    public function writeRegisters(int $slaveId, int $registerAddress, array $data): bool
    {
        return $this->executeWithRetry(function() use ($slaveId, $registerAddress, $data) {
            if (!$this->connected) {
                $this->lastError = "Нет подключения к устройству Modbus";
                
                IntegrationLogger::error(
                    "Запись невозможна: нет подключения",
                    'ModbusAdapter'
                );
                
                return false;
            }

            // Формирование Modbus TCP запроса (Function Code 16 - Write Multiple Registers)
            $request = $this->buildWriteRequest($slaveId, $registerAddress, $data);
            
            // Отправка запроса
            $bytesWritten = socket_write($this->socket, $request, strlen($request));
            
            if ($bytesWritten === false || $bytesWritten !== strlen($request)) {
                $this->lastError = "Ошибка отправки запроса на запись";
                
                IntegrationLogger::error(
                    "Ошибка отправки Modbus запроса на запись",
                    'ModbusAdapter'
                );
                
                return false;
            }

            // Чтение подтверждения
            $response = $this->readResponse();
            
            if ($response === false) {
                return false;
            }

            // Проверка ответа
            $success = $this->parseWriteResponse($response, $slaveId, $registerAddress, count($data));
            
            IntegrationLogger::logWriteOperation(
                $slaveId, 
                $registerAddress, 
                $data, 
                $success, 
                'ModbusAdapter'
            );

            return $success;
        });
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

        // Чтение одного регистра для проверки связи
        $result = $this->readRegisters($slaveId, 0, 1);
        
        return $result !== false;
    }

    /**
     * Построение Modbus TCP запроса на чтение регистров
     * 
     * @param int $slaveId ID ведомого
     * @param int $address Адрес регистра
     * @param int $quantity Количество регистров
     * @return string Бинарный запрос
     */
    private function buildReadRequest(int $slaveId, int $address, int $quantity): string
    {
        $this->transactionId = ($this->transactionId + 1) % 65536;
        
        // MBAP Header (7 байт): Transaction ID (2) + Protocol ID (2) + Length (2) + Unit ID (1)
        // Function Code (1): 03 для чтения holding registers
        // Data (4): Starting Address (2) + Quantity (2)
        
        $header = pack('nnnC', 
            $this->transactionId,      // Transaction ID
            0,                         // Protocol ID (всегда 0 для Modbus)
            6,                         // Length (оставшаяся часть: Unit ID + Function Code + Data)
            $slaveId                   // Unit ID
        );
        
        $body = pack('Cnn', 
            3,                         // Function Code 03
            $address,                  // Starting Address
            $quantity                  // Quantity of Registers
        );
        
        return $header . $body;
    }

    /**
     * Построение Modbus TCP запроса на запись регистров
     * 
     * @param int $slaveId ID ведомого
     * @param int $address Адрес регистра
     * @param array $data Данные для записи
     * @return string Бинарный запрос
     */
    private function buildWriteRequest(int $slaveId, int $address, array $data): string
    {
        $this->transactionId = ($this->transactionId + 1) % 65536;
        
        $byteCount = count($data) * 2; // Каждое значение - 2 байта
        
        $header = pack('nnnC', 
            $this->transactionId,
            0,
            7 + $byteCount,            // Length
            $slaveId
        );
        
        $body = pack('CnnC', 
            16,                        // Function Code 16
            $address,
            count($data),              // Quantity of Registers
            $byteCount                 // Byte Count
        );
        
        // Добавление данных (каждое значение как 2 байта big-endian)
        foreach ($data as $value) {
            $body .= pack('n', $value);
        }
        
        return $header . $body;
    }

    /**
     * Чтение ответа от устройства
     * 
     * @return string|false Бинарный ответ или false при ошибке
     */
    private function readResponse()
    {
        // Чтение MBAP заголовка (первые 6 байт)
        $header = socket_read($this->socket, 6, PHP_BINARY_READ);
        
        if ($header === false || strlen($header) < 6) {
            $this->lastError = "Ошибка чтения заголовка ответа";
            return false;
        }

        // Парсинг заголовка для получения длины оставшейся части
        $unpack = unpack('ntrans/nproto/nlen', $header);
        $remainingLength = $unpack['len'] - 1; // Вычитаем Unit ID который уже прочитан

        // Чтение остальной части ответа
        $body = '';
        if ($remainingLength > 0) {
            $body = socket_read($this->socket, $remainingLength, PHP_BINARY_READ);
            
            if ($body === false || strlen($body) < $remainingLength) {
                $this->lastError = "Ошибка чтения тела ответа";
                return false;
            }
        }

        return $header . $body;
    }

    /**
     * Парсинг ответа на чтение регистров
     * 
     * @param string $response Бинарный ответ
     * @param int $expectedQuantity Ожидаемое количество регистров
     * @return array|false Массив данных или false при ошибке
     */
    private function parseReadResponse(string $response, int $expectedQuantity)
    {
        if (strlen($response) < 9) {
            $this->lastError = "Слишком короткий ответ";
            return false;
        }

        // Проверка Transaction ID
        $sentTransId = $this->transactionId;
        $recvTransId = unpack('n', substr($response, 0, 2))[1];
        
        if ($recvTransId !== $sentTransId) {
            $this->lastError = "Несовпадение Transaction ID";
            return false;
        }

        // Проверка Function Code
        $functionCode = ord($response[7]);
        
        // Проверка на ошибку (старший бит установлен)
        if ($functionCode & 0x80) {
            $exceptionCode = ord($response[8]);
            $this->lastError = "Ошибка Modbus: код исключения {$exceptionCode}";
            return false;
        }

        if ($functionCode !== 3) {
            $this->lastError = "Неверный Function Code в ответе: {$functionCode}";
            return false;
        }

        // Получение количества байт данных
        $byteCount = ord($response[8]);
        $expectedBytes = $expectedQuantity * 2;
        
        if ($byteCount !== $expectedBytes) {
            $this->lastError = "Несоответствие количества байт: ожидалось {$expectedBytes}, получено {$byteCount}";
            return false;
        }

        // Парсинг данных
        $data = [];
        for ($i = 0; $i < $expectedQuantity; $i++) {
            $offset = 9 + ($i * 2);
            $value = unpack('n', substr($response, $offset, 2))[1];
            $data[] = $value;
        }

        return $data;
    }

    /**
     * Парсинг ответа на запись регистров
     * 
     * @param string $response Бинарный ответ
     * @param int $slaveId ID ведомого
     * @param int $address Адрес регистра
     * @param int $quantity Количество записанных регистров
     * @return bool Успешность
     */
    private function parseWriteResponse(string $response, int $slaveId, int $address, int $quantity): bool
    {
        if (strlen($response) < 10) {
            $this->lastError = "Слишком короткий ответ на запись";
            return false;
        }

        // Проверка Transaction ID
        $sentTransId = $this->transactionId;
        $recvTransId = unpack('n', substr($response, 0, 2))[1];
        
        if ($recvTransId !== $sentTransId) {
            $this->lastError = "Несовпадение Transaction ID при записи";
            return false;
        }

        // Проверка Function Code
        $functionCode = ord($response[7]);
        
        if ($functionCode & 0x80) {
            $exceptionCode = ord($response[8]);
            $this->lastError = "Ошибка Modbus при записи: код исключения {$exceptionCode}";
            return false;
        }

        if ($functionCode !== 16) {
            $this->lastError = "Неверный Function Code в ответе на запись: {$functionCode}";
            return false;
        }

        return true;
    }

    /**
     * Выполнение операции с повторными попытками
     * 
     * @param callable $operation Функция операции
     * @return mixed Результат операции
     */
    private function executeWithRetry(callable $operation)
    {
        $attempts = 0;
        $lastResult = false;

        while ($attempts < $this->retryCount) {
            $attempts++;
            
            if ($attempts > 1) {
                $this->statistics['retries_count']++;
                IntegrationLogger::warning(
                    "Попытка №{$attempts} после ошибки",
                    'ModbusAdapter'
                );
                
                // Небольшая пауза перед повторной попыткой
                usleep(100000); // 100ms
            }

            $lastResult = $operation();
            
            if ($lastResult !== false) {
                return $lastResult;
            }

            // Если ошибка связана с подключением - пробуем переподключиться
            if ($attempts < $this->retryCount && strpos($this->lastError ?? '', 'подключения') !== false) {
                $this->disconnect();
                if ($this->connect($this->host, $this->port)) {
                    continue;
                }
            }
        }

        return $lastResult;
    }

    /**
     * Сброс статистики
     */
    public function resetStatistics(): void
    {
        $this->statistics = [
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'total_response_time_ms' => 0,
            'connection_attempts' => 0,
            'successful_connections' => 0,
            'failed_connections' => 0,
            'retries_count' => 0
        ];
        
        IntegrationLogger::debug("Статистика сброшена", 'ModbusAdapter');
    }
}
