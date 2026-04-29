<?php
/**
 * Клиент для работы с протоколом Modbus TCP
 * Раздел 4 — Протоколы передачи данных
 */

class ModbusClient {
    private $socket = null;
    private $host;
    private $port;
    private $timeout;
    private $connected = false;
    
    public function __construct($host, $port = 502, $timeout = 5) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }
    
    /**
     * Подключение к Modbus-устройству
     */
    public function connect() {
        if ($this->connected) {
            return true;
        }
        
        $this->socket = @fsockopen(
            $this->host,
            $this->port,
            $errno,
            $errstr,
            $this->timeout
        );
        
        if (!$this->socket) {
            error_log("Modbus connection failed to {$this->host}:{$this->port} - $errstr ($errno)");
            return false;
        }
        
        stream_set_timeout($this->socket, $this->timeout);
        stream_set_blocking($this->socket, true);
        $this->connected = true;
        
        return true;
    }
    
    /**
     * Отключение от устройства
     */
    public function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }
    
    /**
     * Проверка доступности устройства
     */
    public function isAvailable() {
        if (!$this->connect()) {
            return false;
        }
        
        // Попытка чтения одного регистра для проверки
        $result = $this->readInt(0, 1); // Попытка чтения регистра 0
        
        return $result !== false;
    }
    
    /**
     * Чтение holding registers (функция 03)
     */
    public function readRegisters($startAddress, $quantity, $slaveId = 1) {
        if (!$this->connected && !$this->connect()) {
            return false;
        }
        
        // Формирование Modbus TCP заголовка
        $transactionId = mt_rand(1, 65535);
        $protocolId = 0;
        $length = 6; // 1 байт slave_id + 1 байт function_code + 2 байта start_addr + 2 байта quantity
        $unitId = $slaveId;
        $functionCode = 3; // Read Holding Registers
        
        $request = pack(
            'n*', // network byte order (unsigned short)
            $transactionId,
            $protocolId,
            $length,
            ($unitId << 8) | $functionCode,
            $startAddress,
            $quantity
        );
        
        // Отправка запроса
        $bytesSent = fwrite($this->socket, $request);
        if ($bytesSent === false || $bytesSent !== strlen($request)) {
            $this->handleConnectionError();
            return false;
        }
        
        // Чтение ответа
        $responseHeader = fread($this->socket, 9);
        if (strlen($responseHeader) !== 9) {
            $this->handleConnectionError();
            return false;
        }
        
        // Разбор заголовка ответа
        $response = unpack('n3', $responseHeader);
        $responseTransactionId = $response[1];
        $responseProtocolId = $response[2];
        $responseLength = $response[3];
        $responseUnitAndFunction = $response[4];
        $responseUnitId = ($responseUnitAndFunction >> 8) & 0xFF;
        $responseFunctionCode = $responseUnitAndFunction & 0xFF;
        $byteCount = $response[5];
        
        // Проверка на ошибку
        if (($responseFunctionCode & 0x80) === 0x80) {
            // Код ошибки
            $errorCode = $response[6] ?? 0;
            error_log("Modbus error: function_code={$responseFunctionCode}, code={$errorCode}");
            $this->handleConnectionError();
            return false;
        }
        
        // Чтение данных
        $data = fread($this->socket, $byteCount);
        if (strlen($data) !== $byteCount) {
            $this->handleConnectionError();
            return false;
        }
        
        // Преобразование данных в массив 16-битных слов
        $registers = [];
        for ($i = 0; $i < $byteCount; $i += 2) {
            $word = unpack('n', substr($data, $i, 2))[1];
            $registers[] = $word;
        }
        
        return $registers;
    }
    
    /**
     * Чтение одного integer значения
     */
    public function readInt($address, $slaveId = 1) {
        $registers = $this->readRegisters($address, 1, $slaveId);
        if ($registers === false || count($registers) < 1) {
            return false;
        }
        
        return $registers[0];
    }
    
    /**
     * Чтение float значения (2 регистра)
     */
    public function readFloat($address, $slaveId = 1) {
        $registers = $this->readRegisters($address, 2, $slaveId);
        if ($registers === false || count($registers) < 2) {
            return false;
        }
        
        // Сборка float из двух 16-битных регистров
        // Modbus использует big-endian byte order
        $hiWord = $registers[0];
        $loWord = $registers[1];
        
        // Сборка 32-битного значения
        $int32 = ($hiWord << 16) | $loWord;
        
        // Преобразование в float
        $floatBytes = pack('L', $int32);
        $floatValue = unpack('f', $floatBytes)[1];
        
        return $floatValue;
    }
    
    /**
     * Чтение нескольких регистров как массив значений
     */
    public function readMultiple($startAddress, $quantity, $slaveId = 1) {
        return $this->readRegisters($startAddress, $quantity, $slaveId);
    }
    
    /**
     * Запись в регистр (функция 06 - Write Single Register)
     */
    public function writeRegister($address, $value, $slaveId = 1) {
        if (!$this->connected && !$this->connect()) {
            return false;
        }
        
        $transactionId = mt_rand(1, 65535);
        $protocolId = 0;
        $length = 6;
        $unitId = $slaveId;
        $functionCode = 6; // Write Single Register
        
        $request = pack(
            'nnnCCnn',
            $transactionId,
            $protocolId,
            $length,
            $unitId,
            $functionCode,
            $address,
            $value
        );
        
        $bytesSent = fwrite($this->socket, $request);
        if ($bytesSent === false) {
            $this->handleConnectionError();
            return false;
        }
        
        $response = fread($this->socket, 12);
        if (strlen($response) !== 12) {
            $this->handleConnectionError();
            return false;
        }
        
        $responseUnpack = unpack('n*', $response);
        $responseFunctionCode = $responseUnpack[5];
        
        // Проверка на ошибку
        if (($responseFunctionCode & 0x80) === 0x80) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Обработка ошибки соединения
     */
    private function handleConnectionError() {
        $this->connected = false;
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
    
    /**
     * Получение информации о соединении
     */
    public function getConnectionInfo() {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'connected' => $this->connected,
            'timeout' => $this->timeout
        ];
    }
    
    /**
     * Деструктор
     */
    public function __destruct() {
        if ($this->connected) {
            $this->disconnect();
        }
    }
}
?>