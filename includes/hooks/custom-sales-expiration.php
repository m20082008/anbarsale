<?php

/**
 * Get custom sale expiration duration (minutes).
 *
 * @return int
 */
function wc_suf_get_custom_sale_expiration_minutes() {
    $minutes = absint( get_option( 'wc_suf_custom_sale_expiration_minutes', 60 ) );
    if ( $minutes <= 0 ) {
        $minutes = 60;
    }
    return $minutes;
}

/**
 * Register admin setting for custom sale expiration.
 *
 * @return void
 */
function wc_suf_register_custom_sale_expiration_setting() {
    register_setting(
        'general',
        'wc_suf_custom_sale_expiration_minutes',
        [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 60,
        ]
    );

    add_settings_field(
        'wc_suf_custom_sale_expiration_minutes',
        'مدت انقضای سفارش فروش سفارشی (دقیقه)',
        'wc_suf_render_custom_sale_expiration_setting_field',
        'general'
    );
}
add_action( 'admin_init', 'wc_suf_register_custom_sale_expiration_setting' );

/**
 * Render admin setting field.
 *
 * @return void
 */
function wc_suf_render_custom_sale_expiration_setting_field() {
    $value = wc_suf_get_custom_sale_expiration_minutes();
    echo '<input type="number" min="1" step="1" id="wc_suf_custom_sale_expiration_minutes" name="wc_suf_custom_sale_expiration_minutes" value="' . esc_attr( $value ) . '" class="small-text" />';
    echo '<p class="description">پس از این مدت، سفارش‌های فروش سفارشیِ در انتظار پرداخت به‌صورت خودکار لغو می‌شوند.</p>';
}

/**
 * Add a 5-minute cron interval.
 *
 * @param array<string,array<string,mixed>> $schedules
 * @return array<string,array<string,mixed>>
 */
function wc_suf_add_five_minute_cron_schedule( $schedules ) {
    if ( ! isset( $schedules['wc_suf_every_five_minutes'] ) ) {
        $schedules['wc_suf_every_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 Minutes (WC SUF)',
        ];
    }
    return $schedules;
}
add_filter( 'cron_schedules', 'wc_suf_add_five_minute_cron_schedule' );

/**
 * Schedule recurring expiration job.
 *
 * @return void
 */
function wc_suf_schedule_custom_sales_expiration_cron() {
    if ( ! wp_next_scheduled( 'wc_suf_expire_custom_sales_orders_event' ) ) {
        wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wc_suf_every_five_minutes', 'wc_suf_expire_custom_sales_orders_event' );
    }
}

/**
 * Unschedule recurring expiration job.
 *
 * @return void
 */
function wc_suf_unschedule_custom_sales_expiration_cron() {
    $timestamp = wp_next_scheduled( 'wc_suf_expire_custom_sales_orders_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wc_suf_expire_custom_sales_orders_event' );
    }
}

register_activation_hook( WC_SUF_PLUGIN_FILE, 'wc_suf_schedule_custom_sales_expiration_cron' );
register_deactivation_hook( WC_SUF_PLUGIN_FILE, 'wc_suf_unschedule_custom_sales_expiration_cron' );

/**
 * Ensure cron remains scheduled.
 *
 * @return void
 */
function wc_suf_ensure_custom_sales_expiration_cron() {
    wc_suf_schedule_custom_sales_expiration_cron();
}
add_action( 'plugins_loaded', 'wc_suf_ensure_custom_sales_expiration_cron' );

/**
 * Expire overdue custom sales orders.
 *
 * @return void
 */
function wc_suf_expire_custom_sales_orders() {
    if ( ! function_exists( 'wc_get_orders' ) ) {
        return;
    }

    $order_ids = wc_get_orders(
        [
            'limit'      => 100,
            'status'     => 'pending',
            'type'       => 'shop_order',
            'return'     => 'ids',
            'meta_key'   => '_is_custom_sales_order',
            'meta_value' => 'yes',
        ]
    );

    if ( empty( $order_ids ) ) {
        return;
    }

    global $wpdb;
    $audit_table   = $wpdb->prefix . 'stock_audit';
    $pending_table = $wpdb->prefix . 'custom_sales_pending_items';
    $now_ts        = time();
    $now_mysql     = current_time( 'mysql' );

    foreach ( $order_ids as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->has_status( 'pending' ) ) {
            continue;
        }

        if ( 'yes' !== $order->get_meta( '_is_custom_sales_order', true ) ) {
            continue;
        }
        if ( 'yes' === $order->get_meta( '_wc_suf_auto_expired', true ) ) {
            continue;
        }

        $expiration_ts = (int) $order->get_meta( '_expiration_timestamp', true );
        if ( $expiration_ts <= 0 ) {
            continue;
        }
        if ( $expiration_ts > $now_ts ) {
            continue;
        }

        $order_number = (string) $order->get_order_number();
        $user_id      = (int) $order->get_meta( '_wc_suf_seller_id', true );
        $user_login   = (string) $order->get_meta( '_wc_suf_seller_name', true );

        $items = $order->get_items( 'line_item' );
        if ( ! empty( $items ) ) {
            foreach ( $items as $item ) {
                if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
                    continue;
                }
                $product_id = (int) $item->get_variation_id();
                if ( $product_id <= 0 ) {
                    $product_id = (int) $item->get_product_id();
                }
                if ( $product_id <= 0 ) {
                    continue;
                }

                $qty = (float) $item->get_quantity();
                if ( $qty <= 0 ) {
                    continue;
                }

                $product = wc_get_product( $product_id );
                $product_name = $product ? wc_suf_full_product_label( $product ) : (string) $item->get_name();

                $wpdb->insert(
                    $audit_table,
                    [
                        'batch_code'   => $order_number,
                        'op_type'      => 'sale_cancel',
                        'purpose'      => 'لغو خودکار سفارش و بازگشت موجودی به انبار',
                        'print_label'  => 0,
                        'product_id'   => $product_id,
                        'product_name' => $product_name,
                        'old_qty'      => null,
                        'added_qty'    => $qty,
                        'new_qty'      => null,
                        'user_id'      => $user_id > 0 ? $user_id : null,
                        'user_login'   => $user_login !== '' ? $user_login : null,
                        'user_code'    => $order_number,
                        'ip'           => '',
                        'created_at'   => $now_mysql,
                    ],
                    [ '%s', '%s', '%s', '%d', '%d', '%s', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s' ]
                );
            }
        }

        $order->update_status( 'cancelled', 'لغو خودکار سفارش و بازگشت موجودی به انبار' );
        wc_increase_stock_levels( $order->get_id() );

        $wpdb->update(
            $pending_table,
            [
                'allocated_qty' => 0,
                'pending_qty'   => 0,
            ],
            [
                'order_id' => (int) $order->get_id(),
            ],
            [ '%d', '%d' ],
            [ '%d' ]
        );

        $order->update_meta_data( '_wc_suf_auto_expired', 'yes' );
        $order->save_meta_data();
    }
}
add_action( 'wc_suf_expire_custom_sales_orders_event', 'wc_suf_expire_custom_sales_orders' );
