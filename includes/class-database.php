<?php

if (!defined('ABSPATH')) {
    exit;
}

class DPV_Database {
    
    // 检查表是否存在
    private static function table_exists($table_name) {
        global $wpdb;
        $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        return $result === $table_name;
    }
    
    // 保存验证码
    public static function save_verification_code($phone, $code) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_phone_verifications';
        
        // 检查表是否存在
        if (!self::table_exists($table_name)) {
            error_log('DPV Plugin: Table ' . $table_name . ' does not exist');
            return false;
        }
        
        $ip_address = self::get_client_ip();
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        
        // 先删除该手机号的旧验证码
        $wpdb->delete(
            $table_name,
            array('phone' => $phone),
            array('%s')
        );
        
        // 插入新验证码，使用当前时间+5分钟作为过期时间
        $current_time = current_time('mysql', true); // 使用UTC时间
        $expires_at = date('Y-m-d H:i:s', strtotime($current_time) + 300); // 5分钟后过期
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'phone' => $phone,
                'code' => $code,
                'user_id' => $user_id,
                'ip_address' => $ip_address,
                'created_at' => $current_time,
                'expires_at' => $expires_at,
                'verified' => 0
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%d')
        );
        
        return $result !== false;
    }
    
    // 验证验证码
    public static function verify_code($phone, $code) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_phone_verifications';
        
        // 检查表是否存在
        if (!self::table_exists($table_name)) {
            error_log('DPV Plugin: Table ' . $table_name . ' does not exist');
            return false;
        }
        
        $current_time = current_time('mysql', true); // 使用UTC时间
        
        // 查找有效的验证码
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE phone = %s 
             AND code = %s 
             AND verified = 0 
             AND expires_at > %s 
             ORDER BY created_at DESC 
             LIMIT 1",
            $phone,
            $code,
            $current_time
        ));
        
        if ($record) {
            // 标记为已验证
            $wpdb->update(
                $table_name,
                array('verified' => 1),
                array('id' => $record->id),
                array('%d'),
                array('%d')
            );
            
            return true;
        }
        
        return false;
    }
    
    // 检查IP发送限制
    public static function check_ip_limit($minutes) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_send_logs';
        
        // 检查表是否存在
        if (!self::table_exists($table_name)) {
            error_log('DPV Plugin: Table ' . $table_name . ' does not exist');
            return false;
        }
        
        $ip_address = self::get_client_ip();
        $time_limit = date('Y-m-d H:i:s', time() - ($minutes * 60));
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
             WHERE ip_address = %s 
             AND sent_at > %s",
            $ip_address,
            $time_limit
        ));
        
        return $count > 0;
    }
    
    // 检查手机号发送限制
    public static function check_phone_limit($phone, $minutes) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_send_logs';
        
        // 检查表是否存在
        if (!self::table_exists($table_name)) {
            error_log('DPV Plugin: Table ' . $table_name . ' does not exist');
            return false;
        }
        
        $time_limit = date('Y-m-d H:i:s', time() - ($minutes * 60));
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
             WHERE phone = %s 
             AND sent_at > %s",
            $phone,
            $time_limit
        ));
        
        return $count > 0;
    }
    
    // 检查每日发送限制
    public static function check_daily_limit($phone, $limit) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_send_logs';
        
        // 检查表是否存在
        if (!self::table_exists($table_name)) {
            error_log('DPV Plugin: Table ' . $table_name . ' does not exist');
            return false;
        }
        
        $today_start = date('Y-m-d 00:00:00');
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
             WHERE phone = %s 
             AND sent_at >= %s",
            $phone,
            $today_start
        ));
        
        return $count >= $limit;
    }
    
    // 记录发送日志
    public static function log_send_attempt($phone) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_send_logs';
        
        // 检查表是否存在
        if (!self::table_exists($table_name)) {
            error_log('DPV Plugin: Table ' . $table_name . ' does not exist');
            return false;
        }
        
        $ip_address = self::get_client_ip();
        
        return $wpdb->insert(
            $table_name,
            array(
                'phone' => $phone,
                'ip_address' => $ip_address,
                'sent_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );
    }
    
    // 绑定手机号到用户
    public static function bind_phone_to_user($user_id, $phone) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_user_phones';
        
        // 检查表是否存在
        if (!self::table_exists($table_name)) {
            error_log('DPV Plugin: Table ' . $table_name . ' does not exist');
            return false;
        }
        
        // 先删除用户的旧绑定
        $wpdb->delete(
            $table_name,
            array('user_id' => $user_id),
            array('%d')
        );
        
        // 删除该手机号的其他绑定
        $wpdb->delete(
            $table_name,
            array('phone' => $phone),
            array('%s')
        );
        
        // 插入新绑定
        return $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'phone' => $phone,
                'verified_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s')
        );
    }
    
    // 获取用户绑定的手机号
    public static function get_user_phone($user_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_user_phones';
        
        // 检查表是否存在
        if (!self::table_exists($table_name)) {
            error_log('DPV Plugin: Table ' . $table_name . ' does not exist');
            return '';
        }
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT phone FROM $table_name WHERE user_id = %d",
            $user_id
        ));
    }
    
    // 保存评论的手机号
    public static function save_comment_phone($comment_id, $phone) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_comment_phones';
        
        // 检查表是否存在，如果不存在则尝试创建
        if (!self::table_exists($table_name)) {
            self::create_comment_phones_table();
            
            // 再次检查
            if (!self::table_exists($table_name)) {
                error_log('DPV Plugin: Failed to create table ' . $table_name);
                return false;
            }
        }
        
        // 先删除该评论的旧记录
        $wpdb->delete(
            $table_name,
            array('comment_id' => $comment_id),
            array('%d')
        );
        
        // 插入新记录
        return $wpdb->insert(
            $table_name,
            array(
                'comment_id' => $comment_id,
                'phone' => $phone,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s')
        );
    }
    
    // 获取评论的手机号
    public static function get_comment_phone($comment_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_comment_phones';
        
        // 检查表是否存在
        if (!self::table_exists($table_name)) {
            // 尝试创建表
            self::create_comment_phones_table();
            
            // 如果仍然不存在，返回空字符串
            if (!self::table_exists($table_name)) {
                error_log('DPV Plugin: Table ' . $table_name . ' does not exist and failed to create');
                return '';
            }
        }
        
        $phone = $wpdb->get_var($wpdb->prepare(
            "SELECT phone FROM $table_name WHERE comment_id = %d",
            $comment_id
        ));
        
        return $phone ? $phone : '';
    }
    
    // 创建评论手机号关联表
    private static function create_comment_phones_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dpv_comment_phones';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
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
        
        // 记录创建日志
        if (self::table_exists($table_name)) {
            error_log('DPV Plugin: Successfully created table ' . $table_name);
        } else {
            error_log('DPV Plugin: Failed to create table ' . $table_name);
        }
    }
    
    // 获取客户端IP地址
    private static function get_client_ip() {
        $ip_keys = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    // 清理过期数据
    public static function cleanup_expired_data() {
        global $wpdb;
        
        // 清理过期的验证码
        $table_name = $wpdb->prefix . 'dpv_phone_verifications';
        if (self::table_exists($table_name)) {
            $current_time = current_time('mysql', true);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name WHERE expires_at < %s",
                $current_time
            ));
        }
        
        // 清理7天前的发送记录
        $table_name2 = $wpdb->prefix . 'dpv_send_logs';
        if (self::table_exists($table_name2)) {
            $week_ago = date('Y-m-d H:i:s', time() - (7 * 24 * 60 * 60));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name2 WHERE sent_at < %s",
                $week_ago
            ));
        }
    }
}

