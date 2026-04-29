-- security_tables.sql
-- Таблицы для модуля безопасности системы мониторинга ресурсов

-- Таблица для отслеживания неудачных попыток входа (brute force protection)
CREATE TABLE IF NOT EXISTS failed_logins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(100),
    attempts INT DEFAULT 1,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ip (ip_address),
    INDEX idx_last_attempt (last_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица для rate limiting (ограничение частоты запросов)
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(64) NOT NULL,
    endpoint VARCHAR(128) NOT NULL,
    request_count INT DEFAULT 1,
    first_request TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_request TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_identifier_endpoint (identifier, endpoint),
    INDEX idx_last_request (last_request)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица для правил фаервола (whitelist/blacklist)
CREATE TABLE IF NOT EXISTS firewall_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    rule_type ENUM('whitelist', 'blacklist') NOT NULL,
    reason TEXT,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip_type (ip_address, rule_type),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Добавление данных по умолчанию (localhost в whitelist)
INSERT IGNORE INTO firewall_rules (ip_address, rule_type, reason) 
VALUES ('127.0.0.1', 'whitelist', 'Локальный хост');

INSERT IGNORE INTO firewall_rules (ip_address, rule_type, reason) 
VALUES ('::1', 'whitelist', 'Локальный хост IPv6');

-- Индексы для производительности (если еще не созданы в основной схеме)
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_sensors_zone_id ON sensors(zone_id);
CREATE INDEX IF NOT EXISTS idx_readings_sensor_id ON readings(sensor_id);
CREATE INDEX IF NOT EXISTS idx_readings_timestamp ON readings(timestamp);

COMMENT ON TABLE failed_logins IS 'Журнал неудачных попыток входа для защиты от brute force';
COMMENT ON TABLE rate_limits IS 'Счетчики запросов для rate limiting';
COMMENT ON TABLE firewall_rules IS 'Правила фаервола: белые и черные списки IP';
