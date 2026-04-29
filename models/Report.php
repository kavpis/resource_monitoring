<?php
/**
 * Модель отчётов
 * Раздел 7.1 — Отчёты и экспортируемые данные
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

class Report {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    // Получение всех отчётов
    public function getAll($filters = []) {
        $sql = "SELECT r.*, u.full_name as generated_by_name, z.zone_name
                FROM reports r
                LEFT JOIN users u ON r.generated_by = u.user_id
                LEFT JOIN zones z ON r.zone_id = z.zone_id
                WHERE 1=1";
        
        $params = [];
        
        // Фильтр по типу
        if (!empty($filters['report_type'])) {
            $sql .= " AND r.report_type = :report_type";
            $params['report_type'] = $filters['report_type'];
        }
        
        // Фильтр по зоне
        if (!empty($filters['zone_id'])) {
            $sql .= " AND r.zone_id = :zone_id";
            $params['zone_id'] = $filters['zone_id'];
        }
        
        // Фильтр по типу ресурса
        if (!empty($filters['resource_type'])) {
            $sql .= " AND r.resource_type = :resource_type";
            $params['resource_type'] = $filters['resource_type'];
        }
        
        // Фильтр по формату
        if (!empty($filters['format'])) {
            $sql .= " AND r.format = :format";
            $params['format'] = $filters['format'];
        }
        
        $sql .= " ORDER BY r.generated_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    // Получение отчёта по ID
    public function getById($reportId) {
        $stmt = $this->db->prepare("
            SELECT r.*, u.full_name as generated_by_name, z.zone_name
            FROM reports r
            LEFT JOIN users u ON r.generated_by = u.user_id
            LEFT JOIN zones z ON r.zone_id = z.zone_id
            WHERE r.report_id = :report_id
        ");
        $stmt->execute(['report_id' => $reportId]);
        return $stmt->fetch();
    }
    
    // Создание нового отчёта
    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO reports 
            (report_name, report_type, zone_id, resource_type, period_start, period_end,
             format, file_path, generated_by, generated_at)
            VALUES 
            (:report_name, :report_type, :zone_id, :resource_type, :period_start, :period_end,
             :format, :file_path, :generated_by, NOW())
        ");
        
        $result = $stmt->execute([
            'report_name' => $data['report_name'],
            'report_type' => $data['report_type'],
            'zone_id' => $data['zone_id'] ?? null,
            'resource_type' => $data['resource_type'] ?? null,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'format' => $data['format'],
            'file_path' => $data['file_path'] ?? null,
            'generated_by' => $data['generated_by'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    // Обновление отчёта
    public function update($reportId, $data) {
        $fields = [];
        $params = ['report_id' => $reportId];
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[$key] = $value;
        }
        
        if (empty($fields)) return false;
        
        $sql = "UPDATE reports SET " . implode(', ', $fields) . " WHERE report_id = :report_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    // Удаление отчёта
    public function delete($reportId) {
        // Сначала получаем путь к файлу для удаления
        $report = $this->getById($reportId);
        
        $stmt = $this->db->prepare("DELETE FROM reports WHERE report_id = :report_id");
        $result = $stmt->execute(['report_id' => $reportId]);
        
        // Удаляем файл, если он существует
        if ($result && $report && $report['file_path']) {
            $filePath = ROOT_PATH . '/' . $report['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        return $result;
    }
    
    // Получение статистики по отчётам
    public function getStatistics() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_reports,
                SUM(CASE WHEN report_type = 'consumption' THEN 1 ELSE 0 END) as consumption_reports,
                SUM(CASE WHEN report_type = 'alerts' THEN 1 ELSE 0 END) as alerts_reports,
                SUM(CASE WHEN report_type = 'efficiency' THEN 1 ELSE 0 END) as efficiency_reports,
                SUM(CASE WHEN report_type = 'custom' THEN 1 ELSE 0 END) as custom_reports,
                SUM(CASE WHEN format = 'pdf' THEN 1 ELSE 0 END) as pdf_reports,
                SUM(CASE WHEN format = 'excel' THEN 1 ELSE 0 END) as excel_reports,
                SUM(CASE WHEN format = 'csv' THEN 1 ELSE 0 END) as csv_reports,
                MAX(generated_at) as last_generated
            FROM reports
        ");
        return $stmt->fetch();
    }
    
    // Получение отчётов по периоду
    public function getByPeriod($start, $end) {
        $stmt = $this->db->prepare("
            SELECT r.*, u.full_name as generated_by_name
            FROM reports r
            LEFT JOIN users u ON r.generated_by = u.user_id
            WHERE r.generated_at BETWEEN :start AND :end
            ORDER BY r.generated_at DESC
        ");
        
        $stmt->execute([
            'start' => $start,
            'end' => $end
        ]);
        
        return $stmt->fetchAll();
    }
    
    // Генерация отчёта о потреблении
    public function generateConsumptionReport($data) {
        require_once __DIR__ . '/../modules/reporting/ReportGenerator.php';
        
        $generator = new ReportGenerator();
        $reportData = $generator->generateConsumptionReport($data);
        
        // Сохраняем в БД
        $reportId = $this->create([
            'report_name' => $data['report_name'],
            'report_type' => 'consumption',
            'zone_id' => $data['zone_id'] ?? null,
            'resource_type' => $data['resource_type'] ?? null,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'format' => $data['format'],
            'generated_by' => $data['generated_by'] ?? null
        ]);
        
        return [
            'report_id' => $reportId,
            'data' => $reportData
        ];
    }
    
    // Генерация отчёта об авариях
    public function generateAlertsReport($data) {
        require_once __DIR__ . '/../modules/reporting/ReportGenerator.php';
        
        $generator = new ReportGenerator();
        $reportData = $generator->generateAlertsReport($data);
        
        // Сохраняем в БД
        $reportId = $this->create([
            'report_name' => $data['report_name'],
            'report_type' => 'alerts',
            'zone_id' => $data['zone_id'] ?? null,
            'resource_type' => $data['resource_type'] ?? null,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'format' => $data['format'],
            'generated_by' => $data['generated_by'] ?? null
        ]);
        
        return [
            'report_id' => $reportId,
            'data' => $reportData
        ];
    }
    
    // Генерация отчёта об эффективности
    public function generateEfficiencyReport($data) {
        require_once __DIR__ . '/../modules/reporting/ReportGenerator.php';
        
        $generator = new ReportGenerator();
        $reportData = $generator->generateEfficiencyReport($data);
        
        // Сохраняем в БД
        $reportId = $this->create([
            'report_name' => $data['report_name'],
            'report_type' => 'efficiency',
            'zone_id' => $data['zone_id'] ?? null,
            'resource_type' => $data['resource_type'] ?? null,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'format' => $data['format'],
            'generated_by' => $data['generated_by'] ?? null
        ]);
        
        return [
            'report_id' => $reportId,
            'data' => $reportData
        ];
    }
    
    // Получение последних отчётов пользователя
    public function getUserReports($userId, $limit = 10) {
        $stmt = $this->db->prepare("
            SELECT r.*, z.zone_name
            FROM reports r
            LEFT JOIN zones z ON r.zone_id = z.zone_id
            WHERE r.generated_by = :user_id
            ORDER BY r.generated_at DESC
            LIMIT :limit
        ");
        
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    // Получение отчётов по зоне
    public function getByZone($zoneId) {
        $stmt = $this->db->prepare("
            SELECT r.*, u.full_name as generated_by_name
            FROM reports r
            LEFT JOIN users u ON r.generated_by = u.user_id
            WHERE r.zone_id = :zone_id
            ORDER BY r.generated_at DESC
        ");
        $stmt->execute(['zone_id' => $zoneId]);
        return $stmt->fetchAll();
    }
    
    // Обновление пути к файлу отчёта
    public function updateFilePath($reportId, $filePath) {
        $stmt = $this->db->prepare("
            UPDATE reports 
            SET file_path = :file_path 
            WHERE report_id = :report_id
        ");
        return $stmt->execute([
            'report_id' => $reportId,
            'file_path' => $filePath
        ]);
    }
    
    // Получение количества отчётов по месяцам (для графика)
    public function getCountByMonth($months = 12) {
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(generated_at, '%Y-%m') as month,
                COUNT(*) as count,
                report_type
            FROM reports
            WHERE generated_at >= DATE_SUB(NOW(), INTERVAL :months MONTH)
            GROUP BY month, report_type
            ORDER BY month DESC, report_type
        ");
        
        $stmt->bindParam(':months', $months, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    // Проверка существования отчёта
    public function exists($reportId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM reports WHERE report_id = :report_id");
        $stmt->execute(['report_id' => $reportId]);
        return $stmt->fetchColumn() > 0;
    }
    
    // Получение популярных отчётов
    public function getPopularReports($limit = 5) {
        $stmt = $this->db->query("
            SELECT 
                report_type,
                COUNT(*) as count
            FROM reports
            GROUP BY report_type
            ORDER BY count DESC
            LIMIT :limit
        ");
        
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}
?>