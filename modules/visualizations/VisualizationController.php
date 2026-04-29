<?php
/**
 * Контроллер для управления визуализациями и графиками
 * 
 * @package Visualizations
 */

require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once __DIR__ . '/ChartDataGenerator.php';
require_once __DIR__ . '/VisualizationLogger.php';

class VisualizationController {
    private $auth;
    private $generator;
    private $logger;
    
    /**
     * Конструктор
     */
    public function __construct() {
        $this->auth = Auth::getInstance();
        $this->generator = new ChartDataGenerator();
        $this->logger = VisualizationLogger::getInstance();
        
        $this->logger->info('Контроллер визуализаций инициализирован', ['component' => 'VisualizationController']);
    }
    
    /**
     * Обработать запрос API
     * 
     * @param string $action Действие
     * @param array $params Параметры
     * @return array Ответ
     */
    public function handleRequest($action, $params = []) {
        try {
            // Проверка авторизации
            if (!$this->auth->isLoggedIn()) {
                $this->logger->warning('Попытка доступа к визуализациям без авторизации', [
                    'component' => 'VisualizationController',
                    'action' => $action
                ]);
                return ['success' => false, 'error' => 'Требуется авторизация'];
            }
            
            $user = $this->auth->getCurrentUser();
            $this->logger->debug('Запрос к визуализациям', [
                'component' => 'VisualizationController',
                'action' => $action,
                'user' => $user['username']
            ]);
            
            switch ($action) {
                case 'consumption':
                    return $this->getConsumptionChart($params);
                
                case 'zone_comparison':
                    return $this->getZoneComparisonChart($params);
                
                case 'alerts':
                    return $this->getAlertsChart($params);
                
                case 'map':
                    return $this->getMapData($params);
                
                case 'realtime':
                    return $this->getRealtimeData($params);
                
                case 'dashboard':
                    return $this->getDashboardData($params);
                
                default:
                    $this->logger->warning('Неизвестное действие визуализации', [
                        'component' => 'VisualizationController',
                        'action' => $action
                    ]);
                    return ['success' => false, 'error' => 'Неизвестное действие'];
            }
            
        } catch (Exception $e) {
            $this->logger->critical('Ошибка в контроллере визуализаций: ' . $e->getMessage(), [
                'component' => 'VisualizationController',
                'action' => $action,
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
        }
    }
    
    /**
     * Получить данные графика потребления
     */
    private function getConsumptionChart($params) {
        $resourceType = isset($params['resource']) ? $params['resource'] : 'electricity';
        $zoneId = isset($params['zone_id']) ? (int)$params['zone_id'] : null;
        $startDate = isset($params['start_date']) ? $params['start_date'] : date('Y-m-d', strtotime('-30 days'));
        $endDate = isset($params['end_date']) ? $params['end_date'] : date('Y-m-d');
        $grouping = isset($params['grouping']) ? $params['grouping'] : 'day';
        
        // Проверка прав доступа к зоне
        if ($zoneId !== null && !$this->auth->hasZoneAccess($zoneId)) {
            $this->logger->warning('Попытка доступа к данным зоны без прав', [
                'component' => 'VisualizationController',
                'zone_id' => $zoneId,
                'user' => $this->auth->getCurrentUser()['username']
            ]);
            return ['success' => false, 'error' => 'Нет доступа к данной зоне'];
        }
        
        $data = $this->generator->getConsumptionData(
            $resourceType,
            $zoneId,
            $startDate,
            $endDate,
            $grouping
        );
        
        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']];
        }
        
        return [
            'success' => true,
            'data' => $data,
            'metadata' => [
                'resource_type' => $resourceType,
                'zone_id' => $zoneId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'grouping' => $grouping
            ]
        ];
    }
    
    /**
     * Получить данные сравнения зон
     */
    private function getZoneComparisonChart($params) {
        $resourceType = isset($params['resource']) ? $params['resource'] : 'electricity';
        $startDate = isset($params['start_date']) ? $params['start_date'] : date('Y-m-d', strtotime('-30 days'));
        $endDate = isset($params['end_date']) ? $params['end_date'] : date('Y-m-d');
        
        $data = $this->generator->getZoneComparisonData($resourceType, $startDate, $endDate);
        
        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']];
        }
        
        return [
            'success' => true,
            'data' => $data,
            'metadata' => [
                'resource_type' => $resourceType,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ];
    }
    
    /**
     * Получить данные графика аварий
     */
    private function getAlertsChart($params) {
        $startDate = isset($params['start_date']) ? $params['start_date'] : date('Y-m-d', strtotime('-30 days'));
        $endDate = isset($params['end_date']) ? $params['end_date'] : date('Y-m-d');
        $zoneId = isset($params['zone_id']) ? (int)$params['zone_id'] : null;
        
        // Проверка прав доступа к зоне
        if ($zoneId !== null && !$this->auth->hasZoneAccess($zoneId)) {
            return ['success' => false, 'error' => 'Нет доступа к данной зоне'];
        }
        
        $data = $this->generator->getAlertsData($startDate, $endDate, $zoneId);
        
        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']];
        }
        
        return [
            'success' => true,
            'data' => $data,
            'metadata' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'zone_id' => $zoneId
            ]
        ];
    }
    
    /**
     * Получить данные карты объекта
     */
    private function getMapData($params) {
        $data = $this->generator->getMapData();
        
        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']];
        }
        
        // Фильтрация зон по правам доступа
        $user = $this->auth->getCurrentUser();
        if ($user['role'] !== 'admin') {
            $accessibleZones = $this->auth->getAccessibleZones();
            $data['zones'] = array_filter($data['zones'], function($zone) use ($accessibleZones) {
                return in_array($zone['id'], $accessibleZones) || 
                       in_array($zone['parent_id'], $accessibleZones);
            });
        }
        
        return [
            'success' => true,
            'data' => $data
        ];
    }
    
    /**
     * Получить данные реального времени
     */
    private function getRealtimeData($params) {
        $zoneId = isset($params['zone_id']) ? (int)$params['zone_id'] : null;
        
        // Проверка прав доступа к зоне
        if ($zoneId !== null && !$this->auth->hasZoneAccess($zoneId)) {
            return ['success' => false, 'error' => 'Нет доступа к данной зоне'];
        }
        
        $data = $this->generator->getRealtimeData($zoneId);
        
        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']];
        }
        
        return [
            'success' => true,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Получить сводные данные для дашборда
     */
    private function getDashboardData($params) {
        $zoneId = isset($params['zone_id']) ? (int)$params['zone_id'] : null;
        $period = isset($params['period']) ? $params['period'] : 'day';
        
        // Проверка прав доступа к зоне
        if ($zoneId !== null && !$this->auth->hasZoneAccess($zoneId)) {
            return ['success' => false, 'error' => 'Нет доступа к данной зоне'];
        }
        
        $startDate = date('Y-m-d', strtotime('-' . $period));
        $endDate = date('Y-m-d');
        
        $dashboardData = [
            'consumption' => [],
            'alerts_summary' => null,
            'realtime' => null,
            'zones_status' => null
        ];
        
        // Данные потребления по каждому ресурсу
        foreach (['water', 'heat', 'electricity'] as $resource) {
            $dashboardData['consumption'][$resource] = $this->generator->getConsumptionData(
                $resource,
                $zoneId,
                $startDate,
                $endDate,
                'day'
            );
        }
        
        // Данные аварий
        $dashboardData['alerts_summary'] = $this->generator->getAlertsData($startDate, $endDate, $zoneId);
        
        // Данные реального времени
        $dashboardData['realtime'] = $this->generator->getRealtimeData($zoneId);
        
        // Статус зон
        $mapData = $this->generator->getMapData();
        $dashboardData['zones_status'] = isset($mapData['zones']) ? $mapData['zones'] : [];
        
        return [
            'success' => true,
            'data' => $dashboardData,
            'metadata' => [
                'zone_id' => $zoneId,
                'period' => $period,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }
}
