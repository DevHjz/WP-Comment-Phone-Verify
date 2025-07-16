<?php

if (!defined('ABSPATH')) {
    exit;
}

class DPV_Ajax {
    
    public function __construct() {
        add_action('wp_ajax_dpv_send_code', array($this, 'send_verification_code'));
        add_action('wp_ajax_nopriv_dpv_send_code', array($this, 'send_verification_code'));
        add_action('wp_ajax_dpv_verify_code', array($this, 'verify_code'));
        add_action('wp_ajax_nopriv_dpv_verify_code', array($this, 'verify_code'));
        add_action('wp_ajax_dpv_get_captcha', array($this, 'get_captcha_image'));
        add_action('wp_ajax_nopriv_dpv_get_captcha', array($this, 'get_captcha_image'));
        
        // 定期清理过期数据
        add_action('wp_scheduled_delete', array($this, 'cleanup_expired_data'));
    }
    
    // 发送验证码
    public function send_verification_code() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'dpv_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败！'));
            return;
        }
        
        $phone = sanitize_text_field($_POST['phone']);
        $captcha_code = sanitize_text_field($_POST['captcha_code']);
        
        // 验证手机号格式
        if (!$this->validate_phone($phone)) {
            wp_send_json_error(array('message' => '手机号格式不正确！'));
            return;
        }
        
        // 验证图片验证码
        $captcha = new DPV_Captcha();
        if (!$captcha->verify_captcha($captcha_code)) {
            wp_send_json_error(array('message' => '图片验证码错误或已过期！'));
            return;
        }
        
        // 检查黑名单
        if ($this->is_blacklisted_phone($phone)) {
            wp_send_json_error(array('message' => '禁止该号段绑定！'));
            return;
        }
        
        // 检查发送频率限制
        $ip_limit = get_option('dpv_ip_limit_minutes', 2);
        if (DPV_Database::check_ip_limit($ip_limit)) {
            wp_send_json_error(array('message' => "发送短信频率过快，请{$ip_limit}分钟后再试。"));
            return;
        }
        
        $phone_limit = get_option('dpv_phone_limit_minutes', 2);
        if (DPV_Database::check_phone_limit($phone, $phone_limit)) {
            wp_send_json_error(array('message' => "发送短信频率过快，请{$phone_limit}分钟后再试。"));
            return;
        }
        
        $daily_limit = get_option('dpv_daily_limit', 5);
        if (DPV_Database::check_daily_limit($phone, $daily_limit)) {
            wp_send_json_error(array('message' => '今日发送次数已达上限！'));
            return;
        }
        
        // 生成验证码
        $code = sprintf('%06d', mt_rand(100000, 999999));
        
        // 保存验证码到数据库
        if (!DPV_Database::save_verification_code($phone, $code)) {
            wp_send_json_error(array('message' => '验证码保存失败！'));
            return;
        }
        
        // 发送短信
        $sms_result = $this->send_sms($phone, $code);
        
        if ($sms_result['success']) {
            // 记录发送日志
            DPV_Database::log_send_attempt($phone);
            wp_send_json_success(array('message' => '验证码发送成功！'));
        } else {
            wp_send_json_error(array('message' => $sms_result['message']));
        }
    }
    
    // 验证验证码
    public function verify_code() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'dpv_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败！'));
            return;
        }
        
        $phone = sanitize_text_field($_POST['phone']);
        $code = sanitize_text_field($_POST['code']);
        
        // 验证手机号格式
        if (!$this->validate_phone($phone)) {
            wp_send_json_error(array('message' => '手机号格式不正确！'));
            return;
        }
        
        // 验证验证码格式
        if (!preg_match('/^\d{6}$/', $code)) {
            wp_send_json_error(array('message' => '验证码格式不正确！'));
            return;
        }
        
        // 验证验证码
        if (DPV_Database::verify_code($phone, $code)) {
            // 设置验证状态 - 直接调用静态方法，不依赖类实例
            $this->set_phone_verified($phone);
            wp_send_json_success(array('message' => '验证成功！'));
        } else {
            wp_send_json_error(array('message' => '验证码错误或已过期！'));
        }
    }
    
    // 设置手机验证状态（从DPV_Frontend移过来避免依赖问题）
    private function set_phone_verified($phone) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['dpv_phone_verified'] = true;
        $_SESSION['dpv_verified_phone'] = $phone;
        
        // 设置cookie作为备用（24小时有效）
        setcookie('dpv_phone_verified', '1', time() + 86400, '/');
    }
    
    // 验证手机号格式
    private function validate_phone($phone) {
        return preg_match('/^1[3-9]\d{9}$/', $phone);
    }
    
    // 检查是否为黑名单号段
    private function is_blacklisted_phone($phone) {
        $blacklist = get_option('dpv_blacklist_prefixes', '');
        if (empty($blacklist)) {
            return false;
        }
        
        if (is_string($blacklist)) {
            $blacklist = array_filter(array_map('trim', explode(',', $blacklist)));
        }
        
        $prefix = substr($phone, 0, 3);
        return in_array($prefix, $blacklist);
    }
    
    // 发送短信
    private function send_sms($phone, $code) {
        // 获取模板ID
        $template_id = get_option('dpv_template_id', '');
        
        // 如果为空，尝试旧的选项名称（向后兼容）
        if (empty($template_id)) {
            $template_id = get_option('dpv_sms_template_id', '');
        }
        
        if (empty($template_id)) {
            return array(
                'success' => false,
                'message' => '短信模板ID未配置，请在后台设置中配置！'
            );
        }
        
        $url = 'https://push.spug.cc/send/' . trim($template_id);
        $body = array(
            'action' => '评论',
            'code' => $code,
            'targets' => $phone
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($body),
            'timeout' => 30,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => '网络请求失败：' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            return array(
                'success' => false,
                'message' => '短信服务异常，状态码：' . $status_code
            );
        }
        
        $data = json_decode($response_body, true);
        
        if (!$data) {
            return array(
                'success' => false,
                'message' => '响应数据解析失败！'
            );
        }
        
        if ($data['code'] == 200) {
            return array(
                'success' => true,
                'message' => '发送成功！'
            );
        } elseif ($data['code'] == 204) {
            return array(
                'success' => false,
                'message' => '操作频繁，请2分钟后再试！'
            );
        } else {
            return array(
                'success' => false,
                'message' => isset($data['msg']) ? $data['msg'] . '（错误码：' . $data['code'] . '）' : '发送失败，错误码：' . $data['code']
            );
        }
    }
    
    // 清理过期数据
    public function cleanup_expired_data() {
        DPV_Database::cleanup_expired_data();
    }
    
    // 获取验证码图片
    public function get_captcha_image() {
        $captcha = new DPV_Captcha();
        $captcha->generate_captcha();
    }
}

