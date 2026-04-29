<?php
// Тестовая страница — НЕ требует логина, сессий, БД или моделей
// Цель: проверить, что сервер обрабатывает PHP и отдаёт HTML
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✅ Тестовая страница | Resource Monitoring</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #2d3748;
            min-height: 100vh;
            padding: 40px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
            padding: 40px;
            max-width: 600px;
            text-align: center;
            width: 100%;
        }
        h1 {
            font-size: 28px;
            margin-bottom: 16px;
            color: #2d3748;
        }
        .emoji {
            font-size: 64px;
            margin: 20px 0;
        }
        .info {
            background: #f8fafc;
            border-left: 4px solid #667eea;
            padding: 16px;
            margin: 24px 0;
            text-align: left;
            font-size: 14px;
            line-height: 1.;
        }
        .success { color: #28a745; }
        .warning { color: #f59e0b; }
        .error { color: #e53e3e; }
        pre {
            background: #1e293b;
            color: #f1f5f9;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            text-align: left;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="emoji">🧪</div>
        <h1>Тестовая страница системы мониторинга</h1>
        <p><strong>Сервер и PHP работают!</strong></p>

        <div class="info">
            <strong>✅ Статус:</strong> Успешно загружена тестовая страница<br>
            <strong>📅 Дата/время:</strong> <?= date('d.m.Y H:i:s') ?><br>
            <strong>🌐 PHP версия:</strong> <?= phpversion() ?><br>
            <strong>📁 Путь к файлу:</strong> <?= __FILE__ ?>
        </div>

        <div class="info">
            <strong>🔍 Как проверить систему:</strong><br>
            • Откройте в браузере: <code>http://localhost/resource_monitoring/test.php</code><br>
            • Если видите эту страницу — Apache + PHP работают.<br>
            • Если 4 — проблема в пути или конфигурации Apache.<br>
            • Если белый экран — ошибка PHP (проверьте `error.log`).
        </div>

        <div class="info">
            <strong>📌 Что делать дальше:</strong><br>
            1. Убедитесь, что файл лежит по пути:<br>
               <code>C:\xampp\htdocs\resource_monitoring\test.php</code><br>
            2. Перезапустите Apache в XAMPP (на всякий случай)<br>
            3. Попробуйте другие URL:
               <ul>
                 <li><code>/resource_monitoring/</code> → должен показать 403/404 (без index.php)</li>
                 <li><code>/resource_monitoring/templates/login.php</code> → если есть файл — должна быть форма</li>
               </ul>
        </div>

        <pre>
&lt;?php
echo "Hello from test.php";
?&gt;
        </pre>

        <p style="margin-top: 30px; font-size: 14px; color: #718096;">
            Эта страница — для диагностики. После подтверждения работы — удаляйте её.
        </p>
    </div>
</body>
</html>