<?php

if (!defined('ABSPATH')) {
    exit;
}

class DPV_Captcha {
    
    private $width = 120;
    private $height = 40;
    private $font_size = 16;
    private $code_length = 4;
    
    public function __construct() {
        // 确保session已启动
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * 生成验证码图片
     */
    public function generate_captcha() {
        // 生成验证码
        $code = $this->generate_code();
        
        // 保存到session
        $_SESSION['dpv_captcha_code'] = strtolower($code);
        $_SESSION['dpv_captcha_time'] = time();
        
        // 创建图片
        $image = imagecreate($this->width, $this->height);
        
        // 设置颜色
        $bg_color = imagecolorallocate($image, 245, 245, 245);
        $text_color = imagecolorallocate($image, 50, 50, 50);
        $line_color = imagecolorallocate($image, 200, 200, 200);
        $noise_color = imagecolorallocate($image, 180, 180, 180);
        
        // 填充背景
        imagefill($image, 0, 0, $bg_color);
        
        // 添加干扰线
        for ($i = 0; $i < 5; $i++) {
            imageline($image, 
                rand(0, $this->width), rand(0, $this->height),
                rand(0, $this->width), rand(0, $this->height),
                $line_color
            );
        }
        
        // 添加噪点
        for ($i = 0; $i < 50; $i++) {
            imagesetpixel($image, 
                rand(0, $this->width), rand(0, $this->height),
                $noise_color
            );
        }
        
        // 添加验证码文字
        $char_width = $this->width / $this->code_length;
        for ($i = 0; $i < $this->code_length; $i++) {
            $char = $code[$i];
            $x = $char_width * $i + rand(5, 10);
            $y = rand($this->height / 2, $this->height - 5);
            $angle = rand(-15, 15);
            
            // 使用内置字体
            imagestring($image, 5, $x, $y - 15, $char, $text_color);
        }
        
        // 输出图片
        header('Content-Type: image/png');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        imagepng($image);
        imagedestroy($image);
        exit;
    }
    
    /**
     * 验证验证码
     */
    public function verify_captcha($input_code) {
        if (!isset($_SESSION['dpv_captcha_code']) || !isset($_SESSION['dpv_captcha_time'])) {
            return false;
        }
        
        // 检查是否过期（5分钟）
        if (time() - $_SESSION['dpv_captcha_time'] > 300) {
            $this->clear_captcha();
            return false;
        }
        
        $stored_code = $_SESSION['dpv_captcha_code'];
        $result = strtolower(trim($input_code)) === $stored_code;
        
        // 验证后清除验证码（一次性使用）
        if ($result) {
            $this->clear_captcha();
        }
        
        return $result;
    }
    
    /**
     * 清除验证码
     */
    public function clear_captcha() {
        unset($_SESSION['dpv_captcha_code']);
        unset($_SESSION['dpv_captcha_time']);
    }
    
    /**
     * 生成验证码字符串
     */
    private function generate_code() {
        // 使用数字和字母，排除容易混淆的字符
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        
        for ($i = 0; $i < $this->code_length; $i++) {
            $code .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        return $code;
    }
}