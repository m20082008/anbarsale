<?php
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
    $allowed_ops = wc_suf_get_allowed_ops_for_current_user();
    $is_marjoo_only_user = wc_suf_is_marjoo_only_user();

    if( ! $op_type ){
        wp_send_json_error(['message'=>'نوع عملیات مشخص نیست (ورود/خروج/انتقال/مرجوعی/صرفاً چاپ لیبل).']);
    }
    if ( ! in_array( $op_type, $allowed_ops, true ) ) {
        wp_send_json_error(['message'=>'شما به نوع عملیات انتخابی دسترسی ندارید.']);
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
        $locked_prod_qty = [];
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

    $processed_items = 0;
    $csv_rows = [];

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
            wp_send_json_error(['message'=>'ساخت فایل رسید HTML ناموفق بود: '.$word_result->get_error_message()]);
        }

        $word_file_url = (string) ( $word_result['url'] ?? '' );
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
    $message .= " کد ثبت: {$batch_code}";

    wp_send_json_success([
        'message' => $message,
        'batch_code' => $batch_code,
        'csv_url' => $csv_file_url,
        'word_url' => $word_file_url,
        'order_id' => 0,
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
