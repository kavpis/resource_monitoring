<?php
/**
 * Модель пользователей системы
 * Раздел 6.5 — Ролевая модель доступа
 */

require_once __DIR__ . '/../config/database.php';

class User {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    // Получение всех пользователей
    public function getAll() {
        $stmt = $this->db->query("
            SELECT user_id, login, full_name, role, email, phone, is_active, last_login, created_at
            FROM users
            ORDER BY role, full_name
        ");
        return $stmt->fetchAll();
    }
    
    // Получение пользователя по ID
    public function getById($userId) {
        $stmt = $this->db->prepare("
            SELECT * FROM users WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetch();
    }
    
    // Получение пользователя по логину
    public function getByLogin($login) {
        $stmt = $this->db->prepare("
            SELECT * FROM users WHERE login = :login
        ");
        $stmt->execute(['login' => $login]);
        return $stmt->fetch();
    }
    
    // Добавление нового пользователя
    public function create($data) {
        // Проверка уникальности логина
        if ($this->getByLogin($data['login'])) {
            return ['success' => false, 'message' => 'Пользователь с таким логином уже существует'];
        }
        
        // Хеширование пароля
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
        
        $stmt = $this->db->prepare("
            INSERT INTO users 
            (login, password_hash, role, full_name, email, phone, zone_access, is_active)
            VALUES 
            (:login, :password_hash, :role, :full_name, :email, :phone, :zone_access, :is_active)
        ");
        
        $result = $stmt->execute([
            'login' => $data['login'],
            'password_hash' => $passwordHash,
            'role' => $data['role'],
            'full_name' => $data['full_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'zone_access' => $data['zone_access'] ?? null,
            'is_active' => isset($data['is_active']) ? $data['is_active'] : 1
        ]);
        
        if ($result) {
            return [
                'success' => true,
                'message' => 'Пользователь успешно создан',
                'user_id' => $this->db->lastInsertId()
            ];
        }
        
        return ['success' => false, 'message' => 'Ошибка создания пользователя'];
    }
    
    // Обновление пользователя
    public function update($userId, $data) {
        $fields = [];
        $params = ['user_id' => $userId];
        
        // Если меняется пароль — хешируем
        if (isset($data['password']) && !empty($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
            unset($data['password']);
        }
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[$key] = $value;
        }
        
        if (empty($fields)) return ['success' => false, 'message' => 'Нет данных для обновления'];
        
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        
        $result = $stmt->execute($params);
        
        return [
            'success' => $result,
            'message' => $result ? 'Пользователь успешно обновлён' : 'Ошибка обновления'
        ];
    }
    
    // Удаление пользователя (мягкое — деактивация)
    public function delete($userId) {
        $stmt = $this->db->prepare("
            UPDATE users SET is_active = 0 WHERE user_id = :user_id
        ");
        return $stmt->execute(['user_id' => $userId]);
    }
    
    // Активация/деактивация пользователя
    public function setActive($userId, $isActive) {
        $stmt = $this->db->prepare("
            UPDATE users SET is_active = :is_active WHERE user_id = :user_id
        ");
        return $stmt->execute([
            'user_id' => $userId,
            'is_active' => $isActive ? 1 : 0
        ]);
    }
    
    // Получение пользователей по роли
    public function getByRole($role) {
        $stmt = $this->db->prepare("
            SELECT user_id, login, full_name, email, phone, is_active, last_login
            FROM users
            WHERE role = :role
            ORDER BY full_name
        ");
        $stmt->execute(['role' => $role]);
        return $stmt->fetchAll();
    }
    
    // Получение статистики по пользователям
    public function getStatistics() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN role = 'dispatcher' THEN 1 ELSE 0 END) as dispatchers,
                SUM(CASE WHEN role = 'engineer' THEN 1 ELSE 0 END) as engineers,
                SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
                SUM(CASE WHEN role = 'manager' THEN 1 ELSE 0 END) as managers
            FROM users
        ");
        return $stmt->fetch();
    }
    
    // Получение активных пользователей
    public function getActiveUsers() {
        $stmt = $this->db->query("
            SELECT user_id, login, full_name, role, email, last_login
            FROM users
            WHERE is_active = 1
            ORDER BY last_login DESC
        ");
        return $stmt->fetchAll();
    }
    
    // Проверка пароля
    public function verifyPassword($userId, $password) {
        $user = $this->getById($userId);
        if (!$user) return false;
        
        return password_verify($password, $user['password_hash']);
    }
    
    // Смена пароля
    public function changePassword($userId, $oldPassword, $newPassword) {
        $user = $this->getById($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'Пользователь не найден'];
        }
        
        // Проверка старого пароля
        if (!password_verify($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Неверный текущий пароль'];
        }
        
        // Хеширование нового пароля
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        
        $stmt = $this->db->prepare("
            UPDATE users SET password_hash = :password_hash WHERE user_id = :user_id
        ");
        
        $result = $stmt->execute([
            'user_id' => $userId,
            'password_hash' => $newHash
        ]);
        
        return [
            'success' => $result,
            'message' => $result ? 'Пароль успешно изменён' : 'Ошибка изменения пароля'
        ];
    }
    
    // Получение пользователей с доступом к зоне
    public function getUsersWithZoneAccess($zoneId) {
        $stmt = $this->db->prepare("
            SELECT DISTINCT u.*
            FROM users u
            WHERE u.is_active = 1
            AND (
                u.role IN ('admin', 'dispatcher', 'manager') 
                OR (
                    u.role = 'engineer' 
                    AND (
                        u.zone_access IS NULL 
                        OR JSON_CONTAINS(u.zone_access, :zone_id_str)
                    )
                )
            )
        ");
        
        $stmt->execute(['zone_id_str' => json_encode($zoneId)]);
        return $stmt->fetchAll();
    }
    
    // Обновление зон доступа для инженера
    public function updateZoneAccess($userId, $zoneIds) {
        $zoneAccessJson = json_encode($zoneIds);
        
        $stmt = $this->db->prepare("
            UPDATE users 
            SET zone_access = :zone_access 
            WHERE user_id = :user_id
        ");
        
        return $stmt->execute([
            'user_id' => $userId,
            'zone_access' => $zoneAccessJson
        ]);
    }
    
    // Проверка существования пользователя
    public function exists($userId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchColumn() > 0;
    }
    
    // Получение количества активных сессий (по последнему входу)
    public function getActiveSessionsCount($minutes = 30) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM users 
            WHERE last_login > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
        ");
        $stmt->execute(['minutes' => $minutes]);
        return $stmt->fetchColumn();
    }
}
?>