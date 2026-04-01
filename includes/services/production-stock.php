<?php
if ( ! defined( 'WC_SUF_PRODUCTION_STOCK_META_KEY' ) ) {
    define( 'WC_SUF_PRODUCTION_STOCK_META_KEY', '_wc_suf_production_stock' );
}

function wc_suf_get_production_stock_meta_qty( $product_id ) {
    $raw = get_post_meta( absint( $product_id ), WC_SUF_PRODUCTION_STOCK_META_KEY, true );
    if ( $raw === '' || $raw === null ) {
        return null;
    }
    if ( ! is_numeric( $raw ) ) {
        return null;
    }
    return max( 0, (int) $raw );
}

function wc_suf_set_production_stock_meta_qty( $product_id, $qty ) {
    update_post_meta( absint( $product_id ), WC_SUF_PRODUCTION_STOCK_META_KEY, max( 0, (int) $qty ) );
}

function wc_suf_get_production_stock_qty( $product_id ) {
    global $wpdb;
    $table = $wpdb->prefix.'stock_production_inventory';
    $pid = absint( $product_id );
    $qty = $wpdb->get_var( $wpdb->prepare("SELECT qty FROM `$table` WHERE product_id = %d", $pid ) );
    $table_qty = (int) ( $qty ?? 0 );

    $meta_qty = wc_suf_get_production_stock_meta_qty( $pid );
    if ( null !== $meta_qty ) {
        if ( $meta_qty !== $table_qty ) {
            $wpdb->update(
                $table,
                [ 'qty' => $meta_qty, 'updated_at' => current_time('mysql') ],
                [ 'product_id' => $pid ],
                [ '%f', '%s' ],
                [ '%d' ]
            );
        }
        return $meta_qty;
    }

    wc_suf_set_production_stock_meta_qty( $pid, $table_qty );
    return $table_qty;
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

    $locked_qty = (int) $qty;
    $meta_qty = wc_suf_get_production_stock_meta_qty( $pid );
    if ( null !== $meta_qty && $meta_qty !== $locked_qty ) {
        $locked_qty = $meta_qty;
        $wpdb->update(
            $table,
            [ 'qty' => $locked_qty, 'updated_at' => current_time('mysql') ],
            [ 'product_id' => $pid ],
            [ '%f', '%s' ],
            [ '%d' ]
        );
    } elseif ( null === $meta_qty ) {
        wc_suf_set_production_stock_meta_qty( $pid, $locked_qty );
    }

    return $locked_qty;
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
    wc_suf_set_production_stock_meta_qty( $pid, $new_qty );

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
    wc_suf_set_production_stock_meta_qty( $pid, $new );

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
