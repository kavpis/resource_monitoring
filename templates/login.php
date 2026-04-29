<?php
// C:\xampp\htdocs\resource_monitoring\templates\login.php

session_start(); // ВАЖНО: всегда первая строка

// Если пользователь уже авторизован, перенаправляем на дашборд
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    header('Location: /resource_monitoring/');
    exit();
}

// Получение сообщения об ошибке, если есть
$error_message = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']); // Удаляем, чтобы не показывалось снова
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему | Мониторинг ресурсов</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px;
            padding: 40px;
            position: relative;
            overflow: hidden;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-logo h1 {
            font-size: 28px;
            color: #2d3748;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .login-logo p {
            color: #718096;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .error-message {
            background-color: #fed7d7;
            color: #742a2a;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #e53e3e;
            display: <?php echo $error_message ? 'block' : 'none'; ?>;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: #718096;
            font-size: 14px;
        }
        
        .login-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <h1>🔐 Вход в систему</h1>
            <p>Мониторинг потребления ресурсов предприятия</p>
        </div>
        
        <?php if ($error_message): ?>
            <div class="error-message" id="errorMessage">
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="/resource_monitoring/auth.php" id="loginForm">
            <div class="form-group">
                <label for="login">Логин</label>
                <input 
                    type="text" 
                    id="login" 
                    name="login" 
                    placeholder="Введите логин" 
                    required 
                    autocomplete="username"
                    value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                >
            </div>
            
            <div class="form-group">
                <label for="password">Пароль</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="Введите пароль" 
                    required 
                    autocomplete="current-password"
                >
            </div>
            
            <button type="submit" class="btn-login">
                🚀 Войти в систему
            </button>
        </form>
        
        <div class="login-footer">
            <p>Демонстрационный доступ:</p>
            <p><strong>Логин:</strong> admin | <strong>Пароль:</strong> admin123</p>
        </div>
    </div>
    
    <script>
        // Авто-скрытие сообщения об ошибке через 5 секунд
        document.addEventListener('DOMContentLoaded', function() {
            const errorDiv = document.getElementById('errorMessage');
            if (errorDiv && errorDiv.textContent.trim() !== '') {
                setTimeout(() => {
                    errorDiv.style.display = 'none';
                }, 5000);
            }
            
            // Фокус на поле пароля, если логин уже заполнен
            const loginInput = document.getElementById('login');
            const passwordInput = document.getElementById('password');
            
            if (loginInput.value.trim() !== '') {
                passwordInput.focus();
            }
        });
        
        // Добавление анимации при отправке формы
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = document.querySelector('.btn-login');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Вход...';
            
            // Через 30 сек возврат (на случай зависания)
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = '🚀 Войти в систему';
                    alert('Время ожидания ответа сервера истекло. Проверьте подключение.');
                }
            }, 30000);
        });
    </script>
</body>
</html>