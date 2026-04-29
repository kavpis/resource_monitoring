<?php
/**
 * Детектор аномалий теплоносителя
 * Раздел 5.3 — Алгоритмы обработки сигналов (тепло)
 */

require_once __DIR__ . '/DetectionEngine.php';

class HeatAnomalyDetector extends DetectionEngine {
    private $overheatThreshold = 130; // °C
    private $lowTemperatureThreshold = 40; // °C
    private $lowDeltaThreshold = 5; // °C разница между подачей и обраткой
    private $normalDeltaRange = [10, 20]; // Нормальная дельта
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Детекция перегрева теплоносителя
     */
    public function detectOverheat($sensorId, $temperatureThreshold = null) {
        $temperatureThreshold = $temperatureThreshold ?? $this->overheatThreshold;
        
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime('-30 minutes')),
            date('Y-m-d H:i:s')
        );
        
        if (empty($readings)) {
            return false;
        }
        
        $currentValue = end($readings)['reading_value'];
        
        return $currentValue > $temperatureThreshold;
    }
    
    /**
     * Детекция низкой температуры
     */
    public function detectLowTemperature($sensorId, $temperatureThreshold = null) {
        $temperatureThreshold = $temperatureThreshold ?? $this->lowTemperatureThreshold;
        
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime('-1 hour')),
            date('Y-m-d H:i:s')
        );
        
        if (empty($readings)) {
            return false;
        }
        
        $currentValue = end($readings)['reading_value'];
        
        return $currentValue < $temperatureThreshold;
    }
    
    /**
     * Детекция низкой дельты температур (подача - обратка)
     */
    public function detectLowDelta($supplySensorId, $returnSensorId) {
        $supplyReadings = $this->readingModel->getByPeriod(
            $supplySensorId,
            date('Y-m-d H:i:s', strtotime('-1 hour')),
            date('Y-m-d H:i:s')
        );
        
        $returnReadings = $this->readingModel->getByPeriod(
            $returnSensorId,
            date('Y-m-d H:i:s', strtotime('-1 hour')),
            date('Y-m-d H:i:s')
        );
        
        if (empty($supplyReadings) || empty($returnReadings)) {
            return false;
        }
        
        $supplyValue = end($supplyReadings)['reading_value'];
        $returnValue = end($returnReadings)['reading_value'];
        
        $delta = $supplyValue - $returnValue;
        
        return $delta < $this->lowDeltaThreshold;
    }
    
    /**
     * Детекция резкого падения расхода теплоносителя
     */
    public function detectFlowDrop($sensorId, $timeWindowMinutes = 10) {
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
            return false;
        }
        
        $dropPercent = (($previousAverage - $currentValue) / $previousAverage) * 100;
        
        return $dropPercent > 80; // Падение более чем на 80%
    }
    
    /**
     * Детекция аномального роста температуры
     */
    public function detectTemperatureRise($sensorId, $timeWindowMinutes = 5) {
        $readings = $this->readingModel->getByPeriod(
            $sensorId,
            date('Y-m-d H:i:s', strtotime("-{$timeWindowMinutes} minutes")),
            date('Y-m-d H:i:s')
        );
        
        if (count($readings) < 2) {
            return false;
        }
        
        $firstValue = reset($readings)['reading_value'];
        $lastValue = end($readings)['reading_value'];
        
        $riseRate = ($lastValue - $firstValue) / ($timeWindowMinutes * 60); // °C в секунду
        
        // Если рост > 1°C в минуту
        return $riseRate > (1 / 60);
    }
    
    /**
     * Комплексная проверка аномалий тепла
     */
    public function detectHeatAnomalies($sensorId, $returnSensorId = null) {
        $results = [
            'overheat' => $this->detectOverheat($sensorId),
            'low_temperature' => $this->detectLowTemperature($sensorId),
            'flow_drop' => $this->detectFlowDrop($sensorId),
            'rapid_rise' => $this->detectTemperatureRise($sensorId)
        ];
        
        // Если есть сенсор обратки
        if ($returnSensorId) {
            $results['low_delta'] = $this->detectLowDelta($sensorId, $returnSensorId);
        }
        
        return [
            'sensor_id' => $sensorId,
            'return_sensor_id' => $returnSensorId,
            'anomalies' => $results,
            'has_anomalies' => in_array(true, $results),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Создание аварии по теплу
     */
    public function createHeatAlert($sensorId, $anomalyType, $currentValue) {
        $sensor = $this->sensorModel->getById($sensorId);
        if (!$sensor) {
            return false;
        }
        
        $alertDescription = match($anomalyType) {
            'overheat' => "Перегрев теплоносителя в зоне {$sensor['zone_name']}: температура {$currentValue}°C",
            'low_temperature' => "Низкая температура теплоносителя в зоне {$sensor['zone_name']}: {$currentValue}°C",
            'low_delta' => "Низкая дельта температур в зоне {$sensor['zone_name']}: разница {$currentValue}°C",
            'flow_drop' => "Резкое падение расхода теплоносителя в зоне {$sensor['zone_name']}: {$currentValue} т/ч",
            'rapid_rise' => "Аномальный рост температуры в зоне {$sensor['zone_name']}: {$currentValue}°C/мин",
            default => "Аномалия теплоносителя в зоне {$sensor['zone_name']}: {$currentValue}"
        };
        
        $alertLevel = match($anomalyType) {
            'overheat' => 'critical',
            'low_delta', 'flow_drop' => 'emergency',
            'low_temperature', 'rapid_rise' => 'warning',
            default => 'warning'
        };
        
        return $this->alertModel->create([
            'sensor_id' => $sensorId,
            'zone_id' => $sensor['zone_id'],
            'alert_type' => "heat_{$anomalyType}",
            'alert_level' => $alertLevel,
            'description' => $alertDescription,
            'current_value' => $currentValue,
            'threshold_value' => match($anomalyType) {
                'overheat' => $this->overheatThreshold,
                'low_temperature' => $this->lowTemperatureThreshold,
                'low_delta' => $this->lowDeltaThreshold,
                default => null
            },
            'status' => 'active'
        ]);
    }
    
    /**
     * Расчёт среднего значения
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
}
?>