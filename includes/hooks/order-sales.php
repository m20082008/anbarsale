<?php
function wc_suf_get_sale_hold_minutes() {
    $minutes = (int) get_option( 'wc_suf_sale_hold_minutes', 30 );
    return max( 1, $minutes );
}

function wc_suf_schedule_sale_hold_expiry( $order_id ) {
    $order_id = (int) $order_id;
    if ( $order_id <= 0 ) return;
    $hook = 'wc_suf_sale_hold_expire_event';
    wp_clear_scheduled_hook( $hook, [ $order_id ] );
    wp_schedule_single_event( time() + ( wc_suf_get_sale_hold_minutes() * MINUTE_IN_SECONDS ), $hook, [ $order_id ] );
}

function wc_suf_clear_sale_hold_expiry( $order_id ) {
    $order_id = (int) $order_id;
    if ( $order_id <= 0 ) return;
    wp_clear_scheduled_hook( 'wc_suf_sale_hold_expire_event', [ $order_id ] );
}

function wc_suf_expire_sale_hold_order( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    if ( $order->get_created_via() !== 'wc_suf_manual_sale_hold' ) return;
    if ( ! $order->has_status( [ 'pending', 'initialorder' ] ) ) return;
    $order->set_status( 'instaformremove', 'انقضای زمان هولد فرم فروش اینستا.' );
    $order->save();
}
add_action( 'wc_suf_sale_hold_expire_event', 'wc_suf_expire_sale_hold_order', 10, 1 );

add_action( 'init', function() {
    register_post_status( 'wc-initialorder', [
        'label'                     => 'ثبت اولیه سفارش',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'ثبت اولیه سفارش <span class="count">(%s)</span>', 'ثبت اولیه سفارش <span class="count">(%s)</span>' ),
    ] );

    register_post_status( 'wc-instaformremove', [
        'label'                     => 'حذف سفارش اینستا',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'حذف سفارش اینستا <span class="count">(%s)</span>', 'حذف سفارش اینستا <span class="count">(%s)</span>' ),
    ] );
} );
add_filter( 'wc_order_statuses', function( $statuses ) {
    $statuses['wc-initialorder'] = 'ثبت اولیه سفارش';
    $statuses['wc-instaformremove'] = 'حذف سفارش اینستا';
    return $statuses;
} );

add_filter( 'woocommerce_can_reduce_order_stock', function( $can_reduce, $order ) {
    if ( ! $can_reduce || ! is_a( $order, 'WC_Order' ) ) {
        return $can_reduce;
    }

    $created_via = (string) $order->get_created_via();
    if ( in_array( $created_via, [ 'wc_suf_manual_sale', 'wc_suf_manual_sale_hold' ], true ) ) {
        return false;
    }

    return $can_reduce;
}, 20, 2 );

add_action( 'woocommerce_order_status_instaformremove', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    if ( 'yes' === $order->get_meta( '_wc_suf_hold_stock_released', true ) ) return;
    foreach ( $order->get_items( 'line_item' ) as $item ) {
        if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) continue;
        $pid = (int) $item->get_variation_id();
        if ( $pid <= 0 ) $pid = (int) $item->get_product_id();
        if ( $pid <= 0 ) continue;
        $qty = max( 0, (float) $item->get_quantity() );
        if ( $qty <= 0 ) continue;
        $product = wc_get_product( $pid );
        if ( ! $product ) continue;
        wc_update_product_stock( $product, $qty, 'increase' );
    }
    $order->update_meta_data( '_wc_suf_hold_stock_released', 'yes' );
    $order->save_meta_data();
}, 20 );

add_action( 'admin_menu', function() {
    $cap = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
    add_submenu_page(
        'wc-stock-audit-detailed',
        'تنظیمات',
        'تنظیمات',
        $cap,
        'wc-suf-settings',
        'wc_suf_render_settings_page'
    );
}, 60 );

function wc_suf_render_settings_page() {
    if( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) return;
    if ( isset($_POST['wc_suf_save_settings']) ) {
        check_admin_referer( 'wc_suf_save_settings' );
        $minutes = isset($_POST['wc_suf_sale_hold_minutes']) ? absint($_POST['wc_suf_sale_hold_minutes']) : 30;
        update_option( 'wc_suf_sale_hold_minutes', max( 1, $minutes ) );
        echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
    }
    $minutes = wc_suf_get_sale_hold_minutes();
    echo '<div class="wrap" dir="rtl"><h1>تنظیمات</h1><form method="post">';
    wp_nonce_field( 'wc_suf_save_settings' );
    echo '<table class="form-table"><tr><th scope="row">زمان هولد کردن سفارش فروش (دقیقه)</th><td><input type="number" min="1" name="wc_suf_sale_hold_minutes" value="'.esc_attr($minutes).'" class="small-text"></td></tr></table>';
    submit_button( 'ذخیره تنظیمات', 'primary', 'wc_suf_save_settings' );
    echo '</form></div>';
}

function wc_suf_log_woocommerce_order_sale( $order_id ) {
    if ( ! function_exists('wc_get_order') ) {
        return;
    }

    if ( is_object( $order_id ) && is_a( $order_id, 'WC_Order' ) ) {
        $order = $order_id;
    } else {
        $order = wc_get_order( $order_id );
    }
    if ( ! $order ) {
        return;
    }

    if ( 'yes' === $order->get_meta('_wc_suf_sale_logged', true) ) {
        return;
    }
    if ( 'yes' === $order->get_meta('_wc_suf_sale_hold_active', true ) && $order->has_status( [ 'pending', 'initialorder' ] ) ) {
        return;
    }

    $items = $order->get_items( 'line_item' );
    if ( empty($items) ) {
        return;
    }

    global $wpdb;
    $move_table = $wpdb->prefix . 'stock_production_moves';
    $audit_table = $wpdb->prefix . 'stock_audit';

    $customer_name = trim( (string) $order->get_formatted_billing_full_name() );
    if ( $customer_name === '' ) {
        $customer_name = trim( (string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name() );
    }
    if ( $customer_name === '' ) {
        $customer_name = (string) $order->get_billing_email();
    }
    if ( $customer_name === '' ) {
        $customer_name = 'مشتری ووکامرس';
    }

    $customer_id = (int) $order->get_customer_id();
    $seller_name = trim( (string) $order->get_meta( '_wc_suf_seller_name', true ) );
    if ( $seller_name === '' ) {
        $seller_name = trim( (string) $order->get_meta( 'فروشنده', true ) );
    }
    $seller_user_id = (int) $order->get_meta( '_wc_suf_seller_id', true );
    $log_user_id = $seller_user_id > 0 ? $seller_user_id : ( $customer_id > 0 ? $customer_id : null );
    $log_user_login = $seller_name !== '' ? $seller_name : $customer_name;
    $order_number = (string) $order->get_order_number();
    $created_at_mysql = current_time('mysql');
    $stock_source = wc_suf_get_order_stock_source( $order );

    $logged_any_item = false;
    $receipt_rows = [];

    foreach ( $items as $item ) {
        if ( ! is_a($item, 'WC_Order_Item_Product') ) {
            continue;
        }

        $qty = (float) $item->get_quantity();
        if ( $qty <= 0 ) {
            continue;
        }

        $product_id = (int) $item->get_variation_id();
        if ( $product_id <= 0 ) {
            $product_id = (int) $item->get_product_id();
        }
        if ( $product_id <= 0 ) {
            continue;
        }

        $product = wc_get_product( $product_id );
        $current_stock = wc_suf_get_order_stock_qty_by_source( $product, $stock_source );

        $change_qty = -1 * $qty;
        $item_reduced_stock = wc_suf_get_order_item_reduced_stock_qty( $item );
        $order_stock_reduced_flag = $order->get_meta( '_order_stock_reduced', true );
        $order_stock_already_reduced = wc_string_to_bool( (string) $order_stock_reduced_flag );

        if ( $item_reduced_stock !== null && $item_reduced_stock > 0 ) {
            // وقتی ووکامرس قبلاً موجودی را کم کرده، موجودی فعلی همان new_qty است.
            $new_qty = $current_stock;
            $old_qty = $current_stock + $item_reduced_stock;
        } elseif ( $order_stock_already_reduced ) {
            /*
             * بعضی مسیرها (مثل ثبت فروش دستی) ممکن است متای _reduced_stock آیتم را نداشته باشند
             * اما کسر موجودی روی سفارش ثبت شده باشد. در این حالت موجودی فعلی همان "بعد" است.
             */
            $new_qty = $current_stock;
            $old_qty = $current_stock + $qty;
        } else {
            // اگر هنوز کسر انبار انجام نشده، old_qty را از انبار اصلی می‌خوانیم و new_qty را محاسبه می‌کنیم.
            $old_qty = $current_stock;
            $new_qty = $current_stock - $qty;
        }
        $product_name = (string) ( $item->get_name() ?: ( $product ? $product->get_name() : '' ) );

        $move_inserted = $wpdb->insert(
            $move_table,
            [
                'batch_code' => $order_number,
                'operation' => 'sale',
                'destination' => (string) $stock_source['destination'],
                'product_id' => $product_id,
                'product_name' => $product_name,
                'sku' => $product ? (string) $product->get_sku() : '',
                'product_type' => $product ? (string) $product->get_type() : '',
                'parent_id' => $product ? (int) $product->get_parent_id() : 0,
                'attributes_text' => $product ? wc_suf_get_product_attributes_text($product) : '',
                'old_qty' => $old_qty,
                'change_qty' => $change_qty,
                'new_qty' => $new_qty,
                'destination_old_qty' => null,
                'destination_new_qty' => null,
                'user_id' => $log_user_id,
                'user_login' => $log_user_login,
                'user_code' => $order_number,
                'created_at' => $created_at_mysql,
            ],
            [
                '%s','%s','%s','%d','%s','%s','%s','%d','%s',
                '%f','%f','%f','%f','%f','%d','%s','%s','%s'
            ]
        );

        $audit_inserted = $wpdb->insert(
            $audit_table,
            [
                'batch_code'   => $order_number,
                'csv_file_url' => null,
                'word_file_url'=> null,
                'op_type'      => 'sale',
                'purpose'      => 'سفارش ووکامرس | ' . (string) $stock_source['label'],
                'print_label'  => 0,
                'product_id'   => $product_id,
                'product_name' => $product_name,
                'old_qty'      => $old_qty,
                'added_qty'    => $change_qty,
                'new_qty'      => $new_qty,
                'user_id'      => $log_user_id,
                'user_login'   => $log_user_login,
                'user_code'    => $order_number,
                'ip'           => '',
                'created_at'   => $created_at_mysql,
            ],
            [
                '%s','%s','%s','%s','%s','%d','%d','%s','%f',
                '%f','%f','%d','%s','%s','%s','%s'
            ]
        );

        if ( false !== $move_inserted && false !== $audit_inserted ) {
            $logged_any_item = true;
            $receipt_rows[] = [
                'id'   => $product_id,
                'name' => $product_name,
                'qty'  => $qty,
            ];
        }
    }

    if ( $logged_any_item ) {
        $receipt_context = [
            'op_type'      => 'sale',
            'purpose'      => 'سفارش ووکامرس | ' . (string) $stock_source['label'],
            'user_display' => $log_user_login,
            'user_code'    => $order_number,
            'created_at'   => $created_at_mysql,
        ];
        $receipt_result = wc_suf_generate_batch_word_receipt( $order_number, $receipt_context, $receipt_rows );
        if ( ! is_wp_error( $receipt_result ) && ! empty( $receipt_result['url'] ) ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `$audit_table` SET `word_file_url` = %s WHERE `batch_code` = %s",
                    (string) $receipt_result['url'],
                    $order_number
                )
            );
            $order->update_meta_data('_wc_suf_sale_receipt_html', (string) $receipt_result['url']);
        }
        $order->update_meta_data('_wc_suf_sale_logged', 'yes');
        $order->save_meta_data();
    }
}
add_action( 'woocommerce_new_order', 'wc_suf_log_woocommerce_order_sale', 20 );
add_action( 'woocommerce_checkout_order_processed', 'wc_suf_log_woocommerce_order_sale', 20 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'wc_suf_log_woocommerce_order_sale', 20 );
add_action( 'woocommerce_order_status_pending', 'wc_suf_log_woocommerce_order_sale', 20 );
add_action( 'woocommerce_order_status_on-hold', 'wc_suf_log_woocommerce_order_sale', 20 );
add_action( 'woocommerce_order_status_processing', 'wc_suf_log_woocommerce_order_sale', 20 );
add_action( 'woocommerce_order_status_completed', 'wc_suf_log_woocommerce_order_sale', 20 );

function wc_suf_restore_stock_for_cancelled_order( $order_id ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return;
    }

    $order = is_a( $order_id, 'WC_Order' ) ? $order_id : wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    if ( 'yes' === $order->get_meta( '_wc_suf_cancel_restore_logged', true ) ) {
        return;
    }

    global $wpdb;
    $move_table = $wpdb->prefix . 'stock_production_moves';
    $audit_table = $wpdb->prefix . 'stock_audit';

    $order_number = (string) $order->get_order_number();
    $batch_code = 'order_cancel_' . $order_number;
    $created_at_mysql = current_time( 'mysql' );
    $stock_source = wc_suf_get_order_stock_source( $order );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT product_id, MAX(product_name) AS product_name, MAX(destination) AS destination, SUM(change_qty) AS total_change
             FROM `$move_table`
             WHERE user_code = %s AND operation IN ('sale','sale_edit')
             GROUP BY product_id",
            $order_number
        )
    );
    if ( empty( $rows ) ) {
        return;
    }

    $customer_name = trim( (string) $order->get_formatted_billing_full_name() );
    if ( $customer_name === '' ) {
        $customer_name = trim( (string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name() );
    }
    if ( $customer_name === '' ) {
        $customer_name = (string) $order->get_billing_email();
    }
    if ( $customer_name === '' ) {
        $customer_name = 'مشتری ووکامرس';
    }
    $customer_id = (int) $order->get_customer_id();

    $logged_any = false;
    foreach ( $rows as $row ) {
        $product_id = (int) ( $row->product_id ?? 0 );
        if ( $product_id <= 0 ) {
            continue;
        }

        $net_change = (float) ( $row->total_change ?? 0 );
        if ( abs( $net_change ) < 0.0001 ) {
            continue;
        }

        $restore_qty = -1 * $net_change;
        $destination = (string) ( $row->destination ?: ( $stock_source['destination'] ?? 'main' ) );
        $product = wc_get_product( $product_id );
        $stock_product = wc_suf_get_stock_product( $product );
        if ( ! $stock_product ) {
            continue;
        }

        if ( ! $stock_product->managing_stock() ) {
            $stock_product->set_manage_stock( true );
            if ( $stock_product->get_stock_quantity() === null ) {
                $stock_product->set_stock_quantity( 0 );
            }
            $stock_product->save();
        }

        /*
         * در وضعیت لغو سفارش، ووکامرس خودش موجودی را برمی‌گرداند.
         * اینجا فقط همان تغییر انجام‌شده توسط ووکامرس را لاگ می‌کنیم و
         * هیچ افزایشی روی موجودی انجام نمی‌دهیم تا دوباره‌کاری نشود.
         */
        if ( $destination === 'teh' ) {
            $new_qty_raw = wc_suf_yith_get_store_stock_qty( $stock_product, (int) WC_SUF_TEHRANPARS_STORE_ID );
            $new_qty = ( false === $new_qty_raw ) ? 0 : (float) $new_qty_raw;
        } else {
            $new_qty = (float) ( $stock_product->get_stock_quantity() ?? 0 );
        }
        $old_qty = $new_qty - $restore_qty;

        $product_name = (string) ( $row->product_name ?: $stock_product->get_name() );
        $purpose = sprintf(
            'لغو سفارش #%s | برگشت موجودی %s | %s → %s',
            $order_number,
            ( $destination === 'teh' ? 'انبار تهرانپارس' : 'انبار اصلی ووکامرس' ),
            wc_format_decimal( $old_qty, 4 ),
            wc_format_decimal( $new_qty, 4 )
        );

        $wpdb->insert(
            $move_table,
            [
                'batch_code'           => $batch_code,
                'operation'            => 'sale_cancel',
                'destination'          => $destination,
                'product_id'           => $product_id,
                'product_name'         => $product_name,
                'sku'                  => (string) $stock_product->get_sku(),
                'product_type'         => (string) $stock_product->get_type(),
                'parent_id'            => (int) $stock_product->get_parent_id(),
                'attributes_text'      => wc_suf_get_product_attributes_text( $stock_product ),
                'old_qty'              => $old_qty,
                'change_qty'           => $restore_qty,
                'new_qty'              => $new_qty,
                'destination_old_qty'  => $old_qty,
                'destination_new_qty'  => $new_qty,
                'user_id'              => $customer_id > 0 ? $customer_id : null,
                'user_login'           => $customer_name,
                'user_code'            => $order_number,
                'created_at'           => $created_at_mysql,
            ]
        );

        $wpdb->insert(
            $audit_table,
            [
                'batch_code'   => $batch_code,
                'csv_file_url' => null,
                'word_file_url'=> null,
                'op_type'      => 'sale_cancel',
                'purpose'      => $purpose,
                'print_label'  => 0,
                'product_id'   => $product_id,
                'product_name' => $product_name,
                'old_qty'      => $old_qty,
                'added_qty'    => $restore_qty,
                'new_qty'      => $new_qty,
                'user_id'      => $customer_id > 0 ? $customer_id : null,
                'user_login'   => $customer_name,
                'user_code'    => $order_number,
                'ip'           => '',
                'created_at'   => $created_at_mysql,
            ]
        );
        $logged_any = true;
    }

    if ( $logged_any ) {
        $order->update_meta_data( '_wc_suf_cancel_restore_logged', 'yes' );
        $order->save_meta_data();
    }
}
add_action( 'woocommerce_order_status_cancelled', 'wc_suf_restore_stock_for_cancelled_order', 30 );

function wc_suf_is_manual_admin_order_edit_request() {
    if ( ! is_admin() ) {
        return false;
    }
    if ( ! wp_doing_ajax() ) {
        return false;
    }
    if ( wp_doing_cron() ) {
        return false;
    }
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return false;
    }

    $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
    if ( $action !== 'woocommerce_save_order_items' ) {
        return false;
    }

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        return false;
    }

    return true;
}

function wc_suf_parse_admin_order_items_qty_totals( $order, $items ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        return [];
    }

    $requested_qty_by_item_id = [];
    if ( is_string( $items ) ) {
        parse_str( wp_unslash( $items ), $items );
    }
    $items = (array) $items;

    /**
     * WooCommerce در اکشن woocommerce_before_save_order_items معمولاً
     * payload را به‌صورت flat می‌فرستد:
     * - order_item_qty[item_id] = qty
     * اما برای سازگاری، ساختار nested هم پشتیبانی می‌شود.
     */
    if ( isset( $items['order_item_qty'] ) && is_array( $items['order_item_qty'] ) ) {
        foreach ( $items['order_item_qty'] as $item_id => $qty_raw ) {
            $item_id = (int) $item_id;
            if ( $item_id <= 0 ) {
                continue;
            }

            $qty = wc_stock_amount( wc_clean( wp_unslash( $qty_raw ) ) );
            $requested_qty_by_item_id[ $item_id ] = max( 0, (float) $qty );
        }
    } else {
        foreach ( $items as $item_id => $item_data ) {
            $item_id = (int) $item_id;
            if ( $item_id <= 0 || ! is_array( $item_data ) ) {
                continue;
            }

            if ( ! isset( $item_data['order_item_qty'] ) ) {
                continue;
            }

            $qty = wc_stock_amount( wc_clean( wp_unslash( $item_data['order_item_qty'] ) ) );
            $requested_qty_by_item_id[ $item_id ] = max( 0, (float) $qty );
        }
    }

    $totals = [];
    foreach ( $order->get_items( 'line_item' ) as $order_item_id => $order_item ) {
        if ( ! is_a( $order_item, 'WC_Order_Item_Product' ) ) {
            continue;
        }

        $item_product = $order_item->get_product();
        if ( ! $item_product ) {
            continue;
        }

        $stock_product = wc_suf_get_stock_product( $item_product );
        if ( ! $stock_product || ! $stock_product->managing_stock() ) {
            continue;
        }

        $managed_product_id = (int) $stock_product->get_id();
        if ( $managed_product_id <= 0 ) {
            continue;
        }

        $qty = isset( $requested_qty_by_item_id[ $order_item_id ] )
            ? $requested_qty_by_item_id[ $order_item_id ]
            : max( 0, (float) $order_item->get_quantity() );

        if ( ! isset( $totals[ $managed_product_id ] ) ) {
            $totals[ $managed_product_id ] = 0.0;
        }
        $totals[ $managed_product_id ] += (float) $qty;
    }

    return $totals;
}

function wc_suf_get_order_managed_stock_reserved_totals( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        return [];
    }

    $reserved_totals = [];
    foreach ( $order->get_items( 'line_item' ) as $order_item ) {
        if ( ! is_a( $order_item, 'WC_Order_Item_Product' ) ) {
            continue;
        }

        $item_product = $order_item->get_product();
        if ( ! $item_product ) {
            continue;
        }

        $stock_product = wc_suf_get_stock_product( $item_product );
        if ( ! $stock_product || ! $stock_product->managing_stock() ) {
            continue;
        }

        $managed_product_id = (int) $stock_product->get_id();
        if ( $managed_product_id <= 0 ) {
            continue;
        }

        if ( ! isset( $reserved_totals[ $managed_product_id ] ) ) {
            $reserved_totals[ $managed_product_id ] = 0.0;
        }
        $reserved_totals[ $managed_product_id ] += max( 0, (float) $order_item->get_quantity() );
    }

    return $reserved_totals;
}

function wc_suf_abort_admin_order_items_save_with_errors( $messages ) {
    $messages = array_filter( array_map( 'wc_clean', (array) $messages ) );
    if ( empty( $messages ) ) {
        return;
    }

    foreach ( $messages as $message ) {
        if ( class_exists( 'WC_Admin_Meta_Boxes' ) && is_callable( [ 'WC_Admin_Meta_Boxes', 'add_error' ] ) ) {
            WC_Admin_Meta_Boxes::add_error( $message );
        }
    }

    wp_send_json_error(
        [
            'error' => implode( "\n", $messages ),
            'messages' => array_values( $messages ),
        ]
    );
}

function wc_suf_validate_admin_order_item_qty_against_stock( $order_id, $items ) {
    if ( ! wc_suf_is_manual_admin_order_edit_request() ) {
        return;
    }

    $order_id = (int) $order_id;
    if ( $order_id <= 0 ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $attempted_totals = wc_suf_parse_admin_order_items_qty_totals( $order, $items );
    if ( empty( $attempted_totals ) ) {
        return;
    }

    $reserved_totals = wc_suf_get_order_managed_stock_reserved_totals( $order );
    $errors = [];

    foreach ( $attempted_totals as $managed_product_id => $attempted_qty ) {
        $stock_product = wc_get_product( (int) $managed_product_id );
        if ( ! $stock_product ) {
            continue;
        }

        if ( ! $stock_product->managing_stock() ) {
            continue;
        }

        $current_stock = $stock_product->get_stock_quantity();
        $current_stock = ( $current_stock === null ) ? 0.0 : (float) $current_stock;
        $reserved_qty  = isset( $reserved_totals[ $managed_product_id ] ) ? (float) $reserved_totals[ $managed_product_id ] : 0.0;
        $available_qty = max( 0, $current_stock + $reserved_qty );
        $attempted_qty = max( 0, (float) $attempted_qty );

        if ( $attempted_qty <= $available_qty ) {
            continue;
        }

        $errors[] = sprintf(
            'مقدار محصول "%1$s" بیشتر از موجودی مجاز است. موجودی قابل تخصیص: %2$s | مقدار درخواستی: %3$s',
            $stock_product->get_name(),
            wc_format_decimal( $available_qty, 0 ),
            wc_format_decimal( $attempted_qty, 0 )
        );
    }

    if ( ! empty( $errors ) ) {
        wc_suf_abort_admin_order_items_save_with_errors( $errors );
    }
}
add_action( 'woocommerce_before_save_order_items', 'wc_suf_validate_admin_order_item_qty_against_stock', 1, 2 );

function wc_suf_capture_order_stock_snapshot( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        return [];
    }

    $snapshot = [];
    $items = $order->get_items( 'line_item' );
    foreach ( $items as $item ) {
        if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
            continue;
        }

        $item_product = $item->get_product();
        if ( ! $item_product ) {
            continue;
        }

        $stock_product = wc_suf_get_stock_product( $item_product );
        if ( ! $stock_product || ! $stock_product->managing_stock() ) {
            continue;
        }

        $managed_product_id = (int) $stock_product->get_id();
        if ( $managed_product_id <= 0 ) {
            continue;
        }

        $stock_qty = $stock_product->get_stock_quantity();
        if ( $stock_qty === null ) {
            continue;
        }

        $snapshot[ $managed_product_id ] = [
            'product_id'      => $managed_product_id,
            'variation_id'    => $stock_product->is_type( 'variation' ) ? (int) $stock_product->get_id() : 0,
            'product_name'    => wc_suf_full_product_label( $stock_product ),
            'sku'             => (string) $stock_product->get_sku(),
            'product_type'    => (string) $stock_product->get_type(),
            'parent_id'       => (int) $stock_product->get_parent_id(),
            'attributes_text' => wc_suf_get_product_attributes_text( $stock_product ),
            'qty'             => (float) $stock_qty,
        ];
    }

    return $snapshot;
}

function wc_suf_track_order_before_save_for_diff( $order_id, $items ) {
    if ( ! wc_suf_is_manual_admin_order_edit_request() ) {
        return;
    }

    $order_id = (int) $order_id;
    if ( $order_id <= 0 ) {
        return;
    }

    if ( ! empty( $GLOBALS['wc_suf_order_edit_stock_snapshots'][ $order_id ] ) ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // قبل از اعمال تغییرات آیتم‌های سفارش در ادمین، موجودی واقعی محصولات مدیریت‌شونده را ثبت می‌کنیم.
    $GLOBALS['wc_suf_order_edit_stock_snapshots'][ $order_id ] = wc_suf_capture_order_stock_snapshot( $order );
}
add_action( 'woocommerce_before_save_order_items', 'wc_suf_track_order_before_save_for_diff', 5, 2 );

function wc_suf_log_order_item_differences_after_save( $order_id, $items ) {
    if ( ! wc_suf_is_manual_admin_order_edit_request() ) {
        return;
    }

    $order_id = (int) $order_id;
    if ( $order_id <= 0 ) {
        return;
    }

    if ( ! isset( $GLOBALS['wc_suf_order_edit_stock_snapshots'][ $order_id ] ) ) {
        return;
    }

    if ( ! empty( $GLOBALS['wc_suf_order_edit_stock_logged'][ $order_id ] ) ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        unset( $GLOBALS['wc_suf_order_edit_stock_snapshots'][ $order_id ] );
        return;
    }

    $before = (array) $GLOBALS['wc_suf_order_edit_stock_snapshots'][ $order_id ];
    unset( $GLOBALS['wc_suf_order_edit_stock_snapshots'][ $order_id ] );
    $after = wc_suf_capture_order_stock_snapshot( $order );

    $product_ids = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
    if ( empty( $product_ids ) ) {
        return;
    }

    global $wpdb;
    $audit_table = $wpdb->prefix . 'stock_audit';
    $move_table = $wpdb->prefix . 'stock_production_moves';
    $order_number = (string) $order->get_order_number();
    $batch_code = 'order_edit_' . $order_number;
    $created_at_mysql = current_time( 'mysql' );

    $current_user = wp_get_current_user();
    $user_id = get_current_user_id();
    $user_display = '';
    if ( $current_user && $current_user->exists() ) {
        $first_name = trim( (string) $current_user->first_name );
        $last_name  = trim( (string) $current_user->last_name );
        $user_display = trim( $first_name . ' ' . $last_name );
        if ( $user_display === '' ) {
            $user_display = trim( (string) $current_user->display_name );
        }
        if ( $user_display === '' ) {
            $user_display = trim( (string) $current_user->user_login );
        }
    }
    if ( $user_display === '' ) {
        $user_display = 'system';
    }

    $logged_any = false;

    foreach ( $product_ids as $product_id ) {
        $old_row = isset( $before[ $product_id ] ) ? $before[ $product_id ] : null;
        $new_row = isset( $after[ $product_id ] ) ? $after[ $product_id ] : null;

        $old_qty = $old_row ? (float) $old_row['qty'] : 0.0;
        $new_qty = $new_row ? (float) $new_row['qty'] : 0.0;
        $delta   = $new_qty - $old_qty;
        if ( abs( $delta ) < 0.0001 ) {
            continue;
        }

        $meta = $new_row ? $new_row : $old_row;
        if ( ! $meta ) {
            continue;
        }

        $stock_product = wc_suf_get_stock_product( wc_get_product( (int) $product_id ) );
        if ( ! $stock_product || ! $stock_product->managing_stock() ) {
            continue;
        }

        $direction = ( $delta > 0 ) ? 'increase' : 'decrease';
        $purpose = sprintf(
            'ویرایش سفارش #%s | %s موجودی اصلی ووکامرس | %s → %s',
            $order_number,
            ( $direction === 'increase' ? 'افزایش' : 'کاهش' ),
            wc_format_decimal( $old_qty, 4 ),
            wc_format_decimal( $new_qty, 4 )
        );

        $wpdb->insert(
            $move_table,
            [
                'batch_code'           => $batch_code,
                'operation'            => 'sale_edit',
                'destination'          => 'main',
                'product_id'           => (int) $meta['product_id'],
                'product_name'         => (string) $meta['product_name'],
                'sku'                  => (string) $meta['sku'],
                'product_type'         => (string) $meta['product_type'],
                'parent_id'            => (int) $meta['parent_id'],
                'attributes_text'      => (string) $meta['attributes_text'],
                'old_qty'              => $old_qty,
                'change_qty'           => $delta,
                'new_qty'              => $new_qty,
                'destination_old_qty'  => $old_qty,
                'destination_new_qty'  => $new_qty,
                'user_id'              => $user_id > 0 ? $user_id : null,
                'user_login'           => $user_display,
                'user_code'            => $order_number,
                'created_at'           => $created_at_mysql,
            ]
        );

        $wpdb->insert(
            $audit_table,
            [
                'batch_code'   => $batch_code,
                'csv_file_url' => null,
                'word_file_url'=> null,
                'op_type'      => 'sale_edit',
                'purpose'      => $purpose,
                'print_label'  => 0,
                'product_id'   => (int) $meta['product_id'],
                'product_name' => (string) $meta['product_name'],
                'old_qty'      => $old_qty,
                'added_qty'    => $delta,
                'new_qty'      => $new_qty,
                'user_id'      => $user_id > 0 ? $user_id : null,
                'user_login'   => $user_display,
                'user_code'    => $order_number,
                'ip'           => '',
                'created_at'   => $created_at_mysql,
            ]
        );

        $logged_any = true;
    }

    if ( $logged_any ) {
        $GLOBALS['wc_suf_order_edit_stock_logged'][ $order_id ] = true;
    }
}
add_action( 'woocommerce_saved_order_items', 'wc_suf_log_order_item_differences_after_save', 20, 2 );

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
