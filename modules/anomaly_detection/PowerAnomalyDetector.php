<?php
/**
 * Детектор аномалий электрических параметров
 * Раздел 5.4 — Алгоритмы обработки сигналов (электричество)
 */

require_once __DIR__ . '/DetectionEngine.php';

class PowerAnomalyDetector extends DetectionEngine {
    private $overloadThreshold = 100; // кВт
    private $lowVoltageThreshold = 198; // В
    private $highVoltageThreshold = 242; // В
    private $criticalLowVoltage = 180; // В
    private $criticalHighVoltage = 260; // В
    private $lowPowerFactorThreshold = 0.85; // cos φ
    private $frequencyThreshold = 50; // Гц
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Детекция перегрузки по мощности
     */
    public function detectOverload($sensorId, $powerThreshold = null) {
        $powerThreshold = $powerThreshold ?? $this->overloadThreshold;
        
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime('-5 minutes')),
            date('Y-m-d H:i:s')
        );
        
        if (empty($readings)) {
            return false;
        }
        
        $currentValue = end($readings)['reading_value'];
        
        return $currentValue > $powerThreshold;
    }
    
    /**
     * Детекция низкого напряжения
     */
    public function detectLowVoltage($sensorId, $voltageThreshold = null) {
        $voltageThreshold = $voltageThreshold ?? $this->lowVoltageThreshold;
        
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime('-2 minutes')),
            date('Y-m-d H:i:s')
        );
        
        if (empty($readings)) {
            return false;
        }
        
        $currentValue = end($readings)['reading_value'];
        
        return $currentValue < $voltageThreshold;
    }
    
    /**
     * Детекция высокого напряжения
     */
    public function detectHighVoltage($sensorId, $voltageThreshold = null) {
        $voltageThreshold = $voltageThreshold ?? $this->highVoltageThreshold;
        
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime('-2 minutes')),
            date('Y-m-d H:i:s')
        );
        
        if (empty($readings)) {
            return false;
        }
        
        $currentValue = end($readings)['reading_value'];
        
        return $currentValue > $voltageThreshold;
    }
    
    /**
     * Детекция критического низкого напряжения
     */
    public function detectCriticalLowVoltage($sensorId) {
        return $this->detectLowVoltage($sensorId, $this->criticalLowVoltage);
    }
    
    /**
     * Детекция критического высокого напряжения
     */
    public function detectCriticalHighVoltage($sensorId) {
        return $this->detectHighVoltage($sensorId, $this->criticalHighVoltage);
    }
    
    /**
     * Детекция низкого коэффициента мощности
     */
    public function detectLowPowerFactor($sensorId) {
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime('-10 minutes')),
            date('Y-m-d H:i:s')
        );
        
        if (empty($readings)) {
            return false;
        }
        
        $currentValue = end($readings)['reading_value'];
        
        return $currentValue < $this->lowPowerFactorThreshold;
    }
    
    /**
     * Детекция резкого скачка тока
     */
    public function detectCurrentSurge($sensorId, $timeWindowSeconds = 30) {
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime("-{$timeWindowSeconds} seconds")),
            date('Y-m-d H:i:s')
        );
        
        if (count($readings) < 2) {
            return false;
        }
        
        $currentValue = end($readings)['reading_value'];
        $previousValue = prev($readings)['reading_value'];
        
        if ($previousValue == 0) {
            return $currentValue > 100; // Защита от деления на 0
        }
        
        $surgePercent = (($currentValue - $previousValue) / $previousValue) * 100;
        
        return $surgePercent > 200; // Рост более чем на 200%
    }
    
    /**
     * Детекция частотных колебаний
     */
    public function detectFrequencyAnomaly($sensorId) {
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime('-1 minute')),
            date('Y-m-d H:i:s')
        );
        
        if (empty($readings)) {
            return false;
        }
        
        $currentValue = end($readings)['reading_value'];
        
        // Частота должна быть в пределах 49.5-50.5 Гц
        return $currentValue < 49.5 || $currentValue > 50.5;
    }
    
    /**
     * Комплексная проверка аномалий электроэнергии
     */
    public function detectPowerAnomalies($sensorId) {
        $results = [
            'overload' => $this->detectOverload($sensorId),
            'low_voltage' => $this->detectLowVoltage($sensorId),
            'high_voltage' => $this->detectHighVoltage($sensorId),
            'critical_low_voltage' => $this->detectCriticalLowVoltage($sensorId),
            'critical_high_voltage' => $this->detectCriticalHighVoltage($sensorId),
            'low_power_factor' => $this->detectLowPowerFactor($sensorId),
            'current_surge' => $this->detectCurrentSurge($sensorId),
            'frequency_anomaly' => $this->detectFrequencyAnomaly($sensorId)
        ];
        
        return [
            'sensor_id' => $sensorId,
            'anomalies' => $results,
            'has_anomalies' => in_array(true, $results),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Создание аварии по электричеству
     */
    public function createPowerAlert($sensorId, $anomalyType, $currentValue) {
        $sensor = $this->sensorModel->getById($sensorId);
        if (!$sensor) {
            return false;
        }
        
        $alertDescription = match($anomalyType) {
            'overload' => "Перегрузка по мощности в зоне {$sensor['zone_name']}: {$currentValue} кВт",
            'low_voltage' => "Низкое напряжение в зоне {$sensor['zone_name']}: {$currentValue} В",
            'high_voltage' => "Высокое напряжение в зоне {$sensor['zone_name']}: {$currentValue} В",
            'critical_low_voltage' => "КРИТИЧЕСКИ НИЗКОЕ напряжение в зоне {$sensor['zone_name']}: {$currentValue} В",
            'critical_high_voltage' => "КРИТИЧЕСКИ ВЫСОКОЕ напряжение в зоне {$sensor['zone_name']}: {$currentValue} В",
            'low_power_factor' => "Низкий коэффициент мощности в зоне {$sensor['zone_name']}: cos φ = {$currentValue}",
            'current_surge' => "Резкий скачок тока в зоне {$sensor['zone_name']}: {$currentValue} А",
            'frequency_anomaly' => "Аномалия частоты в зоне {$sensor['zone_name']}: {$currentValue} Гц",
            default => "Аномалия электроэнергии в зоне {$sensor['zone_name']}: {$currentValue}"
        };
        
        $alertLevel = match($anomalyType) {
            'overload', 'critical_low_voltage', 'critical_high_voltage' => 'critical',
            'low_voltage', 'high_voltage', 'current_surge', 'frequency_anomaly' => 'emergency',
            'low_power_factor' => 'warning',
            default => 'warning'
        };
        
        return $this->alertModel->create([
            'sensor_id' => $sensorId,
            'zone_id' => $sensor['zone_id'],
            'alert_type' => "power_{$anomalyType}",
            'alert_level' => $alertLevel,
            'description' => $alertDescription,
            'current_value' => $currentValue,
            'threshold_value' => match($anomalyType) {
                'overload' => $this->overloadThreshold,
                'low_voltage' => $this->lowVoltageThreshold,
                'high_voltage' => $this->highVoltageThreshold,
                'critical_low_voltage' => $this->criticalLowVoltage,
                'critical_high_voltage' => $this->criticalHighVoltage,
                'low_power_factor' => $this->lowPowerFactorThreshold,
                default => null
            },
            'status' => 'active'
        ]);
    }
}
?>