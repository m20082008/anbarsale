<?php
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
    if ($op === 'sale')     return 'فروش';
    if ($op === 'sale_teh') return 'فروش تهرانپارس';
    if ($op === 'sale_edit') return 'ویرایش سفارش';
    if ($op === 'sale_cancel') return 'لغو سفارش (برگشت موجودی)';
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
    if ( $destination === 'woocommerce' ) {
        return 'انبار اصلی';
    }
    return $destination;
}

function wc_suf_get_order_item_reduced_stock_qty( $item ) {
    if ( ! $item || ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
        return null;
    }
    $reduced = $item->get_meta( '_reduced_stock', true );
    if ( $reduced === '' || $reduced === null ) {
        return null;
    }
    return (float) $reduced;
}


function wc_suf_meta_truthy( $value ) {
    if ( is_bool( $value ) ) {
        return $value;
    }
    if ( is_numeric( $value ) ) {
        return ( (float) $value ) > 0;
    }
    $normalized = strtolower( trim( (string) $value ) );
    if ( $normalized === '' ) {
        return false;
    }
    return ! in_array( $normalized, [ '0', 'false', 'no', 'off', 'null' ], true );
}

function wc_suf_is_yith_pos_order( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        return false;
    }

    $created_via = (string) $order->get_created_via();
    if ( $created_via !== '' && strpos( strtolower( $created_via ), 'yith' ) !== false ) {
        return true;
    }

    $meta_keys = [
        '_yith_pos_order',
        '_yith_pos_order_id',
        '_yith_pos_register',
        '_yith_pos_store',
        '_ywpos_order',
        '_ywpos_store',
    ];
    foreach ( $meta_keys as $meta_key ) {
        $meta_value = $order->get_meta( $meta_key, true );
        if ( $meta_value === '' || $meta_value === null ) {
            continue;
        }
        if ( is_scalar( $meta_value ) && in_array( strtolower( (string) $meta_value ), [ '0', 'false', 'no' ], true ) ) {
            continue;
        }
        return true;
    }

    $payment_method = (string) $order->get_payment_method();
    if ( $payment_method !== '' && strpos( strtolower( $payment_method ), 'yith' ) !== false ) {
        return true;
    }

    return false;
}

function wc_suf_get_order_stock_source( $order ) {
    $is_pos = wc_suf_is_yith_pos_order( $order );

    $yith_pos_order_flag = wc_suf_meta_truthy( $order->get_meta( '_yith_pos_order', true ) ) || wc_suf_meta_truthy( $order->get_meta( '_ywpos_order', true ) );

    $reduced_stock_store_id = (int) $order->get_meta( '_yith_pos_reduced_stock_by_store', true );
    if ( $reduced_stock_store_id <= 0 ) {
        $reduced_stock_store_id = (int) $order->get_meta( '_ywpos_reduced_stock_by_store', true );
    }

    $tehranpars_store_id = (int) WC_SUF_TEHRANPARS_STORE_ID;
    $is_tehranpars_store = ( $reduced_stock_store_id > 0 && $reduced_stock_store_id === $tehranpars_store_id );

    /*
     * قاعده‌ی قطعی:
     * اگر سفارش با فلگ YITH POS ثبت شده باشد (_yith_pos_order = 1)،
     * مبدا/مقصد موجودی برای لاگ فروش باید تهرانپارس باشد؛ حتی اگر متای استور
     * هنوز کامل نشده باشد یا تابع YITH در این لحظه در دسترس نباشد.
     */
    if ( $yith_pos_order_flag ) {
        return [
            'is_pos'     => true,
            'destination'=> 'teh',
            'label'      => 'انبار تهرانپارس',
            'store_id'   => $tehranpars_store_id,
        ];
    }

    if ( $is_tehranpars_store ) {
        return [
            'is_pos'     => true,
            'destination'=> 'teh',
            'label'      => 'انبار تهرانپارس',
            'store_id'   => $tehranpars_store_id,
        ];
    }

    return [
        'is_pos'     => $is_pos,
        'destination'=> 'main',
        'label'      => 'انبار اصلی ووکامرس',
        'store_id'   => 0,
    ];
}

function wc_suf_get_order_stock_qty_by_source( $product, $stock_source ) {
    if ( ! $product ) {
        return 0.0;
    }

    $source_destination = (string) ( $stock_source['destination'] ?? 'main' );
    if ( $source_destination === 'teh' ) {
        $teh_qty = wc_suf_yith_get_store_stock_qty( $product, (int) WC_SUF_TEHRANPARS_STORE_ID );
        if ( false !== $teh_qty ) {
            return (float) $teh_qty;
        }
    }

    $stock_qty = $product->get_stock_quantity();
    if ( $stock_qty === null ) {
        return 0.0;
    }
    return (float) $stock_qty;
}

function wc_suf_get_product_attributes_text( $product ) {
    if ( ! $product || ! is_object( $product ) ) return '';
    $txt = wc_get_formatted_variation( $product, true, false, false );
    $txt = trim( wp_strip_all_tags( (string) $txt ) );
    return $txt;
}
