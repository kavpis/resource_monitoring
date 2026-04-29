<?php
/**
 * Детектор утечек воды
 * Раздел 5.2 — Алгоритмы обработки сигналов (вода)
 */

require_once __DIR__ . '/DetectionEngine.php';

class WaterLeakDetector extends DetectionEngine {
    private $leakThreshold = 2.0; // Порог утечки в м³/ч
    private $silentLeakThreshold = 0.1; // Порог тихой утечки
    private $rateOfChangeThreshold = 200; // Процент роста за 5 минут
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Детекция резкой утечки воды
     */
    public function detectSuddenLeak($sensorId, $timeWindowMinutes = 5) {
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime("-{$timeWindowMinutes} minutes")),
            date('Y-m-d H:i:s')
        );
        
        if (count($readings) < 2) {
            return false;
        }
        
        $currentValue = end($readings)['reading_value'];
        $previousAverage = $this->calculateAverage($readings, $timeWindowMinutes * 2);
        
        if ($previousAverage == 0) {
            return $currentValue > $this->leakThreshold;
        }
        
        $changePercent = (($currentValue - $previousAverage) / $previousAverage) * 100;
        
        return $currentValue > $this->leakThreshold && $changePercent > $this->rateOfChangeThreshold;
    }
    
    /**
     * Детекция тихой утечки (ночной расход)
     */
    public function detectSilentLeak($sensorId, $timeWindowHours = 24) {
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime("-{$timeWindowHours} hours")),
            date('Y-m-d H:i:s')
        );
        
        if (empty($readings)) {
            return false;
        }
        
        // Фильтрация ночных показаний (с 22:00 до 6:00)
        $nightReadings = array_filter($readings, function($reading) {
            $hour = (int)date('H', strtotime($reading['reading_timestamp']));
            return $hour >= 22 || $hour < 6;
        });
        
        if (count($nightReadings) < 12) { // Минимум 12 показаний
            return false;
        }
        
        // Проверка на постоянный расход > порога
        $persistentReadings = array_filter($nightReadings, function($reading) {
            return $reading['reading_value'] > $this->silentLeakThreshold;
        });
        
        // Если 70% ночных показаний > порога — тихая утечка
        $ratio = count($persistentReadings) / count($nightReadings);
        
        return $ratio > 0.7;
    }
    
    /**
     * Детекция поломки счётчика (нулевые показания при наличии потребления)
     */
    public function detectBrokenMeter($sensorId, $timeWindowHours = 4) {
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime("-{$timeWindowHours} hours")),
            date('Y-m-d H:i:s')
        );
        
        if (empty($readings)) {
            return false;
        }
        
        // Проверка, что все показания = 0
        $zeroReadings = array_filter($readings, function($reading) {
            return $reading['reading_value'] == 0;
        });
        
        // Если 90% показаний = 0 — вероятна поломка счётчика
        $ratio = count($zeroReadings) / count($readings);
        
        return $ratio > 0.9 && count($readings) >= 6; // Минимум 6 показаний
    }
    
    /**
     * Детекция обрыва связи (отсутствие данных)
     */
    public function detectConnectionLoss($sensorId, $timeoutMinutes = 15) {
        $lastReading = $this->readingModel->getLastReading($sensorId);
        
        if (!$lastReading) {
            return true; // Нет вообще данных
        }
        
        $lastTime = strtotime($lastReading['reading_timestamp']);
        $timeDiff = time() - $lastTime;
        
        return $timeDiff > ($timeoutMinutes * 60);
    }
    
    /**
     * Комплексная проверка утечек
     */
    public function detectWaterAnomalies($sensorId) {
        $results = [
            'sudden_leak' => $this->detectSuddenLeak($sensorId),
            'silent_leak' => $this->detectSilentLeak($sensorId),
            'broken_meter' => $this->detectBrokenMeter($sensorId),
            'connection_loss' => $this->detectConnectionLoss($sensorId)
        ];
        
        return [
            'sensor_id' => $sensorId,
            'anomalies' => $results,
            'has_anomalies' => in_array(true, $results),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Расчёт среднего значения за период
     */
    private function calculateAverage($readings, $hoursBack) {
        $relevantReadings = array_filter($readings, function($reading) use ($hoursBack) {
            return strtotime($reading['reading_timestamp']) >= (time() - ($hoursBack * 3600));
        });
        
        if (empty($relevantReadings)) {
            return 0;
        }
        
        $sum = array_sum(array_column($relevantReadings, 'reading_value'));
        return $sum / count($relevantReadings);
    }
    
    /**
     * Создание аварии об утечке
     */
    public function createLeakAlert($sensorId, $leakType, $currentValue) {
        $sensor = $this->sensorModel->getById($sensorId);
        if (!$sensor) {
            return false;
        }
        
        $alertDescription = match($leakType) {
            'sudden' => "Вероятна резкая утечка воды в зоне {$sensor['zone_name']}: расход {$currentValue} м³/ч превышает норму",
            'silent' => "Подозрение на тихую утечку воды в зоне {$sensor['zone_name']}: ночной расход {$currentValue} м³/ч",
            'meter_broken' => "Подозрение на поломку счётчика воды в зоне {$sensor['zone_name']}: нулевые показания",
            'connection_lost' => "Потеря связи со счётчиком воды в зоне {$sensor['zone_name']}",
            default => "Аномалия счётчика воды в зоне {$sensor['zone_name']}: {$currentValue} м³/ч"
        };
        
        $alertLevel = match($leakType) {
            'sudden', 'connection_lost' => 'emergency',
            'silent' => 'warning',
            'meter_broken' => 'warning',
            default => 'warning'
        };
        
        return $this->alertModel->create([
            'sensor_id' => $sensorId,
            'zone_id' => $sensor['zone_id'],
            'alert_type' => "water_{$leakType}_leak",
            'alert_level' => $alertLevel,
            'description' => $alertDescription,
            'current_value' => $currentValue,
            'threshold_value' => $this->leakThreshold,
            'status' => 'active'
        ]);
    }
}
?>