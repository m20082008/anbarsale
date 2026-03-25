<?php
/*--------------------------------------
| ثابت: Store تهرانپارس برای YITH POS
---------------------------------------*/
if ( ! defined('WC_SUF_TEHRANPARS_STORE_ID') ) {
    define('WC_SUF_TEHRANPARS_STORE_ID', 9343);
}

/*--------------------------------------
| DB: ساخت/آپدیت جدول لاگ + آماده‌سازی شمارنده‌ها
---------------------------------------*/
register_activation_hook(WC_SUF_PLUGIN_FILE, function(){
    global $wpdb;
    $table   = $wpdb->prefix.'stock_audit';
    $move_table = $wpdb->prefix.'stock_production_moves';
    $prod_table = $wpdb->prefix.'stock_production_inventory';
    $move_table = $wpdb->prefix.'stock_production_moves';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE `$table` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT,
      `batch_code`   VARCHAR(64) NULL,
      `csv_file_url` TEXT NULL,
      `word_file_url` TEXT NULL,
      `op_type`      VARCHAR(20) NULL,            -- in / out / onlyLabel / out_teh / in_teh
      `purpose`      TEXT NULL,                   -- برای out/out_teh/in_teh
      `print_label`  TINYINT(1) DEFAULT 0,        -- ارسال برای چاپ
      `product_id`   BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `old_qty`      DECIMAL(20,4) NULL,
      `added_qty`    DECIMAL(20,4) NULL,          -- مقدار تغییر (برای onlyLabel صفر)
      `new_qty`      DECIMAL(20,4) NULL,
      `user_id`      BIGINT UNSIGNED NULL,
      `user_login`   VARCHAR(60) NULL,
      `user_code`    VARCHAR(128) NULL,
      `ip`           VARCHAR(64) NULL,
      `created_at`   DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `batch_code` (`batch_code`),
      KEY `product_id` (`product_id`),
      KEY `created_at` (`created_at`),
      KEY `user_id`    (`user_id`),
      KEY `user_code`  (`user_code`)
    ) $charset;";

    dbDelta($sql);

    $sql_prod = "CREATE TABLE `$prod_table` (
      `product_id` BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `sku` VARCHAR(191) NULL,
      `product_type` VARCHAR(40) NULL,
      `parent_id` BIGINT UNSIGNED NULL,
      `attributes_text` TEXT NULL,
      `qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `updated_at` DATETIME NOT NULL,
      PRIMARY KEY (`product_id`),
      KEY `updated_at` (`updated_at`)
    ) $charset;";
    dbDelta($sql_prod);

    $sql_moves = "CREATE TABLE `$move_table` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT,
      `batch_code` VARCHAR(64) NULL,
      `operation` VARCHAR(20) NOT NULL,
      `destination` VARCHAR(40) NULL,
      `product_id` BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `sku` VARCHAR(191) NULL,
      `product_type` VARCHAR(40) NULL,
      `parent_id` BIGINT UNSIGNED NULL,
      `attributes_text` TEXT NULL,
      `old_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `change_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `new_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `destination_old_qty` DECIMAL(20,4) NULL,
      `destination_new_qty` DECIMAL(20,4) NULL,
      `user_id` BIGINT UNSIGNED NULL,
      `user_login` VARCHAR(60) NULL,
      `user_code` VARCHAR(128) NULL,
      `created_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `product_id` (`product_id`),
      KEY `batch_code` (`batch_code`),
      KEY `operation` (`operation`),
      KEY `created_at` (`created_at`)
    ) $charset;";
    dbDelta($sql_moves);
    add_option('wc_suf_db_version', '2.6.0');

    if ( get_option('wc_suf_counter_in', null) === null )        add_option('wc_suf_counter_in',  '0', '', false);
    if ( get_option('wc_suf_counter_out', null) === null )       add_option('wc_suf_counter_out', '0', '', false);
    if ( get_option('wc_suf_counter_label', null) === null )     add_option('wc_suf_counter_label', '0', '', false);
    if ( get_option('wc_suf_counter_transfer', null) === null )  add_option('wc_suf_counter_transfer', '0', '', false);

    $max_in    = $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING_INDEX(batch_code, '_', -1) AS UNSIGNED)) FROM `$table` WHERE batch_code LIKE 'in\_%'" );
    $max_out   = $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING_INDEX(batch_code, '_', -1) AS UNSIGNED)) FROM `$table` WHERE batch_code LIKE 'out\_%'" );
    $max_label = $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING_INDEX(batch_code, '_', -1) AS UNSIGNED)) FROM `$table` WHERE batch_code LIKE 'onlyLabel\_%'" );
    $max_transfer = $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING_INDEX(batch_code, '_', -1) AS UNSIGNED)) FROM `$table` WHERE batch_code LIKE 'transfer\_%'" );

    if ( is_numeric($max_in) && (int)$max_in > (int)get_option('wc_suf_counter_in', '0') )           update_option('wc_suf_counter_in', (string) (int)$max_in );
    if ( is_numeric($max_out) && (int)$max_out > (int)get_option('wc_suf_counter_out', '0') )        update_option('wc_suf_counter_out', (string) (int)$max_out );
    if ( is_numeric($max_label) && (int)$max_label > (int)get_option('wc_suf_counter_label', '0') )  update_option('wc_suf_counter_label', (string) (int)$max_label );
    if ( is_numeric($max_transfer) && (int)$max_transfer > (int)get_option('wc_suf_counter_transfer', '0') )  update_option('wc_suf_counter_transfer', (string) (int)$max_transfer );
});
add_action('plugins_loaded', function(){ wc_suf_maybe_upgrade_schema(); });

function wc_suf_maybe_upgrade_schema(){
    global $wpdb;
    $table = $wpdb->prefix.'stock_audit';
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s", $table
    ) );

    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    $prod_table = $wpdb->prefix.'stock_production_inventory';
    $move_table = $wpdb->prefix.'stock_production_moves';

    dbDelta("CREATE TABLE `$prod_table` (
      `product_id` BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `sku` VARCHAR(191) NULL,
      `product_type` VARCHAR(40) NULL,
      `parent_id` BIGINT UNSIGNED NULL,
      `attributes_text` TEXT NULL,
      `qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `updated_at` DATETIME NOT NULL,
      PRIMARY KEY (`product_id`),
      KEY `updated_at` (`updated_at`)
    ) $charset;");

    dbDelta("CREATE TABLE `$move_table` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT,
      `batch_code` VARCHAR(64) NULL,
      `operation` VARCHAR(20) NOT NULL,
      `destination` VARCHAR(40) NULL,
      `product_id` BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `sku` VARCHAR(191) NULL,
      `product_type` VARCHAR(40) NULL,
      `parent_id` BIGINT UNSIGNED NULL,
      `attributes_text` TEXT NULL,
      `old_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `change_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `new_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `destination_old_qty` DECIMAL(20,4) NULL,
      `destination_new_qty` DECIMAL(20,4) NULL,
      `user_id` BIGINT UNSIGNED NULL,
      `user_login` VARCHAR(60) NULL,
      `user_code` VARCHAR(128) NULL,
      `created_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `product_id` (`product_id`),
      KEY `batch_code` (`batch_code`),
      KEY `operation` (`operation`),
      KEY `created_at` (`created_at`)
    ) $charset;");

    if ( ! $exists ) return;

    $needed = [
        'batch_code'  => "ADD COLUMN `batch_code` VARCHAR(64) NULL AFTER `id`",
        'csv_file_url'=> "ADD COLUMN `csv_file_url` TEXT NULL AFTER `batch_code`",
        'word_file_url'=> "ADD COLUMN `word_file_url` TEXT NULL AFTER `csv_file_url`",
        'op_type'     => "ADD COLUMN `op_type` VARCHAR(20) NULL AFTER `word_file_url`",
        'purpose'     => "ADD COLUMN `purpose` TEXT NULL AFTER `op_type`",
        'print_label' => "ADD COLUMN `print_label` TINYINT(1) DEFAULT 0 AFTER `purpose`",
        'product_name'=> "ADD COLUMN `product_name` TEXT NULL AFTER `product_id`",
        'old_qty'     => "ADD COLUMN `old_qty` DECIMAL(20,4) NULL AFTER `product_name`",
        'added_qty'   => "ADD COLUMN `added_qty` DECIMAL(20,4) NULL AFTER `old_qty`",
        'new_qty'     => "ADD COLUMN `new_qty` DECIMAL(20,4) NULL AFTER `added_qty`",
        'user_id'     => "ADD COLUMN `user_id` BIGINT UNSIGNED NULL AFTER `new_qty`",
        'user_login'  => "ADD COLUMN `user_login` VARCHAR(60) NULL AFTER `user_id`",
        'user_code'   => "ADD COLUMN `user_code` VARCHAR(128) NULL AFTER `user_login`",
        'ip'          => "ADD COLUMN `ip` VARCHAR(64) NULL AFTER `user_code`",
        'created_at'  => "ADD COLUMN `created_at` DATETIME NOT NULL AFTER `ip`",
    ];
    $missing = [];
    foreach($needed as $col => $ddl){
        $has = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $col) );
        if ( ! $has ) $missing[] = $ddl;
    }
    if ( $missing ){
        $sql = "ALTER TABLE `$table` " . implode(", ", $missing) . ";";
        $wpdb->query($sql);
    }

    $move_needed = [
        'destination_old_qty' => "ADD COLUMN `destination_old_qty` DECIMAL(20,4) NULL AFTER `new_qty`",
        'destination_new_qty' => "ADD COLUMN `destination_new_qty` DECIMAL(20,4) NULL AFTER `destination_old_qty`",
    ];
    $move_missing = [];
    foreach ( $move_needed as $col => $ddl ) {
        $has = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM `$move_table` LIKE %s", $col) );
        if ( ! $has ) $move_missing[] = $ddl;
    }
    if ( $move_missing ) {
        $wpdb->query( "ALTER TABLE `$move_table` " . implode(', ', $move_missing) . ';' );
    }

    $indexes = [
        'batch_code' => "ADD KEY `batch_code` (`batch_code`)",
        'product_id' => "ADD KEY `product_id` (`product_id`)",
        'created_at' => "ADD KEY `created_at` (`created_at`)",
        'user_id'    => "ADD KEY `user_id` (`user_id`)",
        'user_code'  => "ADD KEY `user_code` (`user_code`)",
    ];
    foreach($indexes as $iname => $add){
        $hasIdx = $wpdb->get_var( $wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s", $iname) );
        if ( ! $hasIdx ){ $wpdb->query("ALTER TABLE `$table` $add"); }
    }

    if ( get_option('wc_suf_counter_in', null) === null )      add_option('wc_suf_counter_in',  '0', '', false);
    if ( get_option('wc_suf_counter_out', null) === null )     add_option('wc_suf_counter_out', '0', '', false);
    if ( get_option('wc_suf_counter_label', null) === null )   add_option('wc_suf_counter_label', '0', '', false);
    if ( get_option('wc_suf_counter_transfer', null) === null ) add_option('wc_suf_counter_transfer', '0', '', false);
}

