jQuery(document).ready(function($) {
    'use strict';
    
    // 清理过期验证码
    $('#dpv-cleanup-expired').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.prop('disabled', true).text('清理中...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'dpv_cleanup_expired',
                nonce: $('#_wpnonce').val()
            },
            success: function(response) {
                if (response.success) {
                    showMessage('清理完成：删除了 ' + response.data.count + ' 条过期记录。', 'success');
                } else {
                    showMessage('清理失败：' + response.data.message, 'error');
                }
            },
            error: function() {
                showMessage('清理失败：网络错误！', 'error');
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // 清理发送日志
    $('#dpv-cleanup-logs').on('click', function() {
        if (!confirm('确定要清理7天前的发送日志吗？')) {
            return;
        }
        
        var button = $(this);
        var originalText = button.text();
        
        button.prop('disabled', true).text('清理中...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'dpv_cleanup_logs',
                nonce: $('#_wpnonce').val()
            },
            success: function(response) {
                if (response.success) {
                    showMessage('清理完成：删除了 ' + response.data.count + ' 条日志记录。', 'success');
                } else {
                    showMessage('清理失败：' + response.data.message, 'error');
                }
            },
            error: function() {
                showMessage('清理失败：网络错误。', 'error');
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // 显示消息
    function showMessage(message, type) {
        var messageDiv = $('<div class="dpv-message ' + type + '">' + message + '</div>');
        $('.dpv-admin-main').prepend(messageDiv);
        
        setTimeout(function() {
            messageDiv.fadeOut(function() {
                messageDiv.remove();
            });
        }, 3000);
    }
    
    // 表单验证
    $('form').on('submit', function() {
        var templateId = $('#dpv_template_id').val().trim();
        
        if (!templateId) {
            alert('请输入短信模板ID');
            $('#dpv_template_id').focus();
            return false;
        }
        
        return true;
    });
    
    // 实时验证输入
    $('#dpv_template_id').on('input', function() {
        var value = $(this).val().trim();
        var feedback = $(this).siblings('.validation-feedback');
        
        if (feedback.length === 0) {
            feedback = $('<div class="validation-feedback"></div>');
            $(this).after(feedback);
        }
        
        if (value.length === 0) {
            feedback.text('').removeClass('error success');
        } else if (value.length < 10) {
            feedback.text('模板ID长度不足！').addClass('error').removeClass('success');
        } else {
            feedback.text('格式正确。').addClass('success').removeClass('error');
        }
    });
    
    // 黑名单号段验证
    $('#dpv_blacklist_prefixes').on('input', function() {
        var value = $(this).val().trim();
        var feedback = $(this).siblings('.validation-feedback');
        
        if (feedback.length === 0) {
            feedback = $('<div class="validation-feedback"></div>');
            $(this).after(feedback);
        }
        
        if (value.length === 0) {
            feedback.text('').removeClass('error success');
            return;
        }
        
        var prefixes = value.split(',');
        var isValid = true;
        
        for (var i = 0; i < prefixes.length; i++) {
            var prefix = prefixes[i].trim();
            if (!/^\d{3}$/.test(prefix)) {
                isValid = false;
                break;
            }
        }
        
        if (isValid) {
            feedback.text('格式正确。').addClass('success').removeClass('error');
        } else {
            feedback.text('格式错误，应为3位数字，用逗号分隔。').addClass('error').removeClass('success');
        }
    });
});

