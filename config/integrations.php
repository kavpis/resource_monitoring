<?php
/**
 * Конфигурация модуля интеграций
 * 
 * Содержит настройки для подключения к устройствам, параметры опроса,
 * таймауты и другие параметры модуля.
 * 
 * @package Config
 */

return [
    /**
     * Настройки подключений по умолчанию
     */
    'connection' => [
        // Тип подключения по умолчанию (modbus, emulator)
        'default_type' => 'emulator',
        
        // Таймаут подключения в секундах
        'timeout' => 5,
        
        // Количество попыток при ошибке
        'retry_count' => 3,
        
        // Пауза между попытками в миллисекундах
        'retry_delay_ms' => 100,
    ],

    /**
     * Настройки эмулятора
     */
    'emulator' => [
        // URL API эмулятора
        'api_url' => 'http://localhost:8080',
        
        // Имитировать задержку сети
        'simulate_latency' => true,
        
        // Минимальная задержка в мс
        'min_latency_ms' => 50,
        
        // Максимальная задержка в мс
        'max_latency_ms' => 200,
    ],

    /**
     * Настройки Modbus TCP
     */
    'modbus' => [
        // Порт по умолчанию
        'default_port' => 502,
        
        // Таймаут чтения в секундах
        'read_timeout' => 5,
        
        // Таймаут записи в секундах
        'write_timeout' => 5,
        
        // Поддерживаемые Function Codes
        'supported_functions' => [
            3 => 'Read Holding Registers',
            4 => 'Read Input Registers',
            6 => 'Write Single Register',
            16 => 'Write Multiple Registers'
        ],
    ],

    /**
     * Настройки опроса устройств
     */
    'polling' => [
        // Интервал опроса в секундах
        'interval' => 60,
        
        // Максимальное количество датчиков за один цикл
        'max_sensors_per_cycle' => 100,
        
        // Таймаут на опрос одного датчика в секундах
        'sensor_timeout' => 10,
        
        // Кэшировать данные (в секундах)
        'cache_ttl' => 30,
    ],

    /**
     * Настройки логирования
     */
    'logging' => [
        // Путь к файлу лога
        'log_file' => '/workspace/logs/integration.log',
        
        // Уровень логирования (DEBUG, INFO, WARNING, ERROR, CRITICAL)
        'level' => 'DEBUG',
        
        // Максимальный размер файла лога в байтах (для ротации)
        'max_size' => 10485760, // 10 MB
        
        // Количество файлов лога для хранения
        'backup_count' => 5,
        
        // Хранить логи за последние N дней
        'retention_days' => 30,
    ],

    /**
     * Настройки базы данных для сохранений показаний
     */
    'database' => [
        // Таблица для хранений показаний
        'readings_table' => 'readings',
        
        // Таблица для хранения датчиков
        'sensors_table' => 'sensors',
        
        // Пакетная вставка (количество записей за раз)
        'batch_size' => 100,
    ],

    /**
     * Обработка ошибок
     */
    'error_handling' => [
        // Продолжать опрос при ошибке с одним датчиком
        'continue_on_error' => true,
        
        // Максимальное количество последовательных ошибок перед остановкой
        'max_consecutive_errors' => 10,
        
        // Уведомлять об ошибках (true/false)
        'notify_on_error' => true,
        
        // Коды ошибок для игнорирования
        'ignore_error_codes' => [],
    ],

    /**
     * Расширения для будущих протоколов
     */
    'extensions' => [
        // SNMP поддержка (планируется)
        'snmp' => [
            'enabled' => false,
            'version' => '2c',
            'community' => 'public',
            'timeout' => 5,
            'retries' => 3,
        ],
        
        // OPC-UA поддержка (планируется)
        'opcua' => [
            'enabled' => false,
            'security_mode' => 'None',
            'security_policy' => 'None',
        ],
        
        // MQTT поддержка (планируется)
        'mqtt' => [
            'enabled' => false,
            'broker_host' => 'localhost',
            'broker_port' => 1883,
            'client_id' => 'resource_monitor',
            'qos' => 1,
        ],
    ],
];
