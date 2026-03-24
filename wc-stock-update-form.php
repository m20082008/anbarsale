<?php
/**
 * Plugin Name: WC Stock Update Form
 * Description: فرم مدیریت ورود/خروج انبار تولید + چاپ لیبل (ساده + ورییشن) با لاگ گروهی (batch)، گزارش ادمین/فرانت، گروه‌بندی pa_multi، نمایش موجودی و ID در سرچ، ثبت در جدول اختصاصی انبار تولید، و همگام‌سازی خروجی با ووکامرس یا YITH تهرانپارس.
 * Author: Sepand & Narges
 * Version: 2.6.0
 */

if ( ! defined('ABSPATH') ) exit;

function wc_suf_current_user_is_pos_manager(){
    if ( ! is_user_logged_in() ) return false;
    $user = wp_get_current_user();
    $roles = (array) ( $user->roles ?? [] );
    return in_array( 'formeditor', $roles, true ) || in_array( 'marjoo', $roles, true );
}

function wc_suf_current_user_has_role( $role ){
    if ( ! is_user_logged_in() ) return false;
    $user = wp_get_current_user();
    $roles = (array) ( $user->roles ?? [] );
    return in_array( (string) $role, $roles, true );
}

/*--------------------------------------
| ثابت: Store تهرانپارس برای YITH POS
---------------------------------------*/
if ( ! defined('WC_SUF_TEHRANPARS_STORE_ID') ) {
    define('WC_SUF_TEHRANPARS_STORE_ID', 9343);
}

/*--------------------------------------
| DB: ساخت/آپدیت جدول لاگ + آماده‌سازی شمارنده‌ها
---------------------------------------*/
register_activation_hook(__FILE__, function(){
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

/*--------------------------------------
| Helpers
---------------------------------------*/
function wc_suf_normalize_digits($s){
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($ar, $en, str_replace($fa, $en, $s));
}
function wc_suf_capacity_from_product($product){
    $cap = 0;
    if ( $product->is_type('variation') ) {
        $slug = $product->get_meta('attribute_pa_multi', true);
        if ($slug !== '') {
            $slug = wc_suf_normalize_digits($slug);
            if (preg_match('/(\d+)/', $slug, $m)) $cap = intval($m[1]);
        }
        if (!$cap) {
            $val = $product->get_attribute('pa_multi');
            if ($val !== '') {
                $val = wc_suf_normalize_digits($val);
                if (preg_match('/(\d+)/', $val, $m)) $cap = intval($m[1]);
            }
        }
    } else {
        $val = $product->get_attribute('pa_multi');
        if ($val !== '') {
            $val = wc_suf_normalize_digits($val);
            if (preg_match('/(\d+)/', $val, $m)) $cap = intval($m[1]);
        }
    }
    return $cap > 0 ? $cap : 0;
}
function wc_suf_full_product_label( $product ){
    if ( ! $product ) return '';
    if ( $product->is_type('variation') ) {
        $parent = wc_get_product( $product->get_parent_id() );
        $base   = $parent ? $parent->get_name() : ('Variation #'.$product->get_id());
        $attrs  = wc_get_formatted_variation( $product, true, false, false );
        $attrs  = trim( wp_strip_all_tags( (string) $attrs ) );
        $label  = trim( $base . ( $attrs ? ' – ' . $attrs : '' ) );
        return $label !== '' ? $label : ( $product->get_name() ?: ('#'.$product->get_id()) );
    }
    $name = $product->get_name();
    return $name !== '' ? $name : ('#'.$product->get_id());
}

/**
 * ساخت رشتهٔ جستجو برای پاپ‌آپ:
 * - نام والد + نام/ویژگی‌های ورییشن (هم slug و هم نام ترم)
 * - pa_multi (هم meta و هم attribute) برای سرچ‌هایی مثل «۶ نفره»
 * - نرمال‌سازی اعداد فارسی/عربی به انگلیسی جهت یکسان‌سازی
 */
function wc_suf_build_search_blob( $product ) {
    if ( ! $product || ! is_object( $product ) ) return '';

    $parts = [];

    $name = $product->get_name();
    if ( is_string( $name ) && $name !== '' ) $parts[] = $name;

    $pid = $product->get_id();
    if ( $pid ) $parts[] = (string) $pid;

    $sku = $product->get_sku();
    if ( is_string( $sku ) && $sku !== '' ) $parts[] = $sku;

    if ( $product->is_type('variation') ) {
        $parent = wc_get_product( $product->get_parent_id() );
        if ( $parent ) {
            $pname = $parent->get_name();
            if ( is_string( $pname ) && $pname !== '' ) $parts[] = $pname;
        }

        $formatted = wc_get_formatted_variation( $product, true, false, false );
        $formatted = trim( wp_strip_all_tags( (string) $formatted ) );
        if ( $formatted !== '' ) $parts[] = $formatted;

        $va = $product->get_variation_attributes();
        if ( is_array( $va ) ) {
            foreach ( $va as $k => $v ) {
                $k = (string) $k;
                $v = (string) $v;
                if ( $k !== '' ) $parts[] = $k;
                if ( $v !== '' ) $parts[] = $v;

                $tax = str_replace( 'attribute_', '', $k );
                if ( $tax && taxonomy_exists( $tax ) && $v !== '' ) {
                    $term = get_term_by( 'slug', $v, $tax );
                    if ( $term && ! is_wp_error( $term ) && isset( $term->name ) ) {
                        $parts[] = (string) $term->name;
                    }
                }
            }
        }

        $multi_slug = $product->get_meta('attribute_pa_multi', true);
        if ( is_string( $multi_slug ) && $multi_slug !== '' ) $parts[] = $multi_slug;

        $multi_attr = $product->get_attribute('pa_multi');
        if ( is_string( $multi_attr ) && $multi_attr !== '' ) $parts[] = $multi_attr;

    } else {
        $multi_attr = $product->get_attribute('pa_multi');
        if ( is_string( $multi_attr ) && $multi_attr !== '' ) $parts[] = $multi_attr;

        $attrs = $product->get_attributes();
        if ( is_array( $attrs ) ) {
            foreach ( $attrs as $attr_key => $attr_obj ) {
                $attr_key = (string) $attr_key;
                if ( $attr_key !== '' ) $parts[] = $attr_key;

                if ( is_object($attr_obj) && method_exists($attr_obj, 'is_taxonomy') && $attr_obj->is_taxonomy() ) {
                    $tax = method_exists($attr_obj, 'get_name') ? (string) $attr_obj->get_name() : $attr_key;
                    if ( $tax && taxonomy_exists($tax) && method_exists($attr_obj, 'get_options') ) {
                        $term_ids = (array) $attr_obj->get_options();
                        foreach ( $term_ids as $tid ) {
                            $term = get_term_by( 'id', (int) $tid, $tax );
                            if ( $term && ! is_wp_error($term) ) {
                                if ( isset($term->name) ) $parts[] = (string) $term->name;
                                if ( isset($term->slug) ) $parts[] = (string) $term->slug;
                            }
                        }
                    }
                } elseif ( is_object($attr_obj) && method_exists($attr_obj, 'get_options') ) {
                    $vals = (array) $attr_obj->get_options();
                    foreach ( $vals as $v ) {
                        $v = (string) $v;
                        if ( $v !== '' ) $parts[] = $v;
                    }
                }
            }
        }
    }

    $blob = trim( implode( ' ', array_filter( array_map( 'strval', $parts ) ) ) );
    $blob = str_replace( ['-', '_', '/', '\\', '،', ',', '(', ')'], ' ', $blob );
    $blob = wc_suf_normalize_digits( $blob );

    if ( function_exists('mb_strtolower') ) {
        $blob = mb_strtolower( $blob );
    } else {
        $blob = strtolower( $blob );
    }

    return $blob;
}

/**
 * جمع‌آوری همه ویژگی‌های محصول برای فیلتر پاپ‌آپ (همه pa_* های موجود):
 * خروجی: [ 'pa_color' => ['زرشکی'], 'pa_multi' => ['6 نفره'], ... ]
 */
function wc_suf_collect_product_attributes_for_picker( $product ) {
    if ( ! $product || ! is_object( $product ) ) return [];

    $out = [];

    $attr_taxonomies = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];
    if ( empty($attr_taxonomies) || ! is_array($attr_taxonomies) ) return $out;

    if ( $product->is_type('variation') ) {
        $va = $product->get_variation_attributes();
        if ( is_array($va) ) {
            foreach ( $va as $k => $slug ) {
                $tax = str_replace( 'attribute_', '', (string) $k );
                $slug = (string) $slug;
                if ( $tax === '' || $slug === '' ) continue;
                if ( taxonomy_exists( $tax ) ) {
                    $term = get_term_by( 'slug', $slug, $tax );
                    if ( $term && ! is_wp_error($term) && isset($term->name) && $term->name !== '' ) {
                        $out[ $tax ][] = (string) $term->name;
                    } else {
                        $out[ $tax ][] = $slug;
                    }
                } else {
                    $out[ $tax ][] = $slug;
                }
            }
        }
    }

    foreach ( $attr_taxonomies as $a ) {
        if ( ! is_object($a) || empty($a->attribute_name) ) continue;
        $tax = 'pa_' . sanitize_title( (string) $a->attribute_name );
        if ( ! taxonomy_exists( $tax ) ) continue;

        $val = $product->get_attribute( $tax );
        if ( ! is_string($val) || $val === '' ) continue;

        $pieces = array_map('trim', explode(',', $val));
        $pieces = array_values(array_filter($pieces, function($x){ return $x !== ''; }));
        if ( empty($pieces) ) continue;

        foreach ( $pieces as $p ) {
            $out[ $tax ][] = $p;
        }
    }

    foreach ( $out as $tax => $vals ) {
        $clean = [];
        foreach ( (array) $vals as $v ) {
            $v = trim( (string) $v );
            if ( $v === '' ) continue;
            $clean[] = $v;
        }
        $clean = array_values( array_unique( $clean ) );
        if ( empty($clean) ) {
            unset($out[$tax]);
        } else {
            $out[$tax] = $clean;
        }
    }

    return $out;
}

/**
 * تعریف ویژگی‌ها برای UI پاپ‌آپ: همه ویژگی‌های global (pa_*)
 * خروجی: [ ['tax'=>'pa_multi','label'=>'چند نفره'], ... ]
 */
function wc_suf_get_picker_attribute_defs() {
    $defs = [];
    $attr_taxonomies = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];
    if ( empty($attr_taxonomies) || ! is_array($attr_taxonomies) ) return $defs;

    foreach ( $attr_taxonomies as $a ) {
        if ( ! is_object($a) || empty($a->attribute_name) ) continue;
        $tax = 'pa_' . sanitize_title( (string) $a->attribute_name );
        if ( ! taxonomy_exists( $tax ) ) continue;
        $defs[] = [
            'tax'   => $tax,
            'label' => function_exists('wc_attribute_label') ? wc_attribute_label( $tax ) : $tax,
        ];
    }

    return $defs;
}

/*--------------------------------------
| YITH POS multistock helpers
---------------------------------------*/
/*--------------------------------------
| YITH POS multistock helpers (fixed)
---------------------------------------*/
function wc_suf_get_stock_product( $product ) {
    if ( ! $product ) return $product;
    $managed_id = method_exists( $product, 'get_stock_managed_by_id' ) ? $product->get_stock_managed_by_id() : $product->get_id();
    if ( $managed_id && $managed_id !== $product->get_id() ) {
        $managed = wc_get_product( $managed_id );
        if ( $managed ) return $managed;
    }
    return $product;
}

/**
 * خواندن متای multi-stock و تبدیل مطمئن به آرایه تمیز
 * - اگر رشتهٔ JSON باشد decode می‌شود
 * - اگر مقدار تودرتو هم JSON باشد merge می‌شود
 * - همه کلیدها/مقادیر به int تبدیل می‌شوند
 */
function wc_suf_yith_parse_multistock_meta( $product ) {
    $raw = $product->get_meta( '_yith_pos_multistock' );

    if ( is_string( $raw ) && $raw !== '' ) {
        $decoded = json_decode( $raw, true );
        $multi   = is_array( $decoded ) ? $decoded : [];
    } elseif ( is_array( $raw ) ) {
        $multi = $raw;
    } else {
        $multi = [];
    }

    $clean = [];
    foreach ( $multi as $k => $v ) {
        // اگر مقدار خودش JSON باشد (مثل حالت خراب قبلی)، بازش کن و merge کن
        if ( is_string( $v ) ) {
            $decoded_v = json_decode( $v, true );
            if ( is_array( $decoded_v ) ) {
                foreach ( $decoded_v as $dk => $dv ) {
                    $clean[ (int) $dk ] = (int) $dv;
                }
                continue;
            }
        }
        $clean[ (int) $k ] = (int) $v;
    }

    return $clean;
}

function wc_suf_yith_prepare_store_stock( $product, $store_id ) {
    if ( ! function_exists( 'yith_pos_stock_management' ) ) {
        return new WP_Error( 'yith_missing', 'YITH POS فعال نیست.' );
    }
    $product = wc_suf_get_stock_product( $product );

    $multi   = wc_suf_yith_parse_multistock_meta( $product );
    $enabled = $product->get_meta( '_yith_pos_multistock_enabled' );

  if ( ! isset( $multi[ $store_id ] ) ) {
    $multi[ $store_id ] = 0; // استارت از صفر؛ فقط مقادیر انتقالی اضافه می‌شود
    }
    if ( 'yes' !== $enabled ) {
        $product->update_meta_data( '_yith_pos_multistock_enabled', 'yes' );
    }
    $product->update_meta_data( '_yith_pos_multistock', $multi );
    $product->save();

    return true;
}

function wc_suf_yith_get_store_stock( $product, $store_id ) {
    if ( ! function_exists( 'yith_pos_stock_management' ) ) return false;
    $product = wc_suf_get_stock_product( $product );
    $product->update_meta_data( '_yith_pos_multistock', wc_suf_yith_parse_multistock_meta( $product ) );
    $product->save();

    $manager = yith_pos_stock_management();
    return $manager->get_stock_amount( $product, $store_id );
}

function wc_suf_yith_get_store_stock_qty( $product, $store_id ) {
    $qty = wc_suf_yith_get_store_stock( $product, $store_id );
    if ( false === $qty || null === $qty ) {
        return false;
    }
    return (float) $qty;
}

function wc_suf_yith_change_store_stock( $product, $qty, $store_id, $operation = 'increase' ) {
    if ( ! function_exists( 'yith_pos_stock_management' ) ) {
        return new WP_Error( 'yith_missing', 'YITH POS فعال نیست.' );
    }
    $product = wc_suf_get_stock_product( $product );
    $prep    = wc_suf_yith_prepare_store_stock( $product, $store_id );
    if ( is_wp_error( $prep ) ) return $prep;

    // پس از عادی‌سازی متا، update_product_stock روی آرایهٔ تمیز کار می‌کند
    $manager = yith_pos_stock_management();
    $current = $manager->get_stock_amount( $product, $store_id );
    if ( false === $current ) $current = 0;

    return $manager->update_product_stock( $product, absint( $qty ), $store_id, (int) $current, $operation );
}


/*--------------------------------------
| Helpers (برچسب عملیات و کد ثبت ترتیبی)
---------------------------------------*/
function wc_suf_op_label($op){
    if ($op === 'return_production') return 'مرجوعی به انبار تولید';
    if ($op === 'return_main') return 'مرجوعی به انبار اصلی';
    if ($op === 'return_teh')  return 'مرجوعی به تهرانپارس';
    if ($op === 'return')      return 'مرجوعی';
    if ($op === 'out_main') return 'خروج به انبار اصلی';
    if ($op === 'out_teh')  return 'خروج به تهرانپارس';
    if ($op === 'out')      return 'خروج';
    if ($op === 'transfer_main_teh') return 'انتقال از انبار اصلی به تهرانپارس';
    if ($op === 'transfer_teh_main') return 'انتقال از تهرانپارس به انبار اصلی';
    if ($op === 'transfer') return 'انتقال بین انبارها';
    if ($op === 'in')       return 'ورود';
    if ($op === 'onlyLabel') return 'فقط لیبل';
    return $op;
}

function wc_suf_destination_label( $destination ) {
    if ( $destination === 'main' ) {
        return 'انبار اصلی';
    }
    if ( $destination === 'production' ) {
        return 'انبار تولید';
    }
    if ( $destination === 'teh' ) {
        return 'انبار تهرانپارس';
    }
    return $destination;
}

function wc_suf_audit_op_type_for_storage( $op_type, $out_destination = '', $return_destination = '', $transfer_source = '', $transfer_destination = '' ) {
    if ( $op_type === 'out' ) {
        return ( $out_destination === 'teh' ) ? 'out_teh' : 'out_main';
    }
    if ( $op_type === 'transfer' ) {
        // برای سازگاری با دیتابیس‌های قدیمی (enum/محدودیت مقدار)، از مقادیر قبلی استفاده می‌کنیم.
        return ( $transfer_destination === 'teh' ) ? 'out_teh' : 'out_main';
    }
    if ( $op_type === 'return' ) {
        // برای سازگاری با دیتابیس‌های قدیمی که ستون op_type کوتاه/enum دارند.
        return 'return';
    }
    return $op_type;
}

function wc_suf_get_product_attributes_text( $product ) {
    if ( ! $product || ! is_object( $product ) ) return '';
    $txt = wc_get_formatted_variation( $product, true, false, false );
    $txt = trim( wp_strip_all_tags( (string) $txt ) );
    return $txt;
}


function wc_suf_generate_batch_label_html( $batch_code, $rows ) {
    if ( empty( $rows ) || ! is_array( $rows ) ) {
        return new WP_Error( 'label_empty', 'داده‌ای برای ساخت صفحه چاپ لیبل وجود ندارد.' );
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'label_upload_dir', 'مسیر آپلود در دسترس نیست: ' . $upload['error'] );
    }

    $dir = trailingslashit( $upload['basedir'] ) . 'wc-suf-exports';
    if ( ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'label_mkdir_failed', 'ساخت پوشه فایل‌های چاپ لیبل ناموفق بود.' );
    }

    $safe_batch = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $batch_code );
    $filename   = sprintf( '%s-%s-labels.html', $safe_batch, wp_generate_password(6, false, false) );
    $filepath   = trailingslashit( $dir ) . $filename;
    $fileurl    = trailingslashit( $upload['baseurl'] ) . 'wc-suf-exports/' . $filename;

    $website_name = 'www.doukshop.ir';
    $logo_url = 'https://douk.sepandpitch.com/wp-content/uploads/2026/03/enlogo.jpg';

    $label_items = [];
    foreach ( $rows as $r ) {
        $line_count = max( 1, (int) ( $r['qty'] ?? 1 ) );
        for ( $i = 0; $i < $line_count; $i++ ) {
            $label_items[] = [
                'id'    => (string) ( $r['id'] ?? '' ),
                'name'  => (string) ( $r['name'] ?? '' ),
                'price' => (string) ( $r['price'] ?? '' ),
            ];
        }
    }

    $labels_html = '';
    foreach ( $label_items as $idx => $it ) {
        $id = preg_replace('/[^0-9A-Za-z\-\.]/', '', (string) $it['id']);
        if ( $id === '' ) {
            $id = (string) ( $it['id'] ?? '' );
        }
        $labels_html .= '<article class="wc-suf-label">'
            . '<div class="label-row name-row"><div class="name">' . esc_html( (string) $it['name'] ) . '</div></div>'
            . '<div class="label-row middle-row">'
            . '  <div class="barcode-wrap">'
            . '    <svg class="barcode" jsbarcode-format="CODE128" jsbarcode-value="' . esc_attr( $id ) . '" jsbarcode-textmargin="0" jsbarcode-fontoptions="bold"></svg>'
            . '  </div>'
            . '  <div class="price-wrap">'
            . '    <div class="price-line"><strong>قیمت:</strong> <span>' . esc_html( number_format_i18n( (float) $it['price'] ) . ' تومان' ) . '</span></div>'
            . '    <div class="code-line"><strong>کد محصول:</strong> <span>' . esc_html( $id ) . '</span></div>'
            . '  </div>'
            . '</div>'
            . '<div class="label-row footer-row">'
            . '  <div class="logo-wrap"><img src="' . esc_url( $logo_url ) . '" alt="logo"></div>'
            . '  <div class="site-wrap">' . esc_html( $website_name ) . '</div>'
            . '</div>'
            . '</article>';
    }

    $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>چاپ لیبل - ' . esc_html( $batch_code ) . '</title>'
        . '<style>'
        . '@page{size:118.11mm 29.97mm; margin:0;}'
        . 'body{margin:0; font-family:Tahoma,Arial,sans-serif; background:#fff;}'
        . '.sheet{width:118.11mm; margin:0; padding:0 1mm 1mm; box-sizing:border-box; display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); grid-auto-rows:28.92mm; row-gap:1mm; column-gap:1mm;}'
        . '.wc-suf-label{box-sizing:border-box; height:100%; border:0.35mm solid #111; border-radius:4mm; overflow:hidden; display:grid; grid-template-rows:10.5mm 10.5mm 8.97mm; padding:0.6mm 0.4mm 1.8mm;}'
        . '.label-row{display:flex; align-items:center; box-sizing:border-box;}'
        . '.name-row{justify-content:center; border-bottom:0.35mm solid #111; padding:1mm 1.5mm;}'
        . '.name{font-size:3.2mm; font-weight:700; text-align:center; line-height:1.2; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;}'
        . '.middle-row{display:grid; direction:ltr; grid-template-columns:40% 60%; border-bottom:0.35mm solid #111;}'
        . '.barcode-wrap{border-right:0.35mm solid #111; padding:0.7mm 0.6mm; display:flex; align-items:center; justify-content:center;}'
        . '.barcode{width:100%; height:100%;}'
        . '.price-wrap{padding:1mm 1.3mm; font-size:2.8mm; line-height:1.4; text-align:right; direction:rtl; display:flex; flex-direction:column; justify-content:center; gap:0.5mm;}'
        . '.price-line,.code-line{white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}'
        . '.footer-row{display:grid; grid-template-columns:19mm 1fr;}'
        . '.logo-wrap{display:flex; align-items:center; justify-content:center;padding-right: 2mm;
    padding-bottom: 2mm;}'
        . '.logo-wrap img{max-width:100%; max-height:78%; object-fit:contain; object-position:center; margin:0;}'
        . '.site-wrap{display:flex; align-items:center; justify-content:flex-end; padding:0 1.5mm; font-size:2.9mm;}'
        . '@media print{.sheet{margin:0;}}'
        . '</style>'
        . '<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>'
        . '</head><body>'
        . '<main class="sheet">' . $labels_html . '</main>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".barcode").forEach(function(el){try{JsBarcode(el, el.getAttribute("jsbarcode-value") || "", {format:"CODE128",displayValue:false,margin:0,height:24,width:1.2});}catch(e){}});});</script>'
        . '</body></html>';

    $bytes = file_put_contents( $filepath, $html );
    if ( false === $bytes ) {
        return new WP_Error( 'label_write_failed', 'ایجاد صفحه چاپ لیبل ناموفق بود.' );
    }

    return [
        'path' => $filepath,
        'url'  => $fileurl,
    ];
}


function wc_suf_generate_batch_word_receipt( $batch_code, $context, $rows ) {
    if ( empty($rows) || ! is_array($rows) ) {
        return new WP_Error( 'word_empty', 'داده‌ای برای ساخت رسید Word وجود ندارد.' );
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'word_upload_dir', 'مسیر آپلود در دسترس نیست: ' . $upload['error'] );
    }

    $dir = trailingslashit( $upload['basedir'] ) . 'wc-suf-exports';
    if ( ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'word_mkdir_failed', 'ساخت پوشه فایل‌های Word ناموفق بود.' );
    }

    $safe_batch = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $batch_code );
    $filename   = sprintf( '%s-%s.doc', $safe_batch, wp_generate_password(6, false, false) );
    $filepath   = trailingslashit( $dir ) . $filename;
    $fileurl    = trailingslashit( $upload['baseurl'] ) . 'wc-suf-exports/' . $filename;

    $op_type   = (string) ($context['op_type'] ?? '');
    $purpose   = (string) ($context['purpose'] ?? '');
    $user_disp = (string) ($context['user_display'] ?? '');
    $user_code = (string) ($context['user_code'] ?? '');
    $created   = (string) ($context['created_at'] ?? current_time('mysql'));
    $jalali    = wc_suf_format_jalali_datetime($created);
    $op_label  = wc_suf_op_label($op_type);

    $sum = 0;
    $rows_html = '';
    foreach ( $rows as $i => $r ) {
        $qty = (float) ($r['qty'] ?? 0);
        $sum += $qty;
        $rows_html .= '<tr>'
            . '<td>' . esc_html( (string)($i+1) ) . '</td>'
            . '<td>' . esc_html( (string)($r['id'] ?? '') ) . '</td>'
            . '<td>' . esc_html( (string)($r['name'] ?? '') ) . '</td>'
            . '<td>' . esc_html( (string)($r['qty'] ?? 0) ) . '</td>'
            . '</tr>';
    }

    $html = '<html><head><meta charset="UTF-8"><style>'
        . 'body{font-family:Tahoma,Arial,sans-serif; direction:rtl; color:#111827; font-size:12pt;}'
        . '.box{border:1px solid #d1d5db; border-radius:10px; padding:14px;}'
        . 'h1{font-size:18pt; margin:0 0 12px 0; color:#1e3a8a;}'
        . 'table{border-collapse:collapse; width:100%; margin-top:12px;}'
        . 'th,td{border:1px solid #9ca3af; padding:6px; text-align:right;}'
        . 'th{background:#f3f4f6;}'
        . '.meta{margin:3px 0;}'
        . '</style></head><body>'
        . '<div class="box">'
        . '<h1>رسید ' . esc_html($op_label) . '</h1>'
        . '<div class="meta"><strong>کد عملیات:</strong> ' . esc_html($batch_code) . '</div>'
        . '<div class="meta"><strong>تاریخ:</strong> ' . esc_html($jalali) . '</div>'
        . '<div class="meta"><strong>کاربر:</strong> ' . esc_html($user_disp ?: 'مهمان') . '</div>'
        . '<div class="meta"><strong>کد کاربر:</strong> ' . esc_html($user_code ?: '—') . '</div>'
        . '<div class="meta"><strong>توضیحات:</strong> ' . esc_html($purpose ?: '—') . '</div>'
        . '<div class="meta"><strong>تعداد اقلام:</strong> ' . esc_html( (string) count($rows) ) . ' | <strong>جمع تعداد:</strong> ' . esc_html( (string) $sum ) . '</div>'
        . '<table><thead><tr><th>#</th><th>ID</th><th>نام محصول</th><th>تعداد</th></tr></thead><tbody>' . $rows_html . '</tbody></table>'
        . '</div></body></html>';

    $bytes = file_put_contents( $filepath, $html );
    if ( false === $bytes ) {
        return new WP_Error( 'word_write_failed', 'ایجاد فایل Word ناموفق بود.' );
    }

    return [ 'path' => $filepath, 'url' => $fileurl ];
}

function wc_suf_get_production_stock_qty( $product_id ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';
    $qty = $wpdb->get_var( $wpdb->prepare("SELECT qty FROM `$table` WHERE product_id = %d", absint($product_id) ) );
    return (int) ($qty ?? 0);
}

function wc_suf_ensure_production_inventory_row( $product ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';
    $pid   = absint( $product->get_id() );
    if ( ! $pid ) return;

    $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO `$table` (`product_id`,`product_name`,`sku`,`product_type`,`parent_id`,`attributes_text`,`qty`,`updated_at`) VALUES (%d,%s,%s,%s,%d,%s,%f,%s)",
        $pid,
        wc_suf_full_product_label( $product ),
        $product->get_sku() ?: null,
        $product->get_type(),
        $product->is_type('variation') ? $product->get_parent_id() : null,
        wc_suf_get_product_attributes_text( $product ),
        0,
        current_time('mysql')
    ) );
}

function wc_suf_get_production_stock_qty_for_update( $product ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';
    $pid   = absint( $product->get_id() );
    if ( ! $pid ) return 0;

    wc_suf_ensure_production_inventory_row( $product );
    $qty = $wpdb->get_var( $wpdb->prepare(
        "SELECT qty FROM `$table` WHERE product_id = %d FOR UPDATE",
        $pid
    ) );

    return (int) ($qty ?? 0);
}

function wc_suf_set_production_stock_qty( $product, $new_qty ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';
    $pid   = absint( $product->get_id() );
    if ( ! $pid ) return;

    $data = [
        'product_name'     => wc_suf_full_product_label( $product ),
        'sku'              => $product->get_sku() ?: null,
        'product_type'     => $product->get_type(),
        'parent_id'        => $product->is_type('variation') ? $product->get_parent_id() : null,
        'attributes_text'  => wc_suf_get_product_attributes_text( $product ),
        'qty'              => max( 0, (int) $new_qty ),
        'updated_at'       => current_time('mysql'),
    ];

    $updated = $wpdb->update( $table, $data, [ 'product_id' => $pid ], [ '%s','%s','%s','%d','%s','%f','%s' ], [ '%d' ] );
    if ( false === $updated ) {
        return new WP_Error( 'production_update_failed', 'به‌روزرسانی موجودی انبار تولید در دیتابیس ناموفق بود.' );
    }

    $verify_qty = $wpdb->get_var( $wpdb->prepare("SELECT qty FROM `$table` WHERE product_id = %d", $pid ) );
    if ( (int) $verify_qty !== max( 0, (int) $new_qty ) ) {
        return new WP_Error( 'production_verify_failed', 'صحت‌سنجی موجودی انبار تولید ناموفق بود.' );
    }

    return true;
}

function wc_suf_update_production_stock_qty( $product, $delta ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';

    $pid = absint( $product->get_id() );
    $current = wc_suf_get_production_stock_qty( $pid );
    $new = max( 0, $current + (int) $delta );

    $data = [
        'product_id'       => $pid,
        'product_name'     => wc_suf_full_product_label( $product ),
        'sku'              => $product->get_sku() ?: null,
        'product_type'     => $product->get_type(),
        'parent_id'        => $product->is_type('variation') ? $product->get_parent_id() : null,
        'attributes_text'  => wc_suf_get_product_attributes_text( $product ),
        'qty'              => $new,
        'updated_at'       => current_time('mysql'),
    ];

    $exists = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE product_id = %d", $pid ) );
    if ( $exists > 0 ) {
        $wpdb->update( $table, $data, [ 'product_id' => $pid ], [ '%d','%s','%s','%s','%d','%s','%f','%s' ], [ '%d' ] );
    } else {
        $wpdb->insert( $table, $data, [ '%d','%s','%s','%s','%d','%s','%f','%s' ] );
    }

    return [ $current, $new ];
}

function wc_suf_next_batch_code( $op_type ){
    global $wpdb;

    $op_type = ($op_type === 'out')
        ? 'out'
        : ( ($op_type === 'onlyLabel')
            ? 'onlyLabel'
            : ( ($op_type === 'transfer') ? 'transfer' : 'in' ) );

    $opt_map = [
        'in'        => 'wc_suf_counter_in',
        'out'       => 'wc_suf_counter_out',
        'onlyLabel' => 'wc_suf_counter_label',
        'transfer'  => 'wc_suf_counter_transfer',
    ];
    $opt_name = $opt_map[$op_type];

    if ( get_option($opt_name, null) === null ) {
        add_option($opt_name, '0', '', false);
    }

    $current_val = get_option($opt_name, '0');
    if ( ! preg_match('/^\d+$/', (string) $current_val) ) {
        update_option($opt_name, '0', false);
        $current_val = '0';
    }

    $tbl = $wpdb->options;
    $wpdb->query( $wpdb->prepare(
        "UPDATE $tbl SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
        $opt_name
    ) );

    $n = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT option_value FROM $tbl WHERE option_name = %s",
        $opt_name
    ) );

    if ( $n <= 0 ) {
        $n = (int) $current_val + 1;
        update_option($opt_name, (string)$n, false);
    }

    $num = sprintf('%04d', $n);
    $prefix = ($op_type === 'onlyLabel')
        ? 'onlyLabel_'
        : ( $op_type === 'out'
            ? 'out_'
            : ( $op_type === 'transfer' ? 'transfer_' : 'in_' ) );
    return $prefix . $num;
}

/*--------------------------------------
| فرانت: Select2/SelectWoo + استایل‌ها
---------------------------------------*/
function wc_suf_enqueue_front_assets() {
    wp_enqueue_script('jquery');
    $use_core_selectwoo = ( wp_script_is('selectWoo', 'registered') || wp_script_is('selectWoo', 'enqueued') );
    if ( $use_core_selectwoo ) {
        wp_enqueue_script('selectWoo');
        if ( wp_style_is('select2', 'registered') ) wp_enqueue_style('select2');
    } else {
        wp_enqueue_style('wc-suf-select2', plugins_url('assets/select2.min.css', __FILE__), [], '4.1.0');
        wp_enqueue_script('wc-suf-select2', plugins_url('assets/select2.min.js', __FILE__), ['jquery'], '4.1.0', true);
    }
    $css = '
    #sel-id, #sel-name { font-size: 18px; line-height: 1.6; padding: 6px 8px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        font-size: 18px !important; line-height: 1.8 !important; padding-top: 2px !important; padding-bottom: 2px !important; font-weight: 600;
    }
    .select2-results__option { font-size: 16px; }
    .select2-search--dropdown .select2-search__field { font-size: 16px; padding: 8px; }
    .select2-container .select2-results > .select2-results__options { max-height: 85vh !important; }
    .select2-container .select2-dropdown { max-height: 90vh !important; overflow: auto !important; }
    .select2-container { z-index: 999999 !important; }
    .suf-muted { color:#6b7280; font-size:12px }
    .wc-suf-modal-overlay{ position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000000; display:none; }
    .wc-suf-modal{ position:fixed; inset:0; z-index:1000001; display:none; align-items:center; justify-content:center; padding:18px; }
    .wc-suf-modal .wc-suf-modal-card{ width:min(980px, 96vw); max-height:88vh; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,.25); display:flex; flex-direction:column; }
    .wc-suf-modal .wc-suf-modal-head{ padding:12px 14px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:10px; background:#f9fafb; }
    .wc-suf-modal .wc-suf-modal-title{ font-weight:800; }
    .wc-suf-modal .wc-suf-modal-close{ border:1px solid #ef4444; background:#ef4444; color:#fff; border-radius:10px; padding:6px 10px; cursor:pointer; font-weight:900; line-height:1; }
    .wc-suf-modal .wc-suf-modal-body{ padding:12px 14px; overflow:auto; }
    .wc-suf-modal .wc-suf-modal-foot{ padding:12px 14px; border-top:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:12px; background:#f9fafb; }
    .wc-suf-picker-row{ display:grid; grid-template-columns: 1fr 140px; gap:10px; align-items:center; padding:10px 8px; border-bottom:1px solid #f1f5f9; }
    .wc-suf-picker-row:last-child{ border-bottom:none; }
    .wc-suf-picker-name{ font-weight:600; }
    .wc-suf-picker-meta{ font-size:12px; color:#6b7280; margin-top:2px; }
    .wc-suf-picker-qty{ display:flex; align-items:center; justify-content:flex-end; gap:6px; }
    .wc-suf-picker-qty input{ width:76px; text-align:center; padding:6px; font-size:16px; border:1px solid #e5e7eb; border-radius:10px; }
    .wc-suf-picker-qty button{ padding:6px 10px; font-size:16px; cursor:pointer; border:1px solid #e5e7eb; background:#fff; border-radius:10px; }
    .wc-suf-filter-grid{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .wc-suf-filter-grid .wc-suf-filter{ display:flex; gap:6px; align-items:center; }
    .wc-suf-filter-grid label{ font-weight:700; font-size:13px; color:#111827; }
    .wc-suf-filter-grid select{ padding:9px 8px; border:1px solid #e5e7eb; border-radius:12px; font-size:13px; background:#fff; min-width:140px; max-width:180px; }
    #return-destination, #return-reason{ background:#fff !important; color:#111 !important; }
    #return-destination option, #return-reason option{ background:#fff !important; color:#111 !important; }
    ';
    wp_register_style('wc-suf-ui', false, [], '2.4.0');
    wp_enqueue_style('wc-suf-ui');
    wp_add_inline_style('wc-suf-ui', $css);
}

/*--------------------------------------
| Shortcode: [stock_update_form key="910"]
---------------------------------------*/
add_shortcode('stock_update_form', function($atts){
    wc_suf_enqueue_front_assets();
    if ( ! function_exists('wc_get_products') ) {
        return '<div dir="rtl" style="color:#b91c1c">WooCommerce فعال نیست.</div>';
    }
    if ( ! is_user_logged_in() ) {
        ob_start();
        echo '<div dir="rtl" style="max-width:420px; margin:20px auto; padding:16px; border:1px solid #e5e7eb; border-radius:12px; background:#fff">';
        echo '<h3 style="margin-top:0">ورود کاربر</h3>';
        wp_login_form([
            'echo'           => true,
            'remember'       => true,
            'redirect'       => esc_url( add_query_arg( [] ) ),
            'label_username' => 'نام کاربری',
            'label_password' => 'رمز عبور',
            'label_log_in'   => 'ورود',
        ]);
        echo '</div>';
        return ob_get_clean();
    }
    if( ! wc_suf_current_user_is_pos_manager() ){
        return '<div dir="rtl" style="color:#b91c1c">این فرم فقط برای کاربران با نقش formeditor یا marjoo در دسترس است.</div>';
    }
    $is_marjoo = wc_suf_current_user_has_role( 'marjoo' );
    $atts = shortcode_atts(['key' => ''], $atts, 'stock_update_form');
    $current_user = wp_get_current_user();
    $display_name = trim( (string) $current_user->first_name . ' ' . (string) $current_user->last_name );
    if ( $display_name === '' ) {
        $display_name = (string) ( $current_user->display_name ?: $current_user->user_login );
    }

    $cache_key = 'wc_suf_products_cache_v250';
    $products = get_transient( $cache_key );
    if ( ! is_array($products) ) {
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'type' => ['simple','variation'],
            'return' => 'objects',
        ]);
        set_transient( $cache_key, $products, MINUTE_IN_SECONDS * 5 );
    }

    $make_label = function( $p ){
        if ( $p->is_type('variation') ) {
            $parent = wc_get_product( $p->get_parent_id() );
            $base   = $parent ? $parent->get_name() : ('Variation #'.$p->get_id());
            $attrs  = wc_get_formatted_variation( $p, true, false, false );
            $attrs  = trim( wp_strip_all_tags( (string) $attrs ) );
            $label  = trim( $base . ( $attrs ? ' – ' . $attrs : '' ) );
            if ( $label === '' ) $label = $p->get_name() ?: ('#'.$p->get_id());
            return $label;
        }
        $name = $p->get_name();
        return $name !== '' ? $name : ('#'.$p->get_id());
    };

    $picker_attr_defs = wc_suf_get_picker_attribute_defs();

    global $wpdb;
    $prod_table = $wpdb->prefix.'stock_production_inventory';
    $prod_rows = $wpdb->get_results( "SELECT product_id, qty FROM `$prod_table`", ARRAY_A );
    $prod_map = [];
    if ( is_array($prod_rows) ) {
        foreach ( $prod_rows as $pr ) {
            $ppid = isset($pr['product_id']) ? absint($pr['product_id']) : 0;
            if ( ! $ppid ) continue;
            $prod_map[$ppid] = (int) ($pr['qty'] ?? 0);
        }
    }

    $bucketed = [];
    $preferred_order = [4,6,8,12];
    foreach ($products as $p){
        $pid = $p->get_id();
        $wc_stock   = (int) max(0, (int) ($p->get_stock_quantity() ?? 0));
        $prod_stock = isset($prod_map[$pid]) ? (int) $prod_map[$pid] : 0;
        $teh_stock  = 0;
        $teh_ok     = 0;

        if ( function_exists('yith_pos_stock_management') ) {
            $teh_read = wc_suf_yith_get_store_stock( $p, (int) WC_SUF_TEHRANPARS_STORE_ID );
            if ( false !== $teh_read ) {
                $teh_stock = (int) $teh_read;
                $teh_ok = 1;
            }
        }

        $label = $make_label($p);
        $row = [
            'id'           => $pid,
            'label'        => $label,
            'stock'        => $prod_stock,
            'prod_stock'   => $prod_stock,
            'wc_stock'     => $wc_stock,
            'teh_stock'    => $teh_stock,
            'teh_stock_ok' => $teh_ok,
            'search'       => wc_suf_build_search_blob( $p ),
            'attrs'        => wc_suf_collect_product_attributes_for_picker( $p ),
        ];
        $cap = wc_suf_capacity_from_product($p) ?: 0;
        $bucketed[$cap][] = $row;
    }
    foreach ($bucketed as $cap => &$list) { usort($list, fn($a,$b)=> strcasecmp($a['label'],$b['label'])); } unset($list);

    $name_select_html = '';
    $id_select_html   = '';
    $all              = [];

    $emit_group = function($cap, $rows) use (&$name_select_html,&$id_select_html,&$all){
        $group_label = ($cap ? $cap.' نفره' : 'سایر');
        $name_select_html .= '<optgroup label="'.esc_attr($group_label).'">';
        $id_select_html   .= '<optgroup label="'.esc_attr($group_label).'">';
        foreach ($rows as $row) {
            $opt_text = '['.esc_html($row['id']).' | موجودی: '.esc_html($row['stock']).'] '.esc_html($row['label']);
            $name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'. $opt_text .'</option>';
            $id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'. esc_html($row['id']).'</option>';
            $all[] = $row;
        }
        $name_select_html .= '</optgroup>';
        $id_select_html   .= '</optgroup>';
    };

    foreach ($preferred_order as $cap) if (!empty($bucketed[$cap])) $emit_group($cap, $bucketed[$cap]);
    $other_caps = array_diff(array_keys($bucketed), array_merge($preferred_order, [0]));
    sort($other_caps, SORT_NUMERIC);
    foreach ($other_caps as $cap) $emit_group($cap, $bucketed[$cap]);
    if (!empty($bucketed[0])) $emit_group(0, $bucketed[0]);

    ob_start(); ?>
    <div id="stock-form" dir="rtl" style="display:grid; gap:12px; align-items:center;">
        <div style="background:#ecfeff; border:1px solid #bae6fd; color:#0f172a; border-radius:10px; padding:10px 12px; font-weight:700">کاربر: <?php echo esc_html($display_name); ?></div>

        <div id="optype-block" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; background:#f9fafb; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px">
          <div style="font-weight:700; min-width:220px">نوع عملیات موجودی / لیبل</div>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="op-type" value="in" <?php disabled( $is_marjoo ); ?>>
            <span>ورود به انبار تولید</span>
          </label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="op-type" value="out" <?php disabled( $is_marjoo ); ?>>
            <span>خروج از انبار</span>
          </label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="op-type" value="transfer" <?php disabled( $is_marjoo ); ?>>
            <span>انتقال بین انبارها</span>
          </label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="op-type" value="return">
            <span>مرجوعی</span>
          </label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="op-type" value="onlyLabel" <?php disabled( $is_marjoo ); ?>>
            <span>صرفاً چاپ لیبل</span>
          </label>
          <span class="suf-muted">(پس از انتخاب، قابل تغییر نیست مگر با رفرش)</span>
        </div>

            
        <div id="out-destination-wrap" style="display:none; gap:8px; align-items:center; flex-wrap:wrap">
          <label style="min-width:120px">مقصد خروج:</label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="out-destination" value="main">
            <span>خروج به انبار اصلی</span>
          </label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="out-destination" value="teh">
            <span>خروج به انبار تهران پارس</span>
          </label>
        </div>

        <div id="transfer-controls-wrap" style="display:none; gap:10px; align-items:center; flex-wrap:wrap">
          <label for="transfer-source" style="min-width:120px">انبار مبدا:</label>
          <select id="transfer-source" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:180px">
            <option value="">انتخاب انبار مبدا...</option>
            <option value="main">انبار اصلی</option>
            <option value="teh">انبار تهران پارس</option>
          </select>

          <label for="transfer-destination" style="min-width:120px">انبار مقصد:</label>
          <select id="transfer-destination" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:180px">
            <option value="">انتخاب انبار مقصد...</option>
          </select>
        </div>

        <div id="return-controls-wrap" style="display:none; gap:10px; align-items:center; flex-wrap:wrap">
          <label for="return-destination" style="min-width:120px">انبار مرجوعی:</label>
          <select id="return-destination" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:180px">
            <option value="">انتخاب انبار...</option>
            <?php if ( ! $is_marjoo ) : ?>
            <option value="main">انبار اصلی</option>
            <?php endif; ?>
            <option value="teh">انبار تهران پارس</option>
          </select>

          <label for="return-reason" style="min-width:120px">علت مرجوعی:</label>
          <select id="return-reason" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:280px">
            <option value="">انتخاب علت...</option>
            <option value="انصراف از خرید مشتری">۱- انصراف از خرید مشتری</option>
            <option value="تعویض طرح یا رنگ">۲- تعویض طرح یا رنگ</option>
            <option value="خرابی کالا (استوک)">۳- خرابی کالا (استوک)</option>
          </select>
        </div>

        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; opacity:.5" id="picker-open-block">
          <button type="button" id="btn-open-picker" style="padding:12px 18px; cursor:pointer; border:1px solid #10b981; border-radius:10px; background:#bbf7d0; color:#065f46; font-weight:700" disabled>➕ اضافه کردن محصولات</button>
          <span class="suf-muted">ابتدا نوع عملیات را انتخاب کنید، سپس محصولات را در پنجره انتخاب کنید.</span>
        </div>

        <table id="items-table" style="margin-top:10px; display:none; width:100%; border-collapse:collapse; border:1px solid #e5e7eb; font-size:14px">
          <thead>
            <tr style="background:#f3f4f6; border-bottom:1px solid #e5e7eb">
              <th style="padding:8px; text-align:right; width:110px">ID</th>
              <th style="padding:8px; text-align:right">محصول</th>
              <th style="padding:8px; text-align:center; width:140px">موجودی فعلی</th>
              <th style="padding:8px; text-align:center; width:280px">تعداد (+/−)</th>
              <th style="padding:8px; text-align:center; width:100px">حذف</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>

        <div id="items-total-wrap" style="display:none; font-weight:700; color:#1f2937">جمع کل تعداد: <span id="items-total-value">0</span></div>

        <div>
          <button type="button" id="btn-save" style="margin-top:10px; display:none; padding:12px 18px; cursor:pointer; border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:10px" disabled>✅ ثبت نهایی</button>
        </div>

        <div id="save-result" style="display:none; margin-top:8px; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; background:#f9fafb"></div>
    </div>

    <div class="wc-suf-modal-overlay" id="wc-suf-modal-overlay" aria-hidden="true"></div>
    <div class="wc-suf-modal" id="wc-suf-modal" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="wc-suf-modal-card">
        <div class="wc-suf-modal-head">
          <div>
            <div class="wc-suf-modal-title">انتخاب محصولات (جستجو + فیلتر ویژگی‌ها)</div>
            <div class="suf-muted" id="wc-suf-modal-subtitle">ابتدا جستجو کنید، سپس در صورت نیاز از فیلتر ویژگی‌ها استفاده کنید. برای هر محصول تعداد را وارد کنید و «اضافه کن» را بزنید.</div>
          </div>
          <button type="button" class="wc-suf-modal-close" id="wc-suf-modal-close" aria-label="بستن">✕</button>
        </div>

        <div class="wc-suf-modal-body">
          <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap; margin-bottom:10px">
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; flex:1; min-width:260px">
              <label for="wc-suf-picker-q" style="min-width:80px; font-weight:700">جستجو:</label>
              <input id="wc-suf-picker-q" type="text" placeholder="مثلاً توران مربع / زرشکی / کد کالا یا ID" style="flex:1; min-width:260px; padding:10px; border:1px solid #e5e7eb; border-radius:12px; font-size:16px">
              <button type="button" id="wc-suf-picker-clear" aria-label="پاک کردن جستجو" title="پاک کردن" style="width:44px; height:44px; display:inline-flex; align-items:center; justify-content:center; padding:0; border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:12px; cursor:pointer; font-size:18px; font-weight:800">✕</button>
            </div>

            <div style="width:100%; margin-top:8px">
              <div id="wc-suf-picker-filters" class="wc-suf-filter-grid"></div>
            </div>
          </div>

          <div class="suf-muted" style="margin-bottom:8px">
            نکته: جستجو نام + ورییشن (ویژگی‌ها) را پوشش می‌دهد. فیلترها همزمان با جستجو اعمال می‌شوند.
          </div>

          <div id="wc-suf-picker-results" style="border:1px solid #e5e7eb; border-radius:12px; overflow:hidden"></div>
        </div>

        <div class="wc-suf-modal-foot">
          <div class="suf-muted" id="wc-suf-picker-selected-info">هیچ موردی انتخاب نشده است.</div>
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
            <button type="button" id="wc-suf-picker-add" style="padding:12px 16px; cursor:pointer; border:1px solid #10b981; border-radius:12px; background:#10b981; color:#fff; font-weight:800">✅ اضافه کن</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    const ajaxurl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";

    jQuery(function($){
        const allProducts = <?php echo wp_json_encode($all); ?>;
        const pickerAttrDefs = <?php echo wp_json_encode($picker_attr_defs); ?>;
        const isMarjoo = <?php echo $is_marjoo ? 'true' : 'false'; ?>;

        const defaultShortcodeKey = "<?php echo esc_js($atts['key']); ?>";
        const urlParams = new URLSearchParams(window.location.search);
        const urlKey = urlParams.get('key') || urlParams.get('code') || '';
        const userCode = urlKey || defaultShortcodeKey;

        const items = [];
        let opType = null;
        let outDestination = null;
        let transferSource = null;
        let transferDestination = null;
        let returnDestination = null;
        let returnReason = '';

        const $overlay = $('#wc-suf-modal-overlay');
        const $modal   = $('#wc-suf-modal');
        const $q       = $('#wc-suf-picker-q');
        const $results = $('#wc-suf-picker-results');
        const $info    = $('#wc-suf-picker-selected-info');
        const $filters = $('#wc-suf-picker-filters');
        const $modalSubtitle = $('#wc-suf-modal-subtitle');

        const pickerQty = Object.create(null);
        const activeFilters = Object.create(null); // tax => selectedValueNormalized

        function escapeHtml(s){
            return String(s)
                .replaceAll('&','&amp;')
                .replaceAll('<','&lt;')
                .replaceAll('>','&gt;')
                .replaceAll('"','&quot;')
                .replaceAll("'","&#039;");
        }

        function norm(s){
            s = String(s || '').trim();
            s = s.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
            s = s.replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
            return s.toLowerCase();
        }

        function findById(id){ return allProducts.find(p => String(p.id) === String(id)); }
        function findLabelById(id){ const f = findById(id); return f ? f.label : ''; }
        function findProductionStockById(id){ const f = findById(id); return f ? (+f.prod_stock || 0) : 0; }
        function warehouseLabel(code){
            if(code === 'main') return 'انبار اصلی';
            if(code === 'teh') return 'انبار تهران پارس';
            if(code === 'production') return 'انبار تولید';
            return '';
        }
        function updateModalSubtitle(){
            let subtitle = 'ابتدا جستجو کنید، سپس در صورت نیاز از فیلتر ویژگی‌ها استفاده کنید. برای هر محصول تعداد را وارد کنید و «اضافه کن» را بزنید.';
            if(opType === 'transfer' && transferSource && transferDestination){
                subtitle = `در حال انتقال کالا از مبدا (${warehouseLabel(transferSource)}) به مقصد (${warehouseLabel(transferDestination)}) هستید.`;
            }
            $modalSubtitle.text(subtitle);
        }
        function updateProductStocksInMemory(stocks){
            if(!stocks || typeof stocks !== 'object') return;
            for(const pid in stocks){
                if(!Object.prototype.hasOwnProperty.call(stocks, pid)) continue;
                const product = findById(pid);
                const row = stocks[pid] || {};
                if(!product) continue;
                if(row.prod_stock != null){
                    product.prod_stock = +row.prod_stock || 0;
                    product.stock = +row.prod_stock || 0;
                }
                if(row.wc_stock != null){
                    product.wc_stock = +row.wc_stock || 0;
                }
                if(row.teh_stock != null){
                    product.teh_stock = +row.teh_stock || 0;
                }
                if(row.teh_stock_ok != null){
                    product.teh_stock_ok = +row.teh_stock_ok ? 1 : 0;
                }
            }
        }
        function refreshStocksBeforeResult(ids){
            const normalizedIds = Array.isArray(ids)
                ? ids.map(v => parseInt(v, 10)).filter(v => Number.isFinite(v) && v > 0)
                : [];
            if(normalizedIds.length === 0){
                return $.Deferred().resolve({}).promise();
            }
            return $.post(ajaxurl, {
                action   : 'wc_suf_refresh_stocks',
                ids      : JSON.stringify(normalizedIds),
                _wpnonce : '<?php echo wp_create_nonce('wc_suf_refresh_stocks'); ?>'
            });
        }
        function getPickerMetaLine(p){
            const pid = String(p.id || '');
            const prod = (+p.prod_stock || 0);
            const destination = (opType === 'return') ? returnDestination : (opType === 'transfer' ? transferDestination : outDestination);
            if(opType === 'in'){
                return `ID: ${pid} | موجودی انبار تولید: ${prod}`;
            }
            if(opType === 'transfer'){
                const sourceStock = getTransferWarehouseStockByProduct(p, transferSource);
                const destinationStock = getTransferWarehouseStockByProduct(p, transferDestination);
                return `ID: ${pid} | موجودی مبدا: ${sourceStock} | موجودی مقصد: ${destinationStock}`;
            }
            if(opType === 'out' || opType === 'return'){
                if(destination === 'main'){
                    return `ID: ${pid} | موجودی انبار تولید: ${prod} | موجودی انبار اصلی: ${(+p.wc_stock || 0)}`;
                }
                if(destination === 'teh'){
                    const teh = (+p.teh_stock || 0);
                    const note = (+p.teh_stock_ok || 0) ? '' : ' (نامشخص)';
                    return `ID: ${pid} | موجودی انبار تولید: ${prod} | موجودی تهران پارس: ${teh}${note}`;
                }
                return `ID: ${pid} | موجودی انبار تولید: ${prod}`;
            }
            return `ID: ${pid} | موجودی انبار تولید: ${prod}`;
        }

        function canSave(){
            if(opType !== 'in' && opType !== 'out' && opType !== 'transfer' && opType !== 'return' && opType !== 'onlyLabel') return false;
            if(isMarjoo && opType !== 'return') return false;
            if(opType === 'out' && !outDestination) return false;
            if(opType === 'transfer' && (!transferSource || !transferDestination || transferSource === transferDestination)) return false;
            if(opType === 'return' && (!returnDestination || !returnReason)) return false;
            if(isMarjoo && returnDestination !== 'teh') return false;
            return items.length > 0;
        }

        function canOpenPicker(){
            if(!opType) return false;
            if(isMarjoo && opType !== 'return') return false;
            if(opType === 'out' && !outDestination) return false;
            if(opType === 'transfer' && (!transferSource || !transferDestination || transferSource === transferDestination)) return false;
            if(opType === 'return' && (!returnDestination || !returnReason)) return false;
            if(isMarjoo && returnDestination !== 'teh') return false;
            return true;
        }

        function getTransferWarehouseStockByProduct(p, warehouse){
            if(!p) return 0;
            if(warehouse === 'main') return (+p.wc_stock || 0);
            if(warehouse === 'teh') return (+p.teh_stock || 0);
            return 0;
        }

        function findTransferSourceStockById(id){
            const p = findById(id);
            return getTransferWarehouseStockByProduct(p, transferSource);
        }

        function syncTransferDestinationOptions(){
            const $destination = $('#transfer-destination');
            const current = String($destination.val() || '');
            let options = '<option value="">انتخاب انبار مقصد...</option>';
            if(transferSource === 'main'){
                options += '<option value="teh">انبار تهران پارس</option>';
            } else if (transferSource === 'teh'){
                options += '<option value="main">انبار اصلی</option>';
            } else {
                options += '<option value="main">انبار اصلی</option><option value="teh">انبار تهران پارس</option>';
            }
            $destination.html(options);
            if(current && current !== transferSource){
                $destination.val(current);
            }
            if($destination.val() === ''){
                transferDestination = null;
            } else {
                transferDestination = String($destination.val());
            }
        }

        function getDestinationInfoById(id){
            const p = findById(id);
            if(!p) return {label:'', stock:0};
            if(outDestination === 'main'){
                return {label:'موجودی انبار اصلی', stock:(+p.wc_stock || 0)};
            }
            if(outDestination === 'teh'){
                return {label:'موجودی انبار تهران‌پارس', stock:(+p.teh_stock || 0)};
            }
            return {label:'', stock:0};
        }

        function refreshPickerOpenButton(){
            const enabled = canOpenPicker();
            const $btn = $('#btn-open-picker');
            $('#picker-open-block').css('opacity', enabled ? 1 : 0.5);
            $btn.prop('disabled', !enabled);
            if(enabled){
                $btn.css({background:'#16a34a', borderColor:'#15803d', color:'#ffffff'});
            } else {
                $btn.css({background:'#bbf7d0', borderColor:'#10b981', color:'#065f46'});
            }
        }

        function renderTable(){
            const tbody = $('#items-table tbody').empty();
            const theadRow = $('#items-table thead tr').empty();

            const isOutMain = (opType === 'out' && outDestination === 'main');
            const isOutTeh  = (opType === 'out' && outDestination === 'teh');
            const isTransfer = (opType === 'transfer');

            theadRow.append('<th style="padding:8px; text-align:right; width:110px">ID</th>');
            theadRow.append('<th style="padding:8px; text-align:right">محصول</th>');
            theadRow.append(`<th style="padding:8px; text-align:center; width:160px">${isTransfer ? 'موجودی انبار مبدا' : 'موجودی انبار تولید'}</th>`);
            if (isOutMain){
                theadRow.append('<th style="padding:8px; text-align:center; width:170px">موجودی انبار اصلی</th>');
            } else if (isOutTeh){
                theadRow.append('<th style="padding:8px; text-align:center; width:180px">موجودی انبار تهران‌پارس</th>');
            } else if (isTransfer){
                const dstLabel = (transferDestination === 'main') ? 'موجودی انبار اصلی' : (transferDestination === 'teh' ? 'موجودی انبار تهران‌پارس' : 'موجودی انبار مقصد');
                theadRow.append(`<th style="padding:8px; text-align:center; width:180px">${escapeHtml(dstLabel)}</th>`);
            }
            theadRow.append('<th style="padding:8px; text-align:center; width:280px">تعداد (+/−)</th>');
            theadRow.append('<th style="padding:8px; text-align:center; width:100px">حذف</th>');

            if(items.length === 0){
                $('#items-table').hide();
                $('#btn-save').prop('disabled', true).hide();
                $('#items-total-wrap').hide();
                $('#items-total-value').text('0');
                return;
            }
            $('#items-table').show();
            $('#btn-save').show().prop('disabled', !canSave());
            const grandTotal = items.reduce((sum, it) => sum + (parseInt(it.qty, 10) || 0), 0);
            $('#items-total-value').text(String(grandTotal));
            $('#items-total-wrap').show();

            items.forEach((it,idx)=>{
                const tr = $('<tr style="border-top:1px solid #e5e7eb">');
                tr.append(`<td style="padding:8px">${escapeHtml(it.id)}</td>`);
                tr.append(`<td style="padding:8px">${escapeHtml(it.name)}</td>`);
                const sourceStock = isTransfer ? findTransferSourceStockById(it.id) : it.stock;
                tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(sourceStock)}</td>`);

                if (isOutMain || isOutTeh){
                    const dst = getDestinationInfoById(it.id);
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(dst.stock)}</td>`);
                } else if (isTransfer){
                    const p = findById(it.id);
                    const dstStock = getTransferWarehouseStockByProduct(p, transferDestination);
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(dstStock)}</td>`);
                }

                const qtyControls = $(`
                  <td style="padding:6px; text-align:center">
                    <button class="row-dec" data-i="${idx}" style="font-size:18px; padding:4px 10px; margin-inline:4px">➖</button>
                    <input type="number" class="row-qty" data-i="${idx}" value="${escapeHtml(it.qty)}" min="1" style="width:80px; text-align:center; font-size:16px; padding:4px">
                    <button class="row-inc" data-i="${idx}" style="font-size:18px; padding:4px 10px; margin-inline:4px">➕</button>
                  </td>
                `);
                tr.append(qtyControls);
                tr.append(`<td style="padding:8px; text-align:center"><button data-i="${idx}" class="btn-del" style="cursor:pointer">❌</button></td>`);
                tbody.append(tr);
            });
        }

        function buildSubmitConfirmMessage(){
            const typeCount = items.length;
            const totalQty = items.reduce((sum, it) => sum + (parseInt(it.qty, 10) || 0), 0);

            if(opType === 'in'){
                return `در حال ورود ${typeCount} نوع محصول با مجموع ${totalQty} آیتم به انبار تولید هستید. آیا مطمئنید؟`;
            }

            if(opType === 'out'){
                let destinationLabel = 'انبار مقصد';
                if(outDestination === 'main') destinationLabel = 'انبار اصلی';
                if(outDestination === 'teh') destinationLabel = 'انبار تهران پارس';
                return `در حال خروج ${typeCount} نوع محصول و مجموعاً ${totalQty} آیتم کالا از انبار تولید به ${destinationLabel} هستید. آیا مطمئن هستید؟`;
            }
            if(opType === 'transfer'){
                const sourceLabel = transferSource === 'main' ? 'انبار اصلی' : 'انبار تهران پارس';
                const destinationLabel = transferDestination === 'main' ? 'انبار اصلی' : 'انبار تهران پارس';
                return `در حال انتقال ${typeCount} نوع محصول و مجموعاً ${totalQty} آیتم کالا از ${sourceLabel} به ${destinationLabel} هستید. آیا مطمئن هستید؟`;
            }

            if(opType === 'return'){
                let destinationLabel = 'انبار مقصد';
                if(returnDestination === 'main') destinationLabel = 'انبار اصلی';
                if(returnDestination === 'teh') destinationLabel = 'انبار تهران پارس';
                return `در حال ثبت مرجوعی ${typeCount} نوع محصول و مجموعاً ${totalQty} آیتم به ${destinationLabel} با علت «${returnReason}» هستید. آیا مطمئن هستید؟`;
            }

            return '';
        }

        function enforceOutLimit(idx){
            if(opType !== 'out') return;
            const it = items[idx];
            if(!it) return;
            if(it.qty > it.stock){ it.qty = it.stock; }
        }

        function enforceTransferLimit(idx){
            if(opType !== 'transfer') return;
            const it = items[idx];
            if(!it) return;
            const sourceStock = findTransferSourceStockById(it.id);
            if(it.qty > sourceStock){ it.qty = sourceStock; }
        }

        function openModal(){
            if(!opType) return;
            updateModalSubtitle();

            $overlay.show().attr('aria-hidden','false');
            $modal.css('display','flex').attr('aria-hidden','false');
            $('body').css('overflow','hidden');

            buildAttributeFilters();
            $q.trigger('focus');
            renderPickerResults();
        }

        function closeModal(){
            $overlay.hide().attr('aria-hidden','true');
            $modal.hide().attr('aria-hidden','true');
            $('body').css('overflow','');
            $q.val('');
            $results.empty();
            updateSelectedInfo();
            updateModalSubtitle();
        }

        function updateSelectedInfo(){
            let cnt = 0;
            let sum = 0;
            for (const k in pickerQty){
                const v = +pickerQty[k];
                if (v > 0){ cnt++; sum += v; }
            }
            if (cnt <= 0){
                $info.text('هیچ موردی انتخاب نشده است.');
            } else {
                $info.text(`تعداد محصولات انتخاب‌شده: ${cnt} | جمع تعداد: ${sum}`);
            }
        }

        function buildAttributeFilters(){
            $filters.empty();
            for (const k in activeFilters){
                if (Object.prototype.hasOwnProperty.call(activeFilters, k)) delete activeFilters[k];
            }

            if (!Array.isArray(pickerAttrDefs) || pickerAttrDefs.length === 0){
                return;
            }

            const optionsByTax = Object.create(null);

            for (let i=0; i<allProducts.length; i++){
                const p = allProducts[i];
                const attrs = p && p.attrs ? p.attrs : null;
                if (!attrs || typeof attrs !== 'object') continue;

                for (let d=0; d<pickerAttrDefs.length; d++){
                    const def = pickerAttrDefs[d];
                    if (!def || !def.tax) continue;
                    const tax = String(def.tax);

                    const vals = attrs[tax];
                    if (!Array.isArray(vals) || vals.length === 0) continue;

                    if (!optionsByTax[tax]) optionsByTax[tax] = Object.create(null);
                    for (let v=0; v<vals.length; v++){
                        const rawVal = String(vals[v] || '').trim();
                        if (!rawVal) continue;
                        const key = norm(rawVal);
                        if (!key) continue;
                        optionsByTax[tax][key] = rawVal;
                    }
                }
            }

            for (let d=0; d<pickerAttrDefs.length; d++){
                const def = pickerAttrDefs[d];
                if (!def || !def.tax) continue;

                const tax = String(def.tax);
                const label = String(def.label || tax);

                const bag = optionsByTax[tax];
                if (!bag || typeof bag !== 'object') continue;

                const keys = Object.keys(bag);
                if (keys.length === 0) continue;
                keys.sort((a,b) => a.localeCompare(b, 'fa'));

                const selectId = 'wc-suf-filter-' + tax.replace(/[^a-z0-9_]/gi,'_');

                const opts = [];
                opts.push(`<option value="">همه</option>`);
                for (let i=0; i<keys.length; i++){
                    const k = keys[i];
                    const display = bag[k];
                    opts.push(`<option value="${escapeHtml(k)}">${escapeHtml(display)}</option>`);
                }

                const html = `
                  <div class="wc-suf-filter">
                    <label for="${escapeHtml(selectId)}">${escapeHtml(label)}:</label>
                    <select id="${escapeHtml(selectId)}" data-tax="${escapeHtml(tax)}">
                      ${opts.join('')}
                    </select>
                  </div>
                `;
                $filters.append(html);
            }
        }

        function productMatchesFilters(p){
            for (const tax in activeFilters){
                if (!Object.prototype.hasOwnProperty.call(activeFilters, tax)) continue;
                const sel = String(activeFilters[tax] || '');
                if (!sel) continue;

                const attrs = p && p.attrs ? p.attrs : null;
                if (!attrs || typeof attrs !== 'object') return false;

                const vals = attrs[tax];
                if (!Array.isArray(vals) || vals.length === 0) return false;

                let ok = false;
                for (let i=0; i<vals.length; i++){
                    if (norm(vals[i]) === sel){
                        ok = true;
                        break;
                    }
                }
                if (!ok) return false;
            }
            return true;
        }

        function renderPickerResults(){
            const q = norm($q.val());
            const tokens = q.split(/\s+/).map(t => t.trim()).filter(Boolean);
            const matched = [];

            if (tokens.length > 0){
                for (let i=0; i<allProducts.length; i++){
                    const p = allProducts[i];
                    const hay = String(p.search || p.label || '').toLowerCase();
                    const ok = tokens.every(t => hay.includes(t));
                    if (!ok) continue;
                    if (!productMatchesFilters(p)) continue;
                    matched.push(p);
                }
            } else {
                // بدون جستجو: اگر فیلتر انتخاب شده باشد، اجازه بده نتایج بر اساس فیلتر دیده شوند
                const hasAnyFilter = Object.keys(activeFilters).some(k => (activeFilters[k] || '') !== '');
                if (hasAnyFilter){
                    for (let i=0; i<allProducts.length; i++){
                        const p = allProducts[i];
                        if (!productMatchesFilters(p)) continue;
                        matched.push(p);
                    }
                }
            }

            const showList = matched;
            if (showList.length === 0){
                const hasAnyFilter = Object.keys(activeFilters).some(k => (activeFilters[k] || '') !== '');
                const msg = (q.length || hasAnyFilter) ? 'موردی یافت نشد.' : 'برای نمایش نتایج، عبارت جستجو را وارد کنید یا یک فیلتر انتخاب کنید.';
                $results.html(`<div style="padding:14px" class="suf-muted">${escapeHtml(msg)}</div>`);
                updateSelectedInfo();
                return;
            }

            const frag = [];
            for (let i=0; i<showList.length; i++){
                const p = showList[i];
                const pid = String(p.id);
                const stock = (+p.prod_stock || 0);
                const cur = (pickerQty[pid] != null) ? +pickerQty[pid] : 0;
                const metaLine = getPickerMetaLine(p);

                frag.push(`
                    <div class="wc-suf-picker-row" data-pid="${escapeHtml(pid)}">
                      <div>
                        <div class="wc-suf-picker-name">${escapeHtml(p.label || ('#'+pid))}</div>
                        <div class="wc-suf-picker-meta">${escapeHtml(metaLine)}</div>
                      </div>
                      <div class="wc-suf-picker-qty">
                        <button type="button" class="picker-dec" data-pid="${escapeHtml(pid)}">➖</button>
                        <input type="number" min="0" class="picker-qty" data-pid="${escapeHtml(pid)}" value="${escapeHtml(cur)}" />
                        <button type="button" class="picker-inc" data-pid="${escapeHtml(pid)}">➕</button>
                      </div>
                    </div>
                `);
            }

            $results.html(frag.join(''));
            updateSelectedInfo();
        }

        function capQtyForOut(pid, qty, showAlert){
            if(opType !== 'out') return qty;
            const stock = findProductionStockById(pid);
            if (qty > stock){
                if (showAlert){
                    const name = findLabelById(pid) || ('#'+pid);
                    alert(`برای "${name}" حداکثر قابل انتخاب ${stock} عدد است (موجودی انبار تولید).`);
                }
                return stock;
            }
            return qty;
        }

        function capQtyForTransfer(pid, qty, showAlert){
            if(opType !== 'transfer') return qty;
            const stock = findTransferSourceStockById(pid);
            if (qty > stock){
                if (showAlert){
                    const name = findLabelById(pid) || ('#'+pid);
                    alert(`برای "${name}" حداکثر قابل انتخاب ${stock} عدد است (موجودی انبار مبدا).`);
                }
                return stock;
            }
            return qty;
        }

        refreshPickerOpenButton();
        updateModalSubtitle();

        $('#btn-open-picker').on('click', function(){
            if(!canOpenPicker()) return;
            openModal();
        });

        $('#wc-suf-modal-close').on('click', closeModal);
        $overlay.on('click', closeModal);

        $(document).on('keydown', function(e){
            if ($modal.is(':visible') && e.key === 'Escape'){
                e.preventDefault();
                closeModal();
            }
        });

        $q.on('input', function(){
            renderPickerResults();
        });

        $filters.on('change', 'select[data-tax]', function(){
            const tax = String($(this).data('tax') || '');
            const val = String($(this).val() || '');
            if (!tax) return;
            activeFilters[tax] = val; // normalized already
            renderPickerResults();
        });

        $('#wc-suf-picker-clear').on('click', function(){
            $q.val('');
            $filters.find('select[data-tax]').val('');
            for (const k in activeFilters){
                if (Object.prototype.hasOwnProperty.call(activeFilters, k)) activeFilters[k] = '';
            }
            $results.html('<div style="padding:14px" class="suf-muted">برای نمایش نتایج، عبارت جستجو را وارد کنید یا یک فیلتر انتخاب کنید.</div>');
            updateSelectedInfo();
            $q.trigger('focus');
        });

        $results.on('click', '.picker-inc', function(){
            const pid = String($(this).data('pid'));
            let current = (+pickerQty[pid] || 0) + 1;
            current = capQtyForOut(pid, current, true);
            current = capQtyForTransfer(pid, current, true);
            pickerQty[pid] = current;
            $results.find(`.picker-qty[data-pid="${pid}"]`).val(current);
            updateSelectedInfo();
        });

        $results.on('click', '.picker-dec', function(){
            const pid = String($(this).data('pid'));
            const current = Math.max(0, (+pickerQty[pid] || 0) - 1);
            pickerQty[pid] = current;
            $results.find(`.picker-qty[data-pid="${pid}"]`).val(current);
            updateSelectedInfo();
        });

        $results.on('change', '.picker-qty', function(){
            const pid = String($(this).data('pid'));
            let v = +$(this).val();
            if (!Number.isFinite(v)) v = 0;
            v = Math.max(0, Math.floor(v));
            v = capQtyForOut(pid, v, true);
            v = capQtyForTransfer(pid, v, true);
            pickerQty[pid] = v;
            $(this).val(v);
            updateSelectedInfo();
        });

        $('#wc-suf-picker-add').on('click', function(){
            if(!opType) return;

            let addedAny = false;

            for (const pid in pickerQty){
                let qty = +pickerQty[pid];
                if (!qty || qty <= 0) continue;

                const name  = findLabelById(pid) || '(بدون نام)';
                const stock = findProductionStockById(pid);

                if (opType === 'out' && qty > stock){
                    alert(`مقدار انتخابی برای «${name}» بیشتر از موجودی انبار تولید است.`);
                    return;
                }
                if (opType === 'transfer'){
                    const sourceStock = findTransferSourceStockById(pid);
                    if (qty > sourceStock){
                        alert(`مقدار انتخابی برای «${name}» بیشتر از موجودی انبار مبدا است.`);
                        return;
                    }
                }

                const existingIdx = items.findIndex(x => String(x.id) === String(pid));
                if (existingIdx >= 0){
                    items[existingIdx].qty = (items[existingIdx].qty || 0) + qty;
                    items[existingIdx].stock = stock;
                    enforceOutLimit(existingIdx);
                    enforceTransferLimit(existingIdx);
                } else {
                    items.push({id: pid, name, qty, stock});
                    enforceOutLimit(items.length - 1);
                    enforceTransferLimit(items.length - 1);
                }

                addedAny = true;
            }

            if (!addedAny){
                alert('هیچ محصولی با تعداد بالاتر از صفر انتخاب نشده است.');
                return;
            }

            for (const pid in pickerQty){
                if (Object.prototype.hasOwnProperty.call(pickerQty, pid)) pickerQty[pid] = 0;
            }

            renderTable();
            closeModal();
        });

        $('input[name="op-type"]').on('change', function(){
            if(opType) return;
            opType = $(this).val();

            if(isMarjoo && opType !== 'return'){
                opType = null;
                $(this).prop('checked', false);
                alert('کاربر مرجوع فقط مجاز به عملیات مرجوعی است.');
                return;
            }

            $('input[name="op-type"]').prop('disabled', true);

            if(opType === 'out'){
                $('#out-destination-wrap').css('display','flex');
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').hide();
            } else if (opType === 'transfer') {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').css('display','flex');
                $('#return-controls-wrap').hide();
                syncTransferDestinationOptions();
            } else if (opType === 'return') {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').css('display','flex');
            } else {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').hide();
            }

            refreshPickerOpenButton();
            updateModalSubtitle();
            renderTable();
        });

        $('input[name="out-destination"]').on('change', function(){
            if(opType !== 'out') return;
            outDestination = $(this).val() || null;
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-source').on('change', function(){
            if(opType !== 'transfer') return;
            transferSource = $(this).val() || null;
            syncTransferDestinationOptions();
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-destination').on('change', function(){
            if(opType !== 'transfer') return;
            transferDestination = $(this).val() || null;
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-source').on('change', function(){
            if(opType !== 'transfer') return;
            transferSource = $(this).val() || null;
            syncTransferDestinationOptions();
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-destination').on('change', function(){
            if(opType !== 'transfer') return;
            transferDestination = $(this).val() || null;
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#return-destination').on('change', function(){
            if(opType !== 'return') return;
            returnDestination = $(this).val() || null;
            if(isMarjoo && returnDestination !== 'teh'){
                returnDestination = 'teh';
                $(this).val('teh');
            }
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            updateModalSubtitle();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        if(isMarjoo){
            $('label:has(input[name="op-type"][value="in"])').hide();
            $('label:has(input[name="op-type"][value="out"])').hide();
            $('label:has(input[name="op-type"][value="transfer"])').hide();
            $('label:has(input[name="op-type"][value="onlyLabel"])').hide();
            $('input[name="op-type"][value="return"]').prop('checked', true).trigger('change');
            $('#return-destination').val('teh').trigger('change');
        }

        $('#return-reason').on('change', function(){
            if(opType !== 'return') return;
            returnReason = $(this).val() || '';
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
        });

        $('#items-table').on('click','.row-inc', function(){
            const i = +$(this).data('i'); items[i].qty++; enforceOutLimit(i); enforceTransferLimit(i); renderTable();
        });
        $('#items-table').on('click','.row-dec', function(){
            const i = +$(this).data('i'); items[i].qty = Math.max(1, items[i].qty-1); enforceOutLimit(i); enforceTransferLimit(i); renderTable();
        });
        $('#items-table').on('change','.row-qty', function(){
            const i = +$(this).data('i'); let v = +$(this).val();
            v = Math.max(1, v||1); items[i].qty = v; enforceOutLimit(i); enforceTransferLimit(i); renderTable();
        });

        $('#items-table').on('click','.btn-del',function(){
            items.splice($(this).data('i'),1);
            renderTable();
        });

        let submitting = false;
        $('#btn-save').on('click', function(){
            if (submitting) return;
            if (!canSave()) return;

            const submittedProductIds = items.map(function(it){ return parseInt(it.id, 10); }).filter(function(v){ return Number.isFinite(v) && v > 0; });

            if (opType === 'in' || opType === 'out' || opType === 'transfer' || opType === 'return'){
                const ok = window.confirm(buildSubmitConfirmMessage());
                if (!ok){
                    return;
                }
            }

            submitting = true;
            $('#save-result').hide().empty();

            const $btn = $(this);
            const originalText = $btn.text();
            $btn.prop('disabled', true).css({opacity: 0.6, cursor: 'not-allowed'}).text('در حال ثبت...');

            $.post(ajaxurl, {
                action      : 'save_stock_update',
                items       : JSON.stringify(items),
                user_code   : userCode,
                out_destination : String(outDestination || ''),
                transfer_source : String(transferSource || ''),
                transfer_destination : String(transferDestination || ''),
                return_destination : String(returnDestination || ''),
                return_reason : String(returnReason || ''),
                op_type     : opType,
                _wpnonce    : '<?php echo wp_create_nonce('save_stock_update'); ?>'
            }).done(function(res){
                try{
                    if(res && res.success){
                        const msg = (res.data && res.data.message) ? res.data.message : 'ثبت شد.';
                        const csvUrl = (res.data && res.data.csv_url) ? String(res.data.csv_url) : '';
                        const wordUrl = (res.data && res.data.word_url) ? String(res.data.word_url) : '';
                        const refreshIdsRaw = (res.data && Array.isArray(res.data.product_ids)) ? res.data.product_ids : submittedProductIds;

                        const finishSaveUi = function(refreshFailed){
                            items.length = 0;
                            opType = null;
                            outDestination = null;
                            transferSource = null;
                            transferDestination = null;
                            returnDestination = null;
                            returnReason = '';
                            for (const pid in pickerQty){
                                if (Object.prototype.hasOwnProperty.call(pickerQty, pid)) pickerQty[pid] = 0;
                            }
                            $('input[name="op-type"]').prop('checked', false).prop('disabled', false);
                            $('input[name="out-destination"]').prop('checked', false);
                            $('#transfer-source').val('');
                            $('#transfer-destination').html('<option value="">انتخاب انبار مقصد...</option>');
                            $('#return-destination').val('');
                            $('#return-reason').val('');
                            $('#out-destination-wrap').hide();
                            $('#transfer-controls-wrap').hide();
                            $('#return-controls-wrap').hide();
                            closeModal();
                            refreshPickerOpenButton();
                            renderTable();

                            let html = '<div style="font-weight:700; color:#065f46">'+escapeHtml(msg)+'</div>';
                            if(refreshFailed){
                                html += '<div style="margin-top:8px; color:#b45309; font-weight:700">به‌روزرسانی لحظه‌ای موجودی انجام نشد؛ صفحه را یک‌بار رفرش کنید.</div>';
                            }
                            if(csvUrl){
                                html += '<div style="margin-top:8px"><a href="'+csvUrl+'" target="_blank" rel="noopener" style="color:#1d4ed8; font-weight:700">چاپ لیبل (HTML)</a></div>';
                            }
                            if(wordUrl){
                                html += '<div style="margin-top:8px"><a href="'+wordUrl+'" target="_blank" rel="noopener" style="color:#1d4ed8; font-weight:700">دانلود رسید Word عملیات</a></div>';
                            }
                            $('#save-result').html(html).show();
                            alert(msg);
                        };

                        refreshStocksBeforeResult(refreshIdsRaw).done(function(refreshRes){
                            if(refreshRes && refreshRes.success && refreshRes.data && refreshRes.data.stocks){
                                updateProductStocksInMemory(refreshRes.data.stocks);
                            }
                            finishSaveUi(false);
                        }).fail(function(){
                            finishSaveUi(true);
                        }).always(function(){
                            submitting = false;
                            $btn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
                        });
                    }else{
                        alert((res && res.data && res.data.message) ? res.data.message : 'ثبت ناموفق.');
                        submitting = false; $btn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
                    }
                }catch(e){
                    alert('پاسخ نامعتبر از سرور.');
                    submitting = false; $btn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
                }
            }).fail(function(){
                alert('خطای ارتباطی هنگام ثبت.');
                submitting = false; $btn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

/*--------------------------------------
| AJAX: ثبت نهایی (YITH POS)
---------------------------------------*/
add_action('wp_ajax_save_stock_update','wc_suf_save_stock_update_handler');
function wc_suf_save_stock_update_handler(){
    check_ajax_referer('save_stock_update');

    if( ! wc_suf_current_user_is_pos_manager() ){
        wp_send_json_error(['message'=>'دسترسی غیرمجاز.']);
    }

    $raw   = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items = json_decode($raw, true);
    if ( ! is_array($items) || empty($items) ) {
        wp_send_json_error(['message'=>'داده‌ای ارسال نشده است.']);
    }

    $user_code   = isset($_POST['user_code']) ? sanitize_text_field( wp_unslash($_POST['user_code']) ) : '';
    $op_type_in  = isset($_POST['op_type']) ? sanitize_text_field( wp_unslash($_POST['op_type']) ) : '';
    $op_type     = in_array($op_type_in, ['in','out','transfer','return','onlyLabel'], true) ? $op_type_in : '';
    $is_marjoo_user = wc_suf_current_user_has_role( 'marjoo' );

    if( ! $op_type ){
        wp_send_json_error(['message'=>'نوع عملیات مشخص نیست (ورود/خروج/انتقال/مرجوعی/صرفاً چاپ لیبل).']);
    }
    if ( $is_marjoo_user && $op_type !== 'return' ) {
        wp_send_json_error(['message'=>'کاربر مرجوع فقط مجاز به ثبت عملیات مرجوعی است.']);
    }

    $out_destination = isset($_POST['out_destination']) ? sanitize_text_field( wp_unslash($_POST['out_destination']) ) : '';
    $transfer_source = isset($_POST['transfer_source']) ? sanitize_text_field( wp_unslash($_POST['transfer_source']) ) : '';
    $transfer_destination = isset($_POST['transfer_destination']) ? sanitize_text_field( wp_unslash($_POST['transfer_destination']) ) : '';
    $return_destination = isset($_POST['return_destination']) ? sanitize_text_field( wp_unslash($_POST['return_destination']) ) : '';
    $return_reason = isset($_POST['return_reason']) ? sanitize_text_field( wp_unslash($_POST['return_reason']) ) : '';
    $transfer_store_id = null;
    if ( $op_type === 'out' ) {
        if ( ! in_array( $out_destination, ['main','teh'], true ) ) {
            wp_send_json_error(['message'=>'مقصد خروج مشخص نیست.']);
        }
        if ( $out_destination === 'teh' ) {
            $transfer_store_id = (int) WC_SUF_TEHRANPARS_STORE_ID;
        }
    }
    if ( $op_type === 'transfer' ) {
        if ( ! in_array( $transfer_source, ['main','teh'], true ) ) {
            wp_send_json_error(['message'=>'انبار مبدا انتقال مشخص نیست.']);
        }
        if ( ! in_array( $transfer_destination, ['main','teh'], true ) ) {
            wp_send_json_error(['message'=>'انبار مقصد انتقال مشخص نیست.']);
        }
        if ( $transfer_source === $transfer_destination ) {
            wp_send_json_error(['message'=>'انبار مبدا و مقصد انتقال نمی‌توانند یکسان باشند.']);
        }
    }
    if ( $op_type === 'return' ) {
        if ( ! in_array( $return_destination, ['main','teh'], true ) ) {
            wp_send_json_error(['message'=>'انبار مرجوعی مشخص نیست.']);
        }
        if ( $is_marjoo_user && $return_destination !== 'teh' ) {
            wp_send_json_error(['message'=>'کاربر مرجوع فقط مجاز به مرجوعی به انبار تهران پارس است.']);
        }
        $valid_return_reasons = [
            'انصراف از خرید مشتری',
            'تعویض طرح یا رنگ',
            'خرابی کالا (استوک)',
        ];
        if ( ! in_array( $return_reason, $valid_return_reasons, true ) ) {
            wp_send_json_error(['message'=>'علت مرجوعی معتبر نیست.']);
        }
        if ( $return_destination === 'teh' ) {
            $transfer_store_id = (int) WC_SUF_TEHRANPARS_STORE_ID;
        }
    }

    $user      = wp_get_current_user();
    $uid       = (int) ($user->ID ?? 0);
    $ulog      = '';
    if ( $uid ) {
        $ulog = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
        if ( $ulog === '' ) {
            $ulog = (string) ( $user->display_name ?: $user->user_login );
        }
    }
    $ip        = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash($_SERVER['REMOTE_ADDR']) ) : '';

    global $wpdb;
    $table   = $wpdb->prefix.'stock_audit';
    $move_table = $wpdb->prefix.'stock_production_moves';

    $tx_started = false;
    if ( in_array( $op_type, ['in','out','transfer','return','onlyLabel'], true ) ) {
        $tx_started = ( false !== $wpdb->query('START TRANSACTION') );
        if ( ! $tx_started ) {
            wp_send_json_error(['message'=>'شروع تراکنش دیتابیس ناموفق بود. عملیات برای جلوگیری از ثبت ناقص متوقف شد.']);
        }
    }

    if ($op_type === 'out' || $op_type === 'transfer') {
        $insufficient = [];
        $locked_old_qty = [];
        foreach($items as $it){
            $pid = isset($it['id'])  ? absint($it['id']) : 0;
            $req = isset($it['qty']) ? (int) $it['qty']  : 0;
            if( ! $pid || $req <= 0 ) continue;

            $product = wc_get_product($pid);
            if( ! $product ) continue;
            if ( $op_type === 'out' ) {
                $old = $tx_started ? wc_suf_get_production_stock_qty_for_update( $product ) : wc_suf_get_production_stock_qty( $pid );
            } else {
                if ( $transfer_source === 'main' ) {
                    $old = (int) ( wc_suf_get_stock_product( $product )->get_stock_quantity() ?? 0 );
                } else {
                    $teh_old = wc_suf_yith_get_store_stock_qty( $product, (int) WC_SUF_TEHRANPARS_STORE_ID );
                    $old = ( false === $teh_old ) ? 0 : (int) $teh_old;
                }
            }
            $pname = wc_suf_full_product_label( $product );
            $locked_old_qty[$pid] = $old;

            if( $req > $old ){
                $insufficient[] = [
                    'id'   => $pid,
                    'name' => $pname,
                    'req'  => $req,
                    'have' => $old,
                ];
            }
        }

        if ( ! empty($insufficient) ) {
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            $lines = array_map(function($r){
                return sprintf('محصول %s (ID: %d): درخواست %d، موجودی فعلی %d', $r['name'], $r['id'], $r['req'], $r['have']);
            }, $insufficient);

            $operation_label = ( $op_type === 'transfer' ) ? 'انتقال' : 'خروج';
            $msg = "ثبت ناموفق؛ به‌دلیل کمبود موجودی موارد زیر امکان {$operation_label} ندارند:\n- " . implode("\n- ", $lines) . "\n\nلطفاً مقادیر را اصلاح کنید و دوباره تلاش کنید.";
            wp_send_json_error(['message' => $msg]);
        }
    }

    if ( count($items) > 1000 ) {
        if ( $tx_started ) {
            $wpdb->query('ROLLBACK');
        }
        wp_send_json_error(['message'=>'حداکثر 1000 محصول در هر ثبت قابل پردازش است. لطفاً ثبت را در چند مرحله انجام دهید.']);
    }

    $batch_code = wc_suf_next_batch_code( $op_type === 'return' ? 'return' : ( $op_type === 'out' ? 'out' : ( $op_type === 'transfer' ? 'transfer' : $op_type ) ) );

    $inserted = 0;
    $csv_rows = [];

    foreach($items as $it){
        $pid = isset($it['id'])  ? absint($it['id']) : 0;
        $req = isset($it['qty']) ? (int) $it['qty']  : 0;
        if( ! $pid || $req <= 0 ) continue;

        $product = wc_get_product($pid);
        if( ! $product ) continue;
        $stock_product = wc_suf_get_stock_product( $product );

        if( ! $stock_product->managing_stock() ){
            $stock_product->set_manage_stock(true);
            if( $stock_product->get_stock_quantity() === null ){
                $stock_product->set_stock_quantity(0);
            }
            $stock_product->save();
        }

        $old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
        $pname   = $stock_product->get_name();
        $destination_old_qty = null;
        $destination_new_qty = null;

        if( $op_type === 'out' ){
            $prod_old = isset($locked_old_qty[$pid]) ? (int) $locked_old_qty[$pid] : ( $tx_started ? wc_suf_get_production_stock_qty_for_update( $product ) : wc_suf_get_production_stock_qty( $pid ) );
            $prod_new = max( 0, $prod_old - $req );
            $prod_update_result = wc_suf_set_production_stock_qty( $product, $prod_new );
            if ( is_wp_error( $prod_update_result ) ) {
                if ( $tx_started ) {
                    $wpdb->query('ROLLBACK');
                }
                wp_send_json_error(['message'=>$prod_update_result->get_error_message()]);
            }
            $old_qty      = $prod_old;
            $new_qty      = $prod_new;
            $logged_added = $req;

            if ( $out_destination === 'main' ) {
                $destination_old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
                $main_stock_result = wc_update_product_stock($stock_product, $req, 'increase');
                if ( false === $main_stock_result ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار اصلی ووکامرس ناموفق بود.']);
                }
                $stock_product->save();
                $destination_new_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
            } elseif ( $out_destination === 'teh' ) {
                $destination_old_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_old_qty ) {
                    $destination_old_qty = 0;
                }
                $store_result  = wc_suf_yith_change_store_stock( $stock_product, $req, $transfer_store_id, 'increase' );
                if ( is_wp_error( $store_result ) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی استور YITH ناموفق: '.$store_result->get_error_message()]);
                }
                $destination_new_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_new_qty ) {
                    $destination_new_qty = (int) $destination_old_qty + $req;
                }
            }

        } elseif( $op_type === 'transfer' ){
            $transfer_store_id = (int) WC_SUF_TEHRANPARS_STORE_ID;
            if ( $transfer_source === 'main' ) {
                $source_old = isset($locked_old_qty[$pid]) ? (int) $locked_old_qty[$pid] : (int) ( $stock_product->get_stock_quantity() ?? 0 );
                $main_stock_result = wc_update_product_stock($stock_product, $req, 'decrease');
                if ( false === $main_stock_result ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'کاهش موجودی انبار اصلی ووکامرس برای انتقال ناموفق بود.']);
                }
                $stock_product->save();
                $source_new = (int) ( $stock_product->get_stock_quantity() ?? 0 );

                $destination_old_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_old_qty ) {
                    $destination_old_qty = 0;
                }
                $store_result = wc_suf_yith_change_store_stock( $stock_product, $req, $transfer_store_id, 'increase' );
                if ( is_wp_error( $store_result ) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار تهران‌پارس برای انتقال ناموفق: '.$store_result->get_error_message()]);
                }
                $destination_new_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_new_qty ) {
                    $destination_new_qty = (int) $destination_old_qty + $req;
                }
            } else {
                $source_old_raw = isset($locked_old_qty[$pid]) ? (int) $locked_old_qty[$pid] : wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                $source_old = ( false === $source_old_raw ) ? 0 : (int) $source_old_raw;
                $store_result = wc_suf_yith_change_store_stock( $stock_product, $req, $transfer_store_id, 'decrease' );
                if ( is_wp_error( $store_result ) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'کاهش موجودی انبار تهران‌پارس برای انتقال ناموفق: '.$store_result->get_error_message()]);
                }
                $source_new_raw = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                $source_new = ( false === $source_new_raw ) ? max( 0, $source_old - $req ) : (int) $source_new_raw;

                $destination_old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
                $main_stock_result = wc_update_product_stock($stock_product, $req, 'increase');
                if ( false === $main_stock_result ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار اصلی ووکامرس برای انتقال ناموفق بود.']);
                }
                $stock_product->save();
                $destination_new_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
            }

            $old_qty = (float) $source_old;
            $new_qty = (float) $source_new;
            $logged_added = $req;

        } elseif( $op_type === 'in' ){
            $prod_old = $tx_started ? wc_suf_get_production_stock_qty_for_update( $product ) : wc_suf_get_production_stock_qty( $pid );
            $prod_new = max( 0, $prod_old + $req );
            $prod_update_result = wc_suf_set_production_stock_qty( $product, $prod_new );
            if ( is_wp_error( $prod_update_result ) ) {
                if ( $tx_started ) {
                    $wpdb->query('ROLLBACK');
                }
                wp_send_json_error(['message'=>$prod_update_result->get_error_message()]);
            }
            $old_qty      = $prod_old;
            $new_qty      = $prod_new;
            $logged_added = $req;

        } elseif( $op_type === 'return' ){
            $logged_added = $req;
            if ( $return_destination === 'main' ) {
                $destination_old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
                $main_stock_result = wc_update_product_stock($stock_product, $req, 'increase');
                if ( false === $main_stock_result ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار اصلی ووکامرس برای مرجوعی ناموفق بود.']);
                }
                $stock_product->save();
                $destination_new_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
            } elseif ( $return_destination === 'teh' ) {
                $destination_old_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_old_qty ) {
                    $destination_old_qty = 0;
                }
                $store_result  = wc_suf_yith_change_store_stock( $stock_product, $req, $transfer_store_id, 'increase' );
                if ( is_wp_error( $store_result ) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار تهران‌پارس برای مرجوعی ناموفق: '.$store_result->get_error_message()]);
                }
                $destination_new_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_new_qty ) {
                    $destination_new_qty = (int) $destination_old_qty + $req;
                }
            }
            $old_qty = (float) $destination_old_qty;
            $new_qty = (float) $destination_new_qty;

        } else {
            $new_qty      = $old_qty;
            $logged_added = $req;
        }

        $data = [
            'batch_code'   => $batch_code,
            'op_type'      => wc_suf_audit_op_type_for_storage( $op_type, $out_destination, $return_destination, $transfer_source, $transfer_destination ),
            'purpose'      => ($op_type === 'out')
                            ? ( $out_destination === 'teh' ? 'انتقال به انبار تهرانپارس' : 'خروج به انبار اصلی' )
                            : ( ( $op_type === 'transfer' )
                                ? ( 'انتقال بین انبارها: ' . wc_suf_destination_label( $transfer_source ) . ' → ' . wc_suf_destination_label( $transfer_destination ) )
                                : ( ( $op_type === 'return' ) ? ('مرجوعی - علت: '.$return_reason) : null ) ),
            'print_label'  => ($op_type === 'onlyLabel') ? 1 : 0,
            'product_id'   => $pid,
            'product_name' => $pname,
            'old_qty'      => $old_qty,
            'added_qty'    => $logged_added,
            'new_qty'      => $new_qty,
            'user_id'      => $uid ?: null,
            'user_login'   => $ulog ?: null,
            'user_code'    => $user_code ?: null,
            'ip'           => $ip ?: null,
            'created_at'   => current_time('mysql'),
        ];
        $formats = ['%s','%s','%s','%d','%d','%s','%f','%f','%f','%d','%s','%s','%s','%s'];

        $ok = $wpdb->insert( $table, $data, $formats );
        if( false === $ok ){
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            error_log('[WC Stock Update] DB Insert FAILED: '.$wpdb->last_error.' | Data: '.wp_json_encode($data));
            wp_send_json_error(['message'=>'ثبت در پایگاه‌داده ناموفق بود.']);
        } else {
            $inserted++;
        }

        if ( in_array( $op_type, ['in','out','transfer','return','onlyLabel'], true ) ) {
            $move_data = [
                'batch_code'      => $batch_code,
                'operation'       => $op_type,
                'destination'     => ( $op_type === 'out' ) ? $out_destination : ( $op_type === 'transfer' ? $transfer_destination : ( $op_type === 'return' ? $return_destination : ( $op_type === 'onlyLabel' ? 'label_only' : 'production' ) ) ),
                'product_id'      => $pid,
                'product_name'    => wc_suf_full_product_label( $product ),
                'sku'             => $product->get_sku() ?: null,
                'product_type'    => $product->get_type(),
                'parent_id'       => $product->is_type('variation') ? $product->get_parent_id() : null,
                'attributes_text' => wc_suf_get_product_attributes_text( $product ),
                'old_qty'         => (float) $old_qty,
                'change_qty'      => (float) $req,
                'new_qty'         => (float) $new_qty,
                'destination_old_qty' => ( $destination_old_qty === null ? null : (float) $destination_old_qty ),
                'destination_new_qty' => ( $destination_new_qty === null ? null : (float) $destination_new_qty ),
                'user_id'         => $uid ?: null,
                'user_login'      => $ulog ?: null,
                'user_code'       => $user_code ?: null,
                'created_at'      => current_time('mysql'),
            ];
            $wpdb->insert(
                $move_table,
                $move_data,
                ['%s','%s','%s','%d','%s','%s','%s','%d','%s','%f','%f','%f','%f','%f','%d','%s','%s','%s']
            );
            if ( ! empty($wpdb->last_error) ) {
                if ( $tx_started ) {
                    $wpdb->query('ROLLBACK');
                }
                wp_send_json_error(['message'=>'ثبت لاگ حرکات انبار ناموفق بود.']);
            }
        }

        $full_name = wc_suf_full_product_label($product);
        $price = wc_get_price_to_display( $product );
        $csv_rows[] = [
            'id'    => (string) $pid,
            'name'  => (string) $full_name,
            'price' => (string) $price,
            'qty'   => (string) $req,
            'sku'   => (string) ($product->get_sku() ?: ''),
        ];
    }

    $csv_file_url = '';
    $word_file_url = '';
    if ( ! empty($csv_rows) ) {
        $csv_result = wc_suf_generate_batch_label_html( $batch_code, $csv_rows );
        if ( is_wp_error( $csv_result ) ) {
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message'=>'ساخت صفحه چاپ لیبل ناموفق بود: '.$csv_result->get_error_message()]);
        }

        $csv_file_url = (string) ( $csv_result['url'] ?? '' );
        $csv_updated = $wpdb->update(
            $table,
            [ 'csv_file_url' => $csv_file_url ],
            [ 'batch_code' => $batch_code ],
            [ '%s' ],
            [ '%s' ]
        );
        if ( false === $csv_updated ) {
            if ( ! empty( $csv_result['path'] ) && file_exists( $csv_result['path'] ) ) {
                @unlink( $csv_result['path'] );
            }
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message'=>'ثبت لینک صفحه چاپ لیبل در دیتابیس ناموفق بود.']);
        }

        $word_context = [
            'op_type'      => $op_type === 'out' ? ( $out_destination === 'teh' ? 'out_teh' : 'out_main' ) : ( $op_type === 'transfer' ? ( $transfer_source === 'main' ? 'transfer_main_teh' : 'transfer_teh_main' ) : ( $op_type === 'return' ? ( $return_destination === 'teh' ? 'return_teh' : 'return_main' ) : $op_type ) ),
            'purpose'      => $op_type === 'out' ? ( $out_destination === 'teh' ? 'انتقال به انبار تهرانپارس' : 'خروج به انبار اصلی' ) : ( $op_type === 'transfer' ? ( 'انتقال بین انبارها: ' . wc_suf_destination_label( $transfer_source ) . ' → ' . wc_suf_destination_label( $transfer_destination ) ) : ( $op_type === 'return' ? ('مرجوعی - علت: '.$return_reason) : null ) ),
            'user_display' => $ulog ?: ( $uid ? ('user#'.$uid) : 'مهمان' ),
            'user_code'    => $user_code,
            'created_at'   => current_time('mysql'),
        ];
        $word_result = wc_suf_generate_batch_word_receipt( $batch_code, $word_context, $csv_rows );
        if ( is_wp_error( $word_result ) ) {
            if ( ! empty( $csv_result['path'] ) && file_exists( $csv_result['path'] ) ) {
                @unlink( $csv_result['path'] );
            }
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message'=>'ساخت فایل Word ناموفق بود: '.$word_result->get_error_message()]);
        }

        $word_file_url = (string) ( $word_result['url'] ?? '' );
        $word_updated = $wpdb->update(
            $table,
            [ 'word_file_url' => $word_file_url ],
            [ 'batch_code' => $batch_code ],
            [ '%s' ],
            [ '%s' ]
        );
        if ( false === $word_updated ) {
            if ( ! empty( $word_result['path'] ) && file_exists( $word_result['path'] ) ) {
                @unlink( $word_result['path'] );
            }
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message'=>'ثبت لینک Word در دیتابیس ناموفق بود.']);
        }
    }


    if ( $inserted === 0 ) {
        if ( $tx_started ) {
            $wpdb->query('ROLLBACK');
        }
        $msg = 'هیچ موردی ثبت نشد.' . ( $wpdb->last_error ? (' DB error: '.$wpdb->last_error) : '' );
        wp_send_json_error(['message'=>$msg]);
    }

    if ( $tx_started ) {
        $wpdb->query('COMMIT');
    }

    $op_label = wc_suf_op_label(
        $op_type === 'out'
        ? ( $out_destination === 'teh' ? 'out_teh' : 'out_main' )
        : ( $op_type === 'return'
            ? ( $return_destination === 'teh' ? 'return_teh' : 'return_main' )
            : $op_type )
    );
    $product_ids = [];
    foreach ( $csv_rows as $row ) {
        if ( isset( $row['id'] ) ) {
            $pid = absint( $row['id'] );
            if ( $pid > 0 ) {
                $product_ids[] = $pid;
            }
        }
    }
    $product_ids = array_values( array_unique( $product_ids ) );

    wp_send_json_success([
        'message'=>"ثبت {$op_label} انجام شد. کد ثبت: {$batch_code}",
        'batch_code' => $batch_code,
        'csv_url' => $csv_file_url,
        'word_url' => $word_file_url,
        'product_ids' => $product_ids,
    ]);
}

add_action('wp_ajax_wc_suf_refresh_stocks', 'wc_suf_refresh_stocks_handler');
function wc_suf_refresh_stocks_handler(){
    check_ajax_referer('wc_suf_refresh_stocks');

    if( ! wc_suf_current_user_is_pos_manager() ){
        wp_send_json_error(['message'=>'دسترسی غیرمجاز.']);
    }

    $raw_ids = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '[]';
    $ids = json_decode($raw_ids, true);
    if ( ! is_array($ids) || empty($ids) ) {
        wp_send_json_success(['stocks' => []]);
    }

    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
    if ( empty($ids) ) {
        wp_send_json_success(['stocks' => []]);
    }

    $stocks = [];
    foreach ( $ids as $pid ) {
        if ( $pid <= 0 ) continue;
        $product = wc_get_product( $pid );
        if ( ! $product ) continue;

        $prod_stock = wc_suf_get_production_stock_qty( $pid );
        $wc_stock   = (int) max(0, (int) ($product->get_stock_quantity() ?? 0));
        $teh_stock  = 0;
        $teh_ok     = 0;

        if ( function_exists('yith_pos_stock_management') ) {
            $teh_read = wc_suf_yith_get_store_stock( $product, (int) WC_SUF_TEHRANPARS_STORE_ID );
            if ( false !== $teh_read && null !== $teh_read ) {
                $teh_stock = (int) $teh_read;
                $teh_ok    = 1;
            }
        }

        $stocks[(string) $pid] = [
            'prod_stock'   => (int) $prod_stock,
            'wc_stock'     => (int) $wc_stock,
            'teh_stock'    => (int) $teh_stock,
            'teh_stock_ok' => (int) $teh_ok,
        ];
    }

    wp_send_json_success(['stocks' => $stocks]);
}

/*--------------------------------------
| رندر مشترک گزارش (لیست و جزئیات)
---------------------------------------*/
function wc_suf_render_audit_html($args = []){
    $public = ! empty( $args['public'] );

    if( ! $public ){
        if( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ){
            return '<div class="wrap" dir="rtl" style="color:#b91c1c">دسترسی کافی برای مشاهده گزارش ندارید.</div>';
        }
    }

    global $wpdb;
    $table = $wpdb->prefix.'stock_audit';
    ob_start();

    if( isset($_GET['view'], $_GET['code']) && $_GET['view']==='batch' ){
        $code = sanitize_text_field( wp_unslash($_GET['code']) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE batch_code = %s ORDER BY id ASC", $code
        ) );
        ?>
        <div class="wrap" dir="rtl">
            <h1>جزئیات کد ثبت: <?php echo esc_html($code); ?></h1>
            <p><a href="<?php echo esc_url( remove_query_arg(['view','code']) ); ?>">&larr; بازگشت به فهرست</a></p>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>#</th><th>عملیات</th><th>ID</th><th>محصول</th>
                        <th>تغییر</th><th>قبل → بعد</th><th>چاپ لیبل</th>
                        <th>کاربر</th><th>کد</th><th>IP</th><th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if($rows){
                    foreach($rows as $r){
                        $user_disp = $r->user_login ? $r->user_login : ( $r->user_id ? 'user#'.$r->user_id : 'مهمان' );
                        ?>
                        <tr>
                            <td><?php echo esc_html($r->id); ?></td>
                            <td><?php echo esc_html( wc_suf_op_label($r->op_type) ); ?></td>
                            <td><?php echo esc_html($r->product_id); ?></td>
                            <td><?php echo esc_html($r->product_name ?: ''); ?></td>
                            <td><?php echo esc_html(intval($r->added_qty)); ?></td>
                            <td><?php echo esc_html(intval($r->old_qty).' → '.intval($r->new_qty)); ?></td>
                            <td><?php echo esc_html($r->print_label ? 'بله' : 'خیر'); ?></td>
                            <td><?php echo esc_html($user_disp); ?></td>
                            <td><?php echo esc_html($r->user_code ?: '—'); ?></td>
                            <td><?php echo esc_html($r->ip ?: ''); ?></td>
                            <td><?php echo esc_html($r->created_at); ?></td>
                        </tr>
                        <?php
                    }
                } else {
                    echo '<tr><td colspan="11" style="text-align:center">‌رکوردی یافت نشد.</td></tr>';
                }
                ?>
                </tbody>
            </table>
            <?php
            $op_for_batch = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(op_type) FROM $table WHERE batch_code=%s", $code
            ) );
            if ( in_array( $op_for_batch, ['out','out_main','out_teh'], true ) ) {
                $pur = $wpdb->get_var( $wpdb->prepare(
                    "SELECT purpose FROM $table WHERE batch_code=%s AND purpose IS NOT NULL AND purpose<>'' LIMIT 1", $code
                ) );
                if($pur){
                    echo '<p><strong>هدف خروج:</strong> '.esc_html($pur).'</p>';
                }
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    $limit = 200;
    $rows = $wpdb->get_results(
        $wpdb->prepare("
            SELECT
              batch_code,
              MIN(created_at) as created_at,
              MAX(op_type) as op_type,
              MAX(print_label) as print_label,
              MAX(user_login) as user_login,
              MAX(user_id) as user_id,
              MAX(user_code) as user_code,
              COUNT(*) as items_count,
              SUM(added_qty) as total_qty_change
            FROM $table
            GROUP BY batch_code
            ORDER BY MAX(id) DESC
            LIMIT %d
        ", $limit)
    );
    ?>
    <div class="wrap" dir="rtl">
        <h1>گزارش تغییر موجودی (گروهی)</h1>
        <p>آخرین <?php echo esc_html($limit); ?> ثبت گروهی. برای جزئیات روی کد ثبت کلیک کنید.</p>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>کد ثبت</th>
                    <th>عملیات</th>
                    <th>تعداد آیتم‌ها</th>
                    <th>جمع تغییر</th>
                    <th>چاپ لیبل</th>
                    <th>کاربر</th>
                    <th>کد (URL/Shortcode)</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if($rows){
                    foreach($rows as $r){
                        $user_disp = $r->user_login ? $r->user_login : ( $r->user_id ? 'user#'.$r->user_id : 'مهمان' );
                        $link = esc_url( add_query_arg( ['view'=>'batch','code'=>$r->batch_code] ) );
                        ?>
                        <tr>
                            <td><a href="<?php echo $link; ?>"><?php echo esc_html($r->batch_code); ?></a></td>
                            <td><?php echo esc_html( wc_suf_op_label($r->op_type) ); ?></td>
                            <td><?php echo esc_html((int)$r->items_count); ?></td>
                            <td><?php echo esc_html((int)$r->total_qty_change); ?></td>
                            <td><?php echo esc_html($r->print_label ? 'بله' : 'خیر'); ?></td>
                            <td><?php echo esc_html($user_disp); ?></td>
                            <td><?php echo esc_html($r->user_code ?: '—'); ?></td>
                            <td><?php echo esc_html($r->created_at); ?></td>
                        </tr>
                        <?php
                    }
                } else {
                    echo '<tr><td colspan="8" style="text-align:center">‌رکوردی یافت نشد.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}



function wc_suf_gregorian_to_jalali( $gy, $gm, $gd ) {
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [ $jy, $jm, $jd ];
}

function wc_suf_format_jalali_datetime( $mysql_datetime ) {
    $ts = strtotime( (string) $mysql_datetime );
    if ( ! $ts ) return (string) $mysql_datetime;

    $gy = (int) gmdate('Y', $ts);
    $gm = (int) gmdate('n', $ts);
    $gd = (int) gmdate('j', $ts);
    [$jy, $jm, $jd] = wc_suf_gregorian_to_jalali( $gy, $gm, $gd );

    return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, gmdate('H:i:s', $ts));
}

function wc_suf_collect_detailed_log_filters() {
    return [
        'q' => isset($_GET['q']) ? sanitize_text_field( wp_unslash($_GET['q']) ) : '',
        'from' => isset($_GET['from']) ? sanitize_text_field( wp_unslash($_GET['from']) ) : '',
        'to' => isset($_GET['to']) ? sanitize_text_field( wp_unslash($_GET['to']) ) : '',
        'batch_code' => isset($_GET['batch_code']) ? sanitize_text_field( wp_unslash($_GET['batch_code']) ) : '',
        'op' => isset($_GET['op']) ? sanitize_text_field( wp_unslash($_GET['op']) ) : '',
        'product_id' => isset($_GET['product_id']) ? absint($_GET['product_id']) : 0,
        'user_id' => isset($_GET['user_id']) ? absint($_GET['user_id']) : 0,
    ];
}

function wc_suf_order_has_yith_pos_marker( $order ) {
    if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_meta_data' ) ) {
        return false;
    }

    $created_via = strtolower( (string) $order->get_created_via() );
    if ( $created_via !== '' && ( strpos( $created_via, 'yith' ) !== false || strpos( $created_via, 'pos' ) !== false ) ) {
        return true;
    }

    foreach ( (array) $order->get_meta_data() as $meta_obj ) {
        if ( ! is_object( $meta_obj ) || ! method_exists( $meta_obj, 'get_data' ) ) {
            continue;
        }
        $meta = (array) $meta_obj->get_data();
        $key  = strtolower( (string) ( $meta['key'] ?? '' ) );
        $val  = strtolower( (string) ( $meta['value'] ?? '' ) );

        if ( strpos( $key, 'yith' ) !== false && strpos( $key, 'pos' ) !== false ) {
            return true;
        }
        if ( strpos( $val, 'yith' ) !== false && strpos( $val, 'pos' ) !== false ) {
            return true;
        }
    }

    return false;
}

function wc_suf_detect_sale_channel( $order ) {
    $created_via = strtolower( (string) $order->get_created_via() );

    if ( wc_suf_order_has_yith_pos_marker( $order ) ) {
        return [
            'channel' => 'yith_pos',
            'label'   => 'فروش حضوری تهرانپارس (YITH POS)',
            'warehouse' => 'انبار تهرانپارس',
        ];
    }

    if ( $created_via === 'admin' ) {
        return [
            'channel' => 'manual_order',
            'label'   => 'افزودن دستی سفارش در ووکامرس',
            'warehouse' => 'انبار اصلی',
        ];
    }

    if ( $created_via === 'wc_suf' || (string) $order->get_meta('_wc_suf_sale_source', true) !== '' ) {
        return [
            'channel' => 'wc_suf_sale',
            'label'   => 'فروش ثبت‌شده از افزونه انبار',
            'warehouse' => 'انبار اصلی',
        ];
    }

    return [
        'channel' => 'website',
        'label'   => 'فروش عادی وب‌سایت',
        'warehouse' => 'انبار اصلی',
    ];
}

function wc_suf_get_woocommerce_sales_rows( $filters, $limit = 500 ) {
    if ( ! function_exists( 'wc_get_orders' ) ) {
        return [];
    }

    $op_filter = (string) ( $filters['op'] ?? '' );
    if ( $op_filter !== '' && $op_filter !== 'sale' ) {
        return [];
    }

    $q = trim( (string) ( $filters['q'] ?? '' ) );
    $from = (string) ( $filters['from'] ?? '' );
    $to = (string) ( $filters['to'] ?? '' );
    $pid_filter = (int) ( $filters['product_id'] ?? 0 );
    $uid_filter = (int) ( $filters['user_id'] ?? 0 );

    $statuses = array_keys( (array) wc_get_order_statuses() );
    $statuses = array_values( array_filter( $statuses, static function( $status ) {
        return ! in_array( $status, [ 'wc-cancelled', 'wc-failed', 'wc-refunded', 'wc-trash' ], true );
    } ) );

    $args = [
        'type' => 'shop_order',
        'status' => ! empty( $statuses ) ? $statuses : [ 'wc-processing', 'wc-completed' ],
        'limit' => (int) $limit,
        'orderby' => 'date',
        'order' => 'DESC',
        'return' => 'objects',
    ];

    if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ) {
        $after  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from . ' 00:00:00' : null;
        $before = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to . ' 23:59:59' : null;
        $range = [];
        if ( $after )  $range['after'] = $after;
        if ( $before ) $range['before'] = $before;
        if ( ! empty( $range ) ) {
            $range['inclusive'] = true;
            $args['date_created'] = $range;
        }
    }

    if ( $uid_filter > 0 ) {
        $args['customer_id'] = $uid_filter;
    }

    if ( $q !== '' && ctype_digit( $q ) ) {
        $args['search'] = '#' . $q;
    } elseif ( $q !== '' ) {
        $args['search'] = '*' . $q . '*';
    }

    $orders = wc_get_orders( $args );
    if ( empty( $orders ) ) {
        return [];
    }

    $rows = [];
    foreach ( (array) $orders as $order ) {
        if ( ! $order || ! is_object( $order ) ) {
            continue;
        }
        $channel = wc_suf_detect_sale_channel( $order );
        $created = $order->get_date_created();
        $created_at = $created ? $created->date_i18n('Y-m-d H:i:s') : '';

        foreach ( (array) $order->get_items('line_item') as $item_id => $item ) {
            if ( ! $item || ! is_object( $item ) ) {
                continue;
            }
            $product_id = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            $effective_product_id = $variation_id > 0 ? $variation_id : $product_id;
            if ( $pid_filter > 0 && $pid_filter !== $effective_product_id ) {
                continue;
            }
            $qty = (float) $item->get_quantity();
            if ( $qty <= 0 ) {
                continue;
            }
            $product_name = (string) $item->get_name();
            if ( $q !== '' && ! ctype_digit( $q ) ) {
                $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
                $hay_product = function_exists('mb_strtolower') ? mb_strtolower($product_name, 'UTF-8') : strtolower($product_name);
                $hay_channel = function_exists('mb_strtolower') ? mb_strtolower($channel['label'], 'UTF-8') : strtolower($channel['label']);
                $hay_order = (string) $order->get_order_number();
                if ( strpos($hay_product, $needle) === false && strpos($hay_channel, $needle) === false && strpos($hay_order, $needle) === false ) {
                    continue;
                }
            }

            $customer_title = '';
            if ( $order->get_user_id() > 0 ) {
                $customer_title = 'user#' . $order->get_user_id();
            } else {
                $customer_title = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
                if ( $customer_title === '' ) {
                    $customer_title = 'مشتری مهمان';
                }
            }

            $rows[] = (object) [
                'id' => 'sale-' . $order->get_id() . '-' . $item_id,
                'batch_code' => 'order_' . $order->get_order_number(),
                'operation' => 'sale',
                'destination' => (string) $channel['warehouse'],
                'product_id' => $effective_product_id,
                'product_name' => $product_name,
                'old_qty' => null,
                'change_qty' => -1 * $qty,
                'new_qty' => null,
                'user_login' => $customer_title,
                'user_id' => $order->get_user_id() > 0 ? (int) $order->get_user_id() : null,
                'created_at' => $created_at,
                'csv_file_url' => '',
                'word_file_url' => '',
                'sale_channel' => (string) $channel['label'],
                'order_status' => wc_get_order_status_name( $order->get_status() ),
            ];
        }
    }

    return $rows;
}

function wc_suf_get_detailed_logs_rows( $filters, $limit = 500, $apply_limit = true ) {
    global $wpdb;
    $move_table = $wpdb->prefix.'stock_production_moves';
    $audit_table = $wpdb->prefix.'stock_audit';

    $q = (string) ( $filters['q'] ?? '' );
    $from = (string) ( $filters['from'] ?? '' );
    $to = (string) ( $filters['to'] ?? '' );
    $batch_filter = (string) ( $filters['batch_code'] ?? '' );
    $op_filter = (string) ( $filters['op'] ?? '' );
    $pid_filter = (int) ( $filters['product_id'] ?? 0 );
    $uid_filter = (int) ( $filters['user_id'] ?? 0 );

    $where = [];
    $params = [];

    if ( $q !== '' ) {
        $where[] = '(m.product_name LIKE %s OR m.batch_code LIKE %s OR m.operation LIKE %s)';
        $like = '%' . $wpdb->esc_like($q) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ( $batch_filter !== '' ) {
        $where[] = 'm.batch_code = %s';
        $params[] = $batch_filter;
    }

    if ( in_array( $op_filter, ['in','out','transfer','return','onlyLabel'], true ) ) {
        $where[] = 'm.operation = %s';
        $params[] = $op_filter;
    }

    if ( $pid_filter > 0 ) {
        $where[] = 'm.product_id = %d';
        $params[] = $pid_filter;
    }

    if ( $uid_filter > 0 ) {
        $where[] = 'm.user_id = %d';
        $params[] = $uid_filter;
    }

    if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ) {
        $where[] = 'DATE(m.created_at) >= %s';
        $params[] = $from;
    }

    if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ) {
        $where[] = 'DATE(m.created_at) <= %s';
        $params[] = $to;
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT m.*, a.csv_file_url, a.word_file_url
            FROM `$move_table` m
            LEFT JOIN (
                SELECT batch_code, MAX(csv_file_url) AS csv_file_url, MAX(word_file_url) AS word_file_url
                FROM `$audit_table`
                GROUP BY batch_code
            ) a ON a.batch_code = m.batch_code
            $where_sql
            ORDER BY m.id DESC";

    if ( $apply_limit ) {
        $sql .= " LIMIT %d";
        $params[] = (int) $limit;
    }

    return $wpdb->get_results( $wpdb->prepare($sql, ...$params) );
}

function wc_suf_generate_simple_xlsx( $filename, $headers, $rows ) {
    if ( ! class_exists('ZipArchive') ) {
        return false;
    }

    $temp_zip = wp_tempnam( 'wc-suf-export-' );
    if ( ! $temp_zip ) {
        return false;
    }

    $zip = new ZipArchive();
    if ( true !== $zip->open( $temp_zip, ZipArchive::OVERWRITE ) ) {
        return false;
    }

    $esc = static function( $value ) {
        return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    };

    $sheet_rows = [];
    $all_rows = array_merge( [ $headers ], $rows );
    foreach ( $all_rows as $row_index => $row_cells ) {
        $cells_xml = '';
        foreach ( array_values( (array) $row_cells ) as $idx => $cell ) {
            $col = '';
            $col_idx = $idx + 1;
            while ( $col_idx > 0 ) {
                $mod = ($col_idx - 1) % 26;
                $col = chr(65 + $mod) . $col;
                $col_idx = intdiv($col_idx - 1, 26);
            }
            $ref = $col . ($row_index + 1);
            $cells_xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc($cell) . '</t></is></c>';
        }
        $sheet_rows[] = '<row r="' . ($row_index + 1) . '">' . $cells_xml . '</row>';
    }

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . implode('', $sheet_rows)
        . '</sheetData></worksheet>';

    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    nocache_headers();
    header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"' );
    header( 'Content-Length: ' . filesize($temp_zip) );
    readfile( $temp_zip );
    @unlink( $temp_zip );
    exit;
}

function wc_suf_export_detailed_logs() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'Unauthorized', 403 );
    }
    if ( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') && ! wc_suf_current_user_has_role('formeditor') ) {
        wp_die( 'Forbidden', 403 );
    }
    if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_GET['_wpnonce']) ), 'wc_suf_export_detailed_logs' ) ) {
        wp_die( 'Invalid nonce', 403 );
    }

    $format = isset($_GET['format']) ? sanitize_key( wp_unslash($_GET['format']) ) : '';
    if ( ! in_array( $format, ['pdf','xlsx'], true ) ) {
        wp_die( 'Invalid format', 400 );
    }

    $filters = wc_suf_collect_detailed_log_filters();
    $rows = wc_suf_get_detailed_logs_rows( $filters, 5000, false );

    $header_row = ['#','کد عملیات','عملیات','مقصد','ID محصول','نام محصول','قبل','تغییر','بعد','کاربر','تاریخ'];
    $export_rows = [];
    foreach ( (array) $rows as $r ) {
        $user_disp = $r->user_login ? $r->user_login : ( $r->user_id ? 'user#'.$r->user_id : 'مهمان' );
        $export_rows[] = [
            (string) $r->id,
            (string) $r->batch_code,
            (string) wc_suf_op_label($r->operation),
            (string) wc_suf_destination_label( $r->destination ?: '—' ),
            (string) $r->product_id,
            (string) ($r->product_name ?: ''),
            (string) ((float)$r->old_qty),
            (string) ((float)$r->change_qty),
            (string) ((float)$r->new_qty),
            (string) $user_disp,
            (string) wc_suf_format_jalali_datetime($r->created_at),
        ];
    }

    if ( $format === 'xlsx' ) {
        wc_suf_generate_simple_xlsx( 'detailed-logs-export.xlsx', $header_row, $export_rows );
    }

    nocache_headers();
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'Content-Disposition: inline; filename="detailed-logs-report.html"' );
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>گزارش لاگ دقیق</title>';
    echo '<style>body{font-family:tahoma,sans-serif;padding:16px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:6px;font-size:12px}th{background:#f3f4f6}h1{font-size:18px}small{color:#6b7280}</style>';
    echo '</head><body>';
    echo '<h1>گزارش لاگ دقیق (خروجی PDF)</h1>';
    echo '<small>برای ذخیره PDF از Print مرورگر استفاده کنید.</small>';
    echo '<table><thead><tr>';
    foreach ( $header_row as $col ) {
        echo '<th>' . esc_html($col) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ( $export_rows as $row ) {
        echo '<tr>';
        foreach ( $row as $cell ) {
            echo '<td>' . esc_html($cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table><script>window.print();</script></body></html>';
    exit;
}
add_action( 'admin_post_wc_suf_export_detailed_logs', 'wc_suf_export_detailed_logs' );

function wc_suf_render_detailed_logs_html( $args = [] ) {
    $public = ! empty($args['public']);
    $only_formeditor = ! empty($args['only_formeditor']);
    if ( $only_formeditor ) {
        if ( ! wc_suf_current_user_has_role('formeditor') ) {
            return '<div class="wrap" dir="rtl" style="color:#b91c1c">فقط کاربران با نقش formeditor اجازه مشاهده این صفحه را دارند.</div>';
        }
    } elseif ( ! $public && ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) {
        return '<div class="wrap" dir="rtl" style="color:#b91c1c">دسترسی کافی برای مشاهده لاگ ندارید.</div>';
    }

    global $wpdb;
    $move_table = $wpdb->prefix.'stock_production_moves';
    $filters = wc_suf_collect_detailed_log_filters();
    $q = $filters['q'];
    $from = $filters['from'];
    $to = $filters['to'];
    $batch_filter = $filters['batch_code'];
    $op_filter = $filters['op'];
    $pid_filter = (int) $filters['product_id'];
    $uid_filter = (int) $filters['user_id'];

    $limit = 500;
    $rows = wc_suf_get_detailed_logs_rows( $filters, $limit, true );
    $sales_rows = wc_suf_get_woocommerce_sales_rows( $filters, $limit );
    $batch_codes = $wpdb->get_col( "SELECT DISTINCT batch_code FROM `$move_table` WHERE batch_code IS NOT NULL AND batch_code<>'' ORDER BY id DESC LIMIT 1000" );
    $products_for_filter = $wpdb->get_results( "SELECT product_id, MAX(product_name) AS product_name FROM `$move_table` GROUP BY product_id ORDER BY MAX(id) DESC LIMIT 2000" );
    $users_for_filter = $wpdb->get_results( "SELECT user_id, MAX(user_login) AS user_login FROM `$move_table` WHERE user_id IS NOT NULL AND user_id > 0 GROUP BY user_id ORDER BY MAX(id) DESC LIMIT 500" );
    $has_filter = ( $q !== '' || $from !== '' || $to !== '' || $batch_filter !== '' || $op_filter !== '' || $pid_filter > 0 || $uid_filter > 0 );

    wp_enqueue_style('wc-suf-select2', plugins_url('assets/select2.min.css', __FILE__), [], '4.1.0');
    wp_enqueue_script('wc-suf-select2', plugins_url('assets/select2.min.js', __FILE__), ['jquery'], '4.1.0', true);

    ob_start();
    ?>
    <div class="wrap" dir="rtl">
        <h1>لاگ دقیق عملیات انبار</h1>
        <form method="get" style="margin:10px 0 14px; padding:12px; border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc; display:flex; gap:10px; flex-wrap:wrap; align-items:end">
            <?php if ( ! $public ) : ?>
                <input type="hidden" name="page" value="wc-stock-audit-detailed">
            <?php endif; ?>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">جستجو (نام محصول/کد عملیات)</label>
                <input type="text" name="q" value="<?php echo esc_attr($q); ?>" placeholder="مثلاً توران یا out_0123" style="min-width:260px; padding:8px; border:1px solid #cbd5e1; border-radius:8px">
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">از تاریخ</label>
                <input type="date" name="from" value="<?php echo esc_attr($from); ?>" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px">
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">تا تاریخ</label>
                <input type="date" name="to" value="<?php echo esc_attr($to); ?>" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px">
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">کد عملیات</label>
                <select name="batch_code" class="wc-suf-select2" style="min-width:260px">
                    <option value="">همه</option>
                    <?php foreach ( (array) $batch_codes as $bc ) : ?>
                        <option value="<?php echo esc_attr($bc); ?>" <?php selected($batch_filter, $bc); ?>><?php echo esc_html($bc); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">نوع عملیات</label>
                <select name="op" style="min-width:180px; padding:8px; border:1px solid #cbd5e1; border-radius:8px">
                    <option value="">همه</option>
                    <option value="in" <?php selected($op_filter, 'in'); ?>>ورود</option>
                    <option value="out" <?php selected($op_filter, 'out'); ?>>خروج</option>
                    <option value="transfer" <?php selected($op_filter, 'transfer'); ?>>انتقال بین انبارها</option>
                    <option value="return" <?php selected($op_filter, 'return'); ?>>مرجوعی</option>
                    <option value="onlyLabel" <?php selected($op_filter, 'onlyLabel'); ?>>صرفاً جهت چاپ</option>
                    <option value="sale" <?php selected($op_filter, 'sale'); ?>>فروش ووکامرس</option>
                </select>
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">محصول</label>
                <select name="product_id" class="wc-suf-select2" style="min-width:320px">
                    <option value="0">همه محصولات</option>
                    <?php foreach ( (array) $products_for_filter as $pf ) : $ppid = (int)($pf->product_id ?? 0); if(!$ppid) continue; ?>
                        <option value="<?php echo esc_attr($ppid); ?>" <?php selected($pid_filter, $ppid); ?>><?php echo esc_html($ppid.' | '.($pf->product_name ?: '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">کاربر ثبت‌کننده</label>
                <select name="user_id" class="wc-suf-select2" style="min-width:280px">
                    <option value="0">همه کاربران</option>
                    <?php foreach ( (array) $users_for_filter as $uf ) : $uid = (int)($uf->user_id ?? 0); if(!$uid) continue; $uname = trim((string)($uf->user_login ?? '')); ?>
                        <option value="<?php echo esc_attr($uid); ?>" <?php selected($uid_filter, $uid); ?>><?php echo esc_html($uid.' | '.($uname !== '' ? $uname : ('user#'.$uid))); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="button button-primary">جستجو</button>
        </form>
        <?php if ( $has_filter && ! empty($rows) ) :
            $base_args = [
                'action' => 'wc_suf_export_detailed_logs',
                'q' => $q,
                'from' => $from,
                'to' => $to,
                'batch_code' => $batch_filter,
                'op' => $op_filter,
                'product_id' => $pid_filter,
                'user_id' => $uid_filter,
                '_wpnonce' => wp_create_nonce('wc_suf_export_detailed_logs'),
            ];
            $pdf_url = add_query_arg( array_merge($base_args, ['format' => 'pdf']), admin_url('admin-post.php') );
            $xlsx_url = add_query_arg( array_merge($base_args, ['format' => 'xlsx']), admin_url('admin-post.php') );
        ?>
            <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center">
                <a class="button" href="<?php echo esc_url($pdf_url); ?>" target="_blank" rel="noopener">گزارش PDF</a>
                <a class="button button-secondary" href="<?php echo esc_url($xlsx_url); ?>">گزارش اکسل (xlsx)</a>
            </div>
        <?php endif; ?>

        <table class="widefat fixed striped">
            <thead><tr>
                <th>#</th><th>کد عملیات</th><th>عملیات</th><th>مقصد</th><th>ID</th><th>نام محصول</th><th>قبل</th><th>تغییر</th><th>بعد</th><th>کاربر</th><th>تاریخ (شمسی)</th><th>چاپ لیبل</th><th>Word</th>
            </tr></thead>
            <tbody>
            <?php if ( ! empty($rows) ) : foreach($rows as $r):
                $user_disp = $r->user_login ? $r->user_login : ( $r->user_id ? 'user#'.$r->user_id : 'مهمان' );
                $csv_link = ! empty($r->csv_file_url) ? '<a href="'.esc_url($r->csv_file_url).'" target="_blank" rel="noopener">دانلود</a>' : '—';
                $word_link = ! empty($r->word_file_url) ? '<a href="'.esc_url($r->word_file_url).'" target="_blank" rel="noopener">دانلود</a>' : '—';
                $old_display = (string) ((float)$r->old_qty);
                $new_display = (string) ((float)$r->new_qty);
                if ( $r->operation === 'out' || $r->operation === 'return' ) {
                    $dest_old = isset($r->destination_old_qty) && $r->destination_old_qty !== null ? (float) $r->destination_old_qty : null;
                    $dest_new = isset($r->destination_new_qty) && $r->destination_new_qty !== null ? (float) $r->destination_new_qty : null;
                    if ( $dest_old !== null ) {
                        $old_display .= "
مقصد: " . $dest_old;
                    }
                    if ( $dest_new !== null ) {
                        $new_display .= "
مقصد: " . $dest_new;
                    }
                }
            ?>
                <tr>
                    <td><?php echo esc_html($r->id); ?></td>
                    <td><?php echo esc_html($r->batch_code); ?></td>
                    <td><?php echo esc_html( wc_suf_op_label($r->operation) ); ?></td>
                    <td><?php echo esc_html( wc_suf_destination_label( $r->destination ?: '—' ) ); ?></td>
                    <td><?php echo esc_html($r->product_id); ?></td>
                    <td><?php echo esc_html($r->product_name ?: ''); ?></td>
                    <td><?php echo nl2br( esc_html($old_display) ); ?></td>
                    <td><?php echo esc_html((float)$r->change_qty); ?></td>
                    <td><?php echo nl2br( esc_html($new_display) ); ?></td>
                    <td><?php echo esc_html($user_disp); ?></td>
                    <td><?php echo esc_html( wc_suf_format_jalali_datetime($r->created_at) ); ?></td>
                    <td><?php echo $csv_link; ?></td>
                    <td><?php echo $word_link; ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="13" style="text-align:center">رکوردی یافت نشد.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <h2 style="margin-top:28px">لاگ فروش ووکامرس</h2>
        <p style="margin-top:0; color:#475569">این بخش تمام سفارش‌های ثبت‌شده در ووکامرس را (فروش عادی، سفارش دستی، فروش YITH POS و فروش ثبت‌شده از افزونه) به‌صورت ردیف‌های فروش نمایش می‌دهد.</p>
        <table class="widefat fixed striped">
            <thead><tr>
                <th>#</th><th>کد سفارش</th><th>کانال فروش</th><th>انبار کاهشی</th><th>ID محصول</th><th>نام محصول</th><th>تغییر موجودی</th><th>مشتری/کاربر</th><th>وضعیت سفارش</th><th>تاریخ (شمسی)</th>
            </tr></thead>
            <tbody>
            <?php if ( ! empty($sales_rows) ) : foreach($sales_rows as $r): ?>
                <tr>
                    <td><?php echo esc_html($r->id); ?></td>
                    <td><?php echo esc_html($r->batch_code); ?></td>
                    <td><?php echo esc_html($r->sale_channel ?? 'فروش'); ?></td>
                    <td><?php echo esc_html($r->destination ?: '—'); ?></td>
                    <td><?php echo esc_html($r->product_id); ?></td>
                    <td><?php echo esc_html($r->product_name ?: ''); ?></td>
                    <td><?php echo esc_html((float)$r->change_qty); ?></td>
                    <td><?php echo esc_html($r->user_login ?: 'مهمان'); ?></td>
                    <td><?php echo esc_html($r->order_status ?: '—'); ?></td>
                    <td><?php echo esc_html( wc_suf_format_jalali_datetime($r->created_at) ); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="10" style="text-align:center">سفارشی یافت نشد.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <script>
        jQuery(function($){ if ($.fn.select2) { $('.wc-suf-select2').select2({ width:'resolve', dir:'rtl' }); } });
        </script>
    </div>
    <?php
    return ob_get_clean();
}


function wc_suf_render_detailed_logs_page(){
    echo wc_suf_render_detailed_logs_html(['public' => false]);
}

/*--------------------------------------
| Admin: گزارش گروهی
---------------------------------------*/
add_action('admin_menu', function(){
    $cap = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
    add_menu_page(
        'گزارش تغییر موجودی',
        'گزارش موجودی',
        $cap,
        'wc-stock-audit',
        'wc_suf_render_audit_page',
        'dashicons-clipboard',
        56
    );

    add_submenu_page(
        'wc-stock-audit',
        'لاگ دقیق عملیات',
        'لاگ دقیق عملیات',
        $cap,
        'wc-stock-audit-detailed',
        'wc_suf_render_detailed_logs_page'
    );
});
function wc_suf_render_audit_page(){
    if( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) return;
    echo wc_suf_render_audit_html();
}

/*--------------------------------------
| Shortcode گزارش فرانت: [stock_audit_report]
---------------------------------------*/
add_shortcode('stock_audit_report', function($atts){
    $atts = shortcode_atts(['public' => '0'], $atts, 'stock_audit_report');
    $is_public = ($atts['public'] === '1' || strtolower($atts['public']) === 'true');
    return wc_suf_render_audit_html(['public' => $is_public]);
});


add_shortcode('stock_detailed_logs', function($atts){
    $atts = shortcode_atts(['public' => '0'], $atts, 'stock_detailed_logs');
    $is_public = ($atts['public'] === '1' || strtolower($atts['public']) === 'true');
    return wc_suf_render_detailed_logs_html(['public' => $is_public]);
});

add_shortcode('stock_detailed_logs_formeditor', function($atts){
    $atts = shortcode_atts([], $atts, 'stock_detailed_logs_formeditor');
    return wc_suf_render_detailed_logs_html([
        'public' => true,
        'only_formeditor' => true,
    ]);
});
