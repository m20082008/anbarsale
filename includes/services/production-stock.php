<?php
if ( ! defined( 'WC_SUF_PRODUCTION_STOCK_META_KEY' ) ) {
    define( 'WC_SUF_PRODUCTION_STOCK_META_KEY', '_wc_suf_production_stock_qty' );
}

function wc_suf_normalize_production_stock_qty( $qty ) {
    return max( 0, (int) $qty );
}

function wc_suf_get_production_stock_meta_qty( $product_id ) {
    $raw = get_post_meta( absint( $product_id ), WC_SUF_PRODUCTION_STOCK_META_KEY, true );
    if ( '' === $raw || null === $raw ) {
        return null;
    }

    return wc_suf_normalize_production_stock_qty( $raw );
}

function wc_suf_sync_production_stock_meta( $product_id, $qty ) {
    static $sync_in_progress = false;

    if ( $sync_in_progress ) {
        return;
    }

    $pid = absint( $product_id );
    if ( ! $pid ) {
        return;
    }

    $sync_in_progress = true;
    update_post_meta( $pid, WC_SUF_PRODUCTION_STOCK_META_KEY, wc_suf_normalize_production_stock_qty( $qty ) );
    $sync_in_progress = false;
}

function wc_suf_get_production_stock_qty( $product_id ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';
    $pid = absint( $product_id );
    $qty = $wpdb->get_var( $wpdb->prepare("SELECT qty FROM `$table` WHERE product_id = %d", $pid ) );

    if ( null !== $qty ) {
        $qty = wc_suf_normalize_production_stock_qty( $qty );
        wc_suf_sync_production_stock_meta( $pid, $qty );
        return $qty;
    }

    $meta_qty = wc_suf_get_production_stock_meta_qty( $pid );
    if ( null !== $meta_qty ) {
        $product = wc_get_product( $pid );
        if ( $product ) {
            wc_suf_ensure_production_inventory_row( $product );
            wc_suf_set_production_stock_qty( $product, $meta_qty );
        }
        return $meta_qty;
    }

    return 0;
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
    $qty = wc_suf_get_production_stock_qty_for_update_strict( $product );
    if ( is_wp_error( $qty ) ) {
        return 0;
    }
    return (int) $qty;
}

function wc_suf_get_production_stock_qty_for_update_strict( $product ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';
    $pid   = absint( $product->get_id() );
    if ( ! $pid ) {
        return new WP_Error( 'production_invalid_product', 'شناسه محصول برای قفل‌گذاری موجودی تولید معتبر نیست.' );
    }

    wc_suf_ensure_production_inventory_row( $product );
    $qty = $wpdb->get_var( $wpdb->prepare(
        "SELECT qty FROM `$table` WHERE product_id = %d FOR UPDATE",
        $pid
    ) );

    if ( '' !== $wpdb->last_error ) {
        return new WP_Error( 'production_lock_read_failed', 'خواندن موجودی تولید با قفل‌گذاری ناموفق بود. لطفاً دوباره تلاش کنید.' );
    }
    if ( null === $qty ) {
        return new WP_Error( 'production_lock_read_empty', 'خواندن موجودی تولید با قفل‌گذاری نتیجه‌ای برنگرداند. لطفاً دوباره تلاش کنید.' );
    }

    return (int) $qty;
}

function wc_suf_set_production_stock_qty( $product, $new_qty ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';
    $pid   = absint( $product->get_id() );
    if ( ! $pid ) return;

    $normalized_qty = wc_suf_normalize_production_stock_qty( $new_qty );

    $data = [
        'product_name'     => wc_suf_full_product_label( $product ),
        'sku'              => $product->get_sku() ?: null,
        'product_type'     => $product->get_type(),
        'parent_id'        => $product->is_type('variation') ? $product->get_parent_id() : null,
        'attributes_text'  => wc_suf_get_product_attributes_text( $product ),
        'qty'              => $normalized_qty,
        'updated_at'       => current_time('mysql'),
    ];

    $updated = $wpdb->update( $table, $data, [ 'product_id' => $pid ], [ '%s','%s','%s','%d','%s','%f','%s' ], [ '%d' ] );
    if ( false === $updated ) {
        return new WP_Error( 'production_update_failed', 'به‌روزرسانی موجودی انبار تولید در دیتابیس ناموفق بود.' );
    }

    $verify_qty = $wpdb->get_var( $wpdb->prepare("SELECT qty FROM `$table` WHERE product_id = %d", $pid ) );
    if ( (int) $verify_qty !== $normalized_qty ) {
        return new WP_Error( 'production_verify_failed', 'صحت‌سنجی موجودی انبار تولید ناموفق بود.' );
    }

    wc_suf_sync_production_stock_meta( $pid, $normalized_qty );

    return true;
}

function wc_suf_update_production_stock_qty( $product, $delta ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';

    $pid = absint( $product->get_id() );
    $current = wc_suf_get_production_stock_qty( $pid );
    $new = wc_suf_normalize_production_stock_qty( $current + (int) $delta );

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

    wc_suf_sync_production_stock_meta( $pid, $new );

    return [ $current, $new ];
}

add_action( 'updated_post_meta', 'wc_suf_maybe_sync_production_stock_from_meta', 10, 4 );
add_action( 'added_post_meta', 'wc_suf_maybe_sync_production_stock_from_meta', 10, 4 );

function wc_suf_maybe_sync_production_stock_from_meta( $meta_id, $product_id, $meta_key, $meta_value ) {
    if ( WC_SUF_PRODUCTION_STOCK_META_KEY !== $meta_key ) {
        return;
    }

    static $sync_in_progress = false;
    if ( $sync_in_progress ) {
        return;
    }

    $product = wc_get_product( absint( $product_id ) );
    if ( ! $product ) {
        return;
    }

    $sync_in_progress = true;
    wc_suf_ensure_production_inventory_row( $product );
    wc_suf_set_production_stock_qty( $product, wc_suf_normalize_production_stock_qty( $meta_value ) );
    $sync_in_progress = false;
}

add_action( 'admin_init', 'wc_suf_backfill_production_stock_meta_keys' );
function wc_suf_backfill_production_stock_meta_keys() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $done_flag = 'wc_suf_production_stock_meta_backfill_v2_done';
    if ( 'yes' === get_option( $done_flag, 'no' ) ) {
        return;
    }

    global $wpdb;
    $inventory_table = $wpdb->prefix . 'stock_production_inventory';
    $postmeta_table  = $wpdb->postmeta;

    $rows = $wpdb->get_results(
        "SELECT product_id, qty FROM `{$inventory_table}`",
        ARRAY_A
    );

    if ( empty( $rows ) ) {
        update_option( $done_flag, 'yes', false );
        return;
    }

    foreach ( $rows as $row ) {
        $pid = absint( $row['product_id'] ?? 0 );
        if ( ! $pid ) {
            continue;
        }

        $meta_exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id FROM {$postmeta_table} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $pid,
            WC_SUF_PRODUCTION_STOCK_META_KEY
        ) );

        if ( $meta_exists > 0 ) {
            continue;
        }

        wc_suf_sync_production_stock_meta( $pid, wc_suf_normalize_production_stock_qty( $row['qty'] ?? 0 ) );
    }

    update_option( $done_flag, 'yes', false );
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
