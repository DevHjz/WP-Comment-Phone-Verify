<?php

if (!defined('ABSPATH')) {
    exit;
}

class DPV_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // 添加评论列表的手机号列
        add_filter('manage_edit-comments_columns', array($this, 'add_comment_phone_column'));
        add_action('manage_comments_custom_column', array($this, 'show_comment_phone_column'), 10, 2);
        
        // 添加数据库检查通知
        add_action('admin_notices', array($this, 'check_database_status'));
    }
    
    // 添加管理菜单
    public function add_admin_menu() {
        add_options_page(
            '手机验证设置',
            '手机验证',
            'manage_options',
            'dpv-settings',
            array($this, 'admin_page')
        );
    }
    
    // 注册设置
    public function register_settings() {
        register_setting('dpv_settings_group', 'dpv_template_id');
        register_setting('dpv_settings_group', 'dpv_blacklist_prefixes');
        register_setting('dpv_settings_group', 'dpv_daily_limit');
        register_setting('dpv_settings_group', 'dpv_ip_limit_minutes');
        register_setting('dpv_settings_group', 'dpv_phone_limit_minutes');
    }
    
    // 加载后台资源
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'settings_page_dpv-settings') {
            wp_enqueue_style(
                'dpv-admin-style',
                DPV_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                DPV_VERSION
            );
            
            wp_enqueue_script(
                'dpv-admin-script',
                DPV_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                DPV_VERSION,
                true
            );
        }
    }
    
    // 添加评论手机号列
    public function add_comment_phone_column($columns) {
        $columns['phone'] = '手机号';
        return $columns;
    }
    
    // 显示评论手机号
    public function show_comment_phone_column($column, $comment_id) {
        if ($column === 'phone') {
            $phone = DPV_Database::get_comment_phone($comment_id);
            if ($phone) {
                //若要求脱敏显示手机号需修改
                //$masked_phone = substr($phone, 0, 3) . '****' . substr($phone, -4);
                echo '<span title="' . esc_attr($phone) . '">' . esc_html($phone) . '</span>';
            } else {
                echo '<span style="color: #999;">未验证</span>';
            }
        }
    }
    
    // 检查数据库状态
    public function check_database_status() {
        global $wpdb;
        
        // 只在插件设置页面显示
        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_dpv-settings') {
            return;
        }
        
        $tables = array(
            'dpv_phone_verifications' => '手机验证记录表',
            'dpv_send_logs' => '发送记录表',
            'dpv_user_phones' => '用户手机绑定表',
            'dpv_comment_phones' => '评论手机号关联表'
        );
        
        $missing_tables = array();
        
        foreach ($tables as $table_suffix => $table_desc) {
            $table_name = $wpdb->prefix . $table_suffix;
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            if ($result !== $table_name) {
                $missing_tables[] = $table_desc . ' (' . $table_name . ')';
            }
        }
        
        if (!empty($missing_tables)) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>手机验证插件数据库错误:</strong> 以下数据表缺失: <br>';
            echo implode('<br>', $missing_tables);
            echo '<br><br>请尝试停用并重新激活插件来修复此问题。';
            echo '</p></div>';
        }
    }
    
    // 管理页面
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>手机验证设置</h1>
            
            <?php
            // 显示数据库状态
            $this->show_database_status();
            ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('dpv_settings_group');
                do_settings_sections('dpv_settings_group');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">短信模板ID</th>
                        <td>
                            <input type="text" name="dpv_template_id" value="<?php echo esc_attr(get_option('dpv_template_id', '')); ?>" class="regular-text" />
                            <p class="description">请输入spug.cc的短信模板ID</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">黑名单号段</th>
                        <td>
                            <input type="text" name="dpv_blacklist_prefixes" value="<?php echo esc_attr(get_option('dpv_blacklist_prefixes', '170,171,162,165,167')); ?>" class="regular-text" />
                            <p class="description">禁止绑定的手机号前三位，用逗号分隔</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">每日发送限制</th>
                        <td>
                            <input type="number" name="dpv_daily_limit" value="<?php echo esc_attr(get_option('dpv_daily_limit', 6)); ?>" min="1" max="20" />
                            <p class="description">每个手机号每天最多发送验证码次数</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">IP限制时间（分钟）</th>
                        <td>
                            <input type="number" name="dpv_ip_limit_minutes" value="<?php echo esc_attr(get_option('dpv_ip_limit_minutes', 1)); ?>" min="1" max="60" />
                            <p class="description">同一IP地址发送验证码的间隔时间</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">手机号限制时间（分钟）</th>
                        <td>
                            <input type="number" name="dpv_phone_limit_minutes" value="<?php echo esc_attr(get_option('dpv_phone_limit_minutes', 2)); ?>" min="1" max="60" />
                            <p class="description">同一手机号发送验证码的间隔时间</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>当前配置状态</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>配置项</th>
                        <th>当前值</th>
                        <th>状态</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>短信模板ID</td>
                        <td><?php echo esc_html(get_option('dpv_template_id', '未设置')); ?></td>
                        <td><?php echo get_option('dpv_template_id', '') ? '<span style="color: green;">✓ 已配置</span>' : '<span style="color: red;">✗ 未配置</span>'; ?></td>
                    </tr>
                    <tr>
                        <td>黑名单号段</td>
                        <td><?php echo esc_html(get_option('dpv_blacklist_prefixes', '未设置')); ?></td>
                        <td><span style="color: green;">✓ 已配置</span></td>
                    </tr>
                    <tr>
                        <td>数据库版本</td>
                        <td><?php echo esc_html(get_option('dpv_db_version', '未知')); ?></td>
                        <td><?php echo version_compare(get_option('dpv_db_version', '0'), '1.3.2', '>=') ? '<span style="color: green;">✓ 最新</span>' : '<span style="color: orange;">⚠ 需要更新</span>'; ?></td>
                    </tr>
                </tbody>
            </table>
            
            <hr>
            
            <h2>数据库表状态</h2>
            <?php $this->show_table_status(); ?>
            
            <hr>
            
            <h2>操作工具</h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=dpv-settings&action=recreate_tables&_wpnonce=' . wp_create_nonce('dpv_recreate_tables')); ?>" 
                   class="button button-secondary" 
                   onclick="return confirm('确定要重新创建数据库表吗？这将清空所有验证记录。');">
                    重新创建数据库表
                </a>
            </p>
            
            <?php
            // 处理重新创建表的操作
            if (isset($_GET['action']) && $_GET['action'] === 'recreate_tables' && wp_verify_nonce($_GET['_wpnonce'], 'dpv_recreate_tables')) {
                $this->recreate_tables();
            }
            ?>
        </div>
        <?php
    }
    
    // 显示数据库状态
    private function show_database_status() {
        global $wpdb;
        
        $tables = array(
            'dpv_phone_verifications' => '手机验证记录表',
            'dpv_send_logs' => '发送记录表',
            'dpv_user_phones' => '用户手机绑定表',
            'dpv_comment_phones' => '评论手机号关联表'
        );
        
        $all_exist = true;
        
        foreach ($tables as $table_suffix => $table_desc) {
            $table_name = $wpdb->prefix . $table_suffix;
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            if ($result !== $table_name) {
                $all_exist = false;
                break;
            }
        }
        
        if ($all_exist) {
            echo '<div class="notice notice-success"><p><strong>数据库状态:</strong> 所有必需的数据表都已正确创建。</p></div>';
        }
    }
    
    // 显示表状态
    private function show_table_status() {
        global $wpdb;
        
        $tables = array(
            'dpv_phone_verifications' => '手机验证记录表',
            'dpv_send_logs' => '发送记录表',
            'dpv_user_phones' => '用户手机绑定表',
            'dpv_comment_phones' => '评论手机号关联表'
        );
        
        echo '<table class="widefat">';
        echo '<thead><tr><th>表名</th><th>描述</th><th>状态</th><th>记录数</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($tables as $table_suffix => $table_desc) {
            $table_name = $wpdb->prefix . $table_suffix;
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            
            echo '<tr>';
            echo '<td><code>' . esc_html($table_name) . '</code></td>';
            echo '<td>' . esc_html($table_desc) . '</td>';
            
            if ($result === $table_name) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
                echo '<td><span style="color: green;">✓ 存在</span></td>';
                echo '<td>' . intval($count) . '</td>';
            } else {
                echo '<td><span style="color: red;">✗ 不存在</span></td>';
                echo '<td>-</td>';
            }
            
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    // 重新创建表
    private function recreate_tables() {
        global $wpdb;
        
        // 删除现有表
        $tables = array(
            $wpdb->prefix . 'dpv_phone_verifications',
            $wpdb->prefix . 'dpv_send_logs',
            $wpdb->prefix . 'dpv_user_phones',
            $wpdb->prefix . 'dpv_comment_phones'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        // 重新创建表
        $plugin = new DuxPhoneVerification();
        $reflection = new ReflectionClass($plugin);
        $method = $reflection->getMethod('create_tables');
        $method->setAccessible(true);
        $method->invoke($plugin);
        
        // 更新数据库版本
        update_option('dpv_db_version', '1.3.2');
        
        echo '<div class="notice notice-success"><p><strong>操作完成:</strong> 数据库表已重新创建。</p></div>';
    }
}

