# Модуль отчётов — Документация

## Обзор

Модуль отчётов системы мониторинга ресурсов предприятия предназначен для генерации, экспорта и управления отчётами по потреблению ресурсов, аварийным событиям и эффективности работы зон.

## Структура модуля

```
modules/reports/
├── ReportGenerator.php    # Основной генератор отчётов
├── ExportService.php      # Сервис экспорта в различные форматы
└── README.md              # Этот файл

templates/reports/
└── index.php              # Веб-интерфейс отчётов

cron/
└── generate_reports.php   # Скрипт автоматической генерации по расписанию
```

## Типы отчётов

### 1. Отчёт по потреблению ресурсов

**Назначение:** Анализ потребления воды, тепла и электричества за указанный период.

**Параметры:**
- `start_date` — начальная дата периода (формат: YYYY-MM-DD)
- `end_date` — конечная дата периода (формат: YYYY-MM-DD)
- `zone_id` — ID зоны (опционально, по умолчанию все зоны)
- `resource_type` — тип ресурса: `water`, `heat`, `electricity`, `all`
- `group_by` — группировка данных: `hour`, `day`, `week`, `month`
- `aggregation` — тип агрегации: `sum`, `avg`, `max`, `min`
- `format` — формат вывода: `json`, `csv`, `excel`, `pdf`, `xml`

**Пример использования:**
```php
$reportGenerator = new ReportGenerator();
$result = $reportGenerator->generateConsumptionReport([
    'start_date' => '2024-01-01',
    'end_date' => '2024-01-31',
    'resource_type' => 'water',
    'group_by' => 'day',
    'format' => 'json'
]);
```

**Возвращаемые данные:**
- Периодические значения потребления
- Статистика (сумма, среднее, минимум, максимум)
- Информация по зонам и датчикам

---

### 2. Отчёт по аварийным событиям

**Назначение:** Журнал всех аварийных и предупредительных событий за период.

**Параметры:**
- `start_date` — начальная дата периода
- `end_date` — конечная дата периода
- `zone_id` — ID зоны (опционально)
- `alert_level` — уровень аварии: `critical`, `emergency`, `warning`, `all`
- `status` — статус: `active`, `acknowledged`, `resolved`, `all`
- `format` — формат вывода

**Пример использования:**
```php
$result = $reportGenerator->generateAlertsReport([
    'start_date' => '2024-01-01',
    'end_date' => '2024-01-31',
    'alert_level' => 'critical',
    'status' => 'resolved',
    'format' => 'csv'
]);
```

**Возвращаемые данные:**
- Список аварий с детализацией
- Статистика по уровням и статусам
- Информация о разрешении инцидентов

---

### 3. Отчёт по эффективности работы зон

**Назначение:** Оценка эффективности работы цехов и участков.

**Параметры:**
- `start_date` — начальная дата периода
- `end_date` — конечная дата периода
- `zone_id` — ID зоны (опционально)
- `format` — формат вывода

**Метрики эффективности:**
- Процент доступности датчиков
- Плотность аварий на датчик
- Процент разрешения инцидентов
- Количество активных/неактивных датчиков

**Пример использования:**
```php
$result = $reportGenerator->generateEfficiencyReport([
    'start_date' => '2024-01-01',
    'end_date' => '2024-01-31',
    'format' => 'excel'
]);
```

---

### 4. Сводный отчёт по предприятию

**Назначение:** Общая сводка по всем показателям предприятия.

**Параметры:**
- `start_date` — начальная дата периода
- `end_date` — конечная дата периода

**Включает:**
- Потребление по типам ресурсов
- Статистику по зонам
- Статус датчиков
- Статистику по авариям

**Пример использования:**
```php
$result = $reportGenerator->generateEnterpriseSummaryReport([
    'start_date' => '2024-01-01',
    'end_date' => '2024-01-31'
]);
```

---

### 5. Пользовательский отчёт

**Назначение:** Генерация отчёта по произвольному SQL-запросу.

**Параметры:**
- `query` — SELECT-запрос к базе данных
- `start_date` — начальная дата (для подстановки в запрос)
- `end_date` — конечная дата
- `format` — формат вывода

**Пример использования:**
```php
$result = $reportGenerator->generateCustomReport([
    'query' => 'SELECT * FROM sensors WHERE is_active = 1',
    'format' => 'json'
]);
```

**Безопасность:**
- Разрешены только SELECT-запросы
- Блокируются опасные ключевые слова (DROP, DELETE, UPDATE и т.д.)

---

## Форматы экспорта

### JSON
Стандартный формат для интеграции с другими системами.

### CSV
Текстовый формат с разделителями-запятыми. Подходит для импорта в Excel и другие табличные процессоры.

**Кодировка:** UTF-8  
**Разделитель:** запятая  
**Экранирование:** двойные кавычки

### Excel (HTML)
Табличный формат с базовым форматированием.

### PDF
Документ с форматированием для печати и распространения.

### XML
Структурированный формат для обмена данными.

---

## Автоматическая генерация по расписанию

### Настройка cron

Скрипт `cron/generate_reports.php` автоматически генерирует отчёты по расписанию.

**Пример настройки crontab:**

```bash
# Ежедневная генерация отчётов за предыдущий день (в 1:00 ночи)
0 1 * * * /usr/bin/php /path/to/cron/generate_reports.php

# Еженедельная генерация (каждый понедельник в 2:00)
0 2 * * 1 /usr/bin/php /path/to/cron/generate_reports.php

# Ежемесячная генерация (1 числа в 3:00)
0 3 1 * * /usr/bin/php /path/to/cron/generate_reports.php
```

### Типы запланированных отчётов

**Ежедневно:**
- Потребление воды (по часам)
- Потребление тепла (по часам)
- Потребление электричества (по часам)
- Аварийные события

**Еженедельно (по понедельникам):**
- Сводное потребление за неделю (Excel)
- Эффективность работы зон (PDF)

**Ежемесячно (1 числа):**
- Сводное потребление за месяц (Excel)
- Аварии за месяц (PDF)
- Сводный отчёт по предприятию (JSON)

### Логирование

Все операции генерации логируются в файл `logs/reports_cron.log`.

**Уровни логирования:**
- `INFO` — штатные сообщения
- `WARNING` — предупреждения
- `ERROR` — ошибки
- `CRITICAL` — критические ошибки

---

## Веб-интерфейс

Страница отчётов доступна по адресу: `/templates/reports/index.php`

**Функционал интерфейса:**
- Выбор типа отчёта из шаблонов
- Настройка параметров (период, зона, ресурс)
- Предварительный просмотр данных
- Экспорт в выбранный формат
- История сгенерированных отчётов

---

## API эндпоинты

Интеграция через REST API (`api.php?action=reports`):

### Получить список шаблонов отчётов
```
GET /api.php?action=reports&action=get_templates
```

### Сгенерировать отчёт
```
POST /api.php?action=reports&action=generate
Content-Type: application/json

{
    "report_type": "consumption",
    "params": {
        "start_date": "2024-01-01",
        "end_date": "2024-01-31",
        "resource_type": "water",
        "format": "json"
    }
}
```

### Скачать готовый отчёт
```
GET /api.php?action=reports&action=download&file=daily_water_2024-01-15.json
```

### Получить историю отчётов
```
GET /api.php?action=reports&action=history&limit=50
```

---

## Требования к окружению

### PHP
- Версия: 8.0+
- Расширения: PDO, JSON, MBString

### База данных
- MySQL 5.7+ или MariaDB 10.3+
- Таблицы: readings, sensors, zones, alerts, detection_rules, report_history

### Права доступа
- Запись в директорию `uploads/reports/`
- Запись в директорию `logs/`

---

## Примеры использования

### Пример 1: Дневной отчёт по воде
```php
<?php
require_once 'modules/reports/ReportGenerator.php';

$generator = new ReportGenerator();
$report = $generator->generateConsumptionReport([
    'start_date' => date('Y-m-d', strtotime('-1 day')),
    'end_date' => date('Y-m-d', strtotime('-1 day')),
    'resource_type' => 'water',
    'group_by' => 'hour',
    'format' => 'csv'
]);

if ($report['success']) {
    echo "Отчёт сгенерирован: " . $report['filename'];
} else {
    echo "Ошибка: " . $report['message'];
}
?>
```

### Пример 2: Месячный отчёт по авариям с экспортом
```php
<?php
require_once 'modules/reports/ReportGenerator.php';
require_once 'modules/reports/ExportService.php';

$generator = new ReportGenerator();
$exporter = new ExportService();

// Генерация
$report = $generator->generateAlertsReport([
    'start_date' => '2024-01-01',
    'end_date' => '2024-01-31',
    'format' => 'excel'
]);

// Экспорт
if ($report['success']) {
    $result = $exporter->exportToFile('alerts', [
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
        'format' => 'excel'
    ]);
    
    if ($result['success']) {
        echo "Файл доступен для скачивания: " . $result['download_url'];
    }
}
?>
```

### Пример 3: Интеграция с внешним приложением
```php
<?php
// Получение данных для внешнего дашборда
header('Content-Type: application/json');

require_once 'modules/reports/ReportGenerator.php';

$generator = new ReportGenerator();
$report = $generator->generateEnterpriseSummaryReport([
    'start_date' => date('Y-m-01'),
    'end_date' => date('Y-m-d')
]);

echo json_encode($report);
?>
```

---

## Устранение неполадок

### Ошибка: "Не удалось создать файл для экспорта"
**Решение:** Проверьте права на запись в директорию `uploads/reports/`

```bash
chmod 755 uploads/reports/
chown www-data:www-data uploads/reports/
```

### Ошибка: "Превышен лимит памяти"
**Решение:** Увеличьте лимит памяти в php.ini или настройте выборку данных порциями

```ini
memory_limit = 512M
```

### Ошибка: "Таймаут выполнения скрипта"
**Решение:** Для больших отчётов увеличьте максимальное время выполнения

```php
set_time_limit(300); // 5 минут
```

### Отчёт пустой
**Возможные причины:**
- Нет данных за указанный период
- Неправильно выбран фильтр (зона, тип ресурса)
- Датчики не активны

**Решение:** Проверьте наличие данных в базе данных:
```sql
SELECT COUNT(*) FROM readings 
WHERE reading_timestamp BETWEEN '2024-01-01' AND '2024-01-31';
```

---

## Контакты и поддержка

По вопросам работы модуля отчётов обращайтесь к разработчику системы мониторинга.

**Версия модуля:** 1.0.0  
**Дата обновления:** 2024
