<?php
/**
 * SecurityHeaders.php
 * Установка заголовков безопасности (CSP, HSTS, X-Frame-Options и др.)
 */

namespace Modules\Security;

class SecurityHeaders
{
    /**
     * Установка всех заголовков безопасности
     */
    public static function setHeaders()
    {
        // Защита от XSS
        header('X-XSS-Protection: 1; mode=block');
        
        // Запрет контента в iframe (защита от clickjacking)
        header('X-Frame-Options: SAMEORIGIN');
        
        // Запрет MIME-type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Content Security Policy (CSP)
        $cspPolicy = self::getCspPolicy();
        header("Content-Security-Policy: $cspPolicy");
        
        // HTTP Strict Transport Security (HSTS) - только для HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        
        // Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Permissions Policy (бывший Feature Policy)
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        
        // Удаление заголовка о версии PHP
        header_remove('X-Powered-By');
        
        SecurityLogger::log('Заголовки безопасности', 'Установлены заголовки безопасности', 'debug');
    }

    /**
     * Получение политики CSP
     */
    private static function getCspPolicy()
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $self = "'self'";
        $unsafeInline = "'unsafe-inline'"; // Для совместимости с inline-скриптами (лучше убрать в продакшене)
        $unsafeEval = "'unsafe-eval'";     // Для eval() (лучше убрать в продакшене)
        
        $policies = [
            "default-src $self",
            "script-src $self $unsafeInline $unsafeEval https://cdn.jsdelivr.net",
            "style-src $self $unsafeInline https://cdn.jsdelivr.net",
            "img-src $self data: https:",
            "font-src $self https://cdn.jsdelivr.net",
            "connect-src $self {$protocol}://{$host} ws://{$host} wss://{$host}",
            "frame-ancestors $self",
            "base-uri $self",
            "form-action $self"
        ];
        
        return implode('; ', $policies);
    }

    /**
     * Установка заголовков для API ответов
     */
    public static function setApiHeaders()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        
        // CORS настройки (при необходимости)
        // header('Access-Control-Allow-Origin: https://trusted-domain.com');
        // header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        // header('Access-Control-Allow-Headers: Content-Type, Authorization');
        // header('Access-Control-Allow-Credentials: true');
        
        SecurityLogger::log('API заголовки', 'Установлены заголовки для API', 'debug');
    }

    /**
     * Установка заголовков для скачивания файлов
     */
    public static function setDownloadHeaders($filename, $mimeType = 'application/octet-stream')
    {
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        
        SecurityLogger::log('Заголовки скачивания', "Подготовлено скачивание файла: $filename", 'info');
    }

    /**
     * Проверка наличия необходимых заголовков
     */
    public static function auditHeaders()
    {
        $required = [
            'X-XSS-Protection',
            'X-Frame-Options',
            'X-Content-Type-Options',
            'Content-Security-Policy',
            'Referrer-Policy'
        ];
        
        $missing = [];
        foreach ($required as $header) {
            $found = false;
            foreach (headers_list() as $h) {
                if (stripos($h, $header . ':') === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $header;
            }
        }
        
        if (!empty($missing)) {
            SecurityLogger::log('Аудит заголовков', "Отсутствуют заголовки: " . implode(', ', $missing), 'warning');
            return ['status' => 'fail', 'missing' => $missing];
        }
        
        SecurityLogger::log('Аудит заголовков', 'Все необходимые заголовки присутствуют', 'info');
        return ['status' => 'ok', 'missing' => []];
    }
}
