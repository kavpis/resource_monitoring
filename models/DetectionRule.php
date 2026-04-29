<?php
/**
 * Модель правил детекции аномалий
 * Раздел 5 — Алгоритмы обработки сигналов
 */

require_once __DIR__ . '/../config/database.php';

class DetectionRule {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    // Получение всех активных правил
    public function getActiveRules() {
        $stmt = $this->db->query("
            SELECT dr.*, z.zone_name, u.full_name as created_by_name
            FROM detection_rules dr
            LEFT JOIN zones z ON dr.zone_id = z.zone_id
            LEFT JOIN users u ON dr.created_by = u.user_id
            WHERE dr.is_active = 1
            ORDER BY dr.resource_type, dr.rule_name
        ");
        return $stmt->fetchAll();
    }
    
    // Получение всех правил
    public function getAll() {
        $stmt = $this->db->query("
            SELECT dr.*, z.zone_name, u.full_name as created_by_name
            FROM detection_rules dr
            LEFT JOIN zones z ON dr.zone_id = z.zone_id
            LEFT JOIN users u ON dr.created_by = u.user_id
            ORDER BY dr.is_active DESC, dr.resource_type, dr.rule_name
        ");
        return $stmt->fetchAll();
    }
    
    // Получение правила по ID
    public function getById($ruleId) {
        $stmt = $this->db->prepare("
            SELECT * FROM detection_rules WHERE rule_id = :rule_id
        ");
        $stmt->execute(['rule_id' => $ruleId]);
        return $stmt->fetch();
    }
    
    // Получение правил по типу ресурса
    public function getByResourceType($resourceType) {
        $stmt = $this->db->prepare("
            SELECT * FROM detection_rules 
            WHERE resource_type IN (:resource_type, 'all')
            AND is_active = 1
            ORDER BY alert_level DESC, rule_name
        ");
        
        $stmt->execute(['resource_type' => $resourceType]);
        return $stmt->fetchAll();
    }
    
    // Получение правил по зоне
    public function getByZone($zoneId) {
        $stmt = $this->db->prepare("
            SELECT * FROM detection_rules 
            WHERE (zone_id = :zone_id OR zone_id IS NULL)
            AND is_active = 1
            ORDER BY resource_type, rule_name
        ");
        $stmt->execute(['zone_id' => $zoneId]);
        return $stmt->fetchAll();
    }
    
    // Получение правил по типу условия
    public function getByConditionType($conditionType) {
        $stmt = $this->db->prepare("
            SELECT * FROM detection_rules 
            WHERE condition_type = :condition_type
            AND is_active = 1
            ORDER BY rule_name
        ");
        $stmt->execute(['condition_type' => $conditionType]);
        return $stmt->fetchAll();
    }
    
    // Добавление нового правила
    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO detection_rules 
            (rule_name, resource_type, zone_id, condition_type, threshold_value,
             time_window_seconds, comparison_operator, alert_level, alert_message_template,
             is_active, created_by)
            VALUES 
            (:rule_name, :resource_type, :zone_id, :condition_type, :threshold_value,
             :time_window_seconds, :comparison_operator, :alert_level, :alert_message_template,
             :is_active, :created_by)
        ");
        
        $result = $stmt->execute([
            'rule_name' => $data['rule_name'],
            'resource_type' => $data['resource_type'],
            'zone_id' => $data['zone_id'] ?? null,
            'condition_type' => $data['condition_type'],
            'threshold_value' => $data['threshold_value'] ?? null,
            'time_window_seconds' => $data['time_window_seconds'] ?? null,
            'comparison_operator' => $data['comparison_operator'] ?? null,
            'alert_level' => $data['alert_level'],
            'alert_message_template' => $data['alert_message_template'],
            'is_active' => isset($data['is_active']) ? $data['is_active'] : 1,
            'created_by' => $data['created_by'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    // Обновление правила
    public function update($ruleId, $data) {
        $fields = [];
        $params = ['rule_id' => $ruleId];
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[$key] = $value;
        }
        
        if (empty($fields)) return false;
        
        $sql = "UPDATE detection_rules SET " . implode(', ', $fields) . " WHERE rule_id = :rule_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    // Удаление правила
    public function delete($ruleId) {
        $stmt = $this->db->prepare("DELETE FROM detection_rules WHERE rule_id = :rule_id");
        return $stmt->execute(['rule_id' => $ruleId]);
    }
    
    // Активация/деактивация правила
    public function setActive($ruleId, $isActive) {
        $stmt = $this->db->prepare("
            UPDATE detection_rules 
            SET is_active = :is_active 
            WHERE rule_id = :rule_id
        ");
        return $stmt->execute([
            'rule_id' => $ruleId,
            'is_active' => $isActive ? 1 : 0
        ]);
    }
    
    // Получение статистики по правилам
    public function getStatistics() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_rules,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_rules,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_rules,
                SUM(CASE WHEN resource_type = 'water' THEN 1 ELSE 0 END) as water_rules,
                SUM(CASE WHEN resource_type = 'heat' THEN 1 ELSE 0 END) as heat_rules,
                SUM(CASE WHEN resource_type = 'electricity' THEN 1 ELSE 0 END) as electricity_rules,
                SUM(CASE WHEN resource_type = 'all' THEN 1 ELSE 0 END) as universal_rules,
                SUM(CASE WHEN alert_level = 'warning' THEN 1 ELSE 0 END) as warning_rules,
                SUM(CASE WHEN alert_level = 'emergency' THEN 1 ELSE 0 END) as emergency_rules,
                SUM(CASE WHEN alert_level = 'critical' THEN 1 ELSE 0 END) as critical_rules
            FROM detection_rules
        ");
        return $stmt->fetch();
    }
    
    // Проверка правила на соответствие данным
    public function evaluate($rule, $currentValue, $previousValues = []) {
        if (!$rule || !$rule['is_active']) {
            return false;
        }
        
        $threshold = $rule['threshold_value'];
        $operator = $rule['comparison_operator'];
        $conditionType = $rule['condition_type'];
        
        switch ($conditionType) {
            case 'threshold':
                // Простое пороговое сравнение
                return $this->compare($currentValue, $operator, $threshold);
                
            case 'rate_of_change':
                // Проверка скорости изменения
                if (empty($previousValues)) return false;
                
                $timeWindow = $rule['time_window_seconds'] ?? 300; // 5 минут по умолчанию
                $minValuesNeeded = max(2, floor($timeWindow / 30)); // минимум 2 значения
                
                if (count($previousValues) < $minValuesNeeded) return false;
                
                // Берём первое и последнее значение в окне
                $first = reset($previousValues);
                $last = end($previousValues);
                
                if ($first == 0) return false;
                
                $percentChange = (($last - $first) / abs($first)) * 100;
                
                return $this->compare($percentChange, $operator, $threshold);
                
            case 'absence':
                // Проверка отсутствия данных
                $timeWindow = $rule['time_window_seconds'] ?? 600; // 10 минут
                
                if (empty($previousValues)) {
                    return true; // Нет данных вообще
                }
                
                $lastTimestamp = end($previousValues)['timestamp'] ?? null;
                if (!$lastTimestamp) return false;
                
                $timeDiff = time() - strtotime($lastTimestamp);
                return $timeDiff > $timeWindow;
                
            case 'delta':
                // Проверка разницы между значениями
                if (count($previousValues) < 2) return false;
                
                $current = $currentValue;
                $previous = end($previousValues);
                
                $delta = abs($current - $previous);
                return $this->compare($delta, $operator, $threshold);
                
            case 'custom':
                // Пользовательская логика (будет реализована отдельно)
                return false;
                
            default:
                return false;
        }
    }
    
    // Вспомогательная функция сравнения
    private function compare($value, $operator, $threshold) {
        switch ($operator) {
            case '>': return $value > $threshold;
            case '<': return $value < $threshold;
            case '>=': return $value >= $threshold;
            case '<=': return $value <= $threshold;
            case '=': return $value == $threshold;
            case '!=': return $value != $threshold;
            default: return false;
        }
    }
    
    // Получение правил с группировкой по ресурсу
    public function getGroupedByResource() {
        $stmt = $this->db->query("
            SELECT 
                resource_type,
                COUNT(*) as rule_count,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
                GROUP_CONCAT(rule_name ORDER BY rule_name SEPARATOR '; ') as rule_names
            FROM detection_rules
            GROUP BY resource_type
            ORDER BY resource_type
        ");
        return $stmt->fetchAll();
    }
    
    // Клонирование правила
    public function cloneRule($ruleId) {
        $rule = $this->getById($ruleId);
        if (!$rule) return false;
        
        $newName = $rule['rule_name'] . ' (копия)';
        
        $stmt = $this->db->prepare("
            INSERT INTO detection_rules 
            (rule_name, resource_type, zone_id, condition_type, threshold_value,
             time_window_seconds, comparison_operator, alert_level, alert_message_template,
             is_active, created_by)
            VALUES 
            (:rule_name, :resource_type, :zone_id, :condition_type, :threshold_value,
             :time_window_seconds, :comparison_operator, :alert_level, :alert_message_template,
             :is_active, :created_by)
        ");
        
        $result = $stmt->execute([
            'rule_name' => $newName,
            'resource_type' => $rule['resource_type'],
            'zone_id' => $rule['zone_id'],
            'condition_type' => $rule['condition_type'],
            'threshold_value' => $rule['threshold_value'],
            'time_window_seconds' => $rule['time_window_seconds'],
            'comparison_operator' => $rule['comparison_operator'],
            'alert_level' => $rule['alert_level'],
            'alert_message_template' => $rule['alert_message_template'],
            'is_active' => 0, // клон создаётся неактивным
            'created_by' => $rule['created_by']
        ]);
        
        return $result ? $this->db->lastInsertId() : false;
    }
    
    // Получение стандартных правил по умолчанию
    public function getDefaultRules() {
        return [
            [
                'rule_name' => 'Утечка воды - резкий скачок',
                'resource_type' => 'water',
                'condition_type' => 'rate_of_change',
                'threshold_value' => 200,
                'comparison_operator' => '>',
                'time_window_seconds' => 300,
                'alert_level' => 'emergency',
                'alert_message_template' => 'Вероятна утечка в зоне [zone]: расход вырос на [value]% за 5 минут'
            ],
            [
                'rule_name' => 'Тихая утечка воды',
                'resource_type' => 'water',
                'condition_type' => 'threshold',
                'threshold_value' => 0.1,
                'comparison_operator' => '>',
                'alert_level' => 'warning',
                'alert_message_template' => 'Подозрение на скрытую утечку в зоне [zone]: ночной расход [value] м³/ч'
            ],
            [
                'rule_name' => 'Перегрев теплоносителя',
                'resource_type' => 'heat',
                'condition_type' => 'threshold',
                'threshold_value' => 130,
                'comparison_operator' => '>',
                'alert_level' => 'critical',
                'alert_message_template' => 'Перегрев теплоносителя в зоне [zone]: температура [value]°C - риск разрыва трубы'
            ],
            [
                'rule_name' => 'Низкая температура подачи',
                'resource_type' => 'heat',
                'condition_type' => 'threshold',
                'threshold_value' => 40,
                'comparison_operator' => '<',
                'alert_level' => 'warning',
                'alert_message_template' => 'Недостаточная температура подачи в зоне [zone]: [value]°C'
            ],
            [
                'rule_name' => 'Перегрузка по мощности',
                'resource_type' => 'electricity',
                'condition_type' => 'threshold',
                'threshold_value' => 100,
                'comparison_operator' => '>',
                'time_window_seconds' => 300,
                'alert_level' => 'emergency',
                'alert_message_template' => 'Перегрузка на линии [zone]: мощность [value] кВт превышает лимит'
            ],
            [
                'rule_name' => 'Низкое напряжение',
                'resource_type' => 'electricity',
                'condition_type' => 'threshold',
                'threshold_value' => 198,
                'comparison_operator' => '<',
                'alert_level' => 'warning',
                'alert_message_template' => 'Низкое напряжение в зоне [zone]: [value] В'
            ],
            [
                'rule_name' => 'Высокое напряжение',
                'resource_type' => 'electricity',
                'condition_type' => 'threshold',
                'threshold_value' => 242,
                'comparison_operator' => '>',
                'alert_level' => 'warning',
                'alert_message_template' => 'Высокое напряжение в зоне [zone]: [value] В'
            ],
            [
                'rule_name' => 'Критическое напряжение - низкое',
                'resource_type' => 'electricity',
                'condition_type' => 'threshold',
                'threshold_value' => 180,
                'comparison_operator' => '<',
                'alert_level' => 'critical',
                'alert_message_template' => 'КРИТИЧЕСКОЕ: напряжение в зоне [zone] упало до [value] В'
            ],
            [
                'rule_name' => 'Критическое напряжение - высокое',
                'resource_type' => 'electricity',
                'condition_type' => 'threshold',
                'threshold_value' => 260,
                'comparison_operator' => '>',
                'alert_level' => 'critical',
                'alert_message_template' => 'КРИТИЧЕСКОЕ: напряжение в зоне [zone] выросло до [value] В'
            ],
            [
                'rule_name' => 'Низкий коэффициент мощности',
                'resource_type' => 'electricity',
                'condition_type' => 'threshold',
                'threshold_value' => 0.85,
                'comparison_operator' => '<',
                'alert_level' => 'warning',
                'alert_message_template' => 'Низкий cos φ в зоне [zone]: [value] - требуется компенсация реактивной мощности'
            ]
        ];
    }
}
?>