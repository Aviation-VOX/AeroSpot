# AeroSpot

🛬 Aviation VOX旗下的专业拍机点分享与管理系统

## 📖 项目简介

AeroSpot 是一个专为航空摄影爱好者打造的拍机点分享平台，隶属于 Aviation VOX 社区。用户可以在平台上分享、发现和管理各大机场的最佳拍摄位置，构建专业的航拍社区。

## ✨ 主要功能

### 🔐 用户系统
- **用户注册与登录** - 支持用户名/邮箱登录
- **邮箱验证** - 注册时需要邮箱验证码确认
- **会话管理** - 安全的 Session 管理，关闭浏览器自动登出
- **用户状态管理** - 支持用户封禁功能

### 🗺️ 拍机点管理
- **机位分享** - 用户可以提交新的拍机点信息
- **内容审核** - 管理员审核机制，确保内容质量
- **机场数据** - 完整的机场信息数据库
- **热门统计** - 按拍机点数量统计热门机场

### 📊 数据统计
- **用户统计** - 总用户数量显示
- **机位统计** - 已审核和待审核机位统计
- **最新动态** - 展示最新提交的拍机点
- **热门机场** - 拍机点数量排行

### 👥 社区功能
- **交流群** - 提供 QQ 群和微信群二维码
- **公告系统** - 最新公告和通知发布

## 🛠️ 技术栈

- **后端**: PHP 7.4+
- **数据库**: MySQL
- **前端**: HTML5, CSS3, JavaScript
- **依赖管理**: Composer
- **邮件服务**: PHPMailer

## 📁 项目结构

```
AeroSpot/
├── index.php                 # 主页面
├── login.php                 # 用户登录
├── register.php              # 用户注册
├── logout.php                # 用户登出
├── send_code.php             # 发送验证码
├── join_group.html           # 加入交流群页面
├── composer.json             # Composer 依赖配置
├── config/
│   └── config.php            # 数据库配置
├── includes/
│   ├── db.php                # 数据库连接
│   └── functions.php         # 公共函数
└── *.jpg                     # 群二维码图片
```

## 🗄️ 数据库架构

项目使用两个独立的数据库：

### aeroview_com (用户数据库)
- `users` - 用户信息表
- `airport_data` - 机场数据表

### spot_aviationvox (机位数据库)  
- `spots` - 拍机点信息表

## 🚀 部署指南

### 环境要求
- PHP 7.4 或更高版本
- MySQL 5.7 或更高版本
- Composer

### 安装步骤

1. **克隆项目**
   ```bash
   git clone https://github.com/adminlby/AeroSpot.git
   cd AeroSpot
   ```

2. **安装依赖**
   ```bash
   composer install
   ```

3. **配置数据库**
   - 编辑 `config/config.php` 设置数据库连接信息
   - 创建相应的数据库和表结构

4. **配置Web服务器**
   - 将项目部署到Web服务器根目录
   - 确保PHP有读写权限

5. **访问系统**
   - 打开浏览器访问项目地址
   - 首次访问建议先注册管理员账户

## ⚙️ 配置说明

### 数据库配置
在 `config/config.php` 中修改数据库连接参数：

```php
return [
    'db_aeroview' => [
        'dsn' => 'mysql:host=localhost;dbname=your_user_db;charset=utf8mb4',
        'username' => 'your_username',
        'password' => 'your_password',
    ],
    'db_spot' => [
        'dsn' => 'mysql:host=localhost;dbname=your_spot_db;charset=utf8mb4',
        'username' => 'your_username', 
        'password' => 'your_password',
    ],
];
```

### 邮件配置
系统支持邮箱验证功能，需要在相应文件中配置SMTP服务器信息。

## 🔒 安全特性

- **Session 安全** - 使用安全的 Session Cookie 配置
- **密码加密** - 用户密码经过哈希加密存储
- **SQL 注入防护** - 使用 PDO 预处理语句
- **XSS 防护** - 对用户输入进行转义处理
- **CSRF 防护** - 表单令牌验证

## 🤝 贡献指南

欢迎提交 Pull Request 或 Issue！

1. Fork 本仓库
2. 创建特性分支 (`git checkout -b feature/amazing-feature`)
3. 提交更改 (`git commit -m 'Add some amazing feature'`)
4. 推送到分支 (`git push origin feature/amazing-feature`)
5. 开启 Pull Request

## 📄 开源协议

本项目采用 MIT 协议 - 查看 [LICENSE](LICENSE) 文件了解详情

## 📞 联系我们

- **项目维护**: [adminlby](https://github.com/adminlby)
- **社区交流**: 通过系统内置的交流群功能加入我们
- **问题反馈**: [GitHub Issues](https://github.com/adminlby/AeroSpot/issues)

---

🛩️ **Aviation VOX** - 专业的航空爱好者社区
