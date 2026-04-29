<?php
/**
 * InputValidator.php
 * Защита от SQL-инъекций, XSS и других атак через входные данные
 */

namespace Modules\Security;

class InputValidator
{
    /**
     * Очистка строки от XSS
     */
    public static function sanitizeString($input)
    {
        if (!is_string($input)) {
            return $input;
        }
        
        // Удаление тегов script и опасных атрибутов
        $input = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $input);
        $input = preg_replace('/on\w+="[^"]*"/i', '', $input);
        $input = preg_replace('/javascript:/i', '', $input);
        
        // HTML-кодирование специальных символов
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return trim($input);
    }

    /**
     * Проверка на SQL-инъекцию (базовая эвристика)
     */
    public static function detectSqlInjection($input)
    {
        if (!is_string($input)) {
            return false;
        }

        $patterns = [
            '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|ALTER|CREATE|TRUNCATE)\b)/i',
            '/(--|#|\/\*)/',  // SQL комментарии
            '/(\b(OR|AND)\b\s+\d+\s*=\s*\d+)/i',  // OR 1=1
            '/(\')\s*(OR|AND)\s*(\'|\d)/i',  // ' OR '
            '/(;)\s*(SELECT|INSERT|UPDATE|DELETE|DROP)/i',  // ; SELECT
            '/(\bEXEC\b|\bEXECUTE\b)/i',  // EXEC команды
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                SecurityLogger::log('SQL-инъекция', "Обнаружена попытка SQL-инъекции: $input", 'critical', ['input' => substr($input, 0, 100)]);
                return true;
            }
        }

        return false;
    }

    /**
     * Проверка email
     */
    public static function isValidEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Проверка IP адреса
     */
    public static function isValidIp($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Проверка целого числа
     */
    public static function isInteger($value)
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Проверка URL
     */
    public static function isValidUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Очистка имени файла
     */
    public static function sanitizeFilename($filename)
    {
        // Разрешаем только буквы, цифры, дефис, подчеркивание и точку
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
        // Удаляем множественные точки
        $filename = preg_replace('/\.+/', '.', $filename);
        // Удаляем точку в начале
        $filename = ltrim($filename, '.');
        
        return $filename;
    }

    /**
     * Валидация массива данных
     */
    public static function validateArray($data, $rules)
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $ruleSet) {
            $value = $data[$field] ?? null;
            $fieldRules = explode('|', $ruleSet);

            foreach ($fieldRules as $rule) {
                // required
                if ($rule === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = "Поле '$field' обязательно для заполнения";
                    continue 2;
                }

                // email
                if ($rule === 'email' && $value !== '' && !self::isValidEmail($value)) {
                    $errors[$field][] = "Поле '$field' должно быть корректным email";
                }

                // integer
                if ($rule === 'integer' && $value !== '' && !self::isInteger($value)) {
                    $errors[$field][] = "Поле '$field' должно быть целым числом";
                }

                // ip
                if ($rule === 'ip' && $value !== '' && !self::isValidIp($value)) {
                    $errors[$field][] = "Поле '$field' должно быть IP-адресом";
                }

                // min_length:X
                if (preg_match('/min_length:(\d+)/', $rule, $matches)) {
                    $minLen = (int)$matches[1];
                    if (strlen($value) < $minLen) {
                        $errors[$field][] = "Поле '$field' должно содержать минимум $minLen символов";
                    }
                }

                // max_length:X
                if (preg_match('/max_length:(\d+)/', $rule, $matches)) {
                    $maxLen = (int)$matches[1];
                    if (strlen($value) > $maxLen) {
                        $errors[$field][] = "Поле '$field' должно содержать максимум $maxLen символов";
                    }
                }

                // sql_safe - проверка на SQL инъекции
                if ($rule === 'sql_safe' && self::detectSqlInjection($value)) {
                    $errors[$field][] = "Поле '$field' содержит недопустимые символы";
                }
            }

            // Если нет ошибок, добавляем очищенное значение
            if (!isset($errors[$field])) {
                $validated[$field] = is_string($value) ? self::sanitizeString($value) : $value;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $validated
        ];
    }
}
