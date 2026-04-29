<?php
/**
 * Планировщик опроса счётчиков
 * Раздел 6.1 — Модуль сбора данных
 */

require_once __DIR__ . '/DataCollector.php';

class Scheduler {
    private $collector;
    private $running = false;
    private $pollingInterval = 30;
    private $timer = null;
    
    public function __construct($collector = null) {
        $this->collector = $collector ?: new DataCollector();
        $settings = $this->collector->getPollingInterval();
        $this->pollingInterval = $settings['interval'];
    }
    
    /**
     * Запуск циклического опроса
     */
    public function start() {
        if ($this->running) {
            return ['success' => false, 'message' => 'Планировщик уже запущен'];
        }
        
        $this->running = true;
        
        // Запуск в отдельном процессе (для CLI)
        if (php_sapi_name() === 'cli') {
            return $this->startCLIScheduler();
        } else {
            return ['success' => false, 'message' => 'Планировщик может запускаться только в CLI режиме'];
        }
    }
    
    /**
     * Запуск планировщика в CLI режиме
     */
    private function startCLIScheduler() {
        echo "=== Запуск планировщика опроса счётчиков ===\n";
        echo "Интервал: {$this->pollingInterval} секунд\n";
        echo "Время запуска: " . date('Y-m-d H:i:s') . "\n";
        echo "Нажмите Ctrl+C для остановки...\n\n";
        
        $lastRun = 0;
        
        while ($this->running) {
            $currentTime = time();
            
            // Проверка, пора ли запускать опрос
            if ($currentTime - $lastRun >= $this->pollingInterval) {
                try {
                    $startTime = microtime(true);
                    echo "[" . date('H:i:s') . "] Начало сбора данных...\n";
                    
                    $result = $this->collector->collectAll();
                    
                    $duration = round(microtime(true) - $startTime, 2);
                    
                    echo "[" . date('H:i:s') . "] Сбор завершён. Успешно: {$result['collected']}, Ошибок: {$result['errors']}, Время: {$duration}s\n\n";
                    
                    $lastRun = $currentTime;
                    
                } catch (Exception $e) {
                    echo "[" . date('H:i:s') . "] ОШИБКА: " . $e->getMessage() . "\n";
                    sleep(5); // Пауза перед повторной попыткой
                }
            }
            
            // Небольшая пауза для экономии CPU
            usleep(100000); // 0.1 секунда
        }
        
        echo "Планировщик остановлен\n";
        return ['success' => true, 'message' => 'Планировщик остановлен'];
    }
    
    /**
     * Остановка планировщика
     */
    public function stop() {
        $this->running = false;
        return ['success' => true, 'message' => 'Планировщик остановлен'];
    }
    
    /**
     * Получение статуса планировщика
     */
    public function getStatus() {
        return [
            'running' => $this->running,
            'polling_interval' => $this->pollingInterval,
            'next_run_in' => $this->running ? 
                max(0, $this->pollingInterval - (time() % $this->pollingInterval)) : null
        ];
    }
    
    /**
     * Установка интервала опроса
     */
    public function setPollingInterval($seconds) {
        $result = $this->collector->setPollingInterval($seconds);
        
        if ($result['success']) {
            $this->pollingInterval = $seconds;
        }
        
        return $result;
    }
    
    /**
     * Ручной запуск опроса
     */
    public function runOnce() {
        return $this->collector->collectAll();
    }
    
    /**
     * Получение статистики по опросу
     */
    public function getStatistics($startDate = null, $endDate = null) {
        return $this->collector->getStatistics($startDate, $endDate);
    }
}
?>