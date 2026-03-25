<?php

/**
 * جلوگیری از منفی شدن موجودی هنگام ویرایش آیتم‌های سفارش در ادمین.
 */
function wc_suf_prevent_negative_stock_in_admin_order_edit( $prevent, $item, $item_quantity ) {
    if ( $prevent ) {
        return $prevent;
    }

    if ( ! is_admin() || ! wp_doing_ajax() ) {
        return $prevent;
    }

    if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
        return $prevent;
    }

    $order = $item->get_order();
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return $prevent;
    }

    $product = $item->get_product();
    if ( ! $product || ! $product->managing_stock() || $product->backorders_allowed() ) {
        return $prevent;
    }

    $target_qty = wc_stock_amount( null !== $item_quantity ? $item_quantity : $item->get_quantity() );
    $reduced_qty = wc_stock_amount( wc_suf_get_order_item_reduced_stock_qty( $item ) );
    if ( $target_qty <= $reduced_qty ) {
        return $prevent;
    }

    $extra_reduce_qty = $target_qty - $reduced_qty;
    $stock_qty = $product->get_stock_quantity();
    $available_qty = $stock_qty === null ? 0 : wc_stock_amount( $stock_qty );

    if ( $extra_reduce_qty <= $available_qty ) {
        return $prevent;
    }

    if ( class_exists( 'WC_Admin_Meta_Boxes' ) && method_exists( 'WC_Admin_Meta_Boxes', 'add_error' ) ) {
        WC_Admin_Meta_Boxes::add_error(
            sprintf(
                'امکان ثبت تعداد %1$s برای "%2$s" وجود ندارد. موجودی قابل کسر در حال حاضر %3$s است.',
                wc_format_localized_decimal( $target_qty ),
                $product->get_name(),
                wc_format_localized_decimal( $available_qty + $reduced_qty )
            )
        );
    }

    return true;
}
add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', 'wc_suf_prevent_negative_stock_in_admin_order_edit', 10, 3 );
