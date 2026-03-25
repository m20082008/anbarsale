<?php
/**
 * Plugin Name: WC Stock Update Form
 * Description: فرم مدیریت ورود/خروج انبار تولید + چاپ لیبل (ساده + ورییشن) با لاگ گروهی (batch)، گزارش ادمین/فرانت، گروه‌بندی pa_multi، نمایش موجودی و ID در سرچ، ثبت در جدول اختصاصی انبار تولید، و همگام‌سازی خروجی با ووکامرس یا YITH تهرانپارس.
 * Author: Sepand & Narges
 * Version: 2.6.0
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('WC_SUF_PLUGIN_FILE') ) {
    define('WC_SUF_PLUGIN_FILE', __FILE__);
}
if ( ! defined('WC_SUF_PLUGIN_DIR') ) {
    define('WC_SUF_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

/**
 * Module loader order matters:
 * - Core/auth first (role helpers used widely)
 * - DB/bootstrap hooks next
 * - Shared helpers/services before hook registration modules
 */
$wc_suf_modules = [
    'includes/core/auth.php',
    'includes/core/db-schema.php',
    'includes/helpers/helpers.php',
    'includes/services/documents.php',
    'includes/services/production-stock.php',
    'includes/hooks/order-sales.php',
    'includes/hooks/admin-order-stock-guard.php',
    'includes/ui/stock-form-shortcode.php',
    'includes/ajax/customer-lookup.php',
    'includes/ajax/save-stock-update.php',
    'includes/reports/reports.php',
];

foreach ( $wc_suf_modules as $wc_suf_module ) {
    require_once WC_SUF_PLUGIN_DIR . $wc_suf_module;
}
unset( $wc_suf_module, $wc_suf_modules );
