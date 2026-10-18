-- 积分交易系统数据库初始化SQL
-- ThinkPHP 8.x

-- 用户表
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `phone` varchar(20) NOT NULL COMMENT '手机号',
  `password_hash` varchar(255) NOT NULL COMMENT '登录密码哈希',
  `salt` varchar(32) NOT NULL COMMENT '密码盐',
  `balance` decimal(20,2) NOT NULL DEFAULT 0.00 COMMENT '总余额',
  `principal` decimal(20,2) NOT NULL DEFAULT 0.00 COMMENT '本金余额',
  `bonus` decimal(20,2) NOT NULL DEFAULT 0.00 COMMENT '赠送余额',
  `role` enum('user','merchant','admin') NOT NULL DEFAULT 'user' COMMENT '角色',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:0禁用,1正常,2审核中',
  `real_name_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '实名认证:0未认证,1已认证',
  `merchant_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '商户认证:0未认证,1已认证',
  `login_password` varchar(255) DEFAULT NULL COMMENT '支付密码哈希',
  `pay_salt` varchar(32) DEFAULT NULL COMMENT '支付密码盐',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_phone` (`phone`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- 用户认证表
CREATE TABLE IF NOT EXISTS `user_verify` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `real_name` varchar(50) NOT NULL COMMENT '真实姓名',
  `id_card` varchar(18) NOT NULL COMMENT '身份证号',
  `id_card_front` varchar(255) NOT NULL COMMENT '身份证正面',
  `id_card_back` varchar(255) NOT NULL COMMENT '身份证背面',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态:0待审核,1已通过,2已拒绝',
  `verified_at` datetime DEFAULT NULL COMMENT '审核时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户认证表';

-- 商户表
CREATE TABLE IF NOT EXISTS `merchants` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `website_url` varchar(255) DEFAULT NULL COMMENT '网站地址',
  `totp_secret` varchar(64) DEFAULT NULL COMMENT 'TOTP密钥',
  `api_key` varchar(64) DEFAULT NULL COMMENT 'API密钥',
  `api_secret` varchar(128) DEFAULT NULL COMMENT 'API密钥Secret',
  `rsa_private_key` text DEFAULT NULL COMMENT 'RSA私钥',
  `rsa_public_key` text DEFAULT NULL COMMENT 'RSA公钥',
  `payment_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '支付功能:0关闭,1开启',
  `payment_limit` decimal(20,2) NOT NULL DEFAULT 0.00 COMMENT '支付限额',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:0禁用,1正常,2审核中',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  UNIQUE KEY `uk_api_key` (`api_key`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户表';

-- 订单表
CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) unsigned NOT NULL COMMENT '商户ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '创建者ID',
  `title` varchar(100) NOT NULL COMMENT '订单标题',
  `points_amount` decimal(20,2) NOT NULL COMMENT '积分数量',
  `unit_price` decimal(10,4) NOT NULL COMMENT '单价',
  `total_amount` decimal(20,2) NOT NULL COMMENT '总金额',
  `remaining_amount` decimal(20,2) NOT NULL COMMENT '剩余可售金额',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:1上架,2暂停,3已完成,4已下架',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_merchant_id` (`merchant_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表';

-- 交易流水表
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) unsigned NOT NULL COMMENT '订单ID',
  `buyer_id` int(11) unsigned NOT NULL COMMENT '买家ID',
  `seller_id` int(11) unsigned NOT NULL COMMENT '卖家ID',
  `merchant_id` int(11) unsigned NOT NULL COMMENT '商户ID',
  `points_amount` decimal(20,2) NOT NULL COMMENT '积分数量',
  `total_price` decimal(20,2) NOT NULL COMMENT '交易总价',
  `payment_method` varchar(20) NOT NULL COMMENT '支付方式',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:1待支付,2已完成,3已取消,4已退款',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL COMMENT '完成时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_buyer_id` (`buyer_id`),
  KEY `idx_seller_id` (`seller_id`),
  KEY `idx_merchant_id` (`merchant_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='交易流水表';

-- 收款方式表
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) unsigned NOT NULL COMMENT '商户ID',
  `type` enum('bank','alipay','wechat') NOT NULL COMMENT '类型',
  `account_name` varchar(100) NOT NULL COMMENT '账户名称',
  `account_no` varchar(100) NOT NULL COMMENT '账号',
  `qrcode_url` varchar(255) DEFAULT NULL COMMENT '收款码URL',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否默认:0否,1是',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_merchant_id` (`merchant_id`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='收款方式表';

-- 系统设置表
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL COMMENT '设置键',
  `setting_value` text DEFAULT NULL COMMENT '设置值',
  `description` varchar(255) DEFAULT NULL COMMENT '描述',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置表';

-- 管理员表
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `password_hash` varchar(255) NOT NULL COMMENT '密码哈希',
  `role` varchar(20) NOT NULL DEFAULT 'admin' COMMENT '角色',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:0禁用,1正常',
  `last_login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表';

-- 登录日志表
CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `ip` varchar(45) NOT NULL COMMENT 'IP地址',
  `user_agent` varchar(500) DEFAULT NULL COMMENT 'UserAgent',
  `login_type` varchar(20) NOT NULL DEFAULT 'password' COMMENT '登录类型',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:0失败,1成功',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录日志表';

-- 公告表
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL COMMENT '标题',
  `content` text NOT NULL COMMENT '内容',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:0隐藏,1显示',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告表';

-- 初始化管理员账户 (密码: admin123456)
INSERT INTO `admin_users` (`username`, `password_hash`, `role`, `status`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2UheOtmlI7Y', 'super_admin', 1);

-- 初始化系统设置
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('site_name', '积分交易系统', '站点名称'),
('min_trade_amount', '10.00', '最小交易金额'),
('max_trade_amount', '100000.00', '最大交易金额'),
('merchant_fee_rate', '0.02', '商户手续费率'),
('withdraw_fee_rate', '0.01', '提现手续费率'),
('api_rate_limit', '100', 'API请求频率限制(次/分钟)');
