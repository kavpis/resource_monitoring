<?php
/**
 * Класс для ведения логов системы визуализации
 * 
 * @package Visualizations
 */

class VisualizationLogger {
    private static $instance = null;
    private $logFile;
    private $logLevel = 'info';
    
    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_ERROR = 3;
    const LEVEL_CRITICAL = 4;
    
    private $levels = [
        'debug' => self::LEVEL_DEBUG,
        'info' => self::LEVEL_INFO,
        'warning' => self::LEVEL_WARNING,
        'error' => self::LEVEL_ERROR,
        'critical' => self::LEVEL_CRITICAL
    ];
    
    /**
     * Конструктор
     */
    private function __construct() {
        $this->logFile = dirname(__DIR__, 2) . '/logs/visualization.log';
        
        // Создаем директорию для логов, если не существует
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Получить экземпляр логгера (Singleton)
     * 
     * @return VisualizationLogger
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Установить уровень логирования
     * 
     * @param string $level Уровень логирования
     */
    public function setLogLevel($level) {
        if (isset($this->levels[$level])) {
            $this->logLevel = $level;
        }
    }
    
    /**
     * Записать сообщение в лог
     * 
     * @param string $message Сообщение
     * @param string $level Уровень важности
     * @param array $context Дополнительный контекст
     */
    public function log($message, $level = 'info', $context = []) {
        if ($this->levels[$level] < $this->levels[$this->logLevel]) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $component = isset($context['component']) ? $context['component'] : 'Visualization';
        $user = isset($context['user']) ? $context['user'] : 'system';
        
        $logEntry = sprintf(
            "[%s] [%s] [%s] [%s] %s",
            $timestamp,
            strtoupper($level),
            $component,
            $user,
            $message
        );
        
        if (!empty($context)) {
            unset($context['component'], $context['user']);
            if (!empty($context)) {
                $logEntry .= ' | Контекст: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            }
        }
        
        $logEntry .= PHP_EOL;
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * Логирование отладочной информации
     */
    public function debug($message, $context = []) {
        $this->log($message, 'debug', $context);
    }
    
    /**
     * Логирование информационной информации
     */
    public function info($message, $context = []) {
        $this->log($message, 'info', $context);
    }
    
    /**
     * Логирование предупреждений
     */
    public function warning($message, $context = []) {
        $this->log($message, 'warning', $context);
    }
    
    /**
     * Логирование ошибок
     */
    public function error($message, $context = []) {
        $this->log($message, 'error', $context);
    }
    
    /**
     * Логирование критических ошибок
     */
    public function critical($message, $context = []) {
        $this->log($message, 'critical', $context);
    }
}
