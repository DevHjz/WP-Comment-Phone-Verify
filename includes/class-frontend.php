<?php

if (!defined('ABSPATH')) {
    exit;
}

class DPV_Frontend {
    
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('pre_comment_on_post', array($this, 'validate_comment_phone'));
        add_action('comment_post', array($this, 'save_comment_phone'));
    }
    
    // 加载前端资源
    public function enqueue_scripts() {
        if ((is_single() || is_page()) && comments_open()) {
            wp_enqueue_style(
                'dpv-frontend-style',
                DPV_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                DPV_VERSION
            );
            
            wp_enqueue_script(
                'dpv-frontend-script',
                DPV_PLUGIN_URL . 'assets/js/frontend.js',
                array('jquery'),
                DPV_VERSION,
                true
            );
            
            wp_localize_script('dpv-frontend-script', 'dpv_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dpv_nonce'),
                'is_user_logged_in' => is_user_logged_in(),
                'user_phone_verified' => $this->is_user_phone_verified(),
                'session_phone_verified' => $this->is_phone_verified()
            ));
        }
    }
    
    // 检查用户是否已绑定手机号
    private function is_user_phone_verified() {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $user_id = get_current_user_id();
        $phone = DPV_Database::get_user_phone($user_id);
        return !empty($phone);
    }
    
    // 检查是否已验证手机号（session/cookie）
    private function is_phone_verified() {
        // 检查session
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['dpv_phone_verified']) && $_SESSION['dpv_phone_verified'] === true) {
            return true;
        }
        
        // 检查cookie（作为备用）
        if (isset($_COOKIE['dpv_phone_verified']) && $_COOKIE['dpv_phone_verified'] === '1') {
            return true;
        }
        
        return false;
    }
    
    // 验证评论提交时的手机号
    public function validate_comment_phone($comment_post_ID) {
        // 如果已登录且已绑定手机号，或者已登录但未绑定手机号，则跳过验证
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $phone = DPV_Database::get_user_phone($user_id);
            if ($phone) {
                // 已登录且已绑定手机号，直接跳过验证
                return;
            } else {
                // 已登录但未绑定手机号，也跳过验证，允许其评论
                return;
            }
        }
        
        // 检查是否已验证手机号
        if (!$this->is_phone_verified()) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(405);
            echo '请先完成手机验证再提交！';
            exit;
        }
    }
    
    // 保存评论的手机号
    public function save_comment_phone($comment_id) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        // 获取已验证的手机号
        $phone = '';
        if (isset($_SESSION['dpv_verified_phone'])) {
            $phone = $_SESSION['dpv_verified_phone'];
        }
        
        if ($phone) {
            DPV_Database::save_comment_phone($comment_id, $phone);
            
            // 如果是已登录用户，绑定手机号到用户
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                DPV_Database::bind_phone_to_user($user_id, $phone);
            }
        }
    }
    
    // 设置手机验证状态（静态方法，供外部调用）
    public static function set_phone_verified($phone) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['dpv_phone_verified'] = true;
        $_SESSION['dpv_verified_phone'] = $phone;
        
        // 设置cookie作为备用（24小时有效）
        setcookie('dpv_phone_verified', '1', 0, '/');
    }
    
    // 清除手机验证状态
    public static function clear_phone_verified() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        unset($_SESSION['dpv_phone_verified']);
        unset($_SESSION['dpv_verified_phone']);
        
        // 清除cookie
        setcookie('dpv_phone_verified', '', time() - 3600, '/');
    }
}

