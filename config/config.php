<?php
/**
 * 数据库配置 - 多数据库连接
 * [user_data]  → 用户数据
 * [spot_data]  → 拍机点数据
 */

return [
    'db_user_data' => [
        'dsn' => 'mysql:host=localhost;dbname=USER_DATA;charset=utf8mb4',
        'username' => 'YOUR_USERNAME',
        'password' => 'YOUR_PASSWORD',
    ],
    'db_spot_data' => [
        'dsn' => 'mysql:host=localhost;dbname=SPOT_DATA;charset=utf8mb4',
        'username' => 'YOUR_USERNAME',
        'password' => 'YOUR_PASSWORD',
    ],
];
