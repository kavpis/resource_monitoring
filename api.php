<?php
/**
 * REST API для системы мониторинга ресурсов
 * Раздел 7.2 — Архитектура приложений
 * Раздел 6.1 — Модуль сбора данных
 * Раздел 6.5 — Обеспечение безопасности
 */

// Установка заголовков CORS и типов
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Обработка предварительных запросов (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Подключение конфигурации
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Sensor.php';
require_once __DIR__ . '/models/Reading.php';
require_once __DIR__ . '/models/Alert.php';
require_once __DIR__ . '/models/Zone.php';
require_once __DIR__ . '/models/DetectionRule.php';
require_once __DIR__ . '/models/Report.php';
require_once __DIR__ . '/modules/audit/AuditLogger.php';
require_once __DIR__ . '/modules/data_collection/DataCollector.php';

// Инициализация
$auth = new Auth();
$method = $_SERVER['REQUEST_METHOD'];

// Получение пути из URL
$request = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'];
$request = parse_url($request, PHP_URL_PATH);
$request = trim($request, '/');
$request = preg_replace('/^resource_monitoring\/api\.php\//', '', $request);

// Разбор пути
$segments = explode('/', $request);
$resource = $segments[0] ?? '';
$id = $segments[1] ?? null;
$action = $segments[2] ?? null;

/**
 * Вспомогательные функции
 */

// Отправка JSON-ответа
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// Получение тела запроса
function getRequestBody() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? $_POST;
}

// Проверка авторизации
function requireAuth($auth) {
    if (!$auth->isLoggedIn()) {
        sendResponse(['success' => false, 'message' => 'Требуется авторизация'], 401);
    }
}

// Проверка роли
function requireRole($auth, $role) {
    requireAuth($auth);
    if (!$auth->hasRole($role)) {
        sendResponse(['success' => false, 'message' => 'Недостаточно прав'], 403);
    }
}

// Проверка доступа к зоне
function requireZoneAccess($auth, $zoneId) {
    requireAuth($auth);
    if (!$auth->canAccessZone($zoneId)) {
        sendResponse(['success' => false, 'message' => 'Нет доступа к зоне'], 403);
    }
}

/**
 * Роутинг API
 */
try {
    switch ($resource) {
        // =================================================================
        // АВТОРИЗАЦИЯ
        // =================================================================
        case 'auth':
            switch ($action) {
                case 'login':
                    if ($method === 'POST') {
                        $data = getRequestBody();
                        $login = $data['login'] ?? '';
                        $password = $data['password'] ?? '';
                        
                        $result = $auth->login($login, $password);
                        sendResponse($result, $result['success'] ? 200 : 401);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                case 'logout':
                    if ($method === 'POST') {
                        $auth->logout();
                        sendResponse(['success' => true, 'message' => 'Выход выполнен']);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                case 'me':
                    requireAuth($auth);
                    $user = $auth->getUser();
                    unset($user['password_hash']);
                    sendResponse(['success' => true, 'data' => $user]);
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Действие не найдено'], 404);
            }
            break;
            
        // =================================================================
        // СЧЁТЧИКИ
        // =================================================================
        case 'sensors':
            requireAuth($auth);
            
            switch ($method) {
                case 'GET':
                    $sensorModel = new Sensor();
                    
                    if ($id) {
                        // Получение конкретного счётчика
                        $sensor = $sensorModel->getById($id);
                        if ($sensor) {
                            // Проверка доступа к зоне
                            if (!$auth->canAccessZone($sensor['zone_id'])) {
                                sendResponse(['success' => false, 'message' => 'Нет доступа к зоне'], 403);
                            }
                            sendResponse(['success' => true, 'data' => $sensor]);
                        } else {
                            sendResponse(['success' => false, 'message' => 'Счётчик не найден'], 404);
                        }
                    } else {
                        // Получение всех счётчиков с фильтрацией
                        $filters = [
                            'resource_type' => $_GET['resource_type'] ?? null,
                            'zone_id' => $_GET['zone_id'] ?? null,
                            'status' => $_GET['status'] ?? null
                        ];
                        
                        $sensors = $sensorModel->getAllWithLastReadings($filters);
                        
                        // Фильтрация по правам доступа
                        if ($auth->getRole() !== 'admin' && $auth->getRole() !== 'dispatcher') {
                            $zoneAccess = json_decode($auth->getUser()['zone_access'] ?? '[]', true);
                            if (!empty($zoneAccess)) {
                                $sensors = array_filter($sensors, function($sensor) use ($zoneAccess) {
                                    return in_array($sensor['zone_id'], $zoneAccess);
                                });
                            }
                        }
                        
                        sendResponse(['success' => true, 'data' => $sensors]);
                    }
                    break;
                    
                case 'POST':
                    requireRole($auth, 'admin');
                    $data = getRequestBody();
                    
                    $required = ['sensor_name', 'resource_type', 'zone_id', 'ip_address', 'modbus_address', 'register_address', 'unit_of_measurement'];
                    foreach ($required as $field) {
                        if (empty($data[$field])) {
                            sendResponse(['success' => false, 'message' => "Поле '{$field}' обязательно"], 400);
                        }
                    }
                    
                    $sensorModel = new Sensor();
                    $result = $sensorModel->create($data);
                    
                    if ($result) {
                        $sensorId = $sensorModel->db->lastInsertId();
                        
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'create_sensor',
                            "Создан счётчик: {$data['sensor_name']}",
                            'sensor',
                            $sensorId
                        );
                        
                        sendResponse([
                            'success' => true,
                            'message' => 'Счётчик создан',
                            'sensor_id' => $sensorId
                        ]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при создании счётчика'], 500);
                    }
                    break;
                    
                case 'PUT':
                    requireRole($auth, 'admin');
                    if (!$id) {
                        sendResponse(['success' => false, 'message' => 'ID счётчика обязателен'], 400);
                    }
                    
                    $data = getRequestBody();
                    $sensorModel = new Sensor();
                    
                    $result = $sensorModel->update($id, $data);
                    
                    if ($result) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'update_sensor',
                            "Обновлён счётчик ID: {$id}",
                            'sensor',
                            $id
                        );
                        
                        sendResponse(['success' => true, 'message' => 'Счётчик обновлён']);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при обновлении счётчика'], 500);
                    }
                    break;
                    
                case 'DELETE':
                    requireRole($auth, 'admin');
                    if (!$id) {
                        sendResponse(['success' => false, 'message' => 'ID счётчика обязателен'], 400);
                    }
                    
                    $sensorModel = new Sensor();
                    $result = $sensorModel->delete($id);
                    
                    if ($result) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'delete_sensor',
                            "Удалён счётчик ID: {$id}",
                            'sensor',
                            $id
                        );
                        
                        sendResponse(['success' => true, 'message' => 'Счётчик удалён']);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при удалении счётчика'], 500);
                    }
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
            }
            break;
            
        // =================================================================
        // ПОКАЗАНИЯ
        // =================================================================
        case 'readings':
            requireAuth($auth);
            
            switch ($method) {
                case 'GET':
                    $readingModel = new Reading();
                    
                    if ($id) {
                        // Получение конкретного показания
                        $reading = $readingModel->getById($id);
                        if ($reading) {
                            // Проверка доступа к зоне счётчика
                            $sensorModel = new Sensor();
                            $sensor = $sensorModel->getById($reading['sensor_id']);
                            if ($sensor && !$auth->canAccessZone($sensor['zone_id'])) {
                                sendResponse(['success' => false, 'message' => 'Нет доступа к зоне'], 403);
                            }
                            sendResponse(['success' => true, 'data' => $reading]);
                        } else {
                            sendResponse(['success' => false, 'message' => 'Показание не найдено'], 404);
                        }
                    } else {
                        // Получение показаний с фильтрацией
                        $sensorId = $_GET['sensor_id'] ?? null;
                        $zoneId = $_GET['zone_id'] ?? null;
                        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-24 hours'));
                        $endDate = $_GET['end_date'] ?? date('Y-m-d H:i:s');
                        $limit = min(1000, (int)($_GET['limit'] ?? 100));
                        
                        // Проверка доступа к зоне
                        if ($zoneId && !$auth->canAccessZone($zoneId)) {
                            sendResponse(['success' => false, 'message' => 'Нет доступа к зоне'], 403);
                        }
                        
                        if ($sensorId) {
                            // Проверка доступа к конкретному счётчику
                            $sensorModel = new Sensor();
                            $sensor = $sensorModel->getById($sensorId);
                            if ($sensor && !$auth->canAccessZone($sensor['zone_id'])) {
                                sendResponse(['success' => false, 'message' => 'Нет доступа к зоне счётчика'], 403);
                            }
                            
                            $readings = $readingModel->getByPeriod($sensorId, $startDate, $endDate, $limit);
                        } else {
                            // Получение показаний по зоне
                            $readings = $readingModel->getByZonePeriod($zoneId, $startDate, $endDate, $limit);
                        }
                        
                        sendResponse(['success' => true, 'data' => $readings]);
                    }
                    break;
                    
                case 'POST':
                    requireRole($auth, 'admin');
                    $data = getRequestBody();
                    
                    $required = ['sensor_id', 'reading_value', 'unit_of_measurement'];
                    foreach ($required as $field) {
                        if (!isset($data[$field])) {
                            sendResponse(['success' => false, 'message' => "Поле '{$field}' обязательно"], 400);
                        }
                    }
                    
                    $readingModel = new Reading();
                    $result = $readingModel->add(
                        $data['sensor_id'],
                        $data['reading_value'],
                        $data['unit_of_measurement'],
                        $data['reading_timestamp'] ?? null,
                        $data['is_valid'] ?? true,
                        $data['validation_error'] ?? null
                    );
                    
                    if ($result) {
                        sendResponse(['success' => true, 'message' => 'Показание добавлено']);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при добавлении показания'], 500);
                    }
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
            }
            break;
            
        // =================================================================
        // АВАРИИ
        // =================================================================
        case 'alerts':
            requireAuth($auth);
            
            switch ($method) {
                case 'GET':
                    $alertModel = new Alert();
                    
                    if ($id) {
                        // Получение конкретной аварии
                        $alert = $alertModel->getById($id);
                        if ($alert) {
                            // Проверка доступа к зоне
                            if ($alert['zone_id'] && !$auth->canAccessZone($alert['zone_id'])) {
                                sendResponse(['success' => false, 'message' => 'Нет доступа к зоне'], 403);
                            }
                            sendResponse(['success' => true, 'data' => $alert]);
                        } else {
                            sendResponse(['success' => false, 'message' => 'Авария не найдена'], 404);
                        }
                    } else {
                        // Получение аварий с фильтрацией
                        $filters = [
                            'alert_level' => $_GET['alert_level'] ?? null,
                            'status' => $_GET['status'] ?? null,
                            'zone_id' => $_GET['zone_id'] ?? null,
                            'sensor_id' => $_GET['sensor_id'] ?? null,
                            'limit' => min(1000, (int)($_GET['limit'] ?? 50))
                        ];
                        
                        // Проверка доступа к зоне
                        if ($filters['zone_id'] && !$auth->canAccessZone($filters['zone_id'])) {
                            sendResponse(['success' => false, 'message' => 'Нет доступа к зоне'], 403);
                        }
                        
                        $alerts = $alertModel->getActive($filters);
                        
                        sendResponse(['success' => true, 'data' => $alerts]);
                    }
                    break;
                    
                case 'POST':
                    requireRole($auth, 'admin');
                    $data = getRequestBody();
                    
                    $required = ['alert_type', 'alert_level', 'description'];
                    foreach ($required as $field) {
                        if (empty($data[$field])) {
                            sendResponse(['success' => false, 'message' => "Поле '{$field}' обязательно"], 400);
                        }
                    }
                    
                    $alertModel = new Alert();
                    $alertId = $alertModel->create($data);
                    
                    if ($alertId) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'create_alert',
                            "Создана авария: {$data['alert_type']}",
                            'alert',
                            $alertId
                        );
                        
                        sendResponse([
                            'success' => true,
                            'message' => 'Авария создана',
                            'alert_id' => $alertId
                        ]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при создании аварии'], 500);
                    }
                    break;
                    
                case 'PUT':
                    requireAuth($auth);
                    if (!$id) {
                        sendResponse(['success' => false, 'message' => 'ID аварии обязателен'], 400);
                    }
                    
                    $data = getRequestBody();
                    $alertModel = new Alert();
                    
                    // Проверка доступа к аварии
                    $alert = $alertModel->getById($id);
                    if (!$alert) {
                        sendResponse(['success' => false, 'message' => 'Авария не найдена'], 404);
                    }
                    
                    if ($alert['zone_id'] && !$auth->canAccessZone($alert['zone_id'])) {
                        sendResponse(['success' => false, 'message' => 'Нет доступа к зоне'], 403);
                    }
                    
                    // Обновление статуса
                    if (isset($data['status'])) {
                        $result = $alertModel->updateStatus($id, $data['status'], $auth->getUser()['user_id']);
                        
                        if ($result) {
                            // Логирование
                            $auth->logAction(
                                $auth->getUser()['user_id'],
                                'update_alert_status',
                                "Изменён статус аварии ID: {$id} на {$data['status']}",
                                'alert',
                                $id
                            );
                            
                            sendResponse(['success' => true, 'message' => 'Статус аварии обновлён']);
                        } else {
                            sendResponse(['success' => false, 'message' => 'Ошибка при обновлении статуса'], 500);
                        }
                    } else {
                        sendResponse(['success' => false, 'message' => 'Нет данных для обновления'], 400);
                    }
                    break;
                    
                case 'DELETE':
                    requireRole($auth, 'admin');
                    if (!$id) {
                        sendResponse(['success' => false, 'message' => 'ID аварии обязателен'], 400);
                    }
                    
                    $alertModel = new Alert();
                    $result = $alertModel->delete($id);
                    
                    if ($result) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'delete_alert',
                            "Удалена авария ID: {$id}",
                            'alert',
                            $id
                        );
                        
                        sendResponse(['success' => true, 'message' => 'Авария удалена']);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при удалении аварии'], 500);
                    }
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
            }
            break;
            
        // =================================================================
        // ЗОНЫ
        // =================================================================
        case 'zones':
            requireAuth($auth);
            
            switch ($method) {
                case 'GET':
                    $zoneModel = new Zone();
                    
                    if ($id) {
                        // Получение конкретной зоны
                        $zone = $zoneModel->getById($id);
                        if ($zone) {
                            if (!$auth->canAccessZone($id)) {
                                sendResponse(['success' => false, 'message' => 'Нет доступа к зоне'], 403);
                            }
                            sendResponse(['success' => true, 'data' => $zone]);
                        } else {
                            sendResponse(['success' => false, 'message' => 'Зона не найдена'], 404);
                        }
                    } else {
                        // Получение всех зон
                        $zones = $zoneModel->getAll();
                        
                        // Фильтрация по правам доступа
                        if ($auth->getRole() !== 'admin' && $auth->getRole() !== 'dispatcher') {
                            $zoneAccess = json_decode($auth->getUser()['zone_access'] ?? '[]', true);
                            if (!empty($zoneAccess)) {
                                $zones = array_filter($zones, function($zone) use ($zoneAccess) {
                                    return in_array($zone['zone_id'], $zoneAccess);
                                });
                            }
                        }
                        
                        sendResponse(['success' => true, 'data' => $zones]);
                    }
                    break;
                    
                case 'POST':
                    requireRole($auth, 'admin');
                    $data = getRequestBody();
                    
                    $required = ['zone_name', 'zone_type'];
                    foreach ($required as $field) {
                        if (empty($data[$field])) {
                            sendResponse(['success' => false, 'message' => "Поле '{$field}' обязательно"], 400);
                        }
                    }
                    
                    $zoneModel = new Zone();
                    $zoneId = $zoneModel->create($data);
                    
                    if ($zoneId) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'create_zone',
                            "Создана зона: {$data['zone_name']}",
                            'zone',
                            $zoneId
                        );
                        
                        sendResponse([
                            'success' => true,
                            'message' => 'Зона создана',
                            'zone_id' => $zoneId
                        ]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при создании зоны'], 500);
                    }
                    break;
                    
                case 'PUT':
                    requireRole($auth, 'admin');
                    if (!$id) {
                        sendResponse(['success' => false, 'message' => 'ID зоны обязателен'], 400);
                    }
                    
                    $data = getRequestBody();
                    $zoneModel = new Zone();
                    
                    $result = $zoneModel->update($id, $data);
                    
                    if ($result) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'update_zone',
                            "Обновлена зона ID: {$id}",
                            'zone',
                            $id
                        );
                        
                        sendResponse(['success' => true, 'message' => 'Зона обновлена']);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при обновлении зоны'], 500);
                    }
                    break;
                    
                case 'DELETE':
                    requireRole($auth, 'admin');
                    if (!$id) {
                        sendResponse(['success' => false, 'message' => 'ID зоны обязателен'], 400);
                    }
                    
                    $zoneModel = new Zone();
                    $result = $zoneModel->delete($id);
                    
                    if ($result) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'delete_zone',
                            "Удалена зона ID: {$id}",
                            'zone',
                            $id
                        );
                        
                        sendResponse(['success' => true, 'message' => 'Зона удалена']);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при удалении зоны'], 500);
                    }
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
            }
            break;
            
        // =================================================================
        // ПОЛЬЗОВАТЕЛИ
        // =================================================================
        case 'users':
            requireRole($auth, 'admin');
            
            switch ($method) {
                case 'GET':
                    $userModel = new User();
                    
                    if ($id) {
                        // Получение конкретного пользователя
                        $user = $userModel->getById($id);
                        if ($user) {
                            unset($user['password_hash']); // Не возвращаем хеш пароля
                            sendResponse(['success' => true, 'data' => $user]);
                        } else {
                            sendResponse(['success' => false, 'message' => 'Пользователь не найден'], 404);
                        }
                    } else {
                        // Получение всех пользователей
                        $users = $userModel->getAll();
                        // Убираем хеши паролей
                        foreach ($users as &$user) {
                            unset($user['password_hash']);
                        }
                        sendResponse(['success' => true, 'data' => $users]);
                    }
                    break;
                    
                case 'POST':
                    $data = getRequestBody();
                    
                    $required = ['login', 'password', 'role', 'full_name'];
                    foreach ($required as $field) {
                        if (empty($data[$field])) {
                            sendResponse(['success' => false, 'message' => "Поле '{$field}' обязательно"], 400);
                        }
                    }
                    
                    $userModel = new User();
                    $result = $userModel->create($data);
                    
                    if ($result['success']) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'create_user',
                            "Создан пользователь: {$data['full_name']} ({$data['login']})",
                            'user',
                            $result['user_id']
                        );
                        
                        sendResponse([
                            'success' => true,
                            'message' => $result['message'],
                            'user_id' => $result['user_id']
                        ]);
                    } else {
                        sendResponse(['success' => false, 'message' => $result['message']], 400);
                    }
                    break;
                    
                case 'PUT':
                    if (!$id) {
                        sendResponse(['success' => false, 'message' => 'ID пользователя обязателен'], 400);
                    }
                    
                    $data = getRequestBody();
                    $userModel = new User();
                    
                    $result = $userModel->update($id, $data);
                    
                    if ($result['success']) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'update_user',
                            "Обновлён пользователь ID: {$id}",
                            'user',
                            $id
                        );
                        
                        sendResponse(['success' => true, 'message' => $result['message']]);
                    } else {
                        sendResponse(['success' => false, 'message' => $result['message']], 400);
                    }
                    break;
                    
                case 'DELETE':
                    if ($id == $auth->getUser()['user_id']) {
                        sendResponse(['success' => false, 'message' => 'Нельзя удалить самого себя'], 400);
                    }
                    
                    $userModel = new User();
                    $result = $userModel->delete($id);
                    
                    if ($result) {
                        // Логирование
                        $auth->logAction(
                            $auth->getUser()['user_id'],
                            'delete_user',
                            "Удалён пользователь ID: {$id}",
                            'user',
                            $id
                        );
                        
                        sendResponse(['success' => true, 'message' => 'Пользователь удалён']);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Ошибка при удалении пользователя'], 500);
                    }
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
            }
            break;
            
        // =================================================================
        // СБОР ДАННЫХ
        // =================================================================
        case 'collect':
            requireRole($auth, 'admin');
            
            $collector = new DataCollector();
            
            switch ($action) {
                case 'run':
                    if ($method === 'POST') {
                        $result = $collector->collectAll();
                        sendResponse($result);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                case 'test_connection':
                    if ($method === 'GET' && $id) {
                        $result = $collector->testConnection($id);
                        sendResponse($result);
                    } else {
                        sendResponse(['success' => false, 'message' => 'ID счётчика обязателен'], 400);
                    }
                    break;
                    
                case 'status':
                    if ($method === 'GET') {
                        $stats = $collector->getStatistics();
                        sendResponse([
                            'success' => true,
                            'data' => $stats
                        ]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Действие не найдено'], 404);
            }
            break;
            
        // =================================================================
        // АУДИТ
        // =================================================================
        case 'audit':
            requireRole($auth, 'admin');
            
            $auditLogger = new AuditLogger();
            
            switch ($action) {
                case 'logs':
                    if ($method === 'GET') {
                        $filters = [
                            'user_id' => $_GET['user_id'] ?? null,
                            'action_type' => $_GET['action_type'] ?? null,
                            'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')),
                            'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
                            'limit' => min(1000, (int)($_GET['limit'] ?? 100))
                        ];
                        
                        $logs = $auditLogger->getLogs($filters);
                        sendResponse(['success' => true, 'data' => $logs]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                case 'security-analysis':
                    if ($method === 'GET') {
                        require_once __DIR__ . '/modules/audit/AuditAnalyzer.php';
                        $analyzer = new AuditAnalyzer();
                        $analysis = $analyzer->analyzeSuspiciousActivity([
                            'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')),
                            'end_date' => $_GET['end_date'] ?? date('Y-m-d')
                        ]);
                        sendResponse(['success' => true, 'data' => $analysis]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Действие не найдено'], 404);
            }
            break;
            
        // =================================================================
        // ОТЧЁТЫ
        // =================================================================
        case 'reports':
            requireAuth($auth);
            
            switch ($action) {
                case 'generate':
                    requireRole($auth, 'admin');
                    if ($method === 'POST') {
                        $data = getRequestBody();
                        require_once __DIR__ . '/modules/reports/ReportGenerator.php';
                        $reportGenerator = new ReportGenerator();
                        
                        $reportType = $data['type'] ?? 'consumption';
                        $params = $data['params'] ?? [];
                        
                        $result = match($reportType) {
                            'consumption' => $reportGenerator->generateConsumptionReport($params),
                            'alerts' => $reportGenerator->generateAlertsReport($params),
                            'efficiency' => $reportGenerator->generateEfficiencyReport($params),
                            'custom' => $reportGenerator->generateCustomReport($params),
                            default => ['success' => false, 'message' => 'Неизвестный тип отчёта']
                        };
                        
                        sendResponse($result);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                case 'list':
                    if ($method === 'GET') {
                        require_once __DIR__ . '/modules/reports/ReportGenerator.php';
                        $reportGenerator = new ReportGenerator();
                        $reports = $reportGenerator->getSavedReports();
                        sendResponse(['success' => true, 'data' => $reports]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                case 'export':
                    requireRole($auth, 'admin');
                    if ($method === 'GET') {
                        $reportType = $segments[2] ?? 'alerts';
                        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
                        $endDate = $_GET['end_date'] ?? date('Y-m-d');
                        $format = $_GET['format'] ?? 'csv';
                        
                        require_once __DIR__ . '/modules/reports/ExportService.php';
                        $exportService = new ExportService();
                        
                        $result = $exportService->exportToFile($reportType, [
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'format' => $format
                        ]);
                        
                        if ($result['success']) {
                            // Отправка файла для скачивания
                            header('Content-Type: application/octet-stream');
                            header('Content-Disposition: attachment; filename="' . basename($result['file_path']) . '"');
                            header('Content-Length: ' . filesize($result['file_path']));
                            readfile($result['file_path']);
                            exit();
                        } else {
                            sendResponse($result, 500);
                        }
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Действие не найдено'], 404);
            }
            break;
            
        // =================================================================
        // ВИЗУАЛИЗАЦИЯ
        // =================================================================
        case 'visualization':
            requireAuth($auth);
            
            $visAction = $_GET['visualization_action'] ?? $action;
            
            require_once __DIR__ . '/modules/visualizations/VisualizationController.php';
            $visController = new VisualizationController();
            
            $params = [
                'resource' => $_GET['resource'] ?? null,
                'zone_id' => $_GET['zone_id'] ?? null,
                'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
                'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
                'grouping' => $_GET['grouping'] ?? 'day',
                'period' => $_GET['period'] ?? 'day'
            ];
            
            $result = $visController->handleRequest($visAction, $params);
            sendResponse($result, $result['success'] ? 200 : 400);
            break;
            
        // =================================================================
        // АНАЛИТИКА
        // =================================================================
        case 'analytics':
            requireAuth($auth);
            
            switch ($action) {
                case 'dashboard':
                    if ($method === 'GET') {
                        require_once __DIR__ . '/modules/analytics/DashboardService.php';
                        $dashboardService = new DashboardService();
                        
                        $data = $dashboardService->getDashboardData([
                            'user_id' => $auth->getUser()['user_id'],
                            'role' => $auth->getRole()
                        ]);
                        
                        sendResponse(['success' => true, 'data' => $data]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                case 'trends':
                    if ($method === 'GET') {
                        require_once __DIR__ . '/modules/analytics/TrendAnalyzer.php';
                        $trendAnalyzer = new TrendAnalyzer();
                        
                        $data = $trendAnalyzer->getTrends([
                            'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
                            'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
                            'resource_type' => $_GET['resource_type'] ?? 'all',
                            'zone_id' => $_GET['zone_id'] ?? null
                        ]);
                        
                        sendResponse(['success' => true, 'data' => $data]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                case 'efficiency':
                    if ($method === 'GET') {
                        require_once __DIR__ . '/modules/analytics/EfficiencyAnalyzer.php';
                        $efficiencyAnalyzer = new EfficiencyAnalyzer();
                        
                        $data = $efficiencyAnalyzer->getEfficiencyData([
                            'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
                            'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
                            'zone_id' => $_GET['zone_id'] ?? null
                        ]);
                        
                        sendResponse(['success' => true, 'data' => $data]);
                    } else {
                        sendResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
                    }
                    break;
                    
                default:
                    sendResponse(['success' => false, 'message' => 'Действие не найдено'], 404);
            }
            break;
            
        // =================================================================
        // НЕИЗВЕСТНЫЙ РЕСУРС
        // =================================================================
        default:
            sendResponse(['success' => false, 'message' => 'Ресурс не найден'], 404);
    }
    
} catch (PDOException $e) {
    error_log("API Database Error: " . $e->getMessage());
    sendResponse([
        'success' => false,
        'message' => 'Ошибка базы данных',
        'error' => defined('DEBUG') && DEBUG ? $e->getMessage() : null
    ], 500);
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    sendResponse([
        'success' => false,
        'message' => 'Внутренняя ошибка сервера',
        'error' => defined('DEBUG') && DEBUG ? $e->getMessage() : null
    ], 500);
}
?>