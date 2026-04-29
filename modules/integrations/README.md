# Модуль интеграций устройств

## Обзор

Модуль интеграций обеспечивает подключение к счётчикам ресурсов предприятия через различные протоколы (Modbus TCP, эмулятор) и предоставляет единый интерфейс для опроса устройств, обработки данных и логирования.

## Архитектура

```
modules/integrations/
├── Interfaces/
│   └── DeviceAdapterInterface.php  # Интерфейс для всех адаптеров
├── Adapters/
│   ├── ModbusAdapter.php           # Адаптер Modbus TCP
│   └── EmulatorAdapter.php         # Адаптер эмулятора
├── Logs/
│   └── IntegrationLogger.php       # Система логирования
├── AdapterFactory.php              # Фабрика адаптеров
├── DevicePoller.php                # Менеджер опроса устройств
└── README.md                       # Этот файл
```

## Компоненты

### 1. Интерфейс DeviceAdapterInterface

Определяет стандартный контракт для всех адаптеров устройств:

- `connect()` — подключение к устройству
- `disconnect()` — отключение
- `isConnected()` — проверка статуса подключения
- `readRegisters()` — чтение регистров
- `writeRegisters()` — запись в регистры
- `getLastError()` — получение последней ошибки
- `getStatistics()` — статистика работы
- `testConnection()` — тестирование соединения

### 2. Адаптер ModbusAdapter

Полноценная реализация протокола Modbus TCP:

- Поддержка Function Codes: 03 (чтение), 16 (запись)
- Автоматические повторные попытки при ошибках
- Проверка целостности данных (Transaction ID)
- Обработка исключений Modbus
- Статистика подключений и запросов

**Пример использования:**

```php
use Modules\Integrations\Adapters\ModbusAdapter;

$adapter = new ModbusAdapter();

// Подключение к устройству
if ($adapter->connect('192.168.1.100', 502)) {
    // Чтение 3 регистров начиная с адреса 0
    $data = $adapter->readRegisters(slaveId: 1, registerAddress: 0, quantity: 3);
    
    if ($data !== false) {
        echo "Данные: " . implode(', ', $data);
    } else {
        echo "Ошибка: " . $adapter->getLastError();
    }
    
    $adapter->disconnect();
}
```

### 3. Адаптер EmulatorAdapter

Имитация работы с реальными устройствами для разработки и тестирования:

- Генерация тестовых данных (вода, тепло, электричество)
- Имитация сетевых задержек
- Кэширование последних показаний
- Полная совместимость с интерфейсом ModbusAdapter

**Пример использования:**

```php
use Modules\Integrations\Adapters\EmulatorAdapter;

$adapter = new EmulatorAdapter();

// Подключение к эмулятору
$adapter->connect('localhost', 8080);

// Получение данных (генерируются автоматически)
$data = $adapter->readRegisters(slaveId: 1, registerAddress: 0, quantity: 5);

// Статистика работы
$stats = $adapter->getStatistics();
print_r($stats);
```

### 4. Фабрика AdapterFactory

Централизованное создание адаптеров:

```php
use Modules\Integrations\AdapterFactory;

// Создание адаптера по типу
$adapter = AdapterFactory::create('modbus');
$adapter = AdapterFactory::create('emulator');

// Создание из конфигурации датчика
$sensorConfig = [
    'id' => 1,
    'connection_type' => 'modbus',
    'ip_address' => '192.168.1.100',
    'port' => 502,
    'config' => ['timeout' => 10]
];
$adapter = AdapterFactory::createFromSensorConfig($sensorConfig);

// Проверка поддерживаемых типов
if (AdapterFactory::isSupported('modbus')) {
    // ...
}
```

### 5. Менеджер опроса DevicePoller

Автоматический опрос всех датчиков:

```php
use Modules\Integrations\DevicePoller;

$poller = new DevicePoller();

// Опрос всех активных датчиков
$results = $poller->pollAllSensors();

echo "Всего датчиков: {$results['statistics']['total_sensors']}";
echo "Успешно: {$results['statistics']['successful_polls']}";
echo "Ошибок: {$results['statistics']['failed_polls']}";

// Тестирование конкретного датчика
$testResult = $poller->testSensorConnection(sensorId: 1);
```

### 6. Логгер IntegrationLogger

Подробное логирование на русском языке:

```php
use Modules\Integrations\Logs\IntegrationLogger;

// Различные уровни логирования
IntegrationLogger::debug("Отладочное сообщение", 'MyComponent');
IntegrationLogger::info("Информационное сообщение", 'MyComponent');
IntegrationLogger::warning("Предупреждение", 'MyComponent');
IntegrationLogger::error("Ошибка", 'MyComponent');
IntegrationLogger::critical("Критическая ошибка", 'MyComponent');

// Специализированные методы
IntegrationLogger::logConnection('192.168.1.100', 502, true, 'ModbusAdapter');
IntegrationLogger::logReadOperation(1, 0, 3, [100, 200, 300], 'ModbusAdapter');

// Получение последних записей лога
$logs = IntegrationLogger::getRecentLogs(lines: 50);

// Очистка старых логов
IntegrationLogger::cleanupOldLogs(days: 30);
```

## Конфигурация

Файл `config/integrations.php` содержит все настройки модуля:

- Параметры подключения (таймауты, попытки)
- Настройки эмулятора
- Параметры Modbus TCP
- Интервалы опроса
- Настройки логирования
- Расширения для будущих протоколов (SNMP, OPC-UA, MQTT)

## Интеграция с системой

### Добавление в cron для автоматического опроса

```bash
# /etc/cron.d/resource_monitor
*/1 * * * * php /workspace/cron/poll_devices.php
```

```php
<?php
// cron/poll_devices.php

require_once __DIR__ . '/../vendor/autoload.php';

use Modules\Integrations\DevicePoller;
use Modules\Integrations\Logs\IntegrationLogger;

try {
    $poller = new DevicePoller();
    $results = $poller->pollAllSensors();
    
    IntegrationLogger::info(
        "Цикл опроса завершён",
        'CronPoller',
        $results['statistics']
    );
    
} catch (\Exception $e) {
    IntegrationLogger::critical(
        "Критическая ошибка опроса: {$e->getMessage()}",
        'CronPoller'
    );
}
```

### Добавление эндпоинтов API

```php
// api.php - добавить маршруты

case '/api/integrations/status':
    $stats = AdapterFactory::getAllStatistics();
    echo json_encode(['success' => true, 'data' => $stats]);
    break;

case '/api/integrations/test/:id':
    $poller = new DevicePoller();
    $result = $poller->testSensorConnection((int)$params['id']);
    echo json_encode($result);
    break;

case '/api/integrations/logs':
    $logs = IntegrationLogger::getRecentLogs(100);
    echo json_encode(['success' => true, 'data' => $logs]);
    break;
```

## Логирование

Все логи сохраняются в `/workspace/logs/integration.log` в формате:

```
[2025-01-15 10:30:45] [INFO] [ModbusAdapter] Успешное подключение к устройству | Контекст: {"хост":"192.168.1.100","порт":502}
[2025-01-15 10:30:46] [DEBUG] [ModbusAdapter] Успешное чтение регистров | Контекст: {"ID_устройства":1,"адрес_регистра":0,"количество":3,"данные":[100,200,300]}
[2025-01-15 10:30:47] [ERROR] [ModbusAdapter] Ошибка чтения регистров | Контекст: {"ID_устройства":1,"адрес_регистра":10,"количество":1}
```

## Расширение для новых протоколов

Для добавления поддержки нового протокола (например, SNMP):

1. Создайте класс адаптера в `Adapters/`:

```php
namespace Modules\Integrations\Adapters;

use Modules\Integrations\Interfaces\DeviceAdapterInterface;

class SnmpAdapter implements DeviceAdapterInterface
{
    // Реализуйте все методы интерфейса
}
```

2. Добавьте тип в `AdapterFactory`:

```php
case 'snmp':
    $adapter = new SnmpAdapter();
    break;
```

3. Добавьте конфигурацию в `config/integrations.php`:

```php
'snmp' => [
    'enabled' => true,
    'version' => '2c',
    'community' => 'public',
],
```

## Будущие расширения

Модуль готов для добавления поддержки:

- **SNMP** — для сетевого оборудования
- **OPC-UA** — для промышленных контроллеров
- **MQTT** — для IoT устройств
- **REST API** — для облачных сервисов
- **BACnet** — для систем автоматизации зданий

## Требования

- PHP 8.0+
- Расширение `sockets` для Modbus TCP
- Доступ к базе данных для сохранения показаний
- Права на запись в директорию `/workspace/logs/`

## Безопасность

- Валидация всех входных данных
- Логирование всех операций
- Защита от повторяющихся ошибок
- Таймауты для предотвращения зависаний
- Разделение прав доступа через роли системы
