<?php

// 如果不是通过WordPress卸载，则退出
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 删除数据库表
global $wpdb;

$tables = array(
    $wpdb->prefix . 'dpv_phone_verifications',
    $wpdb->prefix . 'dpv_send_logs',
    $wpdb->prefix . 'dpv_user_phones'
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

// 删除选项
delete_option('dpv_template_id');
delete_option('dpv_blacklist_prefixes');
delete_option('dpv_daily_limit');
delete_option('dpv_ip_limit_minutes');
delete_option('dpv_phone_limit_minutes');

// 删除所有评论的手机号元数据
$wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE meta_key = 'dpv_phone'");

