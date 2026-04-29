<?php
/**
 * Интерфейс для всех типов подключений к устройствам
 * 
 * Этот интерфейс определяет стандартный набор методов, которые должны
 * реализовывать все адаптеры устройств (Modbus, SNMP, OPC-UA, MQTT и др.)
 * 
 * @package Modules\Integrations\Interfaces
 */

namespace Modules\Integrations\Interfaces;

interface DeviceAdapterInterface
{
    /**
     * Подключение к устройству
     * 
     * @param string $host IP-адрес или хост устройства
     * @param int $port Порт подключения
     * @param array $config Дополнительные параметры подключения
     * @return bool Успешность подключения
     */
    public function connect(string $host, int $port, array $config = []): bool;

    /**
     * Отключение от устройства
     * 
     * @return void
     */
    public function disconnect(): void;

    /**
     * Проверка состояния подключения
     * 
     * @return bool Статус подключения (true - подключено)
     */
    public function isConnected(): bool;

    /**
     * Чтение данных из регистра устройства
     * 
     * @param int $slaveId ID ведомого устройства (для Modbus)
     * @param int $registerAddress Адрес регистра
     * @param int $quantity Количество регистров для чтения
     * @return array|false Массив прочитанных данных или false при ошибке
     */
    public function readRegisters(int $slaveId, int $registerAddress, int $quantity);

    /**
     * Запись данных в регистр устройства
     * 
     * @param int $slaveId ID ведомого устройства
     * @param int $registerAddress Адрес регистра
     * @param array $data Данные для записи
     * @return bool Успешность записи
     */
    public function writeRegisters(int $slaveId, int $registerAddress, array $data): bool;

    /**
     * Получение последней ошибки
     * 
     * @return string|null Текст ошибки или null если ошибок нет
     */
    public function getLastError(): ?string;

    /**
     * Получение статистики работы адаптера
     * 
     * @return array Массив со статистикой (количество запросов, ошибок, время ответа)
     */
    public function getStatistics(): array;

    /**
     * Тестирование соединения с устройством
     * 
     * @param int $slaveId ID ведомого устройства для теста
     * @return bool Результат теста (true - устройство отвечает)
     */
    public function testConnection(int $slaveId): bool;
}
