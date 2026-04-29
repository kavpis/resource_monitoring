<?php
/**
 * SecurityMiddleware.php
 * Центральный middleware для применения всех мер безопасности
 */

namespace Modules\Security;

class SecurityMiddleware
{
    private $firewall;
    private $rateLimiter;
    private $bruteForceProtector;

    public function __construct()
    {
        $this->firewall = new Firewall();
        $this->rateLimiter = new RateLimiter();
        $this->bruteForceProtector = new BruteForceProtector();
    }

    /**
     * Применение всех мер безопасности к запросу
     * @return array ['allowed' => bool, 'reason' => string, 'code' => int]
     */
    public function handleRequest()
    {
        // 1. Установка заголовков безопасности
        SecurityHeaders::setHeaders();

        // 2. Проверка фаервола
        $firewallResult = $this->firewall->checkRequest();
        if (!$firewallResult['allowed']) {
            http_response_code(403);
            return [
                'allowed' => false,
                'reason' => $firewallResult['reason'],
                'code' => 403
            ];
        }

        // 3. Проверка rate limiting
        $ip = $this->getClientIp();
        $endpoint = $this->detectEndpoint();
        
        $rateLimitResult = $this->rateLimiter->checkLimit($ip, $endpoint);
        if (!$rateLimitResult['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . ($rateLimitResult['reset'] - time()));
            
            SecurityLogger::log('Rate Limit', "Превышен лимит запросов для IP: $ip", 'warning');
            
            return [
                'allowed' => false,
                'reason' => 'Слишком много запросов',
                'code' => 429,
                'retry_after' => $rateLimitResult['reset'] - time()
            ];
        }

        // 4. Проверка блокировки по brute force (для эндпоинтов входа)
        if ($this->isLoginEndpoint()) {
            if ($this->bruteForceProtector->isBlocked($ip)) {
                $remainingTime = $this->bruteForceProtector->getRemainingLockoutTime($ip);
                http_response_code(423);
                
                return [
                    'allowed' => false,
                    'reason' => "IP временно заблокирован. Попробуйте через " . ceil($remainingTime / 60) . " мин.",
                    'code' => 423,
                    'retry_after' => $remainingTime
                ];
            }
        }

        // Добавляем заголовки rate limiting в ответ
        header('X-RateLimit-Limit: ' . $this->getLimitForEndpoint($endpoint));
        header('X-RateLimit-Remaining: ' . $rateLimitResult['remaining']);
        header('X-RateLimit-Reset: ' . $rateLimitResult['reset']);

        return [
            'allowed' => true,
            'reason' => 'Запрос разрешен',
            'code' => 200
        ];
    }

    /**
     * Обработка успешного входа
     */
    public function onSuccessfulLogin()
    {
        $ip = $this->getClientIp();
        $this->bruteForceProtector->recordSuccess($ip);
        $this->rateLimiter->resetLimit($ip, 'login');
    }

    /**
     * Обработка неудачного входа
     */
    public function onFailedLogin($username = null)
    {
        $ip = $this->getClientIp();
        $this->bruteForceProtector->recordFailure($ip, $username);
    }

    /**
     * Валидация входных данных
     */
    public function validateInput($data, $rules)
    {
        return InputValidator::validateArray($data, $rules);
    }

    /**
     * Очистка строки от XSS
     */
    public function sanitize($input)
    {
        return InputValidator::sanitizeString($input);
    }

    /**
     * Получение текущего IP
     */
    private function getClientIp()
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }

    /**
     * Определение типа эндпоинта для rate limiting
     */
    private function detectEndpoint()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        if (strpos($uri, '/api/auth') !== false || strpos($uri, 'auth.php') !== false) {
            return 'login';
        }
        
        if (strpos($uri, '/api/') !== false) {
            return 'api';
        }
        
        if (strpos($uri, '/export') !== false || strpos($uri, 'download') !== false) {
            return 'export';
        }
        
        return 'default';
    }

    /**
     * Проверка, является ли эндпоинт страницей входа
     */
    private function isLoginEndpoint()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($uri, '/api/auth') !== false 
            || strpos($uri, 'auth.php') !== false
            || strpos($uri, 'login') !== false;
    }

    /**
     * Получение лимита для эндпоинта
     */
    private function getLimitForEndpoint($endpoint)
    {
        $limits = [
            'default' => 100,
            'api' => 60,
            'login' => 5,
            'export' => 10
        ];
        
        return $limits[$endpoint] ?? $limits['default'];
    }

    /**
     *cron-задача для очистки устаревших данных
     */
    public static function runCleanup()
    {
        SecurityLogger::log('Очистка', 'Запуск плановой очистки данных безопасности', 'info');
        
        $firewall = new Firewall();
        $deletedFirewall = $firewall->cleanupExpired();
        
        $rateLimiter = new RateLimiter();
        $deletedRateLimit = $rateLimiter->cleanup(24);
        
        $deletedLogs = SecurityLogger::rotate(30);
        
        SecurityLogger::log('Очистка', "Завершена очистка: фаервол=$deletedFirewall, rate_limit=$deletedRateLimit, логи=$deletedLogs", 'info');
        
        return [
            'firewall_cleaned' => $deletedFirewall,
            'rate_limit_cleaned' => $deletedRateLimit,
            'logs_rotated' => $deletedLogs
        ];
    }
}
