<?php
/**
 * Plugin Name: 评论手机验证插件
 * Plugin URI: https://github.com/DevHjz/WP-Comment-Phone-Verify
 * Description: 为个人网站开发者的评论区添加SPUG推送的短信手机验证功能，旨在免去用户登录注册的情况下发布评论时符合网安的要求，兼容DUX主题。
 * Version: 1.5.0
 * Author: DevHjz
 * Author URI: https://www.DevHjz.com
 * License: Apache License 2.0
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Text Domain: phone-verification-plugin
 * Requires at least: 6.0
 * Tested up to: 6.8.2
 * Requires PHP: 8.1
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('DPV_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DPV_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('DPV_VERSION', '1.5.0');

// 主插件类
class DuxPhoneVerification {
    
    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // 添加数据库检查钩子
        add_action('admin_init', array($this, 'check_database_tables'));
    }
    
    public function init() {
        // 加载文本域
        load_plugin_textdomain('phone-verification-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // 先加载所有类文件
        $this->load_classes();
        
        // 然后初始化各个模块
        $this->init_modules();
    }
    
    private function load_classes() {
        // 按依赖顺序加载类文件
        require_once DPV_PLUGIN_PATH . 'includes/class-database.php';
        require_once DPV_PLUGIN_PATH . 'includes/class-captcha.php';
        require_once DPV_PLUGIN_PATH . 'includes/class-frontend.php';
        require_once DPV_PLUGIN_PATH . 'includes/class-ajax.php';
        
        if (is_admin()) {
            require_once DPV_PLUGIN_PATH . 'includes/class-admin.php';
        }
    }
    
    private function init_modules() {
        // 初始化前端模块
        if (!is_admin()) {
            new DPV_Frontend();
        }
        
        // 初始化AJAX模块（前后端都需要）
        new DPV_Ajax();
        
        // 初始化后台模块
        if (is_admin()) {
            new DPV_Admin();
        }
    }
    
    public function activate() {
        $this->create_tables();
        $this->set_default_options();
        
        // 设置数据库版本号
        update_option('dpv_db_version', '1.5.0');
    }
    
    public function deactivate() {
        // 清理临时数据
        $this->cleanup_temp_data();
    }
    
    // 检查数据库表是否存在，不存在则创建
    public function check_database_tables() {
        $current_version = get_option('dpv_db_version', '0');
        
        // 如果版本不匹配或表不存在，重新创建表
        if (version_compare($current_version, '1.5.0', '<') || !$this->tables_exist()) {
            $this->create_tables();
            update_option('dpv_db_version', '1.5.0');
        }
    }
    
    // 检查所有必需的表是否存在
    private function tables_exist() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'dpv_phone_verifications',
            $wpdb->prefix . 'dpv_send_logs',
            $wpdb->prefix . 'dpv_user_phones',
            $wpdb->prefix . 'dpv_comment_phones'
        );
        
        foreach ($tables as $table) {
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if ($result !== $table) {
                return false;
            }
        }
        
        return true;
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // 手机验证记录表
        $table_name = $wpdb->prefix . 'dpv_phone_verifications';
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            phone varchar(20) NOT NULL,
            code varchar(10) NOT NULL,
            user_id bigint(20) DEFAULT NULL,
            ip_address varchar(45) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            verified tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY phone (phone),
            KEY ip_address (ip_address),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // 发送记录表（防刷用）
        $table_name2 = $wpdb->prefix . 'dpv_send_logs';
        $sql2 = "CREATE TABLE $table_name2 (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            phone varchar(20) NOT NULL,
            ip_address varchar(45) NOT NULL,
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY phone (phone),
            KEY ip_address (ip_address),
            KEY sent_at (sent_at)
        ) $charset_collate;";
        
        // 用户手机绑定表
        $table_name3 = $wpdb->prefix . 'dpv_user_phones';
        $sql3 = "CREATE TABLE $table_name3 (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            phone varchar(20) NOT NULL,
            verified_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            UNIQUE KEY phone (phone)
        ) $charset_collate;";
        
        // 评论手机号关联表
        $table_name4 = $wpdb->prefix . 'dpv_comment_phones';
        $sql4 = "CREATE TABLE $table_name4 (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            comment_id bigint(20) NOT NULL,
            phone varchar(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY comment_id (comment_id),
            KEY phone (phone)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        
        // 验证表是否创建成功
        $this->verify_tables_creation();
    }
    
    // 验证表创建是否成功
    private function verify_tables_creation() {
        global $wpdb;
        
        $tables = array(
            'dpv_phone_verifications' => $wpdb->prefix . 'dpv_phone_verifications',
            'dpv_send_logs' => $wpdb->prefix . 'dpv_send_logs',
            'dpv_user_phones' => $wpdb->prefix . 'dpv_user_phones',
            'dpv_comment_phones' => $wpdb->prefix . 'dpv_comment_phones'
        );
        
        $missing_tables = array();
        
        foreach ($tables as $name => $table) {
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if ($result !== $table) {
                $missing_tables[] = $name;
            }
        }
        
        if (!empty($missing_tables)) {
            // 记录错误日志
            error_log('DPV Plugin: Failed to create tables: ' . implode(', ', $missing_tables));
            
            // 显示管理员通知
            add_action('admin_notices', function() use ($missing_tables) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>手机验证插件错误:</strong> 无法创建数据库表: ' . implode(', ', $missing_tables);
                echo '</p></div>';
            });
        }
    }
    
    private function set_default_options() {
        // 设置默认配置
        add_option('dpv_template_id', '');
        add_option('dpv_blacklist_prefixes', '170,171,162,165,167'); // 默认虚拟号段
        add_option('dpv_daily_limit', 5);
        add_option('dpv_ip_limit_minutes', 2);
        add_option('dpv_phone_limit_minutes', 2);
    }
    
    private function cleanup_temp_data() {
        global $wpdb;
        
        // 清理过期的验证码
        $table_name = $wpdb->prefix . 'dpv_phone_verifications';
        $wpdb->query("DELETE FROM $table_name WHERE expires_at < NOW()");
        
        // 清理7天前的发送记录
        $table_name2 = $wpdb->prefix . 'dpv_send_logs';
        $wpdb->query("DELETE FROM $table_name2 WHERE sent_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    }
}

// 启动插件
new DuxPhoneVerification();

