<?php
/*--------------------------------------
| AJAX: ثبت نهایی (YITH POS)
---------------------------------------*/
add_action('wp_ajax_save_stock_update','wc_suf_save_stock_update_handler');
add_action('wp_ajax_wc_suf_sync_sale_hold_order','wc_suf_sync_sale_hold_order_handler');

function wc_suf_sync_sale_hold_order_handler(){
    check_ajax_referer('wc_suf_sync_sale_hold_order');

    if( ! wc_suf_current_user_is_pos_manager() ){
        wp_send_json_error(['message'=>'دسترسی غیرمجاز.']);
    }
    if ( ! function_exists( 'wc_create_order' ) ) {
        wp_send_json_error(['message'=>'ووکامرس فعال نیست.']);
    }

    $raw_items = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items = json_decode($raw_items, true);
    if ( ! is_array($items) ) {
        $items = [];
    }

    $op_type_in = isset($_POST['op_type']) ? sanitize_text_field( wp_unslash($_POST['op_type']) ) : '';
    $op_type = in_array($op_type_in, ['sale','sale_teh'], true) ? $op_type_in : '';
    if ( ! $op_type ) {
        wp_send_json_error(['message'=>'نوع عملیات فروش معتبر نیست.']);
    }

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $customer_name = isset($_POST['sale_customer_name']) ? sanitize_text_field( wp_unslash($_POST['sale_customer_name']) ) : '';
    $customer_mobile = isset($_POST['sale_customer_mobile']) ? sanitize_text_field( wp_unslash($_POST['sale_customer_mobile']) ) : '';
    $customer_mobile = preg_replace('/\D+/', '', wc_suf_normalize_digits( $customer_mobile ) );
    $customer_address = isset($_POST['sale_customer_address']) ? sanitize_textarea_field( wp_unslash($_POST['sale_customer_address']) ) : '';

    $desired = [];
    foreach ( $items as $it ) {
        $pid = isset($it['id']) ? absint($it['id']) : 0;
        $qty = isset($it['qty']) ? (int) $it['qty'] : 0;
        if ( $pid <= 0 || $qty <= 0 ) continue;
        $desired[ $pid ] = ( $desired[ $pid ] ?? 0 ) + $qty;
    }

    $user = wp_get_current_user();
    $uid  = (int) ($user->ID ?? 0);
    $ulog = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
    if ( $ulog === '' ) {
        $ulog = (string) ( $user->display_name ?: $user->user_login );
    }

    if ( $order_id > 0 ) {
        $order = wc_get_order( $order_id );
    } else {
        $order = wc_create_order();
        $order->set_created_via( 'wc_suf_manual_sale_hold' );
        $order->set_status( 'initialorder', 'ایجاد اولیه سفارش هولد از فرم فروش.' );
    }
    if ( ! $order ) {
        wp_send_json_error(['message'=>'دسترسی به سفارش هولد ممکن نیست.']);
    }

    if ( $order->get_created_via() !== 'wc_suf_manual_sale_hold' && $order->get_created_via() !== 'wc_suf_manual_sale' ) {
        wp_send_json_error(['message'=>'این سفارش قابل همگام‌سازی با فرم فروش نیست.']);
    }

    $existing_items = [];
    foreach ( $order->get_items('line_item') as $item_id => $item ) {
        if ( ! is_a($item, 'WC_Order_Item_Product') ) continue;
        $pid = (int) $item->get_variation_id();
        if ( $pid <= 0 ) $pid = (int) $item->get_product_id();
        if ( $pid <= 0 ) continue;
        $existing_items[ $pid ] = ['item_id' => $item_id, 'item' => $item];
    }

    foreach ( $existing_items as $pid => $entry ) {
        $old_qty = max(0, (int) $entry['item']->get_quantity());
        $new_qty = max(0, (int) ($desired[ $pid ] ?? 0));
        $delta = $new_qty - $old_qty;
        if ( $delta > 0 ) {
            $product = wc_get_product( $pid );
            $have = (int) ( $product ? $product->get_stock_quantity() : 0 );
            if ( $delta > $have ) {
                wp_send_json_error(['message'=>sprintf('موجودی محصول #%d کافی نیست.', $pid)]);
            }
            wc_update_product_stock( $product, $delta, 'decrease' );
        } elseif ( $delta < 0 ) {
            $product = wc_get_product( $pid );
            wc_update_product_stock( $product, abs($delta), 'increase' );
        }

        if ( $new_qty <= 0 ) {
            $order->remove_item( $entry['item_id'] );
        } else {
            $entry['item']->set_quantity( $new_qty );
            $order->add_item( $entry['item'] );
        }
        unset($desired[ $pid ]);
    }

    foreach ( $desired as $pid => $qty ) {
        $product = wc_get_product( $pid );
        if ( ! $product || $qty <= 0 ) continue;
        $have = (int) ( $product->get_stock_quantity() ?? 0 );
        if ( $qty > $have ) {
            wp_send_json_error(['message'=>sprintf('موجودی محصول #%d کافی نیست.', $pid)]);
        }
        $order->add_product( $product, $qty );
        wc_update_product_stock( $product, $qty, 'decrease' );
    }

    $order->set_address([
        'first_name' => $customer_name,
        'last_name'  => '.',
        'phone'      => $customer_mobile,
        'address_1'  => $customer_address,
    ], 'billing');
    $order->update_meta_data( 'فروشنده', $ulog ?: $user->user_login );
    $order->update_meta_data( '_wc_suf_seller_name', $ulog ?: $user->user_login );
    $order->update_meta_data( '_wc_suf_seller_id', $uid ?: 0 );
    $order->update_meta_data( '_wc_suf_sale_channel', ( $op_type === 'sale_teh' ? 'tehranpars' : 'main' ) );
    $order->update_meta_data( '_wc_suf_sale_operation', $op_type );
    $order->update_meta_data( '_wc_suf_sale_hold_active', 'yes' );
    $order->update_meta_data( '_wc_suf_sale_customer_name', $customer_name );
    $order->update_meta_data( '_wc_suf_sale_customer_mobile', $customer_mobile );
    $order->update_meta_data( '_wc_suf_sale_customer_address', $customer_address );
    $order->calculate_totals();
    $order->save();

    wc_suf_schedule_sale_hold_expiry( $order->get_id() );

    wp_send_json_success([
        'order_id' => (int) $order->get_id(),
        'order_number' => (string) $order->get_order_number(),
    ]);
}

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
    $op_type     = in_array($op_type_in, ['in','out','transfer','return','onlyLabel','sale','sale_teh'], true) ? $op_type_in : '';
    $allowed_ops = wc_suf_get_allowed_ops_for_current_user();
    $is_marjoo_only_user = wc_suf_is_marjoo_only_user();

    if( ! $op_type ){
        wp_send_json_error(['message'=>'نوع عملیات مشخص نیست (ورود/خروج/انتقال/مرجوعی/فروش/فروش تهرانپارس/صرفاً چاپ لیبل).']);
    }
    if ( ! in_array( $op_type, $allowed_ops, true ) ) {
        wp_send_json_error(['message'=>'شما به نوع عملیات انتخابی دسترسی ندارید.']);
    }

    $out_destination = isset($_POST['out_destination']) ? sanitize_text_field( wp_unslash($_POST['out_destination']) ) : '';
    $transfer_source = isset($_POST['transfer_source']) ? sanitize_text_field( wp_unslash($_POST['transfer_source']) ) : '';
    $transfer_destination = isset($_POST['transfer_destination']) ? sanitize_text_field( wp_unslash($_POST['transfer_destination']) ) : '';
    $return_destination = isset($_POST['return_destination']) ? sanitize_text_field( wp_unslash($_POST['return_destination']) ) : '';
    $return_reason = isset($_POST['return_reason']) ? sanitize_text_field( wp_unslash($_POST['return_reason']) ) : '';
    $sale_customer_name = isset($_POST['sale_customer_name']) ? sanitize_text_field( wp_unslash($_POST['sale_customer_name']) ) : '';
    $sale_customer_mobile = isset($_POST['sale_customer_mobile']) ? sanitize_text_field( wp_unslash($_POST['sale_customer_mobile']) ) : '';
    $sale_customer_mobile = wc_suf_normalize_digits( $sale_customer_mobile );
    $sale_customer_address = isset($_POST['sale_customer_address']) ? sanitize_textarea_field( wp_unslash($_POST['sale_customer_address']) ) : '';
    $sale_hold_order_id = isset($_POST['sale_hold_order_id']) ? absint($_POST['sale_hold_order_id']) : 0;
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
        if ( $is_marjoo_only_user && $return_destination !== 'teh' ) {
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
    if ( $op_type === 'sale' || $op_type === 'sale_teh' ) {
        if ( mb_strlen( trim( $sale_customer_name ) ) < 3 ) {
            wp_send_json_error(['message'=>'نام و نام خانوادگی مشتری معتبر نیست.']);
        }
        $sale_customer_mobile = preg_replace('/\D+/', '', wc_suf_normalize_digits( $sale_customer_mobile ) );
        if ( ! preg_match('/^0\d{10}$/', $sale_customer_mobile) ) {
            wp_send_json_error(['message'=>'شماره موبایل باید با 0 شروع شود و دقیقاً 11 رقم باشد.']);
        }
        if ( mb_strlen( trim( $sale_customer_address ) ) < 8 ) {
            wp_send_json_error(['message'=>'آدرس مشتری معتبر نیست.']);
        }
        $out_destination = 'main';
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
    if ( in_array( $op_type, ['in','out','transfer','return','onlyLabel','sale','sale_teh'], true ) ) {
        $tx_started = ( false !== $wpdb->query('START TRANSACTION') );
        if ( ! $tx_started ) {
            wp_send_json_error(['message'=>'شروع تراکنش دیتابیس ناموفق بود. عملیات برای جلوگیری از ثبت ناقص متوقف شد.']);
        }
    }

    if ($op_type === 'out' || $op_type === 'transfer' || $op_type === 'sale' || $op_type === 'sale_teh') {
        $insufficient = [];
        $locked_old_qty = [];
        $locked_prod_qty = [];
        foreach($items as $it){
            $pid = isset($it['id'])  ? absint($it['id']) : 0;
            $req = isset($it['qty']) ? (int) $it['qty']  : 0;
            if( ! $pid || $req <= 0 ) continue;

            $product = wc_get_product($pid);
            if( ! $product ) continue;
            if ( $op_type === 'out' ) {
                $old = $tx_started ? wc_suf_get_production_stock_qty_for_update( $product ) : wc_suf_get_production_stock_qty( $pid );
            } elseif ( $op_type === 'sale' || $op_type === 'sale_teh' ) {
                $old = (int) ( wc_suf_get_stock_product( $product )->get_stock_quantity() ?? 0 );
                if ( $tx_started ) {
                    $locked_prod_qty[$pid] = wc_suf_get_production_stock_qty_for_update_strict( $product );
                    if ( is_wp_error( $locked_prod_qty[$pid] ) ) {
                        $wpdb->query('ROLLBACK');
                        wp_send_json_error(['message'=>$locked_prod_qty[$pid]->get_error_message()]);
                    }
                } else {
                    $locked_prod_qty[$pid] = wc_suf_get_production_stock_qty( $pid );
                }
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

            $operation_label = ( $op_type === 'transfer' ) ? 'انتقال' : ( ( $op_type === 'sale' || $op_type === 'sale_teh' ) ? 'فروش' : 'خروج' );
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

    $is_sale_operation = in_array( $op_type, ['sale','sale_teh'], true );
    $batch_code = $is_sale_operation
        ? ''
        : wc_suf_next_batch_code( $op_type === 'return' ? 'return' : ( $op_type === 'out' ? 'out' : ( $op_type === 'transfer' ? 'transfer' : $op_type ) ) );

    $inserted = 0;
    $processed_items = 0;
    $csv_rows = [];
    $sale_order = null;

    foreach($items as $it){
        $pid = isset($it['id'])  ? absint($it['id']) : 0;
        $req = isset($it['qty']) ? (int) $it['qty']  : 0;
        if( ! $pid || $req <= 0 ) continue;

        $product = wc_get_product($pid);
        if( ! $product ) continue;

        $processed_items++;
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
        } elseif ( $op_type === 'sale' || $op_type === 'sale_teh' ) {
            $old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
            $new_qty = max( 0, (int) $old_qty - $req );
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

        if ( ! $is_sale_operation ) {
            $data = [
                'batch_code'   => $batch_code,
                'op_type'      => wc_suf_audit_op_type_for_storage( $op_type, $out_destination, $return_destination, $transfer_source, $transfer_destination ),
                'purpose'      => ($op_type === 'out')
                                ? ( $out_destination === 'teh' ? 'انتقال به انبار تهرانپارس' : 'خروج به انبار اصلی' )
                                : ( ( $op_type === 'transfer' )
                                    ? ( 'انتقال بین انبارها: ' . wc_suf_destination_label( $transfer_source ) . ' → ' . wc_suf_destination_label( $transfer_destination ) )
                                    : ( ( $op_type === 'return' ) ? ('مرجوعی - علت: '.$return_reason) : ( ( $op_type === 'sale' || $op_type === 'sale_teh' ) ? 'فروش و ثبت سفارش ووکامرس' : null ) ) ),
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
        }

        if ( ! $is_sale_operation && in_array( $op_type, ['in','out','transfer','return','onlyLabel','sale','sale_teh'], true ) ) {
            $base_move_data = [
                'batch_code'      => $batch_code,
                'operation'       => $op_type,
                'product_id'      => $pid,
                'product_name'    => wc_suf_full_product_label( $product ),
                'sku'             => $product->get_sku() ?: null,
                'product_type'    => $product->get_type(),
                'parent_id'       => $product->is_type('variation') ? $product->get_parent_id() : null,
                'attributes_text' => wc_suf_get_product_attributes_text( $product ),
                'user_id'         => $uid ?: null,
                'user_login'      => $ulog ?: null,
                'user_code'       => $user_code ?: null,
                'created_at'      => current_time('mysql'),
            ];

            $move_rows = [];
            if ( $op_type === 'transfer' ) {
                $move_rows[] = array_merge(
                    $base_move_data,
                    [
                        'destination'         => $transfer_source,
                        'old_qty'             => (float) $old_qty,
                        'change_qty'          => (float) ( -1 * $req ),
                        'new_qty'             => (float) $new_qty,
                        'destination_old_qty' => null,
                        'destination_new_qty' => null,
                    ]
                );
                $move_rows[] = array_merge(
                    $base_move_data,
                    [
                        'destination'         => $transfer_destination,
                        'old_qty'             => ( $destination_old_qty === null ? 0.0 : (float) $destination_old_qty ),
                        'change_qty'          => (float) $req,
                        'new_qty'             => ( $destination_new_qty === null ? (float) $req : (float) $destination_new_qty ),
                        'destination_old_qty' => null,
                        'destination_new_qty' => null,
                    ]
                );
            } else {
                $move_rows[] = array_merge(
                    $base_move_data,
                    [
                        'destination'         => ( $op_type === 'out' ) ? $out_destination : ( $op_type === 'return' ? $return_destination : ( ( $op_type === 'sale' || $op_type === 'sale_teh' ) ? 'main' : ( $op_type === 'onlyLabel' ? 'label_only' : 'production' ) ) ),
                        'old_qty'             => (float) $old_qty,
                        'change_qty'          => (float) $req,
                        'new_qty'             => (float) $new_qty,
                        'destination_old_qty' => ( $destination_old_qty === null ? null : (float) $destination_old_qty ),
                        'destination_new_qty' => ( $destination_new_qty === null ? null : (float) $destination_new_qty ),
                    ]
                );
            }

            foreach ( $move_rows as $move_data ) {
                $wpdb->insert( $move_table, $move_data );
                if ( ! empty($wpdb->last_error) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'ثبت لاگ حرکات انبار ناموفق بود.']);
                }
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

    if ( $processed_items === 0 ) {
        if ( $tx_started ) {
            $wpdb->query('ROLLBACK');
        }
        $msg = 'هیچ موردی ثبت نشد.' . ( $wpdb->last_error ? (' DB error: '.$wpdb->last_error) : '' );
        wp_send_json_error(['message'=>$msg]);
    }

    if ( $op_type === 'sale' || $op_type === 'sale_teh' ) {
        try {
            if ( $sale_hold_order_id > 0 ) {
                $sale_order = wc_get_order( $sale_hold_order_id );
                if ( ! $sale_order ) {
                    throw new Exception('سفارش هولد یافت نشد.');
                }
            } else {
                $sale_order = wc_create_order();
                foreach ( $items as $it ) {
                    $pid = isset($it['id'])  ? absint($it['id']) : 0;
                    $req = isset($it['qty']) ? (int) $it['qty']  : 0;
                    if ( ! $pid || $req <= 0 ) continue;
                    $product = wc_get_product( $pid );
                    if ( ! $product ) continue;
                    $sale_order->add_product( $product, $req );
                }
            }

            $sale_order->set_created_via( 'wc_suf_manual_sale' );
            $sale_order->set_address([
                'first_name' => $sale_customer_name,
                'last_name'  => '.',
                'phone'      => $sale_customer_mobile,
                'address_1'  => $sale_customer_address,
            ], 'billing');
            $sale_order->update_meta_data( 'فروشنده', $ulog ?: $user->user_login );
            $sale_order->update_meta_data( '_wc_suf_seller_name', $ulog ?: $user->user_login );
            $sale_order->update_meta_data( '_wc_suf_seller_id', $uid ?: 0 );
            $sale_order->update_meta_data( '_wc_suf_sale_channel', ( $op_type === 'sale_teh' ? 'tehranpars' : 'main' ) );
            $sale_order->update_meta_data( '_wc_suf_sale_operation', $op_type );
            $sale_order->update_meta_data( '_wc_suf_sale_hold_active', 'no' );
            $sale_order->update_meta_data( '_wc_suf_sale_customer_name', $sale_customer_name );
            $sale_order->update_meta_data( '_wc_suf_sale_customer_mobile', $sale_customer_mobile );
            $sale_order->update_meta_data( '_wc_suf_sale_customer_address', $sale_customer_address );
            $sale_order->calculate_totals();
            $sale_order->set_status( 'processing', 'ثبت سفارش از فرم عملیات فروش انبار تولید.' );
            $sale_order->save();
            if ( $sale_hold_order_id <= 0 ) {
                wc_reduce_stock_levels( $sale_order->get_id() );
            }
            wc_suf_clear_sale_hold_expiry( $sale_order->get_id() );
        } catch ( Exception $e ) {
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message' => 'ساخت سفارش ووکامرس ناموفق بود: ' . $e->getMessage()]);
        }
    }

    $csv_file_url = '';
    $word_file_url = '';
    if ( ! empty($csv_rows) ) {
        $csv_result = null;
        $word_context = [
            'op_type'      => $is_sale_operation
                ? $op_type
                : ( $op_type === 'out'
                    ? ( $out_destination === 'teh' ? 'out_teh' : 'out_main' )
                    : ( $op_type === 'transfer'
                        ? ( $transfer_source === 'main' ? 'transfer_main_teh' : 'transfer_teh_main' )
                        : ( $op_type === 'return'
                            ? ( $return_destination === 'teh' ? 'return_teh' : 'return_main' )
                            : $op_type
                        )
                    )
                ),
            'purpose'      => $is_sale_operation
                ? 'رسید فروش'
                : ( $op_type === 'out'
                    ? ( $out_destination === 'teh' ? 'انتقال به انبار تهرانپارس' : 'خروج به انبار اصلی' )
                    : ( $op_type === 'transfer'
                        ? ( 'انتقال بین انبارها: ' . wc_suf_destination_label( $transfer_source ) . ' → ' . wc_suf_destination_label( $transfer_destination ) )
                        : ( $op_type === 'return' ? ('مرجوعی - علت: '.$return_reason) : null )
                    )
                ),
            'user_display' => $ulog ?: ( $uid ? ('user#'.$uid) : 'مهمان' ),
            'user_code'    => $user_code,
            'created_at'   => current_time('mysql'),
        ];
        $receipt_batch_code = ( $is_sale_operation && $sale_order && $sale_order->get_id() )
            ? (string) $sale_order->get_order_number()
            : $batch_code;

        if ( ! $is_sale_operation ) {
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
        }
        $word_result = wc_suf_generate_batch_word_receipt( $receipt_batch_code, $word_context, $csv_rows );
        if ( is_wp_error( $word_result ) ) {
            if ( ! empty( $csv_result['path'] ) && file_exists( $csv_result['path'] ) ) {
                @unlink( $csv_result['path'] );
            }
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message'=>'ساخت فایل رسید HTML ناموفق بود: '.$word_result->get_error_message()]);
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
            wp_send_json_error(['message'=>'ثبت لینک رسید HTML در دیتابیس ناموفق بود.']);
        }
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

    $message = "ثبت {$op_label} انجام شد.";
    if ( ! $is_sale_operation ) {
        $message .= " کد ثبت: {$batch_code}";
    }
    if ( $sale_order && $sale_order->get_id() ) {
        $message .= ' | سفارش ووکامرس: #'.$sale_order->get_id();
    }
    $response_batch_code = ( $is_sale_operation && $sale_order && $sale_order->get_id() )
        ? (string) $sale_order->get_order_number()
        : $batch_code;

    wp_send_json_success([
        'message' => $message,
        'batch_code' => $response_batch_code,
        'csv_url' => $csv_file_url,
        'word_url' => $word_file_url,
        'order_id' => ( $sale_order && $sale_order->get_id() ) ? (int) $sale_order->get_id() : 0,
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
