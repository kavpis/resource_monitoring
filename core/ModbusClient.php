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
    private $unitId;
    
    public function __construct($host, $port = 502, $timeout = 3) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }
    
    // Подключение к устройству
    public function connect() {
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
        
        return true;
    }
    
    // Отключение
    public function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
    
    // Чтение регистров (функция 03 - Read Holding Registers)
    public function readRegisters($startAddress, $quantity) {
        if (!$this->socket) {
            if (!$this->connect()) {
                return false;
            }
        }
        
        // Формирование запроса Modbus TCP
        $transactionId = rand(1, 65535);
        $protocolId = 0;
        $length = 6;
        $unitId = 1; // Slave ID
        $functionCode = 3; // Read Holding Registers
        
        $request = pack(
            'n n n c c n n',
            $transactionId,    // Transaction ID (2 bytes)
            $protocolId,       // Protocol ID (2 bytes)
            $length,           // Length (2 bytes)
            $unitId,           // Unit ID (1 byte)
            $functionCode,     // Function code (1 byte)
            $startAddress,     // Start address (2 bytes)
            $quantity          // Quantity (2 bytes)
        );
        
        // Отправка запроса
        $bytesSent = fwrite($this->socket, $request);
        if ($bytesSent === false) {
            $this->disconnect();
            return false;
        }
        
        // Чтение ответа
        $header = fread($this->socket, 9); // MBAP header (7 bytes) + unitId + functionCode
        if ($header === false || strlen($header) < 9) {
            $this->disconnect();
            return false;
        }
        
        // Разбор заголовка
        $response = unpack('ntransaction_id/nprotocol_id/nlength/Cunit_id/Cfunction_code/Cbyte_count', $header);
        
        // Проверка ошибок
        if (($response['function_code'] & 0x80) == 0x80) {
            $exceptionCode = ord(fread($this->socket, 1));
            error_log("Modbus exception: function_code={$response['function_code']}, code=$exceptionCode");
            $this->disconnect();
            return false;
        }
        
        // Чтение данных
        $byteCount = $response['byte_count'];
        $data = fread($this->socket, $byteCount);
        
        if (strlen($data) != $byteCount) {
            $this->disconnect();
            return false;
        }
        
        // Преобразование данных в массив 16-битных регистров
        $registers = [];
        for ($i = 0; $i < $byteCount; $i += 2) {
            $registers[] = unpack('n', substr($data, $i, 2))[1];
        }
        
        return $registers;
    }
    
    // Чтение одного регистра как float (2 регистра = 32 бита)
    public function readFloat($address) {
        $registers = $this->readRegisters($address, 2);
        if ($registers === false || count($registers) < 2) {
            return false;
        }
        
        // Сборка float из двух 16-битных регистров (big-endian)
        $packed = pack('nn', $registers[0], $registers[1]);
        $float = unpack('f', strrev($packed))[1];
        
        return $float;
    }
    
    // Чтение одного регистра как целое число
    public function readInt($address) {
        $registers = $this->readRegisters($address, 1);
        if ($registers === false || count($registers) < 1) {
            return false;
        }
        
        return $registers[0];
    }
    
    // Чтение битового поля (алерты)
    public function readAlerts($address) {
        $registers = $this->readRegisters($address, 1);
        if ($registers === false || count($registers) < 1) {
            return [];
        }
        
        $alertByte = $registers[0];
        
        return [
            'water_leak' => ($alertByte & 0x01) != 0,
            'heat_overheat' => ($alertByte & 0x02) != 0,
            'heat_low_delta' => ($alertByte & 0x04) != 0,
            'power_overload' => ($alertByte & 0x08) != 0,
            'voltage_low' => ($alertByte & 0x10) != 0,
            'voltage_high' => ($alertByte & 0x20) != 0
        ];
    }
    
    // Проверка доступности устройства
    public function isAvailable() {
        if ($this->connect()) {
            $this->disconnect();
            return true;
        }
        return false;
    }
}
?>