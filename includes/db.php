<?php
/**
 * 数据库连接助手
 */

function get_pdo(string $dbKey): PDO {
    static $connections = [];
    $config = require __DIR__ . '/../config/config.php';

    if (!isset($config[$dbKey])) {
        throw new InvalidArgumentException("数据库配置不存在: {$dbKey}");
    }

    if (!isset($connections[$dbKey])) {
        $db = $config[$dbKey];
        $connections[$dbKey] = new PDO(
            $db['dsn'],
            $db['username'],
            $db['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]
        );
    }
    return $connections[$dbKey];
}
