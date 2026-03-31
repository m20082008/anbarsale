<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Build front URL for seller orders page.
 *
 * @param array $args
 * @return string
 */
function wc_suf_front_seller_orders_url( $args = [] ) {
    $base = remove_query_arg(
        [ 'wc_suf_seller_orders', 'action', 'order_id', 'updated', 'added' ],
        add_query_arg( [] )
    );

    $defaults = [ 'wc_suf_seller_orders' => '1' ];

    return add_query_arg( array_merge( $defaults, (array) $args ), $base );
}

/**
 * Handle front seller order quantity update.
 *
 * @return void
 */
function wc_suf_handle_front_seller_order_update() {
    if ( ! is_user_logged_in() || ! wc_suf_current_user_is_pos_manager() ) {
        wp_die( esc_html__( 'Unauthorized.', 'wc-suf' ) );
    }

    $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
    if ( ! $order_id ) {
        wp_die( esc_html__( 'Invalid order.', 'wc-suf' ) );
    }

    check_admin_referer( 'wc_suf_front_seller_order_update_' . $order_id );

    $order = wc_get_order( $order_id );
    if ( ! wc_suf_current_user_can_manage_seller_order( $order ) ) {
        wp_die( esc_html__( 'Permission denied.', 'wc-suf' ) );
    }

    if ( ! $order->has_status( [ 'pending', 'processing' ] ) ) {
        wp_safe_redirect( wc_suf_front_seller_orders_url() );
        exit;
    }

    $posted_qty = isset( $_POST['item_qty'] ) ? (array) wp_unslash( $_POST['item_qty'] ) : [];
    foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
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

    wp_safe_redirect(
        wc_suf_front_seller_orders_url(
            [
                'action'   => 'edit',
                'order_id' => $order_id,
                'updated'  => '1',
            ]
        )
    );
    exit;
}
add_action( 'admin_post_wc_suf_front_seller_save_order', 'wc_suf_handle_front_seller_order_update' );

/**
 * Handle adding new items from front edit order popup.
 *
 * @return void
 */
function wc_suf_handle_front_seller_add_items() {
    if ( ! is_user_logged_in() || ! wc_suf_current_user_is_pos_manager() ) {
        wp_die( esc_html__( 'Unauthorized.', 'wc-suf' ) );
    }

    $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
    if ( ! $order_id ) {
        wp_die( esc_html__( 'Invalid order.', 'wc-suf' ) );
    }

    check_admin_referer( 'wc_suf_front_seller_add_items_' . $order_id );

    $order = wc_get_order( $order_id );
    if ( ! wc_suf_current_user_can_manage_seller_order( $order ) ) {
        wp_die( esc_html__( 'Permission denied.', 'wc-suf' ) );
    }

    if ( ! $order->has_status( [ 'pending', 'processing' ] ) ) {
        wp_safe_redirect( wc_suf_front_seller_orders_url() );
        exit;
    }

    $posted_qty = isset( $_POST['new_item_qty'] ) ? (array) wp_unslash( $_POST['new_item_qty'] ) : [];
    foreach ( $posted_qty as $product_id_raw => $qty_raw ) {
        $product_id = absint( $product_id_raw );
        $qty = absint( $qty_raw );
        if ( $product_id <= 0 || $qty <= 0 ) {
            continue;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
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
            $line_item->set_quantity( (int) $line_item->get_quantity() + $qty );
            $line_item->save();
        } else {
            $order->add_product( $product, $qty );
        }
    }

    $order->calculate_totals( true );
    $order->save();

    wp_safe_redirect(
        wc_suf_front_seller_orders_url(
            [
                'action'   => 'edit',
                'order_id' => $order_id,
                'added'    => '1',
            ]
        )
    );
    exit;
}
add_action( 'admin_post_wc_suf_front_seller_add_items', 'wc_suf_handle_front_seller_add_items' );

/**
 * Render seller orders in front area.
 *
 * @return string
 */
function wc_suf_render_front_seller_orders_page() {
    wc_suf_enqueue_front_assets();

    if ( ! is_user_logged_in() ) {
        return '<div dir="rtl" style="color:#b91c1c">برای مشاهده سفارش‌ها باید وارد شوید.</div>';
    }
    if ( ! wc_suf_current_user_is_pos_manager() ) {
        return '<div dir="rtl" style="color:#b91c1c">شما دسترسی لازم برای مشاهده سفارش‌ها را ندارید.</div>';
    }

    $current_user_id = get_current_user_id();
    $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
    $order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
    $updated = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';
    $added = isset( $_GET['added'] ) ? sanitize_text_field( wp_unslash( $_GET['added'] ) ) : '';

    ob_start();
    echo '<div dir="rtl" style="display:grid; gap:12px">';
    echo '<h3 style="margin:0">📋 سفارش‌های فروش من</h3>';

    if ( '1' === $updated ) {
        echo '<div style="padding:10px 12px; border:1px solid #86efac; background:#f0fdf4; border-radius:10px">تغییرات سفارش ذخیره شد.</div>';
    }
    if ( '1' === $added ) {
        echo '<div style="padding:10px 12px; border:1px solid #93c5fd; background:#eff6ff; border-radius:10px">محصولات جدید به سفارش اضافه شدند.</div>';
    }

    if ( 'edit' === $action && $order_id > 0 ) {
        $order = wc_get_order( $order_id );
        if ( ! wc_suf_current_user_can_manage_seller_order( $order ) ) {
            echo '<div style="padding:10px 12px; border:1px solid #fca5a5; background:#fef2f2; border-radius:10px">شما دسترسی ویرایش این سفارش را ندارید.</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $products = wc_get_products(
            [
                'status' => 'publish',
                'limit'  => -1,
                'type'   => [ 'simple', 'variation' ],
                'return' => 'objects',
            ]
        );
        $picker_rows = [];
        foreach ( $products as $p ) {
            $label = $p->get_name();
            if ( $p->is_type( 'variation' ) ) {
                $parent = wc_get_product( $p->get_parent_id() );
                $base   = $parent ? $parent->get_name() : ( 'Variation #' . $p->get_id() );
                $attrs  = trim( wp_strip_all_tags( (string) wc_get_formatted_variation( $p, true, false, false ) ) );
                $label  = trim( $base . ( $attrs ? ' – ' . $attrs : '' ) );
            }
            $picker_rows[] = [
                'id'    => (int) $p->get_id(),
                'label' => $label,
                'stock' => (int) ( $p->get_stock_quantity() ?? 0 ),
            ];
        }

        echo '<div style="padding:12px; border:1px solid #e5e7eb; border-radius:12px; background:#fff">';
        echo '<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap">';
        echo '<h4 style="margin:0">ویرایش سفارش #' . esc_html( $order->get_order_number() ) . '</h4>';
        echo '<a class="button" href="' . esc_url( wc_suf_front_seller_orders_url() ) . '">بازگشت به لیست</a>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
        wp_nonce_field( 'wc_suf_front_seller_order_update_' . $order_id );
        echo '<input type="hidden" name="action" value="wc_suf_front_seller_save_order" />';
        echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';

        echo '<table style="width:100%; border-collapse:collapse; border:1px solid #e5e7eb">';
        echo '<thead><tr style="background:#f9fafb"><th style="padding:8px; text-align:right">محصول</th><th style="padding:8px; width:130px">تعداد</th></tr></thead><tbody>';
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
                continue;
            }
            echo '<tr style="border-top:1px solid #e5e7eb">';
            echo '<td style="padding:8px">' . esc_html( $item->get_name() ) . '</td>';
            echo '<td style="padding:8px"><input type="number" min="0" step="1" name="item_qty[' . esc_attr( $item_id ) . ']" value="' . esc_attr( (int) $item->get_quantity() ) . '" style="width:90px; text-align:center"></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<div style="margin-top:12px"><button type="submit" class="button button-primary">ذخیره تغییرات</button></div>';
        echo '</form>';

        if ( $order->has_status( [ 'pending', 'processing' ] ) ) {
            echo '<div style="margin-top:12px">';
            echo '<button type="button" id="wc-suf-front-open-picker" class="button" style="background:#16a34a;border-color:#15803d;color:#fff">➕ اضافه کردن محصولات</button>';
            echo '</div>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="wc-suf-front-add-form" style="display:none">';
            wp_nonce_field( 'wc_suf_front_seller_add_items_' . $order_id );
            echo '<input type="hidden" name="action" value="wc_suf_front_seller_add_items" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';
            echo '<div id="wc-suf-front-add-hidden"></div>';
            echo '</form>';

            echo '<div class="wc-suf-modal-overlay" id="wc-suf-front-modal-overlay" aria-hidden="true"></div>';
            echo '<div class="wc-suf-modal" id="wc-suf-front-modal" aria-hidden="true" role="dialog" aria-modal="true">';
            echo '<div class="wc-suf-modal-card">';
            echo '<div class="wc-suf-modal-head"><div><div class="wc-suf-modal-title">افزودن محصول به سفارش</div><div class="suf-muted">مشابه بخش فروش: محصول را جستجو کنید، تعداد بدهید و اضافه کنید.</div></div><button type="button" class="wc-suf-modal-close" id="wc-suf-front-modal-close">✕</button></div>';
            echo '<div class="wc-suf-modal-body">';
            echo '<div style="display:flex; gap:8px; margin-bottom:10px"><input id="wc-suf-front-picker-q" type="text" placeholder="جستجوی نام یا ID" style="flex:1; padding:10px; border:1px solid #e5e7eb; border-radius:10px"><button type="button" id="wc-suf-front-picker-clear" class="button">پاک</button></div>';
            echo '<div id="wc-suf-front-picker-results" style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden"></div>';
            echo '</div>';
            echo '<div class="wc-suf-modal-foot"><div class="suf-muted" id="wc-suf-front-picker-info">هیچ موردی انتخاب نشده است.</div><button type="button" id="wc-suf-front-picker-submit" class="button button-primary">✅ افزودن به سفارش</button></div>';
            echo '</div></div>';

            ?>
            <script>
            jQuery(function($){
                const products = <?php echo wp_json_encode( $picker_rows ); ?>;
                const selected = Object.create(null);
                const $overlay = $('#wc-suf-front-modal-overlay');
                const $modal = $('#wc-suf-front-modal');
                const $q = $('#wc-suf-front-picker-q');
                const $results = $('#wc-suf-front-picker-results');
                const $info = $('#wc-suf-front-picker-info');

                function escapeHtml(s){
                    return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
                }
                function norm(s){ return String(s || '').trim().toLowerCase(); }

                function render(){
                    const term = norm($q.val());
                    const list = products.filter(function(p){
                        if(!term) return true;
                        return norm(p.label).indexOf(term) !== -1 || String(p.id).indexOf(term) !== -1;
                    }).slice(0, 200);

                    if(!list.length){
                        $results.html('<div style="padding:10px">نتیجه‌ای یافت نشد.</div>');
                        return;
                    }

                    const rows = list.map(function(p){
                        const qty = selected[p.id] || 0;
                        return '<div class="wc-suf-picker-row">'
                            + '<div><div class="wc-suf-picker-name">' + escapeHtml(p.label) + '</div><div class="wc-suf-picker-meta">ID: ' + escapeHtml(p.id) + ' | موجودی: ' + escapeHtml(p.stock) + '</div></div>'
                            + '<div class="wc-suf-picker-qty"><button type="button" class="wc-suf-front-dec" data-id="' + escapeHtml(p.id) + '">➖</button><input type="number" min="0" class="wc-suf-front-qty" data-id="' + escapeHtml(p.id) + '" value="' + escapeHtml(qty) + '"><button type="button" class="wc-suf-front-inc" data-id="' + escapeHtml(p.id) + '">➕</button></div>'
                            + '</div>';
                    }).join('');
                    $results.html(rows);
                }

                function updateInfo(){
                    const count = Object.keys(selected).filter(function(id){ return (selected[id] || 0) > 0; }).length;
                    $info.text(count > 0 ? (count + ' محصول انتخاب شده است.') : 'هیچ موردی انتخاب نشده است.');
                }

                function openModal(){ $overlay.show(); $modal.css('display','flex'); render(); updateInfo(); }
                function closeModal(){ $overlay.hide(); $modal.hide(); }

                $('#wc-suf-front-open-picker').on('click', openModal);
                $('#wc-suf-front-modal-close, #wc-suf-front-modal-overlay').on('click', closeModal);
                $('#wc-suf-front-picker-clear').on('click', function(){ $q.val(''); render(); });
                $q.on('input', render);

                $(document).on('click', '.wc-suf-front-inc', function(){
                    const id = $(this).data('id');
                    selected[id] = (parseInt(selected[id] || 0, 10) || 0) + 1;
                    render(); updateInfo();
                });
                $(document).on('click', '.wc-suf-front-dec', function(){
                    const id = $(this).data('id');
                    selected[id] = Math.max(0, (parseInt(selected[id] || 0, 10) || 0) - 1);
                    render(); updateInfo();
                });
                $(document).on('input', '.wc-suf-front-qty', function(){
                    const id = $(this).data('id');
                    selected[id] = Math.max(0, parseInt($(this).val() || '0', 10) || 0);
                    updateInfo();
                });

                $('#wc-suf-front-picker-submit').on('click', function(){
                    const $hidden = $('#wc-suf-front-add-hidden').empty();
                    let hasAny = false;
                    Object.keys(selected).forEach(function(id){
                        const qty = parseInt(selected[id] || 0, 10) || 0;
                        if(qty <= 0) return;
                        hasAny = true;
                        $hidden.append('<input type="hidden" name="new_item_qty[' + id + ']" value="' + qty + '">');
                    });
                    if(!hasAny){
                        alert('حداقل یک محصول با تعداد بیشتر از صفر انتخاب کنید.');
                        return;
                    }
                    $('#wc-suf-front-add-form').trigger('submit');
                });
            });
            </script>
            <?php
        }

        echo '</div>';
        echo '</div>';
        return ob_get_clean();
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

    echo '<table style="width:100%; border-collapse:collapse; border:1px solid #e5e7eb; background:#fff">';
    echo '<thead><tr style="background:#f9fafb"><th style="padding:8px; text-align:right">شماره سفارش</th><th style="padding:8px; text-align:right">تاریخ</th><th style="padding:8px; text-align:right">وضعیت</th><th style="padding:8px; text-align:right">عملیات</th></tr></thead><tbody>';

    if ( empty( $orders ) ) {
        echo '<tr><td colspan="4" style="padding:10px">سفارشی برای نمایش یافت نشد.</td></tr>';
    } else {
        foreach ( $orders as $order ) {
            $edit_url = wc_suf_front_seller_orders_url(
                [
                    'action'   => 'edit',
                    'order_id' => $order->get_id(),
                ]
            );

            echo '<tr style="border-top:1px solid #e5e7eb">';
            echo '<td style="padding:8px">#' . esc_html( $order->get_order_number() ) . '</td>';
            echo '<td style="padding:8px">' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td>';
            echo '<td style="padding:8px">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
            echo '<td style="padding:8px"><a class="button" href="' . esc_url( $edit_url ) . '">ویرایش سفارش</a></td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';

    return ob_get_clean();
}

/**
 * Shortcode wrapper for front seller orders page.
 *
 * @return string
 */
function wc_suf_front_seller_orders_shortcode() {
    return wc_suf_render_front_seller_orders_page();
}
add_shortcode( 'wc_suf_seller_orders', 'wc_suf_front_seller_orders_shortcode' );
