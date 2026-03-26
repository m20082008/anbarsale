<?php

/**
 * Register seller orders admin page.
 *
 * @return void
 */
function wc_suf_register_seller_orders_admin_page() {
    add_submenu_page(
        'woocommerce',
        'سفارش‌های فروش من',
        'سفارش‌های فروش من',
        'read',
        'wc-suf-seller-orders',
        'wc_suf_render_seller_orders_admin_page'
    );
}
add_action( 'admin_menu', 'wc_suf_register_seller_orders_admin_page', 30 );

/**
 * Check if current user can manage given order.
 *
 * @param WC_Order $order
 * @return bool
 */
function wc_suf_current_user_can_manage_seller_order( $order ) {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return false;
    }
    if ( ! is_user_logged_in() || ! wc_suf_current_user_is_pos_manager() ) {
        return false;
    }

    $current_user_id = get_current_user_id();
    $seller_id = (int) $order->get_meta( '_wc_suf_seller_id', true );

    return ( $current_user_id > 0 && $seller_id === $current_user_id );
}

/**
 * Handle seller order save/final submit.
 *
 * @return void
 */
function wc_suf_handle_seller_order_update() {
    if ( ! is_user_logged_in() || ! wc_suf_current_user_is_pos_manager() ) {
        wp_die( esc_html__( 'Unauthorized.', 'wc-suf' ) );
    }

    $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
    if ( ! $order_id ) {
        wp_die( esc_html__( 'Invalid order.', 'wc-suf' ) );
    }

    check_admin_referer( 'wc_suf_seller_order_update_' . $order_id );

    $order = wc_get_order( $order_id );
    if ( ! wc_suf_current_user_can_manage_seller_order( $order ) ) {
        wp_die( esc_html__( 'Permission denied.', 'wc-suf' ) );
    }

    if ( ! $order->has_status( [ 'pending', 'processing' ] ) ) {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'    => 'wc-suf-seller-orders',
                    'updated' => '0',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    if ( $order->has_status( 'processing' ) ) {
        $order->set_status( 'pending', 'ویرایش فروشنده: بازگردانی وضعیت به در انتظار پرداخت.' );
    }

    $posted_qty = isset( $_POST['item_qty'] ) ? (array) wp_unslash( $_POST['item_qty'] ) : [];
    $items = $order->get_items( 'line_item' );
    foreach ( $items as $item_id => $item ) {
        $new_qty = isset( $posted_qty[ $item_id ] ) ? absint( $posted_qty[ $item_id ] ) : (int) $item->get_quantity();
        if ( $new_qty <= 0 ) {
            $order->remove_item( $item_id );
            continue;
        }
        $item->set_quantity( $new_qty );
        $item->save();
    }

    $order->calculate_totals( true );
    $order->save();

    $submit_type = isset( $_POST['submit_type'] ) ? sanitize_text_field( wp_unslash( $_POST['submit_type'] ) ) : 'save';
    if ( 'final' === $submit_type ) {
        $order->update_status( 'processing', 'ثبت نهایی توسط فروشنده.' );
    }

    wp_safe_redirect(
        add_query_arg(
            [
                'page'     => 'wc-suf-seller-orders',
                'action'   => 'edit',
                'order_id' => $order_id,
                'updated'  => '1',
            ],
            admin_url( 'admin.php' )
        )
    );
    exit;
}
add_action( 'admin_post_wc_suf_seller_save_order', 'wc_suf_handle_seller_order_update' );

/**
 * Handle seller "Complete Order" action for pending allocations.
 *
 * @return void
 */
function wc_suf_handle_seller_complete_order() {
    if ( ! is_user_logged_in() || ! wc_suf_current_user_is_pos_manager() ) {
        wp_die( esc_html__( 'Unauthorized.', 'wc-suf' ) );
    }

    $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
    if ( ! $order_id ) {
        wp_die( esc_html__( 'Invalid order.', 'wc-suf' ) );
    }

    check_admin_referer( 'wc_suf_seller_complete_order_' . $order_id );

    $order = wc_get_order( $order_id );
    if ( ! wc_suf_current_user_can_manage_seller_order( $order ) ) {
        wp_die( esc_html__( 'Permission denied.', 'wc-suf' ) );
    }
    if ( ! $order->has_status( 'pending' ) ) {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'    => 'wc-suf-seller-orders',
                    'updated' => '0',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    global $wpdb;
    $pending_table = $wpdb->prefix . 'custom_sales_pending_items';
    $audit_table   = $wpdb->prefix . 'stock_audit';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, product_id, variation_id, allocated_qty, pending_qty
             FROM `$pending_table`
             WHERE order_id = %d AND pending_qty > 0",
            $order_id
        )
    );

    $remaining_lines = [];
    $order_number = (string) $order->get_order_number();
    $user_id = get_current_user_id();
    $user_obj = wp_get_current_user();
    $user_name = trim( (string) ( $user_obj->display_name ?: $user_obj->user_login ) );
    $now_mysql = current_time( 'mysql' );

    foreach ( (array) $rows as $row ) {
        $pending_qty = (int) $row->pending_qty;
        if ( $pending_qty <= 0 ) {
            continue;
        }

        $variation_id = (int) $row->variation_id;
        $product_id = $variation_id > 0 ? $variation_id : (int) $row->product_id;
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            continue;
        }

        $stock_product = wc_suf_get_stock_product( $product );
        $available_qty = (int) max( 0, (int) ( $stock_product->get_stock_quantity() ?? 0 ) );
        $alloc_qty = min( $pending_qty, $available_qty );

        if ( $alloc_qty > 0 ) {
            $line_item_id = 0;
            foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
                if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
                    continue;
                }
                $item_variation_id = (int) $item->get_variation_id();
                $item_product_id = $item_variation_id > 0 ? $item_variation_id : (int) $item->get_product_id();
                if ( $item_product_id === $product_id ) {
                    $line_item_id = $item_id;
                    break;
                }
            }

            if ( $line_item_id > 0 ) {
                $line_item = $order->get_item( $line_item_id );
                $line_item->set_quantity( (int) $line_item->get_quantity() + $alloc_qty );
                $line_item->save();
            } else {
                $order->add_product( $product, $alloc_qty );
            }

            $stock_result = wc_update_product_stock( $stock_product, $alloc_qty, 'decrease' );
            if ( false !== $stock_result ) {
                $stock_product->save();
            }

            $new_pending = max( 0, $pending_qty - $alloc_qty );
            $wpdb->update(
                $pending_table,
                [
                    'allocated_qty' => (int) $row->allocated_qty + $alloc_qty,
                    'pending_qty'   => $new_pending,
                ],
                [ 'id' => (int) $row->id ],
                [ '%d', '%d' ],
                [ '%d' ]
            );

            $wpdb->insert(
                $audit_table,
                [
                    'batch_code'   => $order_number,
                    'op_type'      => 'sale_edit',
                    'purpose'      => 'تکمیل سفارش: تخصیص موجودی واقعی به آیتم در انتظار',
                    'print_label'  => 0,
                    'product_id'   => $product_id,
                    'product_name' => wc_suf_full_product_label( $product ),
                    'old_qty'      => $available_qty,
                    'added_qty'    => -1 * $alloc_qty,
                    'new_qty'      => max( 0, $available_qty - $alloc_qty ),
                    'user_id'      => $user_id ?: null,
                    'user_login'   => $user_name ?: null,
                    'user_code'    => $order_number,
                    'ip'           => '',
                    'created_at'   => $now_mysql,
                ],
                [ '%s','%s','%s','%d','%d','%s','%f','%f','%f','%d','%s','%s','%s','%s' ]
            );

            if ( $new_pending > 0 ) {
                $remaining_lines[] = wc_suf_full_product_label( $product ) . ' (مانده: ' . $new_pending . ')';
            }
        } else {
            $remaining_lines[] = wc_suf_full_product_label( $product ) . ' (مانده: ' . $pending_qty . ')';
        }
    }

    $order->calculate_totals( true );
    $order->save();

    $remaining_after = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(pending_qty), 0) FROM `$pending_table` WHERE order_id = %d",
            $order_id
        )
    );

    $args = [
        'page'     => 'wc-suf-seller-orders',
        'action'   => 'edit',
        'order_id' => $order_id,
    ];

    if ( (int) $remaining_after <= 0 ) {
        $order->update_status( 'processing', 'تکمیل سفارش توسط فروشنده و تخصیص کامل اقلام در انتظار.' );
        $args['completion'] = 'done';
    } else {
        $args['completion'] = 'partial';
        $args['remaining'] = rawurlencode( implode( ' | ', array_unique( $remaining_lines ) ) );
    }

    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_wc_suf_seller_complete_order', 'wc_suf_handle_seller_complete_order' );

/**
 * Handle seller global FIFO completion for all pending orders.
 *
 * @return void
 */
function wc_suf_handle_seller_complete_all_orders_fifo() {
    if ( ! is_user_logged_in() || ! wc_suf_current_user_is_pos_manager() ) {
        wp_die( esc_html__( 'Unauthorized.', 'wc-suf' ) );
    }

    check_admin_referer( 'wc_suf_seller_complete_all_orders_fifo' );

    $current_user_id = get_current_user_id();
    $user_obj = wp_get_current_user();
    $user_name = trim( (string) ( $user_obj->display_name ?: $user_obj->user_login ) );

    $orders = wc_get_orders(
        [
            'limit'      => 500,
            'orderby'    => 'ID',
            'order'      => 'ASC',
            'status'     => [ 'pending' ],
            'type'       => 'shop_order',
            'meta_key'   => '_wc_suf_seller_id',
            'meta_value' => $current_user_id,
            'return'     => 'ids',
        ]
    );

    global $wpdb;
    $pending_table = $wpdb->prefix . 'custom_sales_pending_items';
    $audit_table   = $wpdb->prefix . 'stock_audit';
    $now_mysql     = current_time( 'mysql' );
    $partial_lines = [];
    $done_count    = 0;

    foreach ( (array) $orders as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! wc_suf_current_user_can_manage_seller_order( $order ) || ! $order->has_status( 'pending' ) ) {
            continue;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, product_id, variation_id, allocated_qty, pending_qty
                 FROM `$pending_table`
                 WHERE order_id = %d AND pending_qty > 0
                 ORDER BY id ASC",
                $order_id
            )
        );
        if ( empty( $rows ) ) {
            continue;
        }

        $order_number = (string) $order->get_order_number();
        foreach ( $rows as $row ) {
            $pending_qty = (int) $row->pending_qty;
            if ( $pending_qty <= 0 ) {
                continue;
            }

            $variation_id = (int) $row->variation_id;
            $product_id = $variation_id > 0 ? $variation_id : (int) $row->product_id;
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $stock_product = wc_suf_get_stock_product( $product );
            $available_qty = (int) max( 0, (int) ( $stock_product->get_stock_quantity() ?? 0 ) );
            $alloc_qty = min( $pending_qty, $available_qty );
            if ( $alloc_qty <= 0 ) {
                $partial_lines[] = '#' . $order_number . ' - ' . wc_suf_full_product_label( $product ) . ' (مانده: ' . $pending_qty . ')';
                continue;
            }

            $line_item_id = 0;
            foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
                if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
                    continue;
                }
                $item_variation_id = (int) $item->get_variation_id();
                $item_product_id = $item_variation_id > 0 ? $item_variation_id : (int) $item->get_product_id();
                if ( $item_product_id === $product_id ) {
                    $line_item_id = $item_id;
                    break;
                }
            }
            if ( $line_item_id > 0 ) {
                $line_item = $order->get_item( $line_item_id );
                $line_item->set_quantity( (int) $line_item->get_quantity() + $alloc_qty );
                $line_item->save();
            } else {
                $order->add_product( $product, $alloc_qty );
            }

            $stock_result = wc_update_product_stock( $stock_product, $alloc_qty, 'decrease' );
            if ( false !== $stock_result ) {
                $stock_product->save();
            }

            $new_pending = max( 0, $pending_qty - $alloc_qty );
            $wpdb->update(
                $pending_table,
                [
                    'allocated_qty' => (int) $row->allocated_qty + $alloc_qty,
                    'pending_qty'   => $new_pending,
                ],
                [ 'id' => (int) $row->id ],
                [ '%d', '%d' ],
                [ '%d' ]
            );

            $wpdb->insert(
                $audit_table,
                [
                    'batch_code'   => $order_number,
                    'op_type'      => 'sale_edit',
                    'purpose'      => 'تکمیل کلی FIFO: تخصیص موجودی واقعی به آیتم در انتظار',
                    'print_label'  => 0,
                    'product_id'   => $product_id,
                    'product_name' => wc_suf_full_product_label( $product ),
                    'old_qty'      => $available_qty,
                    'added_qty'    => -1 * $alloc_qty,
                    'new_qty'      => max( 0, $available_qty - $alloc_qty ),
                    'user_id'      => $current_user_id ?: null,
                    'user_login'   => $user_name ?: null,
                    'user_code'    => $order_number,
                    'ip'           => '',
                    'created_at'   => $now_mysql,
                ],
                [ '%s','%s','%s','%d','%d','%s','%f','%f','%f','%d','%s','%s','%s','%s' ]
            );

            if ( $new_pending > 0 ) {
                $partial_lines[] = '#' . $order_number . ' - ' . wc_suf_full_product_label( $product ) . ' (مانده: ' . $new_pending . ')';
            }
        }

        $order->calculate_totals( true );
        $order->save();

        $remaining_after = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(pending_qty), 0) FROM `$pending_table` WHERE order_id = %d",
                $order_id
            )
        );
        if ( $remaining_after <= 0 ) {
            $order->update_status( 'processing', 'تکمیل کلی FIFO و تخصیص کامل اقلام در انتظار.' );
            $done_count++;
        }
    }

    $args = [
        'page' => 'wc-suf-seller-orders',
        'fifo' => ( empty( $partial_lines ) ? 'done' : 'partial' ),
        'done_count' => $done_count,
    ];
    if ( ! empty( $partial_lines ) ) {
        $args['remaining'] = rawurlencode( implode( ' | ', array_slice( array_unique( $partial_lines ), 0, 20 ) ) );
    }

    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_wc_suf_seller_complete_all_orders_fifo', 'wc_suf_handle_seller_complete_all_orders_fifo' );

/**
 * Render seller orders page.
 *
 * @return void
 */
function wc_suf_render_seller_orders_admin_page() {
    if ( ! current_user_can( 'read' ) || ! wc_suf_current_user_is_pos_manager() ) {
        echo '<div class="wrap"><h1>سفارش‌های فروش من</h1><p style="color:#b91c1c">شما دسترسی لازم را ندارید.</p></div>';
        return;
    }

    $current_user_id = get_current_user_id();
    $order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
    $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
    $updated = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';
    $completion = isset( $_GET['completion'] ) ? sanitize_text_field( wp_unslash( $_GET['completion'] ) ) : '';
    $remaining_msg = isset( $_GET['remaining'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['remaining'] ) ) ) : '';
    $fifo = isset( $_GET['fifo'] ) ? sanitize_text_field( wp_unslash( $_GET['fifo'] ) ) : '';
    $done_count = isset( $_GET['done_count'] ) ? absint( wp_unslash( $_GET['done_count'] ) ) : 0;

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">سفارش‌های فروش من</h1>';
    echo '<hr class="wp-header-end" />';

    if ( '1' === $updated ) {
        echo '<div class="notice notice-success is-dismissible"><p>سفارش با موفقیت ذخیره شد.</p></div>';
    }
    if ( 'done' === $completion ) {
        echo '<div class="notice notice-success is-dismissible"><p>تمام آیتم‌های در انتظار تخصیص داده شدند و سفارش به وضعیت در حال انجام بازگشت.</p></div>';
    } elseif ( 'partial' === $completion && '' !== $remaining_msg ) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>بخشی از آیتم‌ها همچنان در انتظار هستند:</strong> ' . esc_html( $remaining_msg ) . '</p></div>';
    }
    if ( 'done' === $fifo ) {
        echo '<div class="notice notice-success is-dismissible"><p>تکمیل کلی FIFO انجام شد. تعداد سفارش‌های تکمیل‌شده: ' . esc_html( $done_count ) . '</p></div>';
    } elseif ( 'partial' === $fifo && '' !== $remaining_msg ) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>تکمیل کلی FIFO انجام شد اما برخی آیتم‌ها هنوز در انتظار هستند:</strong> ' . esc_html( $remaining_msg ) . '</p></div>';
    }

    if ( 'edit' === $action && $order_id > 0 ) {
        $order = wc_get_order( $order_id );
        if ( ! wc_suf_current_user_can_manage_seller_order( $order ) ) {
            echo '<div class="notice notice-error"><p>شما دسترسی ویرایش این سفارش را ندارید.</p></div>';
            echo '</div>';
            return;
        }

        if ( $order->has_status( 'completed' ) ) {
            echo '<div class="notice notice-warning"><p>سفارش تکمیل‌شده قابل ویرایش نیست.</p></div>';
        }

        echo '<h2>ویرایش سفارش #' . esc_html( $order->get_order_number() ) . '</h2>';
        echo '<p><strong>وضعیت فعلی:</strong> ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'wc_suf_seller_order_update_' . $order_id );
        echo '<input type="hidden" name="action" value="wc_suf_seller_save_order" />';
        echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';

        echo '<table class="widefat striped" style="max-width:900px">';
        echo '<thead><tr><th>محصول</th><th style="width:140px">تعداد</th></tr></thead><tbody>';
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
                continue;
            }
            echo '<tr>';
            echo '<td>' . esc_html( $item->get_name() ) . '</td>';
            if ( $order->has_status( [ 'pending', 'processing' ] ) ) {
                echo '<td><input type="number" min="0" step="1" name="item_qty[' . esc_attr( $item_id ) . ']" value="' . esc_attr( (int) $item->get_quantity() ) . '" class="small-text" /></td>';
            } else {
                echo '<td>' . esc_html( (int) $item->get_quantity() ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ( $order->has_status( [ 'pending', 'processing' ] ) ) {
            echo '<p style="margin-top:16px">';
            submit_button( 'ذخیره', 'secondary', 'submit_type', false, [ 'value' => 'save', 'style' => 'margin-left:8px;' ] );
            submit_button( 'ثبت نهایی', 'primary', 'submit_type', false, [ 'value' => 'final' ] );
            echo '</p>';
        }

        echo '<p><a class="button" href="' . esc_url( add_query_arg( [ 'page' => 'wc-suf-seller-orders' ], admin_url( 'admin.php' ) ) ) . '">بازگشت به لیست سفارش‌ها</a></p>';
        echo '</form>';
        if ( $order->has_status( 'pending' ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
            wp_nonce_field( 'wc_suf_seller_complete_order_' . $order_id );
            echo '<input type="hidden" name="action" value="wc_suf_seller_complete_order" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';
            echo '<button type="submit" class="button button-primary wc-suf-complete-order-btn">تکمیل سفارش</button>';
            echo '</form>';
        }
        echo '<script>jQuery(function($){$(document).on("click",".wc-suf-complete-order-btn",function(e){if(!window.confirm("موجودی واقعی بررسی و آیتم‌های در انتظار تخصیص داده شوند؟")){e.preventDefault();}});});</script>';
        echo '</div>';
        return;
    }

    $orders = wc_get_orders(
        [
            'limit'      => 200,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'status'     => [ 'pending', 'processing', 'completed' ],
            'type'       => 'shop_order',
            'meta_key'   => '_wc_suf_seller_id',
            'meta_value' => $current_user_id,
        ]
    );

    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0 16px;">';
    wp_nonce_field( 'wc_suf_seller_complete_all_orders_fifo' );
    echo '<input type="hidden" name="action" value="wc_suf_seller_complete_all_orders_fifo" />';
    echo '<button type="submit" class="button wc-suf-complete-all-orders-btn" style="background:#dc2626;border-color:#dc2626;color:#fff">تکمیل کلی سفارش‌ها</button>';
    echo '</form>';

    echo '<table class="widefat striped">';
    echo '<thead><tr><th>شماره سفارش</th><th>تاریخ</th><th>وضعیت</th><th>اقلام</th><th>عملیات</th></tr></thead><tbody>';
    if ( empty( $orders ) ) {
        echo '<tr><td colspan="5">سفارشی برای نمایش یافت نشد.</td></tr>';
    } else {
        foreach ( $orders as $order ) {
            $can_edit = $order->has_status( [ 'pending', 'processing' ] );
            $items_count = count( $order->get_items( 'line_item' ) );
            $edit_url = add_query_arg(
                [
                    'page'     => 'wc-suf-seller-orders',
                    'action'   => 'edit',
                    'order_id' => $order->get_id(),
                ],
                admin_url( 'admin.php' )
            );

            echo '<tr>';
            echo '<td>#' . esc_html( $order->get_order_number() ) . '</td>';
            echo '<td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td>';
            echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
            echo '<td>' . esc_html( $items_count ) . '</td>';
            echo '<td>';
            if ( $can_edit ) {
                echo '<a class="button button-primary" href="' . esc_url( $edit_url ) . '">ویرایش</a>';
                if ( $order->has_status( 'pending' ) ) {
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block; margin-right:6px;">';
                    wp_nonce_field( 'wc_suf_seller_complete_order_' . $order->get_id() );
                    echo '<input type="hidden" name="action" value="wc_suf_seller_complete_order" />';
                    echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';
                    echo '<button type="submit" class="button wc-suf-complete-order-btn">تکمیل سفارش</button>';
                    echo '</form>';
                }
            } else {
                echo '<span class="button disabled" style="pointer-events:none;opacity:.6">قابل ویرایش نیست</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '<script>jQuery(function($){$(document).on("click",".wc-suf-complete-order-btn",function(e){if(!window.confirm("موجودی واقعی بررسی و آیتم‌های در انتظار تخصیص داده شوند؟")){e.preventDefault();}});$(document).on("click",".wc-suf-complete-all-orders-btn",function(e){if(!window.confirm("تخصیص کلی FIFO برای همه سفارش‌های در انتظار شما انجام شود؟")){e.preventDefault();}});});</script>';
    echo '</div>';
}
