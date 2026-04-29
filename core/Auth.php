<?php
/**
 * Система авторизации и управления сессиями
 * Раздел 6.5 — Ролевая модель доступа
 */

require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;
    private $user = null;
    
    public function __construct() {
        // Для CLI режима сессии не нужны
        if (defined('CLI_MODE') && CLI_MODE === true) {
            return;
        }
        
        $this->db = getDB();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->checkSession();
    }
    
    // Проверка активной сессии
    private function checkSession() {
        if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
            // Проверка времени жизни сессии
            if ((time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
                $this->logout();
            } else {
                $_SESSION['last_activity'] = time();
                $this->loadUser($_SESSION['user_id']);
            }
        }
    }
    
    // Загрузка данных пользователя
    private function loadUser($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT user_id, login, full_name, role, email, is_active 
                FROM users 
                WHERE user_id = :user_id
            ");
            $stmt->execute(['user_id' => $userId]);
            $this->user = $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Auth loadUser error: " . $e->getMessage());
        }
    }
    
    // Проверка авторизации
    public function isLoggedIn() {
        return $this->user !== null && $this->user['is_active'] == 1;
    }
    
    // Получение текущего пользователя
    public function getUser() {
        return $this->user;
    }
    
    // Получение роли пользователя
    public function getRole() {
        return $this->user['role'] ?? null;
    }
    
    // Проверка роли
    public function hasRole($role) {
        return $this->isLoggedIn() && $this->user['role'] === $role;
    }
    
    // Проверка прав доступа (для инженеров — ограничение по зонам)
    public function canAccessZone($zoneId) {
        if (!$this->isLoggedIn()) return false;
        
        // Админ и диспетчер — полный доступ
        if (in_array($this->user['role'], ['admin', 'dispatcher', 'manager'])) {
            return true;
        }
        
        // Инженер — проверка зон доступа
        if ($this->user['role'] === 'engineer') {
            $zoneAccess = json_decode($this->user['zone_access'] ?? '[]', true);
            return empty($zoneAccess) || in_array($zoneId, $zoneAccess);
        }
        
        return false;
    }
    
    // Авторизация пользователя
    public function login($login, $password) {
        try {
            // Проверка на блокировку (защита от брутфорса)
            if ($this->isLocked($login)) {
                return ['success' => false, 'message' => 'Аккаунт временно заблокирован'];
            }
            
            $stmt = $this->db->prepare("
                SELECT * FROM users 
                WHERE login = :login AND is_active = 1
            ");
            $stmt->execute(['login' => $login]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $this->logFailedAttempt($login);
                return ['success' => false, 'message' => 'Неверный логин или пароль'];
            }
            
            // Проверка пароля
            if (!password_verify($password, $user['password_hash'])) {
                $this->logFailedAttempt($login);
                return ['success' => false, 'message' => 'Неверный логин или пароль'];
            }
            
            // Успешный вход — сброс счётчика попыток
            $this->resetFailedAttempts($login);
            
            // Создание сессии
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['last_activity'] = time();
            $_SESSION['login_time'] = time();
            
            // Обновление времени последнего входа
            $this->db->prepare("
                UPDATE users SET last_login = NOW() WHERE user_id = :user_id
            ")->execute(['user_id' => $user['user_id']]);
            
            // Логирование входа
            $this->logAction($user['user_id'], 'login', 'Успешный вход в систему');
            
            $this->user = $user;
            
            return ['success' => true, 'message' => 'Добро пожаловать, ' . $user['full_name'] . '!'];
            
        } catch (PDOException $e) {
            error_log("Auth login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Ошибка авторизации'];
        }
    }
    
    // Выход из системы
    public function logout() {
        if ($this->isLoggedIn()) {
            $this->logAction($this->user['user_id'], 'logout', 'Выход из системы');
        }
        
        session_unset();
        session_destroy();
        $this->user = null;
    }
    
    // Защита от брутфорса — логирование неудачных попыток
    private function logFailedAttempt($login) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $this->db->prepare("
            INSERT INTO audit_log (action_type, action_description, ip_address, action_timestamp)
            VALUES ('failed_login', 'Неудачная попытка входа: ' || :login, :ip, NOW())
        ");
        $stmt->execute(['login' => $login, 'ip' => $ip]);
        
        // Увеличение счётчика попыток
        $stmt = $this->db->prepare("
            UPDATE users 
            SET failed_attempts = COALESCE(failed_attempts, 0) + 1,
                last_failed_attempt = NOW()
            WHERE login = :login
        ");
        $stmt->execute(['login' => $login]);
    }
    
    // Проверка блокировки аккаунта
    private function isLocked($login) {
        $stmt = $this->db->prepare("
            SELECT failed_attempts, last_failed_attempt 
            FROM users 
            WHERE login = :login
        ");
        $stmt->execute(['login' => $login]);
        $user = $stmt->fetch();
        
        if (!$user) return false;
        
        $attempts = $user['failed_attempts'] ?? 0;
        $lastAttempt = $user['last_failed_attempt'] ?? null;
        
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockTime = strtotime($lastAttempt) + LOGIN_TIMEOUT;
            return time() < $lockTime;
        }
        
        return false;
    }
    
    // Сброс счётчика попыток
    private function resetFailedAttempts($login) {
        $this->db->prepare("
            UPDATE users 
            SET failed_attempts = 0, last_failed_attempt = NULL 
            WHERE login = :login
        ")->execute(['login' => $login]);
    }
    
    // Логирование действий пользователя
    public function logAction($userId, $actionType, $description, $entityType = null, $entityId = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_log 
                (user_id, action_type, action_description, entity_type, entity_id, ip_address, action_timestamp)
                VALUES (:user_id, :action_type, :description, :entity_type, :entity_id, :ip, NOW())
            ");
            $stmt->execute([
                'user_id' => $userId,
                'action_type' => $actionType,
                'description' => $description,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (PDOException $e) {
            error_log("Audit log error: " . $e->getMessage());
        }
    }
    
    // Требуется авторизация
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: /resource_monitoring/templates/login.php');
            exit();
        }
    }
    
    // Требуется определённая роль
    public function requireRole($role) {
        $this->requireLogin();
        if (!$this->hasRole($role)) {
            header('HTTP/1.0 403 Forbidden');
            die('Доступ запрещён');
        }
    }
}

// Глобальный экземпляр авторизации
$auth = new Auth();
?>