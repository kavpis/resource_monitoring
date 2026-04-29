<?php
/**
 * Класс для работы с кэшем данных визуализации
 * 
 * @package Visualizations
 */

class VisualizationCache {
    private static $instance = null;
    private $cacheDir;
    private $defaultTtl = 300; // 5 минут по умолчанию
    private $logger;
    
    /**
     * Конструктор
     */
    private function __construct() {
        $this->cacheDir = dirname(__DIR__, 2) . '/cache/visualizations';
        $this->logger = VisualizationLogger::getInstance();
        
        // Создаем директорию для кэша, если не существует
        if (!file_exists($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        $this->logger->info('Кэш визуализации инициализирован', ['component' => 'VisualizationCache']);
    }
    
    /**
     * Получить экземпляр кэша (Singleton)
     * 
     * @return VisualizationCache
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Получить ключ кэша
     * 
     * @param string $key Ключ
     * @return string Путь к файлу кэша
     */
    private function getCacheKey($key) {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
    
    /**
     * Получить данные из кэша
     * 
     * @param string $key Ключ кэша
     * @return mixed|null Данные или null если не найдено
     */
    public function get($key) {
        $cacheFile = $this->getCacheKey($key);
        
        if (!file_exists($cacheFile)) {
            $this->logger->debug('Кэш не найден', ['component' => 'VisualizationCache', 'key' => $key]);
            return null;
        }
        
        $data = unserialize(file_get_contents($cacheFile));
        
        if ($data === false || isset($data['expires']) && $data['expires'] < time()) {
            $this->delete($key);
            $this->logger->debug('Кэш устарел или поврежден', ['component' => 'VisualizationCache', 'key' => $key]);
            return null;
        }
        
        $this->logger->debug('Данные получены из кэша', ['component' => 'VisualizationCache', 'key' => $key]);
        return $data['value'];
    }
    
    /**
     * Сохранить данные в кэш
     * 
     * @param string $key Ключ кэша
     * @param mixed $value Данные
     * @param int $ttl Время жизни в секундах
     * @return bool Успешность сохранения
     */
    public function set($key, $value, $ttl = null) {
        if ($ttl === null) {
            $ttl = $this->defaultTtl;
        }
        
        $cacheFile = $this->getCacheKey($key);
        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];
        
        $result = file_put_contents($cacheFile, serialize($data), LOCK_EX);
        
        if ($result !== false) {
            $this->logger->debug('Данные сохранены в кэш', [
                'component' => 'VisualizationCache',
                'key' => $key,
                'ttl' => $ttl
            ]);
            return true;
        }
        
        $this->logger->error('Не удалось сохранить данные в кэш', [
            'component' => 'VisualizationCache',
            'key' => $key
        ]);
        return false;
    }
    
    /**
     * Удалить данные из кэша
     * 
     * @param string $key Ключ кэша
     * @return bool Успешность удаления
     */
    public function delete($key) {
        $cacheFile = $this->getCacheKey($key);
        
        if (file_exists($cacheFile)) {
            $result = unlink($cacheFile);
            if ($result) {
                $this->logger->debug('Данные удалены из кэша', [
                    'component' => 'VisualizationCache',
                    'key' => $key
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Очистить весь кэш
     * 
     * @return bool Успешность очистки
     */
    public function clear() {
        $files = glob($this->cacheDir . '/*.cache');
        $success = true;
        
        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }
        
        if ($success) {
            $this->logger->info('Кэш визуализации очищен', ['component' => 'VisualizationCache']);
        } else {
            $this->logger->error('Не удалось полностью очистить кэш', ['component' => 'VisualizationCache']);
        }
        
        return $success;
    }
    
    /**
     * Очистить старые записи кэша
     * 
     * @return int Количество удаленных файлов
     */
    public function cleanup() {
        $files = glob($this->cacheDir . '/*.cache');
        $count = 0;
        
        foreach ($files as $file) {
            $data = @unserialize(file_get_contents($file));
            if ($data === false || (isset($data['expires']) && $data['expires'] < time())) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }
        
        if ($count > 0) {
            $this->logger->info("Очищено {$count} устаревших записей кэша", ['component' => 'VisualizationCache']);
        }
        
        return $count;
    }
    
    /**
     * Получить статистику кэша
     * 
     * @return array Статистика
     */
    public function getStats() {
        $files = glob($this->cacheDir . '/*.cache');
        $totalSize = 0;
        $oldest = null;
        $newest = null;
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
            
            $data = @unserialize(file_get_contents($file));
            if ($data !== false && isset($data['created'])) {
                if ($oldest === null || $data['created'] < $oldest) {
                    $oldest = $data['created'];
                }
                if ($newest === null || $data['created'] > $newest) {
                    $newest = $data['created'];
                }
            }
        }
        
        return [
            'count' => count($files),
            'size' => $totalSize,
            'size_formatted' => $this->formatBytes($totalSize),
            'oldest' => $oldest ? date('Y-m-d H:i:s', $oldest) : null,
            'newest' => $newest ? date('Y-m-d H:i:s', $newest) : null
        ];
    }
    
    /**
     * Форматировать размер в байтах
     * 
     * @param int $bytes Размер в байтах
     * @return string Отформатированный размер
     */
    private function formatBytes($bytes) {
        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
