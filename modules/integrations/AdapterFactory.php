<?php
/**
 * Фабрика адаптеров устройств
 * 
 * Централизованное создание и управление адаптерами для различных
 * типов устройств (Modbus, эмулятор, SNMP, OPC-UA и др.)
 * 
 * @package Modules\Integrations
 */

namespace Modules\Integrations;

use Modules\Integrations\Interfaces\DeviceAdapterInterface;
use Modules\Integrations\Adapters\ModbusAdapter;
use Modules\Integrations\Adapters\EmulatorAdapter;
use Modules\Integrations\Logs\IntegrationLogger;

class AdapterFactory
{
    /**
     * Типы поддерживаемых адаптеров
     */
    private const TYPE_MODBUS = 'modbus';
    private const TYPE_EMULATOR = 'emulator';
    
    /**
     * Кэш созданных экземпляров адаптеров
     */
    private static array $adapterInstances = [];

    /**
     * Создание адаптера по типу
     * 
     * @param string $type Тип адаптера ('modbus', 'emulator')
     * @param bool $singleton Использовать ли одиночный экземпляр
     * @return DeviceAdapterInterface Экземпляр адаптера
     * @throws \InvalidArgumentException Если тип адаптера не поддерживается
     */
    public static function create(string $type, bool $singleton = true): DeviceAdapterInterface
    {
        $type = strtolower($type);
        
        if ($singleton && isset(self::$adapterInstances[$type])) {
            return self::$adapterInstances[$type];
        }

        IntegrationLogger::debug(
            "Создание адаптера типа: {$type}",
            'AdapterFactory'
        );

        switch ($type) {
            case self::TYPE_MODBUS:
                $adapter = new ModbusAdapter();
                break;
                
            case self::TYPE_EMULATOR:
                $adapter = new EmulatorAdapter();
                break;
                
            default:
                $errorMsg = "Неподдерживаемый тип адаптера: {$type}. Доступные типы: modbus, emulator";
                IntegrationLogger::error($errorMsg, 'AdapterFactory');
                throw new \InvalidArgumentException($errorMsg);
        }

        if ($singleton) {
            self::$adapterInstances[$type] = $adapter;
        }

        return $adapter;
    }

    /**
     * Получение адаптера из конфигурации датчика
     * 
     * @param array $sensorConfig Конфигурация датчика из БД
     * @return DeviceAdapterInterface Адаптер для датчика
     */
    public static function createFromSensorConfig(array $sensorConfig): DeviceAdapterInterface
    {
        $connectionType = strtolower($sensorConfig['connection_type'] ?? 'emulator');
        
        IntegrationLogger::info(
            "Создание адаптера для датчика",
            'AdapterFactory',
            [
                'ID_датчика' => $sensorConfig['id'] ?? 'unknown',
                'тип_подключения' => $connectionType,
                'хост' => $sensorConfig['ip_address'] ?? 'localhost',
                'порт' => $sensorConfig['port'] ?? 502
            ]
        );

        $adapter = self::create($connectionType);
        
        // Подключение к устройству
        $host = $sensorConfig['ip_address'] ?? 'localhost';
        $port = (int)($sensorConfig['port'] ?? 502);
        $config = $sensorConfig['config'] ?? [];
        
        $adapter->connect($host, $port, $config);
        
        return $adapter;
    }

    /**
     * Проверка доступности типа адаптера
     * 
     * @param string $type Тип адаптера
     * @return bool Доступен ли тип
     */
    public static function isSupported(string $type): bool
    {
        $type = strtolower($type);
        return in_array($type, [self::TYPE_MODBUS, self::TYPE_EMULATOR]);
    }

    /**
     * Получение списка поддерживаемых типов
     * 
     * @return array Массив типов
     */
    public static function getSupportedTypes(): array
    {
        return [self::TYPE_MODBUS, self::TYPE_EMULATOR];
    }

    /**
     * Закрытие всех активных подключений
     * 
     * @return void
     */
    public static function closeAll(): void
    {
        IntegrationLogger::info("Закрытие всех подключений адаптеров", 'AdapterFactory');
        
        foreach (self::$adapterInstances as $type => $adapter) {
            if ($adapter->isConnected()) {
                $adapter->disconnect();
            }
        }
        
        self::$adapterInstances = [];
    }

    /**
     * Удаление конкретного адаптера из кэша
     * 
     * @param string $type Тип адаптера
     * @return void
     */
    public static function forget(string $type): void
    {
        $type = strtolower($type);
        
        if (isset(self::$adapterInstances[$type])) {
            if (self::$adapterInstances[$type]->isConnected()) {
                self::$adapterInstances[$type]->disconnect();
            }
            unset(self::$adapterInstances[$type]);
            
            IntegrationLogger::debug("Адаптер {$type} удалён из кэша", 'AdapterFactory');
        }
    }

    /**
     * Получение статистики всех адаптеров
     * 
     * @return array Статистика по всем адаптерам
     */
    public static function getAllStatistics(): array
    {
        $statistics = [];
        
        foreach (self::$adapterInstances as $type => $adapter) {
            $statistics[$type] = [
                'statistics' => $adapter->getStatistics(),
                'is_connected' => $adapter->isConnected()
            ];
        }
        
        return $statistics;
    }
}
