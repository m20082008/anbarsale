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
 * Get base URL for seller orders screen.
 *
 * @return string
 */
function wc_suf_get_seller_orders_base_url() {
    if ( is_admin() ) {
        return admin_url( 'admin.php' );
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    $current_url = home_url( $request_uri );

    return remove_query_arg(
        [ 'wc_suf_front_seller_orders', 'page', 'action', 'order_id', 'updated', 'completion', 'remaining', 'fifo', 'done_count' ],
        $current_url
    );
}

/**
 * Build seller orders URL for current context.
 *
 * @param array $args
 * @return string
 */
function wc_suf_get_seller_orders_url( $args = [] ) {
    if ( is_admin() ) {
        $args = array_merge( [ 'page' => 'wc-suf-seller-orders' ], (array) $args );
    } else {
        $args = array_merge( [ 'wc_suf_front_seller_orders' => '1' ], (array) $args );
    }

    return add_query_arg( $args, wc_suf_get_seller_orders_base_url() );
}

/**
 * Resolve redirect URL after admin-post handlers.
 *
 * @return string
 */
function wc_suf_get_seller_orders_redirect_base_url_from_post() {
    $posted_url = isset( $_POST['wc_suf_return_url'] ) ? esc_url_raw( wp_unslash( $_POST['wc_suf_return_url'] ) ) : '';
    if ( '' !== $posted_url ) {
        return $posted_url;
    }

    return wc_suf_get_seller_orders_url();
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
    $redirect_base_url = wc_suf_get_seller_orders_redirect_base_url_from_post();

    $order = wc_get_order( $order_id );
    if ( ! wc_suf_current_user_can_manage_seller_order( $order ) ) {
        wp_die( esc_html__( 'Permission denied.', 'wc-suf' ) );
    }

    if ( ! $order->has_status( [ 'pending', 'processing' ] ) ) {
        wp_safe_redirect(
            add_query_arg(
                [
                    'updated' => '0',
                ],
                $redirect_base_url
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
    if ( 'add_product' === $submit_type ) {
        $new_product_id = isset( $_POST['new_product_id'] ) ? absint( wp_unslash( $_POST['new_product_id'] ) ) : 0;
        $new_product_qty = isset( $_POST['new_product_qty'] ) ? absint( wp_unslash( $_POST['new_product_qty'] ) ) : 0;

        if ( $new_product_id > 0 && $new_product_qty > 0 ) {
            $new_product = wc_get_product( $new_product_id );
            if ( $new_product ) {
                $order->add_product( $new_product, $new_product_qty );
                $order->calculate_totals( true );
                $order->save();
            }
        }
    } elseif ( 'final' === $submit_type ) {
        $order->update_status( 'processing', 'ثبت نهایی توسط فروشنده.' );
    }

    wp_safe_redirect(
        add_query_arg(
            [
                'action'   => 'edit',
                'order_id' => $order_id,
                'updated'  => '1',
            ],
            $redirect_base_url
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
    $redirect_base_url = wc_suf_get_seller_orders_redirect_base_url_from_post();

    $order = wc_get_order( $order_id );
    if ( ! wc_suf_current_user_can_manage_seller_order( $order ) ) {
        wp_die( esc_html__( 'Permission denied.', 'wc-suf' ) );
    }
    if ( ! $order->has_status( 'pending' ) ) {
        wp_safe_redirect(
            add_query_arg(
                [
                    'updated' => '0',
                ],
                $redirect_base_url
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

    wp_safe_redirect( add_query_arg( $args, $redirect_base_url ) );
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
    $redirect_base_url = wc_suf_get_seller_orders_redirect_base_url_from_post();

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
        'fifo' => ( empty( $partial_lines ) ? 'done' : 'partial' ),
        'done_count' => $done_count,
    ];
    if ( ! empty( $partial_lines ) ) {
        $args['remaining'] = rawurlencode( implode( ' | ', array_slice( array_unique( $partial_lines ), 0, 20 ) ) );
    }

    wp_safe_redirect( add_query_arg( $args, $redirect_base_url ) );
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
    $return_url = wc_suf_get_seller_orders_url();

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
        echo '<input type="hidden" name="wc_suf_return_url" value="' . esc_url( $return_url ) . '" />';

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
            $products_for_add = wc_get_products(
                [
                    'status' => 'publish',
                    'limit'  => 150,
                    'type'   => [ 'simple', 'variation' ],
                    'return' => 'objects',
                ]
            );
            $products_picker_items = [];
            foreach ( (array) $products_for_add as $product_for_add ) {
                if ( ! $product_for_add || ! is_a( $product_for_add, 'WC_Product' ) ) {
                    continue;
                }
                $products_picker_items[] = [
                    'id'     => (int) $product_for_add->get_id(),
                    'label'  => wc_suf_full_product_label( $product_for_add ),
                    'search' => wc_suf_build_search_blob( $product_for_add ),
                    'attrs'  => wc_suf_collect_product_attributes_for_picker( $product_for_add ),
                ];
            }
            $picker_attr_defs = wc_suf_get_picker_attribute_defs();
            echo '<div style="margin-top:14px; padding:10px; border:1px solid #d1fae5; background:#f0fdf4; border-radius:8px; max-width:900px">';
            echo '<strong style="display:block; margin-bottom:8px">افزودن محصول جدید به سفارش</strong>';
            echo '<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">';
            echo '<input type="hidden" name="new_product_id" id="wc-suf-order-new-product-id" value="" />';
            echo '<input type="hidden" name="new_product_qty" id="wc-suf-order-new-product-qty" value="1" />';
            echo '<button type="button" class="button" id="wc-suf-order-open-picker" style="background:#16a34a;border-color:#15803d;color:#fff">➕ اضافه کردن محصولات</button>';
            submit_button( '✅ ثبت محصول انتخاب‌شده', 'secondary', 'submit_type', false, [ 'value' => 'add_product', 'id' => 'wc-suf-order-submit-add-product', 'style' => 'display:none;background:#16a34a;border-color:#15803d;color:#fff;' ] );
            echo '<span id="wc-suf-order-picked-product" style="font-weight:600;color:#065f46;"></span>';
            echo '</div>';
            echo '</div>';

            echo '<div class="wc-suf-order-modal-overlay" id="wc-suf-order-modal-overlay" style="position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000000; display:none;" aria-hidden="true"></div>';
            echo '<div class="wc-suf-order-modal" id="wc-suf-order-modal" style="position:fixed; inset:0; z-index:1000001; display:none; align-items:center; justify-content:center; padding:18px;" aria-hidden="true" role="dialog" aria-modal="true">';
            echo '<div style="width:min(980px,96vw); max-height:88vh; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,.25); display:flex; flex-direction:column;">';
            echo '<div style="padding:12px 14px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:10px; background:#f9fafb;">';
            echo '<div>';
            echo '<div style="font-weight:800;">انتخاب محصولات (جستجو + فیلتر ویژگی‌ها)</div>';
            echo '<div id="wc-suf-order-modal-subtitle" style="color:#6b7280; font-size:12px;">ابتدا جستجو کنید، سپس در صورت نیاز از فیلتر ویژگی‌ها استفاده کنید. محصول را انتخاب و «اضافه کن» را بزنید.</div>';
            echo '</div>';
            echo '<button type="button" id="wc-suf-order-close-picker" style="border:1px solid #ef4444; background:#ef4444; color:#fff; border-radius:10px; padding:6px 10px; cursor:pointer; font-weight:900; line-height:1;">✕</button>';
            echo '</div>';
            echo '<div style="padding:12px 14px; overflow:auto;">';
            echo '<div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap; margin-bottom:10px">';
            echo '<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; flex:1; min-width:260px">';
            echo '<label for="wc-suf-order-picker-q" style="min-width:80px; font-weight:700">جستجو:</label>';
            echo '<input id="wc-suf-order-picker-q" type="text" placeholder="مثلاً توران مربع / زرشکی / کد کالا یا ID" style="flex:1; min-width:260px; padding:10px; border:1px solid #e5e7eb; border-radius:12px; font-size:16px">';
            echo '<button type="button" id="wc-suf-order-picker-clear" aria-label="پاک کردن جستجو" title="پاک کردن" style="width:44px; height:44px; display:inline-flex; align-items:center; justify-content:center; padding:0; border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:12px; cursor:pointer; font-size:18px; font-weight:800">✕</button>';
            echo '</div>';
            echo '<div style="width:100%; margin-top:8px">';
            echo '<div id="wc-suf-order-picker-filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:10px;"></div>';
            echo '</div>';
            echo '</div>';
            echo '<div style="font-size:12px; color:#64748b; margin-bottom:8px;">نکته: جستجو نام + ورییشن (ویژگی‌ها) را پوشش می‌دهد. فیلترها همزمان با جستجو اعمال می‌شوند.</div>';
            echo '<div id="wc-suf-order-picker-results" style="border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; max-height:380px; overflow-y:auto;"></div>';
            echo '</div>';
            echo '<div style="padding:12px 14px; border-top:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:12px; background:#f9fafb; flex-wrap:wrap;">';
            echo '<div id="wc-suf-order-picker-selected-info" style="color:#6b7280;">هیچ موردی انتخاب نشده است.</div>';
            echo '<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">';
            echo '<label for="wc-suf-order-picker-qty" style="font-weight:700;">تعداد:</label>';
            echo '<input id="wc-suf-order-picker-qty" type="number" min="1" step="1" value="1" style="width:90px; padding:8px; border:1px solid #d1d5db; border-radius:8px;">';
            echo '<button type="button" id="wc-suf-order-picker-add" style="padding:12px 16px; cursor:pointer; border:1px solid #10b981; border-radius:12px; background:#10b981; color:#fff; font-weight:800">✅ اضافه کن</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';

            echo '<p style="margin-top:16px">';
            submit_button( 'ذخیره', 'secondary', 'submit_type', false, [ 'value' => 'save', 'style' => 'margin-left:8px;' ] );
            submit_button( 'ثبت نهایی', 'primary', 'submit_type', false, [ 'value' => 'final' ] );
            echo '</p>';
        }

        echo '<p><a class="button" href="' . esc_url( $return_url ) . '">بازگشت به لیست سفارش‌ها</a></p>';
        echo '</form>';
        if ( $order->has_status( 'pending' ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
            wp_nonce_field( 'wc_suf_seller_complete_order_' . $order_id );
            echo '<input type="hidden" name="action" value="wc_suf_seller_complete_order" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';
            echo '<input type="hidden" name="wc_suf_return_url" value="' . esc_url( $return_url ) . '" />';
            echo '<button type="submit" class="button button-primary wc-suf-complete-order-btn">تکمیل سفارش</button>';
            echo '</form>';
        }
        if ( $order->has_status( [ 'pending', 'processing' ] ) ) {
            echo '<script>';
            echo 'jQuery(function($){';
            echo 'const pickerProducts = ' . wp_json_encode( $products_picker_items ) . ';';
            echo 'const pickerAttrDefs = ' . wp_json_encode( $picker_attr_defs ) . ';';
            echo 'const $overlay = $("#wc-suf-order-modal-overlay");';
            echo 'const $modal = $("#wc-suf-order-modal");';
            echo 'const $results = $("#wc-suf-order-picker-results");';
            echo 'const $search = $("#wc-suf-order-picker-q");';
            echo 'const $clear = $("#wc-suf-order-picker-clear");';
            echo 'const $filters = $("#wc-suf-order-picker-filters");';
            echo 'const $qty = $("#wc-suf-order-picker-qty");';
            echo 'const $info = $("#wc-suf-order-picker-selected-info");';
            echo 'const $pickedLabel = $("#wc-suf-order-picked-product");';
            echo 'const $newProductId = $("#wc-suf-order-new-product-id");';
            echo 'const $newProductQty = $("#wc-suf-order-new-product-qty");';
            echo 'const $submitAdd = $("#wc-suf-order-submit-add-product");';
            echo 'let selectedProduct = null;';
            echo 'const activeFilters = Object.create(null);';
            echo 'function esc(v){return String(v).replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;").replaceAll(\'"\',"&quot;").replaceAll("\'","&#039;");}';
            echo 'function normalize(v){return String(v||"").toLowerCase().trim().replace(/[۰-۹]/g,d=>"۰۱۲۳۴۵۶۷۸۹".indexOf(d)).replace(/[٠-٩]/g,d=>"٠١٢٣٤٥٦٧٨٩".indexOf(d));}';
            echo 'function buildAttributeFilters(){if(!Array.isArray(pickerAttrDefs)||!pickerAttrDefs.length){$filters.empty();return;}const optionsByTax=Object.create(null);pickerProducts.forEach(function(p){const attrs=p&&p.attrs?p.attrs:null;if(!attrs||typeof attrs!=="object"){return;}pickerAttrDefs.forEach(function(def){if(!def||!def.tax){return;}const tax=String(def.tax);const vals=attrs[tax];if(!Array.isArray(vals)||!vals.length){return;}if(!optionsByTax[tax]){optionsByTax[tax]=Object.create(null);}vals.forEach(function(raw){const text=String(raw||"").trim();const key=normalize(text);if(!text||!key){return;}optionsByTax[tax][key]=text;});});});$filters.empty();pickerAttrDefs.forEach(function(def){if(!def||!def.tax){return;}const tax=String(def.tax);const label=String(def.label||tax);const bag=optionsByTax[tax];if(!bag){return;}const keys=Object.keys(bag).sort(function(a,b){return a.localeCompare(b,"fa");});if(!keys.length){return;}const id="wc-suf-order-filter-"+tax.replace(/[^a-z0-9_]/gi,"_");let html="<div><label for=\\""+esc(id)+"\\" style=\\"display:block;font-size:12px;font-weight:700;margin-bottom:6px;\\">"+esc(label)+"</label><select id=\\""+esc(id)+"\\" data-tax=\\""+esc(tax)+"\\" style=\\"width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:10px;\\"><option value=\\"\\">همه</option>";keys.forEach(function(k){html += "<option value=\\""+esc(k)+"\\">"+esc(bag[k])+"</option>";});html += "</select></div>";$filters.append(html);});};';
            echo 'function productMatchesFilters(p){for(const tax in activeFilters){if(!Object.prototype.hasOwnProperty.call(activeFilters,tax)){continue;}const sel=String(activeFilters[tax]||"");if(!sel){continue;}const attrs=p&&p.attrs?p.attrs:null;if(!attrs||typeof attrs!=="object"){return false;}const vals=attrs[tax];if(!Array.isArray(vals)||!vals.length){return false;}let ok=false;for(let i=0;i<vals.length;i++){if(normalize(vals[i])===sel){ok=true;break;}}if(!ok){return false;}}return true;}';
            echo 'function renderResults(){const q=normalize($search.val());const tokens=q.split(/\\s+/).map(function(t){return t.trim();}).filter(Boolean);let html="";const rows=pickerProducts.filter(function(p){const hay=normalize((p.search||p.label||"")+" "+p.id);const textOk=!tokens.length || tokens.every(function(t){return hay.includes(t);});return textOk && productMatchesFilters(p);}).slice(0,120);if(!rows.length){$results.html("<div style=\"padding:12px; color:#6b7280;\">موردی پیدا نشد.</div>");return;}rows.forEach(function(p){const active=selectedProduct && String(selectedProduct.id)===String(p.id);html += "<button type=\"button\" class=\"wc-suf-order-picker-row\" data-id=\""+esc(p.id)+"\" style=\"display:block;width:100%;text-align:right;border:0;border-bottom:1px solid #f1f5f9;padding:10px 12px;background:"+(active?"#ecfdf5":"#fff")+";cursor:pointer;\"><strong>"+esc(p.label)+"</strong> <span style=\"color:#6b7280\">(#"+esc(p.id)+")</span></button>";});$results.html(html);}';
            echo 'function openModal(){buildAttributeFilters();renderResults();$overlay.show();$modal.css("display","flex");$search.trigger("focus");}';
            echo 'function closeModal(){$overlay.hide();$modal.hide();}';
            echo '$("#wc-suf-order-open-picker").on("click", openModal);';
            echo '$("#wc-suf-order-close-picker").on("click", closeModal);';
            echo '$overlay.on("click", closeModal);';
            echo '$search.on("input", renderResults);';
            echo '$clear.on("click",function(){$search.val("");$filters.find("select[data-tax]").val("");for(const k in activeFilters){if(Object.prototype.hasOwnProperty.call(activeFilters,k)){activeFilters[k]="";}}renderResults();$search.trigger("focus");});';
            echo '$filters.on("change","select[data-tax]",function(){const tax=String($(this).data("tax")||"");if(!tax){return;}activeFilters[tax]=String($(this).val()||"");renderResults();});';
            echo '$results.on("click",".wc-suf-order-picker-row",function(){const pid=$(this).data("id");selectedProduct=pickerProducts.find(p=>String(p.id)===String(pid))||null;renderResults();if(selectedProduct){$info.text("محصول انتخاب‌شده: "+selectedProduct.label+" (#"+selectedProduct.id+")");}});';
            echo '$("#wc-suf-order-picker-add").on("click",function(){if(!selectedProduct){window.alert("ابتدا یک محصول انتخاب کنید.");return;}const qty=Math.max(1,parseInt($qty.val(),10)||1);$newProductId.val(selectedProduct.id);$newProductQty.val(qty);$pickedLabel.text("انتخاب شد: "+selectedProduct.label+" | تعداد: "+qty);$submitAdd.show();closeModal();});';
            echo '$(document).on("keydown",function(e){if($modal.is(":visible") && e.key==="Escape"){closeModal();}});';
            echo '});';
            echo '</script>';
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
    echo '<input type="hidden" name="wc_suf_return_url" value="' . esc_url( $return_url ) . '" />';
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
            $edit_url = wc_suf_get_seller_orders_url(
                [
                    'action'   => 'edit',
                    'order_id' => $order->get_id(),
                ]
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
                    echo '<input type="hidden" name="wc_suf_return_url" value="' . esc_url( $return_url ) . '" />';
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
