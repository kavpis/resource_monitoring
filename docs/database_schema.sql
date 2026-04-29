-- ============================================
-- Система мониторинга ресурсов предприятия
-- Полный SQL-скрипт создания базы данных
-- Версия: 1.0
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Создание базы данных
CREATE DATABASE IF NOT EXISTS `resource_monitoring` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `resource_monitoring`;

-- ============================================
-- Таблица пользователей (users)
-- ============================================
CREATE TABLE `users` (
  `user_id` INT(11) NOT NULL AUTO_INCREMENT,
  `login` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `role` ENUM('admin', 'dispatcher', 'engineer', 'manager') NOT NULL DEFAULT 'dispatcher',
  `zone_access` JSON DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_attempts` INT(11) DEFAULT 0,
  `last_failed_attempt` DATETIME DEFAULT NULL,
  `last_login` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  INDEX `idx_role` (`role`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица зон/участков (zones)
-- ============================================
CREATE TABLE `zones` (
  `zone_id` INT(11) NOT NULL AUTO_INCREMENT,
  `zone_name` VARCHAR(100) NOT NULL,
  `parent_zone_id` INT(11) DEFAULT NULL,
  `zone_type` ENUM('building', 'floor', 'workshop', 'room') NOT NULL DEFAULT 'workshop',
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`zone_id`),
  FOREIGN KEY (`parent_zone_id`) REFERENCES `zones`(`zone_id`) ON DELETE SET NULL,
  INDEX `idx_parent_zone` (`parent_zone_id`),
  INDEX `idx_zone_type` (`zone_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица счётчиков/датчиков (sensors)
-- ============================================
CREATE TABLE `sensors` (
  `sensor_id` INT(11) NOT NULL AUTO_INCREMENT,
  `sensor_name` VARCHAR(100) NOT NULL,
  `zone_id` INT(11) NOT NULL,
  `resource_type` ENUM('water', 'heat', 'electricity') NOT NULL,
  `device_type` ENUM('modbus', 'emulator', 'snmp', 'opcua') NOT NULL DEFAULT 'emulator',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `port` INT(11) DEFAULT 502,
  `slave_id` INT(11) DEFAULT 1,
  `register_address` INT(11) DEFAULT NULL,
  `register_type` ENUM('holding', 'input', 'coil', 'discrete') DEFAULT 'holding',
  `data_type` ENUM('int16', 'uint16', 'int32', 'uint32', 'float32') DEFAULT 'float32',
  `scale_factor` DECIMAL(10,4) DEFAULT 1.0000,
  `unit` VARCHAR(20) DEFAULT NULL,
  `min_value` DECIMAL(15,4) DEFAULT NULL,
  `max_value` DECIMAL(15,4) DEFAULT NULL,
  `warning_threshold` DECIMAL(15,4) DEFAULT NULL,
  `critical_threshold` DECIMAL(15,4) DEFAULT NULL,
  `polling_interval` INT(11) DEFAULT 30,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_reading_time` DATETIME DEFAULT NULL,
  `last_reading_value` DECIMAL(15,4) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sensor_id`),
  FOREIGN KEY (`zone_id`) REFERENCES `zones`(`zone_id`) ON DELETE CASCADE,
  INDEX `idx_zone` (`zone_id`),
  INDEX `idx_resource_type` (`resource_type`),
  INDEX `idx_device_type` (`device_type`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица показаний (readings)
-- ============================================
CREATE TABLE `readings` (
  `reading_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `sensor_id` INT(11) NOT NULL,
  `reading_value` DECIMAL(15,4) NOT NULL,
  `reading_timestamp` DATETIME NOT NULL,
  `quality` ENUM('good', 'uncertain', 'bad') DEFAULT 'good',
  `raw_value` DECIMAL(15,4) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reading_id`),
  FOREIGN KEY (`sensor_id`) REFERENCES `sensors`(`sensor_id`) ON DELETE CASCADE,
  INDEX `idx_sensor_time` (`sensor_id`, `reading_timestamp`),
  INDEX `idx_timestamp` (`reading_timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица аварий/событий (alerts)
-- ============================================
CREATE TABLE `alerts` (
  `alert_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `sensor_id` INT(11) NOT NULL,
  `alert_type` VARCHAR(50) NOT NULL,
  `alert_level` ENUM('warning', 'emergency', 'critical') NOT NULL,
  `description` TEXT NOT NULL,
  `reading_value` DECIMAL(15,4) DEFAULT NULL,
  `threshold_value` DECIMAL(15,4) DEFAULT NULL,
  `is_acknowledged` TINYINT(1) NOT NULL DEFAULT 0,
  `acknowledged_by` INT(11) DEFAULT NULL,
  `acknowledged_at` DATETIME DEFAULT NULL,
  `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
  `resolved_by` INT(11) DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`alert_id`),
  FOREIGN KEY (`sensor_id`) REFERENCES `sensors`(`sensor_id`) ON DELETE CASCADE,
  FOREIGN KEY (`acknowledged_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  FOREIGN KEY (`resolved_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  INDEX `idx_sensor` (`sensor_id`),
  INDEX `idx_alert_level` (`alert_level`),
  INDEX `idx_is_acknowledged` (`is_acknowledged`),
  INDEX `idx_is_resolved` (`is_resolved`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица правил детекции (detection_rules)
-- ============================================
CREATE TABLE `detection_rules` (
  `rule_id` INT(11) NOT NULL AUTO_INCREMENT,
  `rule_name` VARCHAR(100) NOT NULL,
  `resource_type` ENUM('water', 'heat', 'electricity') NOT NULL,
  `rule_type` VARCHAR(50) NOT NULL,
  `condition_json` JSON NOT NULL,
  `alert_level` ENUM('warning', 'emergency', 'critical') NOT NULL DEFAULT 'warning',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rule_id`),
  INDEX `idx_resource_type` (`resource_type`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица аудита (audit_log)
-- ============================================
CREATE TABLE `audit_log` (
  `log_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) DEFAULT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `action_description` TEXT NOT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` INT(11) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `action_timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_action_type` (`action_type`),
  INDEX `idx_timestamp` (`action_timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица отчётов (reports)
-- ============================================
CREATE TABLE `reports` (
  `report_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `report_name` VARCHAR(100) NOT NULL,
  `report_type` VARCHAR(50) NOT NULL,
  `generated_by` INT(11) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_format` ENUM('pdf', 'excel', 'csv', 'json') NOT NULL,
  `date_from` DATE NOT NULL,
  `date_to` DATE NOT NULL,
  `file_size` BIGINT(20) DEFAULT NULL,
  `is_scheduled` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`),
  FOREIGN KEY (`generated_by`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  INDEX `idx_generated_by` (`generated_by`),
  INDEX `idx_report_type` (`report_type`),
  INDEX `idx_date_range` (`date_from`, `date_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица резервных копий (backups)
-- ============================================
CREATE TABLE `backups` (
  `backup_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `backup_name` VARCHAR(100) NOT NULL,
  `backup_type` ENUM('database', 'files', 'full') NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_size` BIGINT(20) DEFAULT NULL,
  `backup_status` ENUM('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`backup_id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  INDEX `idx_backup_type` (`backup_type`),
  INDEX `idx_backup_status` (`backup_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица системных настроек (system_settings)
-- ============================================
CREATE TABLE `system_settings` (
  `setting_id` INT(11) NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NOT NULL,
  `setting_type` ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_by` INT(11) DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  INDEX `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица сессий уведомлений (notification_subscriptions)
-- ============================================
CREATE TABLE `notification_subscriptions` (
  `subscription_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `endpoint` VARCHAR(500) NOT NULL,
  `auth_key` VARCHAR(100) NOT NULL,
  `p256dh_key` VARCHAR(200) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`subscription_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица логов безопасности (security_logs)
-- ============================================
CREATE TABLE `security_logs` (
  `log_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(50) NOT NULL,
  `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `description` TEXT NOT NULL,
  `details` JSON DEFAULT NULL,
  `is_blocked` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_severity` (`severity`),
  INDEX `idx_ip_address` (`ip_address`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Таблица блокировок IP (ip_blocks)
-- ============================================
CREATE TABLE `ip_blocks` (
  `block_id` INT(11) NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `block_reason` VARCHAR(255) NOT NULL,
  `blocked_until` DATETIME NOT NULL,
  `is_permanent` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`block_id`),
  UNIQUE KEY `unique_ip` (`ip_address`),
  INDEX `idx_blocked_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Начальные данные
-- ============================================

-- Администратор по умолчанию (пароль: admin123)
INSERT INTO `users` (`login`, `password_hash`, `full_name`, `email`, `role`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Администратор системы', 'admin@localhost', 'admin'),
('dispatcher', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Диспетчер Цеха №1', 'dispatcher@localhost', 'dispatcher'),
('engineer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Инженер КИПиА', 'engineer@localhost', 'engineer'),
('manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Руководитель предприятия', 'manager@localhost', 'manager');

-- Зоны предприятия
INSERT INTO `zones` (`zone_name`, `zone_type`, `description`) VALUES
('Главный корпус', 'building', 'Основное производственное здание'),
('Цех №1', 'workshop', 'Цех обработки металла', 1),
('Цех №2', 'workshop', 'Сборочный цех', 1),
('Административный блок', 'building', 'Офисные помещения'),
('Котельная', 'workshop', 'Теплоснабжение предприятия');

-- Счётчики (эмуляторы)
INSERT INTO `sensors` (`sensor_name`, `zone_id`, `resource_type`, `device_type`, `unit`, `scale_factor`) VALUES
('Вода общий вход Цех 1', 2, 'water', 'emulator', 'м³', 1.0),
('Вода общий вход Цех 2', 3, 'water', 'emulator', 'м³', 1.0),
('Тепло Цех 1 подача', 2, 'heat', 'emulator', 'Гкал', 0.001),
('Тепло Цех 1 обратка', 2, 'heat', 'emulator', 'Гкал', 0.001),
('Электричество Цех 1', 2, 'electricity', 'emulator', 'кВт·ч', 1.0),
('Электричество Цех 2', 3, 'electricity', 'emulator', 'кВт·ч', 1.0),
('Вода котельная', 5, 'water', 'emulator', 'м³', 1.0),
('Тепло котельная', 5, 'heat', 'emulator', 'Гкал', 0.001);

-- Правила детекции аномалий
INSERT INTO `detection_rules` (`rule_name`, `resource_type`, `rule_type`, `condition_json`, `alert_level`) VALUES
('Утечка воды', 'water', 'flow_anomaly', '{"min_flow": 0.5, "duration_minutes": 30}', 'warning'),
('Перегрев теплоносителя', 'heat', 'temperature_high', '{"max_temp": 95, "critical_temp": 110}', 'emergency'),
('Низкая дельта температур', 'heat', 'delta_low', '{"min_delta": 15}', 'warning'),
('Перегрузка по мощности', 'electricity', 'power_overload', '{"max_power_percent": 90}', 'warning'),
('Критическая перегрузка', 'electricity', 'power_critical', '{"max_power_percent": 100}', 'critical');

-- Системные настройки
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('app_name', 'Система мониторинга ресурсов', 'string', 'Название приложения'),
('data_retention_days', '1095', 'number', 'Срок хранения данных (дни)'),
('default_polling_interval', '30', 'number', 'Интервал опроса датчиков (сек)'),
('enable_notifications', 'true', 'boolean', 'Включить уведомления'),
('backup_schedule', '0 2 * * *', 'string', 'Расписание бэкапов (cron)');

-- ============================================
-- Представления для отчётности
-- ============================================

-- Последние показания по каждому датчику
CREATE OR REPLACE VIEW `v_latest_readings` AS
SELECT 
    s.sensor_id,
    s.sensor_name,
    s.resource_type,
    z.zone_name,
    r.reading_value,
    r.reading_timestamp,
    r.quality
FROM sensors s
JOIN zones z ON s.zone_id = z.zone_id
LEFT JOIN readings r ON s.sensor_id = r.sensor_id
WHERE r.reading_timestamp = (
    SELECT MAX(reading_timestamp) 
    FROM readings r2 
    WHERE r2.sensor_id = s.sensor_id
);

-- Активные аварии
CREATE OR REPLACE VIEW `v_active_alerts` AS
SELECT 
    a.alert_id,
    a.alert_type,
    a.alert_level,
    a.description,
    a.reading_value,
    a.threshold_value,
    s.sensor_name,
    z.zone_name,
    a.created_at
FROM alerts a
JOIN sensors s ON a.sensor_id = s.sensor_id
JOIN zones z ON s.zone_id = z.zone_id
WHERE a.is_resolved = 0
ORDER BY 
    FIELD(a.alert_level, 'critical', 'emergency', 'warning'),
    a.created_at DESC;

-- Статистика по зонам
CREATE OR REPLACE VIEW `v_zone_statistics` AS
SELECT 
    z.zone_id,
    z.zone_name,
    COUNT(DISTINCT s.sensor_id) as sensor_count,
    SUM(CASE WHEN s.resource_type = 'water' THEN 1 ELSE 0 END) as water_sensors,
    SUM(CASE WHEN s.resource_type = 'heat' THEN 1 ELSE 0 END) as heat_sensors,
    SUM(CASE WHEN s.resource_type = 'electricity' THEN 1 ELSE 0 END) as electricity_sensors,
    COUNT(DISTINCT CASE WHEN a.is_resolved = 0 THEN a.alert_id END) as active_alerts
FROM zones z
LEFT JOIN sensors s ON z.zone_id = s.zone_id AND s.is_active = 1
LEFT JOIN alerts a ON s.sensor_id = a.sensor_id AND a.is_resolved = 0
GROUP BY z.zone_id, z.zone_name;

-- ============================================
-- Триггеры для автоматического обновления
-- ============================================

DELIMITER $$

-- Обновление последнего показания в sensors при добавлении reading
CREATE TRIGGER `trg_update_last_reading`
AFTER INSERT ON `readings`
FOR EACH ROW
BEGIN
    UPDATE `sensors`
    SET `last_reading_value` = NEW.reading_value,
        `last_reading_time` = NEW.reading_timestamp
    WHERE `sensor_id` = NEW.sensor_id;
END$$

DELIMITER ;

-- ============================================
-- Завершение скрипта
-- ============================================

SET SQL_MODE = "ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION";

-- Вывод сообщения об успешном создании
SELECT 'База данных resource_monitoring успешно создана!' AS status;
