# Модуль безопасности системы мониторинга ресурсов

## Обзор

Модуль безопасности обеспечивает комплексную защиту системы мониторинга от различных типов атак и несанкционированного доступа.

## Компоненты

### 1. BruteForceProtector.php
**Защита от подбора паролей**
- Отслеживание неудачных попыток входа по IP
- Временная блокировка после 5 неудачных попыток (15 минут)
- Автоматический сброс счетчика через 1 час
- Методы:
  - `isBlocked($ip)` - проверка блокировки
  - `recordFailure($ip, $username)` - запись неудачной попытки
  - `recordSuccess($ip)` - очистка при успешном входе
  - `getRemainingLockoutTime($ip)` - время до разблокировки

### 2. SecurityLogger.php
**Логирование событий безопасности**
- Уровни логирования: debug, info, warning, error, critical
- Запись в файл `/workspace/logs/security.log`
- Автоматическая ротация логов (30 дней)
- Фильтрация по уровню, дате, категории
- Все сообщения на русском языке

### 3. InputValidator.php
**Валидация и очистка входных данных**
- Защита от XSS-атак
- Обнаружение SQL-инъекций
- Валидация email, IP, URL, целых чисел
- Очистка имен файлов
- Массовая валидация массивов с правилами

### 4. RateLimiter.php
**Ограничение частоты запросов**
- Настройка лимитов по типам эндпоинтов:
  - default: 100 запросов/мин
  - api: 60 запросов/мин
  - login: 5 запросов/5 мин
  - export: 10 запросов/5 мин
- Автоматический сброс счетчиков
- Хранение статистики в БД

### 5. SecurityHeaders.php
**HTTP-заголовки безопасности**
- X-XSS-Protection
- X-Frame-Options (SAMEORIGIN/DENY)
- X-Content-Type-Options (nosniff)
- Content-Security-Policy (CSP)
- Strict-Transport-Security (HSTS)
- Referrer-Policy
- Permissions-Policy

### 6. Firewall.php
**Базовый фаервол**
- Whitelist и blacklist IP-адресов
- Обнаружение directory traversal атак
- Блокировка сканеров уязвимостей (sqlmap, nikto, nmap)
- Временные правила с истечением срока
- Проверка User-Agent на вредоносных ботов

### 7. SecurityMiddleware.php
**Центральный middleware**
- Интеграция всех компонентов безопасности
- Автоматическая установка заголовков
- Проверка фаервола, rate limiting, brute force
- Обработка успешных/неудачных входов
- Валидация входных данных
- Cron-метод для плановой очистки

## Установка

### 1. Создание таблиц БД

```bash
mysql -u root -p database_name < /workspace/modules/security/security_tables.sql
```

### 2. Подключение в точках входа

**api.php:**
```php
require_once 'modules/security/SecurityMiddleware.php';

use Modules\Security\SecurityMiddleware;

$security = new SecurityMiddleware();
$result = $security->handleRequest();

if (!$result['allowed']) {
    http_response_code($result['code']);
    echo json_encode(['error' => $result['reason']]);
    exit;
}
```

**auth.php (обработка входа):**
```php
use Modules\Security\SecurityMiddleware;

$security = new SecurityMiddleware();

// При неудачном входе
if (!$authenticated) {
    $security->onFailedLogin($username);
    // обработка ошибки
}

// При успешном входе
$security->onSuccessfulLogin();
```

### 3. Настройка cron

Добавьте в crontab для ежедневной очистки:

```bash
0 2 * * * /usr/bin/php /workspace/modules/security/cron/security_cleanup.php
```

## Использование

### Валидация входных данных

```php
use Modules\Security\InputValidator;

$rules = [
    'email' => 'required|email|max_length:100',
    'password' => 'required|min_length:8',
    'ip' => 'ip',
    'comment' => 'sql_safe|max_length:500'
];

$result = InputValidator::validateArray($_POST, $rules);

if (!$result['valid']) {
    // Обработка ошибок
    print_r($result['errors']);
} else {
    // Использование очищенных данных
    $cleanData = $result['data'];
}
```

### Логирование событий

```php
use Modules\Security\SecurityLogger;

// Разные уровни важности
SecurityLogger::log('Категория', 'Сообщение', 'info');
SecurityLogger::log('Авария', 'Критическое событие', 'critical', ['context' => 'data']);
SecurityLogger::log('Предупреждение', 'Подозрительная активность', 'warning');

// Получение логов
$logs = SecurityLogger::getLogs(
    $startDate = '2025-01-01',
    $endDate = '2025-01-31',
    $level = 'warning',
    $limit = 50
);
```

### Управление фаерволом

```php
use Modules\Security\Firewall;

$firewall = new Firewall();

// Добавление в blacklist
$firewall->addToBlacklist('192.168.1.100', 'Подозрительная активность', 3600);

// Добавление в whitelist
$firewall->addToWhitelist('10.0.0.1', 'Сервер мониторинга');

// Удаление из blacklist
$firewall->removeFromBlacklist('192.168.1.100');

// Статистика
$stats = $firewall->getStats();
```

### Rate Limiting

```php
use Modules\Security\RateLimiter;

$rateLimiter = new RateLimiter();

// Проверка лимита
$result = $rateLimiter->checkLimit('192.168.1.1', 'api');

if (!$result['allowed']) {
    header('Retry-After: ' . ($result['reset'] - time()));
    http_response_code(429);
    echo json_encode(['error' => 'Слишком много запросов']);
    exit;
}

// Доступные заголовки для клиента
header('X-RateLimit-Limit: 60');
header('X-RateLimit-Remaining: ' . $result['remaining']);
header('X-RateLimit-Reset: ' . $result['reset']);
```

## Структура файлов

```
modules/security/
├── BruteForceProtector.php      # Защита от подбора паролей
├── SecurityLogger.php           # Логирование событий
├── InputValidator.php           # Валидация данных
├── RateLimiter.php              # Ограничение запросов
├── SecurityHeaders.php          # HTTP заголовки
├── Firewall.php                 # Фаервол
├── SecurityMiddleware.php       # Центральный middleware
├── security_tables.sql          # SQL-скрипт таблиц
└── cron/
    └── security_cleanup.php     # Cron-скрипт очистки
```

## Логи

Все логи пишутся в `/workspace/logs/security.log` в формате:

```
[2025-01-15 10:30:45] [WARNING] [Фаервол] [IP: 192.168.1.100] [User: guest] Обнаружен подозрительный запрос: /../../../etc/passwd
[2025-01-15 10:31:00] [CRITICAL] [SQL-инъекция] [IP: 192.168.1.101] [User: guest] Попытка SQL-инъекции: ' OR 1=1 --
[2025-01-15 10:32:15] [INFO] [Rate Limit] [IP: 192.168.1.102] [User: 5] Превышен лимит запросов для API
```

## Рекомендации по безопасности

1. **Продакшен**: Уберите `'unsafe-inline'` и `'unsafe-eval'` из CSP политики
2. **HTTPS**: Всегда используйте HTTPS в продакшене для работы HSTS
3. **Cron**: Настройте ежедневный запуск `security_cleanup.php`
4. **Мониторинг**: Регулярно проверяйте логи безопасности
5. **Whitelist**: Добавьте доверенные IP в белый список
6. **Rate Limiting**: Настройте лимиты под вашу нагрузку

## Интеграция с существующей системой

Модуль полностью совместим с существующей архитектурой системы мониторинга и не требует изменений в базовых компонентах (Database, Auth, Models).
