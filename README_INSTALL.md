# 📋 Руководство по запуску и проверке системы

## ✅ Исправленные ошибки

### 1. Ошибки в cron-скриптах
Исправлены проблемы с повторным определением констант `ROOT_PATH` и ошибочным запуском сессий в CLI-режиме:
- `cron/collect_data.php` — добавлена проверка на уже определённые константы
- `cron/detect_anomalies.php` — аналогично
- `core/Auth.php` — добавлена проверка `CLI_MODE` для пропуска сессий

### 2. Ошибка Chart.js
Библиотека Chart.js отсутствовала в проекте. Исправлено:
- Создана директория `public/lib/`
- Загружен файл `Chart.bundle.min.js` (версия 2.9.4)
- Создан пустой файл `public/js/alerts.js` (требуется в layout.php)
- Добавлены заглушки для `logo.png` и `alert.mp3`

---

## 🚀 Инструкция по запуску через XAMPP

### Шаг 1: Установка XAMPP
1. Скачайте XAMPP с официального сайта: https://www.apachefriends.org/
2. Установите XAMPP в директорию `C:\xampp\`
3. Запустите **XAMPP Control Panel**
4. Нажмите **Start** для модулей **Apache** и **MySQL**

### Шаг 2: Размещение файлов проекта
1. Скопируйте всю папку проекта `resource_monitoring` в директорию:
   ```
   C:\xampp\htdocs\resource_monitoring\
   ```
2. Убедитесь, что структура папок выглядит так:
   ```
   C:\xampp\htdocs\resource_monitoring\
   ├── index.php
   ├── api.php
   ├── config/
   ├── core/
   ├── cron/
   ├── modules/
   ├── public/
   │   ├── css/
   │   ├── js/
   │   ├── lib/          ← здесь должен быть Chart.bundle.min.js
   │   ├── img/
   │   └── audio/
   ├── templates/
   └── logs/
   ```

### Шаг 3: Настройка базы данных
1. Откройте браузер и перейдите по адресу:
   ```
   http://localhost/phpmyadmin
   ```
2. Создайте новую базу данных:
   - Имя: `resource_monitoring`
   - Кодировка: `utf8mb4_general_ci`
3. Найдите SQL-файл с структурой БД (в папке `docs/` или `config/`) и импортируйте его:
   - Выберите базу данных `resource_monitoring`
   - Перейдите на вкладку **Импорт**
   - Выберите файл `.sql` и нажмите **Вперёд**

### Шаг 4: Настройка подключения к БД
Откройте файл `config/constants.php` и проверьте параметры:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'resource_monitoring');
define('DB_USER', 'root');
define('DB_PASS', ''); // Пустой пароль по умолчанию в XAMPP
```

### Шаг 5: Проверка прав доступа
Убедитесь, что следующие папки существуют и доступны для записи:
```
C:\xampp\htdocs\resource_monitoring\logs\
C:\xampp\htdocs\resource_monitoring\cache\
C:\xampp\htdocs\resource_monitoring\backups\
C:\xampp\htdocs\resource_monitoring\uploads\
```

Если папок нет — создайте их вручную.

### Шаг 6: Первый вход в систему
1. Откройте браузер и перейдите по адресу:
   ```
   http://localhost/resource_monitoring
   ```
2. Если всё настроено правильно, вы увидите страницу входа
3. Войдите под учётной записью администратора:
   - Логин: `admin`
   - Пароль: `admin123` (или тот, который вы задали при настройке)

---

## 🧪 Проверка работоспособности

### 1. Проверка сбора данных (вручную)
Откройте командную строку Windows (cmd) и выполните:
```cmd
cd C:\xampp\htdocs\resource_monitoring
C:\xampp\php\php.exe cron\collect_data.php
```

**Ожидаемый результат:**
```
=== Запуск автоматического сбора данных ===
Время запуска: 2026-04-29 20:00:00
Начало сбора данных...

=== Результаты сбора данных ===
Время окончания: 2026-04-29 20:00:01
Время выполнения: 0.5 сек
Всего счётчиков: 5
Успешно собрано: 5
Ошибок: 0

=== Сбор данных завершён ===
```

### 2. Проверка детекции аномалий (вручную)
```cmd
C:\xampp\php\php.exe cron\detect_anomalies.php
```

**Ожидаемый результат:**
```
=== Запуск автоматического анализа аномалий ===
Время запуска: 2026-04-29 20:05:00
Инициализация детекторов... ✅
Начало анализа данных...

📊 Результаты анализа:
  - Проанализировано счётчиков: 5
  - Обнаружено аномалий: 0
  - Время выполнения: 0.2 сек
  - Аномалий не обнаружено ✅

=== Анализ аномалий завершён ===
```

### 3. Проверка веб-интерфейса
1. Откройте `http://localhost/resource_monitoring`
2. Убедитесь, что:
   - ✅ Страница входа отображается корректно
   - ✅ После входа открывается дашборд
   - ✅ Графики строятся (нет ошибок Chart.js в консоли)
   - ✅ Статистика загружается (карточки с цифрами)
   - ✅ Боковое меню работает
   - ✅ Нет ошибок JavaScript в консоли разработчика (F12)

### 4. Проверка журналов логов
Откройте файлы в папке `logs/`:
```
C:\xampp\htdocs\resource_monitoring\logs\
├── collect_data_2026-04-29.log    ← логи сбора данных
├── anomaly_detection_2026-04-29.log ← логи анализа
├── security.log                    ← логи безопасности
└── system.log                      ← общие системные логи
```

### 5. Проверка API
Откройте в браузере (после авторизации):
```
http://localhost/resource_monitoring/api.php/dashboard/stats
```
Должен вернуться JSON с данными статистики.

### 6. Проверка отчётов
1. В веб-интерфейсе перейдите в раздел **Отчёты**
2. Попробуйте сгенерировать отчёт за текущий день
3. Проверьте, что файл отчёта создаётся в папке `uploads/reports/`

---

## 🔧 Настройка автоматического запуска (cron)

### Для Windows (Планировщик заданий)
1. Откройте **Планировщик заданий** (Task Scheduler)
2. Создайте простую задачу:
   - **Имя**: `Resource Monitoring - Сбор данных`
   - **Триггер**: Ежедневно, повторять каждые 30 минут
   - **Действие**: Запуск программы
     - Программа: `C:\xampp\php\php.exe`
     - Аргументы: `C:\xampp\htdocs\resource_monitoring\cron\collect_data.php`
     - Рабочая папка: `C:\xampp\htdocs\resource_monitoring\cron\`

3. Создайте вторую задачу для анализа аномалий:
   - **Имя**: `Resource Monitoring - Анализ аномалий`
   - **Триггер**: Ежедневно, повторять каждые 5 минут
   - **Действие**: Запуск программы
     - Программа: `C:\xampp\php\php.exe`
     - Аргументы: `C:\xampp\htdocs\resource_monitoring\cron\detect_anomalies.php`

### Для Linux (crontab)
```bash
# Откройте crontab
crontab -e

# Добавьте строки:
*/30 * * * * /usr/bin/php /path/to/resource_monitoring/cron/collect_data.php >> /var/log/collect_data.log 2>&1
*/5 * * * * /usr/bin/php /path/to/resource_monitoring/cron/detect_anomalies.php >> /var/log/anomaly_detection.log 2>&1
```

---

## ❌ Возможные ошибки и решения

### Ошибка: "Constant ROOT_PATH already defined"
**Решение:** Исправлено в файлах `cron/*.php`. Обновите файлы из репозитория.

### Ошибка: "Session cannot be started after headers have already been sent"
**Решение:** Исправлено в `core/Auth.php`. Теперь сессии не запускаются в CLI-режиме.

### Ошибка: "Uncaught SyntaxError: Unexpected token '<'" для Chart.js
**Решение:** Файл `Chart.bundle.min.js` отсутствовал. Скачайте его вручную или используйте CDN.

### Ошибка: "Chart is not defined"
**Решение:** Убедитесь, что путь к Chart.js правильный:
```html
<script src="/resource_monitoring/public/lib/Chart.bundle.min.js"></script>
```

### Ошибка: "Failed to load resource: net::ERR_BLOCKED_BY_CLIENT"
**Решение:** Это блокировка расширениями браузера (AdBlock и т.п.). Отключите расширения для localhost.

### Ошибка: "Access denied for user 'root'@'localhost'"
**Решение:** Проверьте пароль в `config/constants.php`. По умолчанию в XAMPP пароль пустой.

### Ошибка: "Table 'resource_monitoring.users' doesn't exist"
**Решение:** Импортируйте SQL-скрипт создания таблиц через phpMyAdmin.

---

## 📊 Контрольный список готовности

- [ ] XAMPP установлен, Apache и MySQL запущены
- [ ] Файлы скопированы в `htdocs/resource_monitoring/`
- [ ] База данных создана и таблицы импортированы
- [ ] Файл `Chart.bundle.min.js` присутствует в `public/lib/`
- [ ] Папки `logs/`, `cache/`, `uploads/` существуют
- [ ] Вход в систему работает (admin/admin123)
- [ ] Дашборд отображается без ошибок JS
- [ ] Скрипт `collect_data.php` выполняется без ошибок
- [ ] Скрипт `detect_anomalies.php` выполняется без ошибок
- [ ] Графики строятся корректно
- [ ] Логи записываются в папку `logs/`

---

## 📞 Контакты для поддержки

При возникновении проблем:
1. Проверьте логи в папке `logs/`
2. Откройте консоль разработчика (F12) и проверьте ошибки JS
3. Убедитесь, что все файлы на месте
4. Перезапустите Apache и MySQL в XAMPP Control Panel
