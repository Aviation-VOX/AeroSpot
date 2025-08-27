<?php
session_start();

// 清除用户登录的 session
unset($_SESSION['user']);

// 如果还有其他 session 数据也要清空，可以用
// session_unset(); // 清除所有 session 变量
// session_destroy(); // 销毁 session 文件

// 跳转到首页或登录页
header('Location: login.php');
exit;
