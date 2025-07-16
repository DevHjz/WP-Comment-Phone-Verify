jQuery(document).ready(function($) {
    'use strict';
    
    // 检查是否在评论页面且存在DUX评论表单
    if (!$('#commentform').length || !$('.comt-comterinfo').length) {
        return;
    }
    
    // 全局变量
    var countdownTimer = null;
    var verificationCodeSent = false;
    var isProcessing = false;
    
    // 检查是否需要显示手机验证
    function shouldShowPhoneVerification() {
        // 如果已登录且已绑定手机号，不显示
        if (dpv_ajax.is_user_logged_in && dpv_ajax.user_phone_verified) {
            return false;
        }
        
        // 如果session已验证，不显示
        if (dpv_ajax.session_phone_verified) {
            return false;
        }
        
        return true;
    }
    
    // 动态插入手机验证表单
    function insertPhoneVerificationForm() {
        if (!shouldShowPhoneVerification()) {
            // 显示已验证提示
            var verifiedHtml = '<div class="dpv-verification-container">' +
                '<div class="dpv-verified-notice">' +
                '<div class="dpv-success-message">' +
                '<span class="dpv-success-icon">✓</span>' +
                '<span>您已经完成实名验证，请直接评论。</span>' +
                '</div>' +
                '</div>' +
                '</div>';
            $('.comt-comterinfo').after(verifiedHtml);
            return;
        }
        
        // 插入手机验证表单
        var formHtml = '<div class="dpv-verification-container" id="dpv-verification-container">' +
            '<div class="dpv-form-title">' +
            '手机号验证' +
            '<small>为了防止恶意评论，请完成手机号验证</small>' +
            '</div>' +
            
            '<div class="dpv-input-group">' +
            '<input type="tel" id="dpv-phone-number" placeholder="请输入11位手机号" maxlength="11" autocomplete="tel">' +
            '<div class="dpv-error-message" id="dpv-phone-error" style="display: none;"></div>' +
            '</div>' +
            
            '<div class="dpv-image-captcha-group">' +
            '<div class="dpv-captcha-image-wrapper">' +
            '<img id="dpv-captcha-image" src="' + dpv_ajax.ajax_url + '?action=dpv_get_captcha&t=' + Date.now() + '" alt="验证码" title="点击刷新验证码">' +
            '<button type="button" class="dpv-refresh-captcha" id="dpv-refresh-captcha" title="刷新验证码" aria-label="刷新图片验证码">⟲</button>' +
            '</div>' +
            '<div class="dpv-captcha-input-wrapper">' +
            '<input type="text" id="dpv-captcha-answer" placeholder="请输入验证码" maxlength="4" autocomplete="off">' +
            '</div>' +
            '<div class="dpv-error-message" id="dpv-captcha-error" style="display: none;"></div>' +
            '</div>' +
            
            '<button type="button" id="dpv-send-sms-btn" class="dpv-button disabled" aria-label="发送短信验证码">发送验证码</button>' +
            
            '<div class="dpv-verification-code-wrapper" id="dpv-verification-code-wrapper" style="display: none;">' +
            '<div class="dpv-input-group">' +
            '<input type="text" id="dpv-verification-code" placeholder="请输入6位验证码" maxlength="6" autocomplete="off">' +
            '<div class="dpv-error-message" id="dpv-verification-error" style="display: none;"></div>' +
            '</div>' +
            '<div class="dpv-verification-actions">' +
            '<button type="button" id="dpv-verify-phone-btn" class="dpv-button disabled" disabled aria-label="确认手机号关联">确认关联</button>' +
            '</div>' +
            '</div>' +
            '</div>';
        
        $('.comt-comterinfo').after(formHtml);
        
        // 绑定事件
        bindEvents();
        
        // 初始检查按钮状态
        checkSendButtonState();
    }
    
    // 刷新验证码图片
    function refreshCaptcha() {
        $('#dpv-captcha-image').attr('src', dpv_ajax.ajax_url + '?action=dpv_get_captcha&t=' + Date.now());
        $('#dpv-captcha-answer').val('');
        checkSendButtonState();
    }
    
    // 验证手机号格式
    function validatePhone(phone) {
        var phoneRegex = /^1[3-9]\d{9}$/;
        return phoneRegex.test(phone);
    }
    
    // 显示错误信息
    function showError(elementId, message) {
        $('#' + elementId).text(message).show();
    }
    
    // 隐藏错误信息
    function hideError(elementId) {
        $("#" + elementId).hide();
    }

    // 设置输入框状态
    function setInputStatus(inputId, status) {
        var $input = $('#' + inputId);
        $input.removeClass('error success');
        
        if (status === 'error') {
            $input.addClass('error');
        } else if (status === 'success') {
            $input.addClass('success');
        }
    }
    
    // 设置按钮状态
    function setButtonState(buttonId, state, text) {
        var $button = $('#' + buttonId);
        $button.removeClass('disabled ready success error countdown loading');
        $button.prop('disabled', false);
        
        switch(state) {
            case 'disabled':
                $button.addClass('disabled').prop('disabled', true);
                break;
            case 'ready':
                $button.addClass('ready');
                break;
            case 'success':
                $button.addClass('success');
                break;
            case 'error':
                $button.addClass('error');
                break;
            case 'countdown':
                $button.addClass('countdown').prop('disabled', true);
                break;
            case 'loading':
                $button.addClass('loading').prop('disabled', true);
                break;
        }
        
        if (text) {
            $button.text(text);
        }
    }
    
    // 开始倒计时
    function startCountdown(seconds) {
        var remaining = seconds;
        setButtonState('dpv-send-sms-btn', 'countdown', remaining + '秒后重试');
        
        countdownTimer = setInterval(function() {
            remaining--;
            if (remaining > 0) {
                setButtonState('dpv-send-sms-btn', 'countdown', remaining + '秒后重试');
            } else {
                clearInterval(countdownTimer);
                countdownTimer = null;
                setButtonState('dpv-send-sms-btn', 'ready', '重新发送');
                checkSendButtonState();
            }
        }, 1000);
    }
    
    // 检查发送按钮状态
    function checkSendButtonState() {
        if (isProcessing || countdownTimer) {
            return;
        }
        
        var phone = $('#dpv-phone-number').val().trim();
        var captcha = $('#dpv-captcha-answer').val().trim();
        
        var isValid = true;
        
        // 优化手机号验证规则 - 满11位后立即让提示消失
        if (phone.length > 0) {
            if (phone.length < 11) {
                setInputStatus('dpv-phone-number', 'error');
                showError('dpv-phone-error', '请输入完整的11位手机号！');
                isValid = false;
            } else if (phone.length === 11) {
                if (!validatePhone(phone)) {
                    setInputStatus('dpv-phone-number', 'error');
                    showError('dpv-phone-error', '手机号格式不正确！');
                    isValid = false;
                } else {
                    setInputStatus('dpv-phone-number', 'success');
                    hideError('dpv-phone-error'); // 满11位且格式正确时立即隐藏提示
                }
            }
        } else {
            setInputStatus('dpv-phone-number', '');
            hideError('dpv-phone-error');
        }
        
        // 人机验证码仅限制位数，不显示提示信息
        if (captcha.length > 0 && captcha.length < 4) {
            // 不显示错误提示，仅设置输入框状态
            setInputStatus('dpv-captcha-answer', 'error');
            isValid = false;
        } else if (captcha.length === 4) {
            setInputStatus('dpv-captcha-answer', 'success');
        } else {
            setInputStatus('dpv-captcha-answer', '');
        }
        
        // 设置按钮状态
        if (isValid && phone.length === 11 && captcha.length === 4) {
            setButtonState('dpv-send-sms-btn', 'ready', verificationCodeSent ? '重新发送' : '发送验证码');
        } else {
            setButtonState('dpv-send-sms-btn', 'disabled', verificationCodeSent ? '重新发送' : '发送验证码');
        }
    }
    
    // 发送短信验证码
    function sendSmsCode() {
        if (isProcessing || countdownTimer) {
            return;
        }
        
        var phone = $('#dpv-phone-number').val().trim();
        var captcha = $('#dpv-captcha-answer').val().trim();
        
        // 最终验证
        if (!validatePhone(phone)) {
            setInputStatus('dpv-phone-number', 'error');
            showError('dpv-phone-error', '手机号格式不正确！');
            return;
        }
        
        if (!captcha || captcha.length !== 4) {
            setInputStatus('dpv-captcha-answer', 'error');
            showError('dpv-captcha-error', '请输入4位验证码！');
            return;
        }
        
        isProcessing = true;
        setButtonState('dpv-send-sms-btn', 'loading', '发送中...');
        
        $.ajax({
            url: dpv_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'dpv_send_code',
                phone: phone,
                captcha_code: captcha,
                nonce: dpv_ajax.nonce
            },
            timeout: 30000,
            success: function(response) {
                isProcessing = false;
                
                if (response.success) {
                    setButtonState('dpv-send-sms-btn', 'success', '发送成功！');
                    verificationCodeSent = true;
                    
                    // 显示验证码输入区域
                    $("#dpv-verification-code-wrapper").show();
                    
                    // 刷新图片验证码
                    refreshCaptcha();
                    
                    // 聚焦到验证码输入框
                    setTimeout(function() {
                        $('#dpv-verification-code').focus();
                    }, 400);
                    
                    // 开始倒计时
                    setTimeout(function() {
                        startCountdown(120);
                    }, 1000);
                    
                } else {
                    setButtonState('dpv-send-sms-btn', 'error', '发送失败！');
                    
                    // 如果是验证码错误，显示在验证码区域
                    if (response.data.message && response.data.message.indexOf('验证码') !== -1) {
                        setInputStatus('dpv-captcha-answer', 'error');
                        showError('dpv-captcha-error', '验证码输入错误，请重新输入！');
                        refreshCaptcha();
                    } else {
                        showError('dpv-phone-error', response.data.message || '发送失败，请稍后重试！');
                    }
                    
                    setTimeout(function() {
                        checkSendButtonState();
                    }, 3000);
                }
            },
            error: function(xhr, status, error) {
                isProcessing = false;
                setButtonState('dpv-send-sms-btn', 'error', '网络错误');
                
                var errorMsg = '网络错误，请检查网络连接后重试。';
                if (status === 'timeout') {
                    errorMsg = '请求超时，请稍后重试。';
                }
                
                showError('dpv-phone-error', errorMsg);
                
                setTimeout(function() {
                    checkSendButtonState();
                }, 3000);
            }
        });
    }
    
    // 验证手机验证码
    function verifyPhoneCode() {
        if (isProcessing) {
            return;
        }
        
        var phone = $('#dpv-phone-number').val().trim();
        var code = $('#dpv-verification-code').val().trim();
        
        if (!code) {
            setInputStatus('dpv-verification-code', 'error');
            showError('dpv-verification-error', '请输入验证码！');
            return;
        }
        
        if (code.length !== 6 || !/^\d{6}$/.test(code)) {
            setInputStatus('dpv-verification-code', 'error');
            showError('dpv-verification-error', '验证码应为6位数字。');
            return;
        }
        
        isProcessing = true;
        $('#dpv-verify-phone-btn').prop('disabled', true).text('验证中...').addClass('loading');
        
        $.ajax({
            url: dpv_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'dpv_verify_code',
                phone: phone,
                code: code,
                nonce: dpv_ajax.nonce
            },
            timeout: 15000,
            success: function(response) {
                isProcessing = false;
                
                if (response.success) {
                    setInputStatus('dpv-verification-code', 'success');
                    
                    // 验证成功，显示成功信息
                    $("#dpv-verification-container").hide().html(
                            '<div class="dpv-verified-notice">' +
                            '<div class="dpv-success-message">' +
                            '<span class="dpv-success-icon">✓</span>' +
                            '<span>手机验证成功！您现在可以正常评论了。</span>' +
                            '</div>' +
                            '</div>'
                        ).show();
                    
                } else {
                    setInputStatus('dpv-verification-code', 'error');
                    showError('dpv-verification-error', response.data.message || '验证码错误或已过期。');
                    $('#dpv-verify-phone-btn').removeClass('loading').prop('disabled', false).text('确认关联');
                }
            },
            error: function(xhr, status, error) {
                isProcessing = false;
                setInputStatus('dpv-verification-code', 'error');
                
                var errorMsg = '网络错误，请稍后重试。';
                if (status === 'timeout') {
                    errorMsg = '验证超时，请稍后重试。';
                }
                
                showError('dpv-verification-error', errorMsg);
                $('#dpv-verify-phone-btn').removeClass('loading').prop('disabled', false).text('确认关联');
            }
        });
    }
    
    // 绑定事件
    function bindEvents() {
        // 手机号输入事件 - 仅处理数字过滤，不验证
        $(document).on('input', '#dpv-phone-number', function() {
            // 只允许输入数字
            var value = $(this).val().replace(/\D/g, '');
            $(this).val(value);
        });
        
        // 手机号失焦事件 - 触发验证
        $(document).on('blur', '#dpv-phone-number', function() {
            checkSendButtonState();
        });
        
        // 图片验证码输入事件 - 允许输入任意字符
        $(document).on('input', '#dpv-captcha-answer', function() {
            // 允许输入任意字符，仅限制长度
            var value = $(this).val();
            if (value.length > 4) {
                $(this).val(value.substring(0, 4));
            }
            checkSendButtonState();
            
            // 当输入框有内容且长度为4时（变绿状态），隐藏错误提示
            if (value.length === 4) {
                hideError('dpv-captcha-error');
            }
        });
        
        $(document).on('blur', '#dpv-captcha-answer', function() {
            checkSendButtonState();
        });
        
        // 点击验证码图片刷新
        $(document).on('click', '#dpv-captcha-image', function() {
            refreshCaptcha();
        });
        
        // 刷新验证码按钮
        $(document).on('click', '#dpv-refresh-captcha', function() {
            refreshCaptcha();
        });
        
        // 发送短信
        $(document).on('click', '#dpv-send-sms-btn', function() {
            if ($(this).prop('disabled') || countdownTimer || isProcessing) return;
            sendSmsCode();
        });
        
        // 验证码输入
        $(document).on('input', '#dpv-verification-code', function() {
            // 只允许输入数字
            var value = $(this).val().replace(/\D/g, '');
            $(this).val(value);
            
            var code = value.trim();
            hideError('dpv-verification-error');
            
            if (code.length === 6) {
                setInputStatus('dpv-verification-code', 'success');
                setButtonState('dpv-verify-phone-btn', 'ready', '确认关联');
                $('#dpv-verify-phone-btn').prop('disabled', false);
            } else {
                setInputStatus('dpv-verification-code', '');
                setButtonState('dpv-verify-phone-btn', 'disabled', '确认关联');
                $('#dpv-verify-phone-btn').prop('disabled', true);
            }
        });
        
        // 确认关联
        $(document).on('click', '#dpv-verify-phone-btn', function() {
            if ($(this).prop('disabled') || isProcessing) return;
            verifyPhoneCode();
        });
        
        // 键盘事件
        $(document).on('keypress', '#dpv-phone-number, #dpv-verification-code', function(e) {
            // 只允许数字键
            if (e.which < 48 || e.which > 57) {
                e.preventDefault();
            }
        });
        
        $(document).on('keydown', '#dpv-verification-code', function(e) {
            // 回车键提交验证
            if (e.which === 13 && !$('#dpv-verify-phone-btn').prop('disabled')) {
                verifyPhoneCode();
            }
        });
    }
       
    // 初始化：插入手机验证表单
    insertPhoneVerificationForm();
});
