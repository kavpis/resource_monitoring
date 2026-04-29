<?php
/**
 * Менеджер опроса устройств
 * 
 * Управляет периодическим опросом счётчиков ресурсов через адаптеры.
 * Поддерживает работу с несколькими устройствами, обработку ошибок,
 * сохранение данных в базу и логирование.
 * 
 * @package Modules\Integrations
 */

namespace Modules\Integrations;

use Modules\Integrations\Interfaces\DeviceAdapterInterface;
use Modules\Integrations\Logs\IntegrationLogger;
use Core\Database;

class DevicePoller
{
    /**
     * Экземпляр базы данных
     */
    private Database $db;

    /**
     * Кэш адаптеров для каждого устройства
     */
    private array $adapters = [];

    /**
     * Статистика опроса
     */
    private array $pollStatistics = [
        'total_sensors' => 0,
        'successful_polls' => 0,
        'failed_polls' => 0,
        'start_time' => null,
        'end_time' => null
    ];

    /**
     * Конструктор
     * 
     * @param Database|null $db Экземпляр базы данных
     */
    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        
        IntegrationLogger::info("Инициализация менеджера опроса устройств", 'DevicePoller');
    }

    /**
     * Опрос всех активных датчиков
     * 
     * @return array Результаты опроса
     */
    public function pollAllSensors(): array
    {
        $this->pollStatistics['start_time'] = date('Y-m-d H:i:s');
        
        IntegrationLogger::info("Начало опроса всех датчиков", 'DevicePoller');

        // Получение списка активных датчиков из БД
        $sensors = $this->getActiveSensors();
        $this->pollStatistics['total_sensors'] = count($sensors);

        $results = [];

        foreach ($sensors as $sensor) {
            $result = $this->pollSensor($sensor);
            $results[] = $result;
        }

        $this->pollStatistics['end_time'] = date('Y-m-d H:i:s');
        
        IntegrationLogger::info(
            "Опрос датчиков завершён",
            'DevicePoller',
            [
                'всего_датчиков' => $this->pollStatistics['total_sensors'],
                'успешно' => $this->pollStatistics['successful_polls'],
                'ошибок' => $this->pollStatistics['failed_polls']
            ]
        );

        return [
            'statistics' => $this->pollStatistics,
            'results' => $results
        ];
    }

    /**
     * Опрос конкретного датчика
     * 
     * @param array $sensor Данные датчика из БД
     * @return array Результат опроса
     */
    public function pollSensor(array $sensor): array
    {
        $sensorId = $sensor['id'];
        $sensorName = $sensor['name'] ?? "Датчик #{$sensorId}";
        
        IntegrationLogger::debug(
            "Опрос датчика: {$sensorName}",
            'DevicePoller',
            ['ID' => $sensorId]
        );

        try {
            // Получение или создание адаптера
            $adapter = $this->getAdapterForSensor($sensor);

            if (!$adapter->isConnected()) {
                throw new \Exception("Не удалось подключиться к устройству");
            }

            // Чтение данных из регистров
            $registerAddress = (int)($sensor['register_address'] ?? 0);
            $quantity = (int)($sensor['register_quantity'] ?? 1);
            $slaveId = (int)($sensor['slave_id'] ?? 1);

            $data = $adapter->readRegisters($slaveId, $registerAddress, $quantity);

            if ($data === false) {
                throw new \Exception("Ошибка чтения данных: " . ($adapter->getLastError() ?? 'неизвестная ошибка'));
            }

            // Обработка и сохранение данных
            $processedData = $this->processSensorData($sensor, $data);
            $this->saveReading($sensorId, $processedData);

            $this->pollStatistics['successful_polls']++;

            IntegrationLogger::info(
                "Данные датчика успешно получены",
                'DevicePoller',
                [
                    'ID_датчика' => $sensorId,
                    'название' => $sensorName,
                    'значения' => $processedData
                ]
            );

            return [
                'success' => true,
                'sensor_id' => $sensorId,
                'sensor_name' => $sensorName,
                'data' => $processedData,
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            $this->pollStatistics['failed_polls']++;
            
            IntegrationLogger::error(
                "Ошибка опроса датчика: {$e->getMessage()}",
                'DevicePoller',
                ['ID_датчика' => $sensorId, 'название' => $sensorName]
            );

            return [
                'success' => false,
                'sensor_id' => $sensorId,
                'sensor_name' => $sensorName,
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }

    /**
     * Получение адаптера для датчика
     * 
     * @param array $sensor Данные датчика
     * @return DeviceAdapterInterface Адаптер
     */
    private function getAdapterForSensor(array $sensor): DeviceAdapterInterface
    {
        $sensorId = $sensor['id'];
        
        // Проверка кэша адаптеров
        if (isset($this->adapters[$sensorId])) {
            $adapter = $this->adapters[$sensorId];
            
            // Проверка подключения
            if ($adapter->isConnected()) {
                return $adapter;
            }
            
            // Если подключение разорвано - удаляем из кэша
            unset($this->adapters[$sensorId]);
        }

        // Создание нового адаптера
        $connectionType = $sensor['connection_type'] ?? 'emulator';
        $adapter = AdapterFactory::create($connectionType);

        $host = $sensor['ip_address'] ?? 'localhost';
        $port = (int)($sensor['port'] ?? 502);
        $config = json_decode($sensor['config'] ?? '{}', true) ?? [];

        if (!$adapter->connect($host, $port, $config)) {
            throw new \Exception("Ошибка подключения к устройству: {$adapter->getLastError()}");
        }

        $this->adapters[$sensorId] = $adapter;
        
        return $adapter;
    }

    /**
     * Обработка данных датчика
     * 
     * Преобразует сырые данные в соответствии с настройками датчика
     * 
     * @param array $sensor Данные датчика
     * @param array $rawData Сырые данные из регистров
     * @return array Обработанные данные
     */
    private function processSensorData(array $sensor, array $rawData): array
    {
        $multiplier = (float)($sensor['multiplier'] ?? 1.0);
        $offset = (float)($sensor['offset'] ?? 0.0);
        $decimals = (int)($sensor['decimal_places'] ?? 2);

        $processed = [];
        
        foreach ($rawData as $index => $value) {
            // Применение коэффициента и смещения
            $processedValue = ($value * $multiplier) + $offset;
            
            // Округление
            $processedValue = round($processedValue, $decimals);
            
            $processed[] = $processedValue;
        }

        return $processed;
    }

    /**
     * Сохранение показания в базу данных
     * 
     * @param int $sensorId ID датчика
     * @param array $data Данные показания
     * @return int ID сохранённой записи
     */
    private function saveReading(int $sensorId, array $data): int
    {
        $timestamp = date('Y-m-d H:i:s');
        
        // Для датчиков с несколькими значениями создаём отдельные записи
        $lastId = 0;
        
        foreach ($data as $index => $value) {
            $sql = "INSERT INTO readings (sensor_id, value, timestamp, created_at) 
                    VALUES (:sensor_id, :value, :timestamp, NOW())
                    ON DUPLICATE KEY UPDATE value = :value_upd, timestamp = :timestamp_upd";
            
            $this->db->query($sql, [
                'sensor_id' => $sensorId,
                'value' => $value,
                'timestamp' => $timestamp,
                'value_upd' => $value,
                'timestamp_upd' => $timestamp
            ]);
            
            $lastId = $this->db->lastInsertId();
        }
        
        return $lastId;
    }

    /**
     * Получение списка активных датчиков
     * 
     * @return array Массив датчиков
     */
    private function getActiveSensors(): array
    {
        $sql = "SELECT s.*, z.name as zone_name 
                FROM sensors s
                LEFT JOIN zones z ON s.zone_id = z.id
                WHERE s.is_active = 1
                ORDER BY z.name, s.name";
        
        $this->db->query($sql);
        return $this->db->fetchAll();
    }

    /**
     * Тестирование подключения к датчику
     * 
     * @param int $sensorId ID датчика
     * @return array Результат теста
     */
    public function testSensorConnection(int $sensorId): array
    {
        // Получение данных датчика
        $sql = "SELECT * FROM sensors WHERE id = :id";
        $this->db->query($sql, ['id' => $sensorId]);
        $sensor = $this->db->fetchOne();

        if (!$sensor) {
            return [
                'success' => false,
                'error' => 'Датчик не найден'
            ];
        }

        try {
            $adapter = $this->getAdapterForSensor($sensor);
            
            $slaveId = (int)($sensor['slave_id'] ?? 1);
            $testResult = $adapter->testConnection($slaveId);

            if ($testResult) {
                IntegrationLogger::info(
                    "Тест подключения успешен",
                    'DevicePoller',
                    ['ID_датчика' => $sensorId]
                );
                
                return [
                    'success' => true,
                    'message' => 'Подключение успешно',
                    'statistics' => $adapter->getStatistics()
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $adapter->getLastError() ?? 'Неизвестная ошибка'
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Закрытие всех подключений
     * 
     * @return void
     */
    public function closeAllConnections(): void
    {
        IntegrationLogger::info("Закрытие всех подключений опроса", 'DevicePoller');
        
        foreach ($this->adapters as $sensorId => $adapter) {
            if ($adapter->isConnected()) {
                $adapter->disconnect();
            }
        }
        
        $this->adapters = [];
    }

    /**
     * Деструктор
     */
    public function __destruct()
    {
        $this->closeAllConnections();
    }

    /**
     * Получение статистики опроса
     * 
     * @return array Статистика
     */
    public function getStatistics(): array
    {
        return $this->pollStatistics;
    }
}
