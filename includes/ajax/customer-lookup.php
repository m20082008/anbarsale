<?php
/*--------------------------------------
| AJAX: جستجوی سریع مشتری بر اساس موبایل
---------------------------------------*/
add_action('wp_ajax_wc_suf_lookup_sale_customer','wc_suf_lookup_sale_customer_handler');
function wc_suf_lookup_sale_customer_handler(){
    check_ajax_referer('wc_suf_lookup_sale_customer');

    if ( ! wc_suf_current_user_is_pos_manager() ) {
        wp_send_json_error(['message' => 'دسترسی غیرمجاز.']);
    }

    $mobile = isset($_POST['mobile']) ? sanitize_text_field( wp_unslash($_POST['mobile']) ) : '';
    $mobile = preg_replace('/\D+/', '', wc_suf_normalize_digits( $mobile ) );
    if ( ! preg_match('/^0\d{10}$/', $mobile) ) {
        wp_send_json_success([ 'found' => false ]);
    }

    global $wpdb;
    $found_name = '';

    $user_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta}
         WHERE meta_key = 'billing_phone'
           AND REPLACE(REPLACE(REPLACE(meta_value, ' ', ''), '-', ''), '+98', '0') = %s
         ORDER BY umeta_id DESC LIMIT 1",
        $mobile
    ) );

    if ( $user_id > 0 ) {
        $first_name = trim( (string) get_user_meta( $user_id, 'billing_first_name', true ) );
        $last_name  = trim( (string) get_user_meta( $user_id, 'billing_last_name', true ) );
        $full = trim( $first_name . ' ' . $last_name );
        if ( $full !== '' ) {
            $found_name = $full;
        }
    }

    if ( $found_name === '' ) {
        $order_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_billing_phone'
               AND REPLACE(REPLACE(REPLACE(meta_value, ' ', ''), '-', ''), '+98', '0') = %s
             ORDER BY meta_id DESC LIMIT 1",
            $mobile
        ) );
        if ( $order_id > 0 ) {
            $first_name = trim( (string) get_post_meta( $order_id, '_billing_first_name', true ) );
            $last_name  = trim( (string) get_post_meta( $order_id, '_billing_last_name', true ) );
            $full = trim( $first_name . ' ' . $last_name );
            if ( $full !== '' ) {
                $found_name = $full;
            }
        }
    }

    wp_send_json_success([
        'found' => ( $found_name !== '' ),
        'full_name' => $found_name,
    ]);
}
