<?php
/**
 * Plugin Name: WC Quick Order Box
 * Description: فرم سفارش سریع + لیست سفارش‌های فروشنده + ویرایش سفارش‌های on-hold (AJAX) با کنترل موجودی و سازگاری HPOS. (بدون SMS)
 * Author: Sepand & Narges
 * Version: 2.0.0
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('QOF_VERSION') ) define('QOF_VERSION', '2.0.0');

/*======================================
=  HPOS Compatibility
======================================*/
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});

/*======================================
=  Defer transactional emails
======================================*/
add_filter('woocommerce_defer_transactional_emails', '__return_true');

/*======================================
=  Helpers
======================================*/
function qof_normalize_digits($s){
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($ar, $en, str_replace($fa, $en, (string)$s));
}

function qof_sellers() {
    return [
        '910' => 'هاجر رستمی',
        '911' => 'مهرنوش هاشمی',
        '912' => 'اسماعیل آرین پور',
        '913' => 'زهرا حاتمی',
        '914' => 'شعبه تهرانپارس',
        // '915' => 'نام فروشنده جدید',
    ];
}

function qof_product_label($product){
    if ( ! $product ) return '';
    if ( $product->is_type('variation') ) {
        $parent = wc_get_product( $product->get_parent_id() );
        $base   = $parent ? $parent->get_name() : ('Variation #'.$product->get_id());

        // ✅ Show ONLY attribute values (no attribute labels like "رنگ:" / "چند نفره / شکل:")
        // wc_get_formatted_variation args: ($variation, $flat, $include_names, $skip_attributes_in_name)
        $attrs  = wc_get_formatted_variation( $product, true, false, false );
        $attrs  = trim( wp_strip_all_tags( (string) $attrs ) );

        // Normalize separators for Persian UI: "A, B, C" => "A / B / C"
        if ( $attrs !== '' ) {
            $attrs = preg_replace('/\s*,\s*/u', ' / ', $attrs);
            $attrs = preg_replace('/\s*\/\s*/u', ' / ', $attrs);
            $attrs = trim($attrs);
        }

        $label  = trim( $base . ( $attrs ? ' - ' . $attrs : '' ) );
        if ( $label === '' ) $label = $product->get_name() ?: ('#'.$product->get_id());
        return $label;
    }
    $name = $product->get_name();
    return $name !== '' ? $name : ('#'.$product->get_id());
}
/* استخراج ظرفیت از اتربیوت pa_multi */
function qof_capacity_from_product($product){
    $cap = 0;
    if ( ! $product ) return 0;

    if ( $product->is_type('variation') ) {
        $slug = $product->get_meta('attribute_pa_multi', true);
        if ($slug !== '') {
            $slug = qof_normalize_digits($slug);
            if (preg_match('/(\d+)/', $slug, $m)) $cap = intval($m[1]);
        }
        if (!$cap) {
            $val = $product->get_attribute('pa_multi');
            if ($val !== '') {
                $val = qof_normalize_digits($val);
                if (preg_match('/(\d+)/', $val, $m)) $cap = intval($m[1]);
            }
        }
    } else {
        $val = $product->get_attribute('pa_multi');
        if ($val !== '') {
            $val = qof_normalize_digits($val);
            if (preg_match('/(\d+)/', $val, $m)) $cap = intval($m[1]);
        }
    }
    return $cap > 0 ? $cap : 0;
}

function qof_build_search_blob( $product ) {
    if ( ! $product || ! is_object( $product ) ) return '';

    $parts = [];
    $name = $product->get_name();
    if ( is_string($name) && $name !== '' ) $parts[] = $name;

    if ( $product->is_type('variation') ) {
        $parent = wc_get_product( $product->get_parent_id() );
        if ( $parent ) {
            $parent_name = $parent->get_name();
            if ( is_string($parent_name) && $parent_name !== '' ) $parts[] = $parent_name;
        }

        $formatted = wc_get_formatted_variation( $product, true, false, false );
        $formatted = trim( wp_strip_all_tags( (string) $formatted ) );
        if ( $formatted !== '' ) $parts[] = $formatted;

        $va = $product->get_variation_attributes();
        if ( is_array($va) ) {
            foreach ( $va as $k => $v ) {
                $k = (string) $k;
                $v = (string) $v;
                if ( $k !== '' ) $parts[] = $k;
                if ( $v !== '' ) $parts[] = $v;
            }
        }
    }

    $multi_attr = $product->get_attribute('pa_multi');
    if ( is_string($multi_attr) && $multi_attr !== '' ) $parts[] = $multi_attr;

    $blob = qof_normalize_digits( implode(' ', array_filter(array_map('strval', $parts))) );
    $blob = function_exists('mb_strtolower') ? mb_strtolower($blob) : strtolower($blob);
    return trim($blob);
}

function qof_collect_product_attributes_for_picker( $product ) {
    if ( ! $product || ! is_object( $product ) ) return [];

    $out = [];
    $attr_taxonomies = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];
    if ( empty($attr_taxonomies) || ! is_array($attr_taxonomies) ) return $out;

    if ( $product->is_type('variation') ) {
        $va = $product->get_variation_attributes();
        if ( is_array($va) ) {
            foreach ( $va as $k => $slug ) {
                $tax = str_replace('attribute_', '', (string) $k);
                $slug = (string) $slug;
                if ( $tax === '' || $slug === '' ) continue;
                if ( taxonomy_exists($tax) ) {
                    $term = get_term_by('slug', $slug, $tax);
                    $out[$tax][] = ($term && ! is_wp_error($term) && isset($term->name) && $term->name !== '') ? (string) $term->name : $slug;
                } else {
                    $out[$tax][] = $slug;
                }
            }
        }
    }

    foreach ( $attr_taxonomies as $a ) {
        if ( ! is_object($a) || empty($a->attribute_name) ) continue;
        $tax = 'pa_' . sanitize_title( (string) $a->attribute_name );
        if ( ! taxonomy_exists($tax) ) continue;

        $val = $product->get_attribute($tax);
        if ( ! is_string($val) || $val === '' ) continue;

        foreach ( array_filter(array_map('trim', explode(',', $val))) as $piece ) {
            $out[$tax][] = (string) $piece;
        }
    }

    foreach ( $out as $tax => $vals ) {
        $vals = array_values(array_unique(array_filter(array_map('trim', (array) $vals))));
        if ( empty($vals) ) unset($out[$tax]);
        else $out[$tax] = $vals;
    }

    return $out;
}

function qof_get_picker_attribute_defs() {
    $defs = [];
    $attr_taxonomies = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];
    if ( empty($attr_taxonomies) || ! is_array($attr_taxonomies) ) return $defs;

    foreach ( $attr_taxonomies as $a ) {
        if ( ! is_object($a) || empty($a->attribute_name) ) continue;
        $tax = 'pa_' . sanitize_title((string) $a->attribute_name);
        if ( ! taxonomy_exists($tax) ) continue;
        $defs[] = [
            'tax'   => $tax,
            'label' => function_exists('wc_attribute_label') ? wc_attribute_label($tax) : $tax,
        ];
    }
    return $defs;
}

/**
 * Determine whether a product can fulfill requested quantity for pending allocation.
 *
 * - Managed stock: require enough real quantity or allowed backorders.
 * - Unmanaged stock: rely on stock status/backorders rules.
 */
function qof_can_allocate_pending_qty($product, $req_qty) {
    if ( ! $product || ! is_object($product) ) return false;

    $req_qty = (int) $req_qty;
    if ( $req_qty <= 0 ) return false;

    if ( ! $product->managing_stock() ) {
        return $product->is_in_stock() || $product->backorders_allowed();
    }

    if ( $product->backorders_allowed() ) {
        return true;
    }

    $stock_qty = $product->get_stock_quantity();
    $stock_qty = is_null($stock_qty) ? 0 : (int) wc_stock_amount($stock_qty);

    return $stock_qty >= $req_qty;
}

/*======================================
=  Cache helpers
======================================*/
function qof_products_cache_key() {
    return 'qof_products_cache_' . QOF_VERSION;
}
function qof_bust_products_cache() {
    delete_transient( qof_products_cache_key() );
}

add_action('woocommerce_reduce_order_stock', 'qof_bust_products_cache');
add_action('woocommerce_product_set_stock', 'qof_bust_products_cache');
add_action('woocommerce_variation_set_stock', 'qof_bust_products_cache');

/*======================================
=  Enqueue Front assets
======================================*/
function qof_enqueue_front_assets() {
    wp_enqueue_script('jquery');
    if ( wp_script_is('selectWoo', 'registered') || wp_script_is('selectWoo', 'enqueued') ) {
        wp_enqueue_script('selectWoo');
        if ( wp_style_is('select2', 'registered') ) wp_enqueue_style('select2');
    } else {
        // اگر selectWoo موجود نبود، از assets پلاگین بارگذاری می‌کنیم
        wp_enqueue_style('qof-select2', plugins_url('assets/select2.min.css', __FILE__), [], '4.1.0');
        wp_enqueue_script('qof-select2', plugins_url('assets/select2.min.js', __FILE__), ['jquery'], '4.1.0', true);
    }
}
add_action('wp_enqueue_scripts', 'qof_enqueue_front_assets');

/*======================================
=  Cached Products
======================================*/
function qof_get_cached_products() {
    $cache_key = qof_products_cache_key();
    $products = get_transient($cache_key);

    if (false === $products) {
        $products = wc_get_products([
            'status' => 'publish',
            'limit'  => -1,
            'type'   => ['simple', 'variation'],
            'return' => 'objects',
        ]);
        set_transient($cache_key, $products, HOUR_IN_SECONDS);
    }
    return is_array($products) ? $products : [];
}

/*======================================
=  Shortcode: [quick_order_box key="910|..."]
======================================*/
add_shortcode('quick_order_box', function($atts){
    qof_enqueue_front_assets();
    if ( ! function_exists('wc_get_products') ) return '<div style="color:#b91c1c">WooCommerce فعال نیست.</div>';

    $atts = shortcode_atts(['key' => ''], $atts, 'quick_order_box');

    $products = qof_get_cached_products();
    $bucketed = [];

    foreach ($products as $p){
        if ( ! $p ) continue;
        $stock = $p->get_stock_quantity();
        if ($stock === null) $stock = 0;
        $row = [
            'id'          => $p->get_id(),
            'label'       => qof_product_label($p),
            'stock'       => (int)$stock,
            'search_blob' => qof_build_search_blob($p),
            'attrs'       => qof_collect_product_attributes_for_picker($p),
        ];
        $cap = qof_capacity_from_product($p);
        $bucketed[$cap][] = $row;
    }

    foreach ($bucketed as $cap => &$list) {
        usort($list, fn($a,$b)=>strcasecmp($a['label'],$b['label']));
    }
    unset($list);

    $name_select_html = ''; $id_select_html = '';
    $all = []; $rendered_caps = []; $preferred_order = [4,6,8,12,0];
    $picker_attr_defs = qof_get_picker_attribute_defs();

    foreach ($preferred_order as $cap) {
        if (!isset($bucketed[$cap])) continue;
        $group_label = $cap > 0 ? ($cap.' نفره') : 'سایر';
        $name_select_html .= '<optgroup label="'.esc_attr($group_label).'">';
        $id_select_html   .= '<optgroup label="'.esc_attr($group_label).'">';

        foreach ($bucketed[$cap] as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_name).'</option>';
            $id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_id).'</option>';
            $all[] = $row;
        }

        $name_select_html .= '</optgroup>';
        $id_select_html   .= '</optgroup>';
        $rendered_caps[$cap] = true;
    }

    $others = [];
    foreach ($bucketed as $cap => $list) {
        if (isset($rendered_caps[$cap])) continue;
        foreach ($list as $row) $others[] = $row;
    }
    usort($others, fn($a,$b)=>strcasecmp($a['label'],$b['label']));

    if (!empty($others)) {
        $name_select_html .= '<optgroup label="'.esc_attr('سایر').'">';
        $id_select_html   .= '<optgroup label="'.esc_attr('سایر').'">';
        foreach ($others as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_name).'</option>';
            $id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_id).'</option>';
            $all[] = $row;
        }
        $name_select_html .= '</optgroup>';
        $id_select_html   .= '</optgroup>';
    }

    ob_start(); ?>
    <style>
      #qof-form .row-flex{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
      #qof-form select{max-width:100%}
      #qof-form .w-id{width:220px}
      #qof-form .w-name{width:640px}
      #qof-form .qty-input{width:110px;text-align:center;font-size:18px;padding:6px}
      #qof-form .table-wrap{margin-top:10px;display:none;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
      #qof-form table{border-collapse:collapse;width:100%}
      #qof-form th,#qof-form td{padding:8px;border-top:1px solid #e5e7eb}
      #qof-form thead th{background:#f3f4f6;border-top:none}
      #qof-form .btn{cursor:pointer;border-radius:10px}
      #qof-form .btn-primary{border:1px solid #2563eb;background:#2563eb;color:#fff}
      #qof-form .btn-add{border:1px solid #10b981;background:#bbf7d0;color:#065f46;font-weight:600}
      #qof-form .btn-hold{border:1px solid #f59e0b;background:#fffbeb;color:#92400e;font-weight:700}
      #qof-form .addr-box{width:100%;min-height:96px;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px}
      #qof-form .helper{color:#6b7280;font-size:12px;margin-top:6px}
      #qof-form .stock-badge{font-size:13px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:6px 10px;color:#1e40af}
      #qof-form .stock-badge.zero{background:#fee2e2;border-color:#fecaca;color:#991b1b}
      #qof-form .muted{opacity:.6;pointer-events:none}
      .select2-container .select2-results > .select2-results__options{max-height: 70vh !important;overscroll-behavior: contain;}
      .select2-dropdown{max-height: 75vh !important;overflow: auto !important;}
      .select2-search--dropdown .select2-search__field{padding:8px; font-size:14px; line-height:1.4;}
      #qof-msg{display:none;margin-bottom:8px;padding:10px;border-radius:8px;white-space:pre-line}
      #qof-msg.ok{display:block;background:#ecfdf5;border:1px solid #10b981;color:#065f46}
      #qof-msg.err{display:block;background:#fef2f2;border:1px solid #ef4444;color:#991b1b}
      .qof-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000000;display:none}
      .qof-modal{position:fixed;inset:0;z-index:1000001;display:none;align-items:center;justify-content:center;padding:18px}
      .qof-modal .qof-modal-card{width:min(980px,96vw);max-height:88vh;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.25);display:flex;flex-direction:column}
      .qof-modal .qof-modal-head{padding:12px 14px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:10px;background:#f9fafb}
      .qof-modal .qof-modal-title{font-weight:800}
      .qof-modal .qof-modal-close{border:1px solid #ef4444;background:#ef4444;color:#fff;border-radius:10px;padding:6px 10px;cursor:pointer;font-weight:900;line-height:1}
      .qof-modal .qof-modal-body{padding:12px 14px;overflow:auto}
      .qof-modal .qof-modal-foot{padding:12px 14px;border-top:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f9fafb}
      .qof-picker-row{display:grid;grid-template-columns:1fr 140px;gap:10px;align-items:center;padding:10px 8px;border-bottom:1px solid #f1f5f9}
      .qof-picker-row:last-child{border-bottom:none}
      .qof-picker-row.is-selected{background:#dcfce7}
      .qof-picker-name{font-weight:600}
      .qof-picker-meta{font-size:12px;color:#6b7280;margin-top:2px}
      .qof-picker-qty{display:flex;align-items:center;justify-content:flex-end;gap:6px}
      .qof-picker-qty input{width:76px;text-align:center;padding:6px;font-size:16px;border:1px solid #e5e7eb;border-radius:10px}
      .qof-picker-qty button{padding:6px 10px;font-size:16px;cursor:pointer;border:1px solid #e5e7eb;background:#fff;border-radius:10px}
      .qof-picker-check{width:18px;height:18px;cursor:pointer}
      .qof-filter-grid{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
      .qof-filter-grid .qof-filter{display:flex;gap:6px;align-items:center}
      .qof-filter-grid label{font-weight:700;font-size:13px;color:#111827}
      .qof-filter-grid select{padding:9px 8px;border:1px solid #e5e7eb;border-radius:12px;font-size:13px;background:#fff;min-width:140px;max-width:180px}
      @media (max-width: 768px){
        #qof-form{gap:10px}
        #qof-form input,#qof-form select,#qof-form textarea,#qof-form button{font-size:16px}
        #qof-form .row-flex{gap:8px}
        #qof-form .w-id{width:100%}
        #qof-form .w-name{width:100%}
        #qof-form .qty-input{width:88px;font-size:16px}
        #qof-form .btn-primary,#qof-form .btn-add{width:100%}
        #qof-form .btn{padding:11px 12px}
        #qof-form #qof-delivery-wrap{flex-direction:column;align-items:flex-start}
        .qof-modal{padding:8px}
        .qof-modal .qof-modal-card{width:100%;max-height:94vh;border-radius:12px}
        .qof-modal .qof-modal-head,.qof-modal .qof-modal-foot{padding:10px}
        .qof-modal .qof-modal-body{padding:10px}
        .qof-filter-grid{display:grid;grid-template-columns:1fr}
        .qof-filter-grid .qof-filter{width:100%}
        .qof-filter-grid select{min-width:unset;max-width:none;width:100%}
        .qof-picker-row{grid-template-columns:1fr;gap:8px;padding:12px 10px}
        .qof-picker-qty{justify-content:flex-start;flex-wrap:wrap}
        .qof-picker-qty input{width:88px}
        #qof-picker-apply{width:100%}
        #qof-form thead { display:none; }
        #qof-form tbody tr{display:flex; flex-wrap:wrap; gap:8px; padding:10px;}
        #qof-form tbody td{border:none; padding:0;}
        #qof-form .col-rownum{order:0;font-size:12px;opacity:.6;}
        #qof-form .col-name{order:1; flex:1 1 100%; font-weight:600; font-size:14px;}
        #qof-form .col-qty{order:2; display:flex; align-items:center; gap:6px;}
        #qof-form .col-del{order:3; margin-inline-start:auto;}
        #qof-form .col-stock{order:4; font-size:12px; opacity:.8;}
        #qof-form .col-id{order:5; font-size:12px; opacity:.6;}
      }
    </style>

    <div id="qof-msg"></div>

    <form id="qof-form" dir="rtl" method="post" action="#" style="display:grid;gap:12px;align-items:center">
        <div class="row-flex" id="qof-picker-open-block">
            <button type="button" id="qof-btn-open-picker" class="btn btn-add" style="padding:10px 16px">🔎 انتخاب محصول از لیست</button>
        </div>

        <div id="qof-picker-overlay" class="qof-modal-overlay"></div>
        <div id="qof-picker-modal" class="qof-modal" role="dialog" aria-modal="true" aria-labelledby="qof-picker-title">
          <div class="qof-modal-card" dir="rtl">
            <div class="qof-modal-head">
              <div class="qof-modal-title" id="qof-picker-title">انتخاب محصول</div>
              <button type="button" id="qof-picker-close" class="qof-modal-close" aria-label="بستن">✕</button>
            </div>
            <div class="qof-modal-body">
              <div class="qof-filter-grid" style="margin-bottom:10px">
                <div class="qof-filter" style="flex:1;min-width:220px">
                  <label for="qof-picker-search">جستجو:</label>
                  <input type="text" id="qof-picker-search" style="width:100%;padding:9px 10px;border:1px solid #e5e7eb;border-radius:12px" placeholder="نام، ID یا ویژگی...">
                </div>
                <?php foreach ( $picker_attr_defs as $def ) : ?>
                  <div class="qof-filter">
                    <label for="qof-filter-<?php echo esc_attr($def['tax']); ?>"><?php echo esc_html($def['label']); ?>:</label>
                    <select id="qof-filter-<?php echo esc_attr($def['tax']); ?>" class="qof-attr-filter" data-tax="<?php echo esc_attr($def['tax']); ?>">
                      <option value="">همه</option>
                    </select>
                  </div>
                <?php endforeach; ?>
              </div>
              <div id="qof-picker-list" style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden"></div>
            </div>
            <div class="qof-modal-foot">
              <span class="helper" id="qof-picker-count">۰ محصول</span>
              <button type="button" id="qof-picker-apply" class="btn btn-primary" style="padding:10px 16px">افزودن انتخاب‌ها</button>
            </div>
          </div>
        </div>

        <div class="row-flex" style="width:100%">
          <div style="flex:1; min-width:240px">
            <label for="qof-cust-name" style="display:block;margin-bottom:6px">نام و نام‌خانوادگی مشتری (اختیاری):</label>
            <input type="text" id="qof-cust-name" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px" placeholder="مثال: محسن رضایی">
          </div>
          <div style="flex:1; min-width:220px">
            <label for="qof-cust-phone" style="display:block;margin-bottom:6px">شماره موبایل (اختیاری):</label>
            <input type="tel" id="qof-cust-phone" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px" placeholder="۰۹۱۱۱۱۱۱۱۱۱">
          </div>
          <div style="flex:1; min-width:220px">
            <label for="qof-seller" style="display:block;margin-bottom:6px">فروشنده:</label>
            <input type="text" id="qof-seller" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb" readonly>
          </div>
        </div>

        <!-- فقط برای شعبه تهرانپارس: نوع تحویل -->
        <div class="row-flex" id="qof-delivery-wrap" style="width:100%;display:none;margin-top:4px">
          <span style="font-weight:600">نحوه تحویل:</span>
          <label style="display:flex;align-items:center;gap:4px">
            <input type="radio" name="qof_delivery" value="home" checked>
            <span>ارسال درب منزل (پست)</span>
          </label>
          <label style="display:flex;align-items:center;gap:4px">
            <input type="radio" name="qof_delivery" value="branch">
            <span>تحویل در شعبه تهرانپارس</span>
          </label>
        </div>

        <div class="table-wrap" id="qof-table-wrap">
          <table id="qof-items-table">
            <thead>
              <tr>
                <th style="width:50px;text-align:center">#</th>
                <th style="width:110px">ID</th>
                <th>محصول</th>
                <th style="width:140px;text-align:center">موجودی فعلی</th>
                <th style="width:280px;text-align:center">تعداد (+/−)</th>
                <th style="width:100px;text-align:center">حذف</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div>
          <label for="qof-address" style="display:block;margin-bottom:6px">آدرس سفارش (اختیاری):</label>
          <textarea id="qof-address" class="addr-box" placeholder="اختیاری"></textarea>
          <div class="helper">برای فعال شدن «ثبت نهایی»، فقط کافی است حداقل یک آیتم اضافه شده باشد.</div>
        </div>

        <?php wp_nonce_field('qof_place_order_form','_wpnonce_qof'); ?>
        <input type="hidden" id="qof-hidden-items"   name="items" value="">
        <input type="hidden" id="qof-hidden-address" name="address" value="">
        <input type="hidden" id="qof-hidden-name"    name="cust_name" value="">
        <input type="hidden" id="qof-hidden-phone"   name="cust_phone" value="">
        <input type="hidden" id="qof-hidden-code"    name="user_code" value="">
        <input type="hidden" id="qof-hidden-delivery" name="delivery_type" value="">

        <div class="row-flex" style="justify-content:flex-start">
          <button type="submit" id="qof-btn-save" style="margin-top:4px;padding:12px 18px" class="btn btn-primary" disabled>✅ ثبت نهایی سفارش</button>
          <button type="button" id="qof-btn-hold" style="margin-top:4px;padding:12px 18px" class="btn btn-hold" disabled>⏸️ در انتظار</button>
        </div>
    </form>

    <script>
    jQuery(function($){
        const allProducts = <?php echo wp_json_encode($all); ?>;
        const defaultShortcodeKey = "<?php echo esc_js($atts['key']); ?>";
        const urlParams = new URLSearchParams(window.location.search);
        const urlKey = urlParams.get('key') || urlParams.get('code') || '';
        const userCode = urlKey || defaultShortcodeKey;

        const sellerMap = <?php echo wp_json_encode( qof_sellers(), JSON_UNESCAPED_UNICODE ); ?>;
        const sellerName = sellerMap[userCode] || "";
        $('#qof-seller').val(sellerName);

        const isTehranpars = (sellerName === 'شعبه تهرانپارس' || userCode === '914');
        if (isTehranpars) $('#qof-delivery-wrap').show(); else $('#qof-delivery-wrap').hide();

        $('input[name="qof_delivery"]').on('change', function(){
            if (!isTehranpars) return;
            const val = $('input[name="qof_delivery"]:checked').val();
            if (val === 'branch') {
                $('#qof-address').val('باید به شعبه تهرانپارس تحویل گردد.').prop('readonly', true);
            } else {
                if ($('#qof-address').val() === 'باید به شعبه تهرانپارس تحویل گردد.') $('#qof-address').val('');
                $('#qof-address').prop('readonly', false);
            }
        });

        const items = [];

        let selectedProductId = '';
        const pickerDraft = {};

        function normalizeSearchText(s){
            return String(s == null ? '' : s)
              .replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
              .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
              .replace(/\s+/g, ' ')
              .trim()
              .toLowerCase();
        }

        function findById(id){ return allProducts.find(p => String(p.id) === String(id)); }
        function findLabelById(id){ const f = findById(id); return f ? f.label : ''; }
        function baseStockById(id){ const f = findById(id); return f ? (f.stock||0) : 0; }
        function sumSelectedQty(id){ return items.filter(x=>String(x.id)===String(id)).reduce((a,b)=>a+(+b.qty||0),0); }
        function availableStock(id){ return Math.max(0, baseStockById(id) - sumSelectedQty(id)); }
        function getSelectedId(){ return selectedProductId || ''; }

        function updateSelectedHint(){}

        function updateStockBadge(){}

        function clampQty(val, max){
            val = parseInt(val||0,10);
            if (!Number.isFinite(val)) val = 0;
            const min = 1;
            if (val < min) val = min;
            if (typeof max === 'number' && max >= 0) val = Math.min(val, max);
            return val;
        }

        function refreshAddButtonState(){}

        function onSelectChange(){
            const pid = getSelectedId();
            updateSelectedHint();
            updateStockBadge();
            if(!pid){ return; }
        }

        function openPicker(){ $('#qof-picker-overlay,#qof-picker-modal').show(); renderPickerList(); setTimeout(()=>$('#qof-picker-search').trigger('focus'),20); }
        function closePicker(){ $('#qof-picker-overlay,#qof-picker-modal').hide(); }

        function populateAttributeFilters(){
            const valuesByTax = {};
            allProducts.forEach(function(p){
                const attrs = p.attrs || {};
                Object.keys(attrs).forEach(function(tax){
                    valuesByTax[tax] = valuesByTax[tax] || new Set();
                    (attrs[tax] || []).forEach(v => valuesByTax[tax].add(String(v)));
                });
            });
            $('.qof-attr-filter').each(function(){
                const tax = $(this).data('tax');
                const vals = Array.from(valuesByTax[tax] || []).sort((a,b)=>a.localeCompare(b,'fa'));
                vals.forEach(v => $(this).append($('<option>').val(v).text(v)));
            });
        }

        function currentFilterState(){
            const q = normalizeSearchText($('#qof-picker-search').val() || '');
            const attrs = {};
            $('.qof-attr-filter').each(function(){ const v=$(this).val(); if(v) attrs[$(this).data('tax')] = String(v); });
            return {q, attrs};
        }

        function productMatchesFilters(p, fs){
            const blob = normalizeSearchText((p.search_blob || '') + ' ' + p.id);
            if (fs.q) {
                const words = fs.q.split(' ').filter(Boolean);
                for (let i=0;i<words.length;i++) if (blob.indexOf(words[i]) === -1) return false;
            }
            const attrs = p.attrs || {};
            for (const tax in fs.attrs){
                const wanted = fs.attrs[tax];
                const list = (attrs[tax] || []).map(v=>String(v));
                if (!list.includes(wanted)) return false;
            }
            return true;
        }

        function renderPickerList(){
            const fs = currentFilterState();
            const filtered = allProducts.filter(p => productMatchesFilters(p, fs));
            const $list = $('#qof-picker-list').empty();
            $('#qof-picker-count').text(filtered.length + ' محصول');
            if (!filtered.length){ $list.append('<div style="padding:12px">محصولی یافت نشد.</div>'); return; }

            filtered.forEach(function(p){
                const pid = String(p.id);
                const stock = baseStockById(pid);
                const avail = availableStock(pid);
                const draftQty = parseInt((pickerDraft[pid] && pickerDraft[pid].qty) || 1,10) || 1;
                const $row = $(`
                  <div class="qof-picker-row ${pickerDraft[pid]?'is-selected':''}" data-id="${pid}">
                    <div>
                      <div class="qof-picker-name">${p.label}</div>
                      <div class="qof-picker-meta">ID: ${pid} | موجودی: ${stock} | قابل افزودن: ${avail}</div>
                    </div>
                    <div class="qof-picker-qty">
                      <button type="button" class="qof-picker-dec" data-id="${pid}">➖</button>
                      <input type="number" class="qof-picker-qty-input" data-id="${pid}" min="1" value="${draftQty}">
                      <button type="button" class="qof-picker-inc" data-id="${pid}">➕</button>
                      <input type="checkbox" class="qof-picker-check" data-id="${pid}" ${pickerDraft[pid]?'checked':''}>
                    </div>
                  </div>`);
                $list.append($row);
            });
        }

        populateAttributeFilters();
        $('#qof-btn-open-picker').on('click', openPicker);
        $('#qof-picker-close,#qof-picker-overlay').on('click', closePicker);
        $('#qof-picker-search,.qof-attr-filter').on('input change', renderPickerList);
        $('#qof-picker-list').on('click','.qof-picker-inc', function(){ const id=$(this).data('id'); const cur=(parseInt((pickerDraft[id]&&pickerDraft[id].qty)||1,10)||1)+1; pickerDraft[id]=pickerDraft[id]||{}; pickerDraft[id].qty=cur; renderPickerList(); });
        $('#qof-picker-list').on('click','.qof-picker-dec', function(){ const id=$(this).data('id'); const cur=Math.max(1,(parseInt((pickerDraft[id]&&pickerDraft[id].qty)||1,10)||1)-1); pickerDraft[id]=pickerDraft[id]||{}; pickerDraft[id].qty=cur; renderPickerList(); });
        $('#qof-picker-list').on('change input','.qof-picker-qty-input', function(){ const id=$(this).data('id'); const v=Math.max(1, parseInt($(this).val()||1,10)||1); pickerDraft[id]=pickerDraft[id]||{}; pickerDraft[id].qty=v; });
        $('#qof-picker-list').on('change','.qof-picker-check', function(){
            const id = $(this).data('id');
            const checked = $(this).is(':checked');
            if(checked){ pickerDraft[id]=pickerDraft[id]||{qty:1}; }
            else { delete pickerDraft[id]; }
            $(this).closest('.qof-picker-row').toggleClass('is-selected', checked);
        });
        $('#qof-picker-apply').on('click', function(){
            const ids = Object.keys(pickerDraft);
            if (!ids.length){ alert('حداقل یک محصول انتخاب کنید.'); return; }
            ids.forEach(function(id){
                const qty = Math.max(1, parseInt((pickerDraft[id] && pickerDraft[id].qty) || 1, 10) || 1);
                addProductToItems(String(id), qty);
            });
            const first = ids[0];
            selectedProductId = first;
            onSelectChange();
            renderTable();
            closePicker();
        });

        function renderTable(){
            const $wrap = $('#qof-table-wrap');
            const tbody = $('#qof-items-table tbody').empty();
            if(items.length === 0){
                $wrap.hide(); checkReady(); updateStockBadge(); return;
            }
            $wrap.show();
            items.forEach((it,idx)=>{
                const pid = it.id;
                const stock = baseStockById(pid);
                const sumOthers = items.reduce((a,b,i)=> i===idx ? a : (String(b.id)===String(pid) ? a+(+b.qty||0) : a), 0);
                const rowMax = Math.max(0, stock - sumOthers);
                const $tr = $('<tr>');
                $tr.append(`<td class="col-rownum" style="text-align:center;font-weight:600">${idx + 1}</td>`);
                $tr.append(`<td class="col-id">${it.id}</td>`);
                $tr.append(`<td class="col-name">${it.name}${(it.locked_zero && (it.req_qty||0)>0) ? `<div class="qof-muted" style="font-size:12px;margin-top:2px">تعداد درخواستی: ${it.req_qty}</div>` : ''}</td>`);
                $tr.append(`<td class="col-stock" style="text-align:center">موجودی: ${stock}</td>`);
                const lockedZero = !!it.locked_zero;
                const minQty = lockedZero ? 0 : 1;
                const disableInc = lockedZero || it.qty >= rowMax;
                const disableDec = lockedZero || it.qty <= minQty;
                const qtyControls = $(`
                  <td class="col-qty" style="text-align:center">
                    <button class="qof-row-dec" data-i="${idx}" type="button" ${disableDec?'disabled':''} style="font-size:20px;padding:6px 12px;margin-inline:4px;${disableDec?'opacity:.6;cursor:not-allowed':''}">➖</button>
                    <input type="number" class="qof-row-qty" data-i="${idx}" value="${it.qty}" min="${minQty}" ${(!lockedZero && rowMax>0)?`max="${rowMax}"`:''} ${lockedZero?'disabled':''} style="width:86px;text-align:center;font-size:17px;padding:6px">
                    <button class="qof-row-inc" data-i="${idx}" type="button" ${disableInc?'disabled':''} style="font-size:20px;padding:6px 12px;margin-inline:4px;${disableInc?'opacity:.6;cursor:not-allowed':''}">➕</button>
                  </td>
                `);
                $tr.append(qtyControls);
                $tr.append(`<td class="col-del" style="text-align:center"><button data-i="${idx}" type="button" class="qof-btn-del" style="cursor:pointer">❌</button></td>`);
                tbody.append($tr);
            });
            checkReady(); updateStockBadge();
        }

        function applyStocksToUI(stockMap){
          if(!stockMap) return;
          Object.keys(stockMap).forEach(function(id){
            const stock = parseInt(stockMap[id] || 0,10);
            const p = allProducts.find(x => String(x.id) === String(id));
            if(p){ p.stock = stock; }
          });
          updateStockBadge();
          refreshAddButtonState();
          renderPickerList();
        }

        function addProductToItems(pid, qtyRequested){
            const requestedQty = clampQty(qtyRequested, null);
            const totalStock = baseStockById(pid);
            const avail = availableStock(pid);
            const name  = findLabelById(pid) || '(بدون نام)';

            function setPendingRow(){
                const existingIdx = items.findIndex(x => String(x.id) === String(pid));
                if (existingIdx >= 0) {
                    items[existingIdx].qty = 0;
                    items[existingIdx].locked_zero = true;
                    items[existingIdx].req_qty = requestedQty;
                } else {
                    items.push({id: pid, name, qty: 0, req_qty: requestedQty, locked_zero: true});
                }
            }

            if (totalStock <= 0 || requestedQty > totalStock || requestedQty > avail) {
                setPendingRow();
                return true;
            }

            if (avail <= 0) return false;
            const qty = clampQty(requestedQty, avail);
            if (qty < 1) return false;
            items.push({id: pid, name, qty});
            return true;
        }

        $('#qof-items-table').on('click','.qof-row-inc', function(){
            const idx = +$(this).data('i');
            if (items[idx] && items[idx].locked_zero) return;
            const pid = items[idx].id;
            const stock = baseStockById(pid);
            const sumOthers = items.reduce((a,b,i)=> i===idx ? a : (String(b.id)===String(pid) ? a+(+b.qty||0) : a), 0);
            const rowMax = Math.max(0, stock - sumOthers);
            if(items[idx].qty < rowMax){ items[idx].qty++; }
            renderTable();
        });
        $('#qof-items-table').on('click','.qof-row-dec', function(){
            const idx = +$(this).data('i');
            if (items[idx] && items[idx].locked_zero) return;
            items[idx].qty = Math.max(1, (+items[idx].qty||1)-1);
            renderTable();
        });
        $('#qof-items-table').on('change input','.qof-row-qty', function(){
            const idx = +$(this).data('i');
            if (items[idx] && items[idx].locked_zero) { items[idx].qty = 0; renderTable(); return; }
            const pid = items[idx].id;
            const stock = baseStockById(pid);
            const sumOthers = items.reduce((a,b,i)=> i===idx ? a : (String(b.id)===String(pid) ? a+(+b.qty||0) : a), 0);
            const rowMax = Math.max(0, stock - sumOthers);
            const v = clampQty($(this).val(), rowMax);
            items[idx].qty = Math.max(1, v);
            renderTable();
        });
        $('#qof-items-table').on('click','.qof-btn-del',function(){
            items.splice($(this).data('i'),1);
            renderTable();
        });

        function checkReady(){
            const hasItems = items.length > 0;
            const hasZeroQty = items.some(it => (parseInt(it && it.qty, 10) || 0) === 0);
            $('#qof-btn-save').prop('disabled', !hasItems || hasZeroQty);
            $('#qof-btn-hold').prop('disabled', !hasItems);
        }

        $('#qof-address,#qof-cust-name,#qof-cust-phone').on('input', checkReady);

        let submitType = 'processing';
        $('#qof-btn-save').on('click', function(){ submitType = 'processing'; });
        $('#qof-btn-hold').on('click', function(){ submitType = 'on-hold'; $('#qof-form').trigger('submit'); });

        let submitting = false;
        $('#qof-form').on('submit', function(e){
            e.preventDefault();
            if (submitting) return false;

            // reset msg
            $('#qof-msg').removeClass('ok err').hide().text('');

            if (items.length === 0){
                $('#qof-msg').removeClass('ok').addClass('err').text('هیچ آیتمی انتخاب نشده.').show();
                return false;
            }

            if (items.some(it => (parseInt(it && it.qty, 10) || 0) === 0)) submitType = 'on-hold';

            const address   = ($('#qof-address').val()||'').trim();
            const custName  = ($('#qof-cust-name').val()||'').trim();
            const custPhone = ($('#qof-cust-phone').val()||'').trim();

            // ✅ NEW: require fields for all sellers except "هاجر رستمی"
            const isHajar = (sellerName === 'هاجر رستمی');
            if (!isHajar) {
                if (!custName) {
                    $('#qof-msg').removeClass('ok').addClass('err').text('نام و نام خانوادگی الزامی است.').show();
                    return false;
                }
                 if (!custPhone) {
                    $('#qof-msg').removeClass('ok').addClass('err').text('شماره موبایل الزامی است.').show();
                    return false;
                }

                // ✅ NEW: normalize Persian/Arabic digits to English (validation only; do NOT change input UI)
                const phoneNorm = String(custPhone)
                  .replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
                  .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));

                // ✅ Iranian mobile format validation: ^09\d{9}$
                if (!/^09\d{9}$/.test(phoneNorm)) {
                    $('#qof-msg').removeClass('ok').addClass('err').text('شماره موبایل معتبر نیست.').show();
                    return false;
                }
                if (!address) {
                    $('#qof-msg').removeClass('ok').addClass('err').text('آدرس الزامی است.').show();
                    return false;
                }
            }

            let deliveryType = '';
            if (isTehranpars) deliveryType = $('input[name="qof_delivery"]:checked').val() || 'home';

            $('#qof-hidden-items').val(JSON.stringify(items));
            $('#qof-hidden-address').val(address);
            $('#qof-hidden-name').val(custName);
            $('#qof-hidden-phone').val(custPhone);
            $('#qof-hidden-code').val(userCode);
            $('#qof-hidden-delivery').val(deliveryType);

            submitting = true;

            const isHold = (submitType === 'on-hold');
            const $btnMain = $('#qof-btn-save');
            const $btnHold = $('#qof-btn-hold');

            $btnMain.prop('disabled', true).css({opacity: 0.6, cursor: 'not-allowed'});
            $btnHold.prop('disabled', true).css({opacity: 0.6, cursor: 'not-allowed'});
            if(isHold) $btnHold.text('در حال ثبت (در انتظار)...'); else $btnMain.text('در حال ثبت سفارش...');

            $.ajax({
              url: '<?php echo esc_js(admin_url("admin-ajax.php")); ?>',
              type: 'POST',
              dataType: 'json',
              data: {
                action: 'qof_place_order',
                _wpnonce_qof: $('input[name="_wpnonce_qof"]').val(),
                items: $('#qof-hidden-items').val(),
                address: $('#qof-hidden-address').val(),
                cust_name: $('#qof-hidden-name').val(),
                cust_phone: $('#qof-hidden-phone').val(),
                user_code: $('#qof-hidden-code').val(),
                delivery_type: $('#qof-hidden-delivery').val(),
                submit_type: submitType
              }
            }).done(function(res){
              if(res && res.success && res.data && res.data.ok){
                 $('#qof-msg').removeClass('ok err');
                 const stTxt = (submitType === 'on-hold') ? ' (در انتظار)' : '';
                 $('#qof-msg').addClass('ok').text('سفارش با موفقیت ثبت شد'+stTxt+'. شماره سفارش: #'+res.data.order_id).show();

                 if(res.data.stocks) applyStocksToUI(res.data.stocks);

                 items.splice(0, items.length);
                 $('#qof-address').val('').prop('readonly', false);
                 $('#qof-cust-name').val(''); $('#qof-cust-phone').val('');
                 if (isTehranpars) $('input[name="qof_delivery"][value="home"]').prop('checked', true);

                 renderTable(); updateStockBadge(); checkReady();
                 selectedProductId = '';
                 submitType = 'processing';
              } else {
                 const msg = (res && res.data && res.data.err) ? res.data.err : 'خطای نامشخص در ثبت سفارش.';
                 $('#qof-msg').removeClass('ok').addClass('err').text(msg).show();
              }
            }).fail(function(){
              $('#qof-msg').removeClass('ok').addClass('err').text('عدم ارتباط با سرور. دوباره تلاش کن.').show();
            }).always(function(){
              submitting = false;
              $btnMain.css({opacity: 1, cursor: ''}).text('✅ ثبت نهایی سفارش');
              $btnHold.css({opacity: 1, cursor: ''}).text('⏸️ در انتظار');
              checkReady();
            });
        });

        checkReady();
    });
    </script>
    <?php
    return ob_get_clean();
});

/*======================================
=  AJAX: Place Order
======================================*/
function qof_pending_calc_map($pending_ids, $pending_req, $currency = ''){
    $map = [];
    $sum = 0.0;

    foreach ((array)$pending_ids as $pid) {
        $pid = absint($pid);
        if (!$pid) continue;

        $p = wc_get_product($pid);
        if (!$p) continue;

        $qty  = (int) ($pending_req[$pid] ?? 0);
        $unit = (float) $p->get_price(); // قیمت فعلی محصول در ووکامرس
        $line = $unit * max(0, $qty);

        $map[$pid] = [
            'unit' => $unit,
            'qty'  => $qty,
            'line' => $line,
        ];
        $sum += $line;
    }

    return ['map'=>$map, 'sum'=>$sum];
}

add_action('wp_ajax_qof_place_order', 'qof_place_order_ajax');
add_action('wp_ajax_nopriv_qof_place_order', 'qof_place_order_ajax');

function qof_place_order_ajax(){
    if ( empty($_POST['_wpnonce_qof']) || ! wp_verify_nonce( wp_unslash($_POST['_wpnonce_qof']), 'qof_place_order_form') ) {
        wp_send_json_error(['err' => 'خطای امنیتی (Nonce).']);
    }

    $raw           = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items         = json_decode($raw, true);
    $address       = isset($_POST['address'])        ? sanitize_textarea_field( wp_unslash($_POST['address']) ) : '';
    $cust_name     = isset($_POST['cust_name'])      ? sanitize_text_field( wp_unslash($_POST['cust_name']) )   : '';
    $cust_phone    = isset($_POST['cust_phone'])     ? sanitize_text_field( wp_unslash($_POST['cust_phone']) )  : '';
    $user_code     = isset($_POST['user_code'])      ? sanitize_text_field( wp_unslash($_POST['user_code']) )   : '';
    $delivery_type = isset($_POST['delivery_type'])  ? sanitize_text_field( wp_unslash($_POST['delivery_type']) ) : '';
    $submit_type   = isset($_POST['submit_type'])    ? sanitize_text_field( wp_unslash($_POST['submit_type']) ) : 'processing';

    if ( ! in_array($submit_type, ['processing','on-hold'], true) ) $submit_type = 'processing';
    if ( ! is_array($items) || empty($items) ) wp_send_json_error(['err'=>'هیچ آیتمی ارسال نشده است.']);

   $map    = qof_sellers();
    $seller = isset($map[$user_code]) ? $map[$user_code] : '';

    // ✅ NEW: hard validation for required fields (initial order creation only)
    // Optional ONLY for seller exactly "هاجر رستمی"
    if ($seller !== 'هاجر رستمی') {
        if ($cust_name === '') {
            wp_send_json_error(['err' => 'نام و نام خانوادگی الزامی است.']);
        }
          if ($cust_phone === '') {
            wp_send_json_error(['err' => 'شماره موبایل الزامی است.']);
        }

        // ✅ NEW: normalize Persian/Arabic digits to English before regex (validation only)
        $cust_phone_norm = qof_normalize_digits($cust_phone);

        // ✅ Iranian mobile format validation: ^09\d{9}$ (only for non-Hajar)
        if ( ! preg_match('/^09\d{9}$/', $cust_phone_norm) ) {
            wp_send_json_error(['err' => 'شماره موبایل معتبر نیست.']);
        }
        if ($address === '') {
            wp_send_json_error(['err' => 'آدرس الزامی است.']);
        }
    }

    $user = wp_get_current_user();
    $uid  = (int) ($user->ID ?? 0);
    $ip   = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash($_SERVER['REMOTE_ADDR']) ) : '';

    $req = [];
    $pending = [];
    $pending_req = []; // pid => requested qty
    $prod_cache = [];
    $get_prod = function($pid) use (&$prod_cache){
        $pid = (int)$pid;
        if (!isset($prod_cache[$pid])) $prod_cache[$pid] = wc_get_product($pid);
        return $prod_cache[$pid];
    };

    foreach($items as $it){
        $pid = isset($it['id'])  ? absint($it['id']) : 0;
        $qty_raw = $it['qty'] ?? 0;
        $qty = (int) qof_normalize_digits($qty_raw);
        $req_raw = $it['req_qty'] ?? 0;
        $req_qty = (int) qof_normalize_digits($req_raw);

        if(!$pid) continue;
        if($qty < 0) wp_send_json_error(['err'=>'تعداد منفی معتبر نیست.']);
        if($req_qty < 0) wp_send_json_error(['err'=>'تعداد درخواستی منفی معتبر نیست.']);

        if($qty === 0){
            $pending[$pid] = 1;
            if ($req_qty > 0) $pending_req[$pid] = $req_qty;
            continue;
        }
        $req[$pid] = ($req[$pid] ?? 0) + $qty;
    }

    $pending_ids = array_values(array_unique(array_map('absint', array_keys($pending))));
    if (!empty($pending_ids) && !empty($req)) {
        $pending_ids = array_values(array_diff($pending_ids, array_map('absint', array_keys($req))));
    }
    if(empty($req) && empty($pending_ids)) wp_send_json_error(['err'=>'آیتم معتبر یافت نشد.']);
    if (!empty($pending_ids)) $submit_type = 'on-hold';

    // Stock check
    $errors = [];
    foreach($req as $pid=>$qty){
        $product = $get_prod($pid);
        if( ! $product ){ $errors[] = "محصول #$pid یافت نشد."; continue; }
        $stock = (int) ($product->get_stock_quantity() ?? 0);
        if ( $qty > $stock ){
            $errors[] = sprintf('موجودی کافی برای «%s» نیست. درخواست: %d | موجودی: %d', $product->get_name(), $qty, $stock);
        }
    }
    if(!empty($errors)) wp_send_json_error(['err'=>implode("\n", $errors)]);

    try{
        $order = wc_create_order([ 'customer_id' => $uid ?: 0 ]);

        foreach($req as $pid=>$qty){
            $product = $get_prod($pid);
            if( $product ) $order->add_product( $product, $qty );
        }

        if (!empty($pending_ids)) $order->update_meta_data('_wc_qof_pending_items', $pending_ids);
        else $order->delete_meta_data('_wc_qof_pending_items');

        // ✅ تغییر ۴: ذخیره تعداد درخواستی pending به متا
        $pending_req_clean = [];
        if (!empty($pending_ids)) {
            foreach ($pending_ids as $pid) {
                $pending_req_clean[(int)$pid] = (int) ($pending_req[$pid] ?? 0);
            }
        }
        if (!empty($pending_req_clean)) $order->update_meta_data('_wc_qof_pending_req_qty', $pending_req_clean);
        else $order->delete_meta_data('_wc_qof_pending_req_qty');
        if (!empty($pending_ids)) {
    $calc = qof_pending_calc_map($pending_ids, $pending_req_clean, $order->get_currency());
    $order->update_meta_data('_wc_qof_pending_price_map', $calc['map']);
    $order->update_meta_data('_wc_qof_pending_total', (float) $calc['sum']);
} else {
    $order->delete_meta_data('_wc_qof_pending_price_map');
    $order->delete_meta_data('_wc_qof_pending_total');
}

        $addr = [
            'first_name' => $cust_name,
            'last_name'  => '',
            'address_1'  => $address,
            'address_2'  => '',
            'city'       => '',
            'state'      => '',
            'postcode'   => '',
            'country'    => '',
            'phone'      => $cust_phone,
            'email'      => $uid ? ($user->user_email ?? '') : '',
        ];
        $order->set_address($addr, 'billing');
        $order->set_address($addr, 'shipping');

        $note_lines = [];
        if ($user_code)  $note_lines[] = 'کد کاربر: '.$user_code;
        if ($ip)         $note_lines[] = 'IP: '.$ip;
        if ($cust_name)  $note_lines[] = 'نام مشتری: '.$cust_name;
        if ($cust_phone) $note_lines[] = 'موبایل مشتری: '.$cust_phone;
        if ($seller)     $note_lines[] = 'فروشنده: '.$seller;

        if ($delivery_type) {
            $note_lines[] = ($delivery_type === 'branch') ? 'نحوه تحویل: تحویل در شعبه تهرانپارس' : 'نحوه تحویل: ارسال درب منزل (پست)';
        }

        $note_lines[] = ($submit_type === 'on-hold') ? 'نوع ثبت: در انتظار' : 'نوع ثبت: نهایی';
        $note_lines[] = 'ثبت از «WC Quick Order Box» (AJAX).';

        // ✅ تغییر ۴: pending را در order_note ننویس
        $order->add_order_note( implode(" | ", $note_lines) );
        if ($seller) $order->set_customer_note('فروشنده: '.$seller);

        if ($user_code)      $order->update_meta_data('_wc_qof_user_code', $user_code);
        if ($cust_name)      $order->update_meta_data('_wc_qof_customer_fullname', $cust_name);
        if ($cust_phone)     $order->update_meta_data('_wc_qof_customer_phone', $cust_phone);
        if ($delivery_type)  $order->update_meta_data('_wc_qof_delivery_type', $delivery_type);
        $order->update_meta_data('_wc_qof_submit_type', $submit_type);

        $order->calculate_totals(false);
        $target_status = ($submit_type === 'on-hold') ? 'on-hold' : 'processing';
        $order->update_status($target_status, 'ثبت از فرم سفارش سریع (AJAX).', true);
        $order->save();

        $order_id = $order->get_id();

        if ( function_exists('wc_maybe_reduce_stock_levels') ) {
            wc_maybe_reduce_stock_levels( $order_id );
        }

        $newstocks = [];
        foreach (array_keys($req) as $pid) {
            $pp = wc_get_product($pid);
            $newstocks[$pid] = (int) ($pp ? ($pp->get_stock_quantity() ?? 0) : 0);
        }

        qof_bust_products_cache();

        wp_send_json_success([
            'ok'       => 1,
            'order_id' => $order_id,
            'stocks'   => $newstocks,
        ]);

    } catch (Throwable $e){
        wp_send_json_error(['err'=>'خطا در ایجاد سفارش: '.$e->getMessage()]);
    }
}

/*======================================
=  Orders shortcode: [qof_orders code="910" per_page="100" status="..."]
======================================*/
add_shortcode('qof_orders', function($atts){
    if ( ! function_exists('wc_get_orders') ) {
        return '<div style="color:#b91c1c">WooCommerce فعال نیست.</div>';
    }

    qof_enqueue_front_assets();

    $atts = shortcode_atts([
        'code'     => '',
        'per_page' => '100',
        'status'   => '',
    ], $atts, 'qof_orders');

    $url_code = '';
    if (isset($_GET['code']))   $url_code = sanitize_text_field(wp_unslash($_GET['code']));
    if (isset($_GET['key']))    $url_code = sanitize_text_field(wp_unslash($_GET['key']));
    if (isset($_GET['seller'])) $url_code = sanitize_text_field(wp_unslash($_GET['seller']));

    $seller_code = $url_code !== '' ? $url_code : (string)$atts['code'];
    $seller_code = trim($seller_code);

    if ($seller_code === '') {
        return '<div style="color:#b91c1c">کد فروشنده مشخص نشده است.</div>';
    }

    $statuses = [];
    if (!empty($atts['status'])) {
        $statuses = array_filter(array_map('trim', explode(',', (string)$atts['status'])));
    } else {
        $statuses = ['pending','processing','on-hold','completed','cancelled','failed','refunded'];
    }

    $per_page = max(1, intval($atts['per_page']));

    $orders = wc_get_orders([
        'type'       => 'shop_order',
        'status'     => $statuses,
        'limit'      => $per_page,
        'orderby'    => 'date',
        'order'      => 'DESC',
        'return'     => 'objects',
        'meta_query' => [
            [
                'key'   => '_wc_qof_user_code',
                'value' => $seller_code,
            ]
        ],
    ]);

    if (is_array($orders) && !empty($orders)) {
        usort($orders, function($a, $b){
            if ( ! $a instanceof WC_Order || ! $b instanceof WC_Order ) return 0;
            $a_hold = ((string) $a->get_status() === 'on-hold');
            $b_hold = ((string) $b->get_status() === 'on-hold');
            if ($a_hold !== $b_hold) return $a_hold ? -1 : 1;
            $a_date = $a->get_date_created();
            $b_date = $b->get_date_created();
            $a_ts   = $a_date ? $a_date->getTimestamp() : 0;
            $b_ts   = $b_date ? $b_date->getTimestamp() : 0;
            return $b_ts <=> $a_ts;
        });
    }

    $nonce      = wp_create_nonce('qof_orders_nonce');
    $edit_nonce = wp_create_nonce('qof_orders_edit_nonce');

    // product dataset for edit UI
    $products = qof_get_cached_products();
    $bucketed = [];
    foreach ($products as $p){
        if(!$p) continue;
        $stock = $p->get_stock_quantity();
        if ($stock === null) $stock = 0;
        $row = ['id'=>$p->get_id(),'label'=>qof_product_label($p),'stock'=>(int)$stock];
        $cap = qof_capacity_from_product($p);
        $bucketed[$cap][] = $row;
    }
    foreach ($bucketed as $cap => &$list) usort($list, fn($a,$b)=>strcasecmp($a['label'],$b['label']));
    unset($list);

    $edit_name_select_html = ''; $edit_id_select_html = '';
    $edit_all_products = []; $rendered_caps = []; $preferred_order = [4,6,8,12,0];

    foreach ($preferred_order as $cap) {
        if (!isset($bucketed[$cap])) continue;
        $group_label = $cap > 0 ? ($cap.' نفره') : 'سایر';
        $edit_name_select_html .= '<optgroup label="'.esc_attr($group_label).'">';
        $edit_id_select_html   .= '<optgroup label="'.esc_attr($group_label).'">';
        foreach ($bucketed[$cap] as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $edit_name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_name).'</option>';
            $edit_id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_id).'</option>';
            $edit_all_products[] = $row;
        }
        $edit_name_select_html .= '</optgroup>';
        $edit_id_select_html   .= '</optgroup>';
        $rendered_caps[$cap] = true;
    }

    $others = [];
    foreach ($bucketed as $cap => $list) {
        if (isset($rendered_caps[$cap])) continue;
        foreach ($list as $row) $others[] = $row;
    }
    usort($others, fn($a,$b)=>strcasecmp($a['label'],$b['label']));
    if (!empty($others)) {
        $edit_name_select_html .= '<optgroup label="'.esc_attr('سایر').'">';
        $edit_id_select_html   .= '<optgroup label="'.esc_attr('سایر').'">';
        foreach ($others as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $edit_name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_name).'</option>';
            $edit_id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_id).'</option>';
            $edit_all_products[] = $row;
        }
        $edit_name_select_html .= '</optgroup>';
        $edit_id_select_html   .= '</optgroup>';
    }

    ob_start(); ?>
    <style>
      .qof-orders-wrap{direction:rtl}
      .qof-table{width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}
      .qof-table th,.qof-table td{padding:10px;border-top:1px solid #e5e7eb;vertical-align:top}
      .qof-table thead th{background:#f3f4f6;border-top:none;text-align:right}
      .qof-order-link{color:#2563eb;cursor:pointer;text-decoration:underline}
      .qof-details{background:#f9fafb;padding:8px 12px;border:1px dashed #d1d5db;border-radius:8px;margin-top:6px;display:none}
      .qof-badge{display:inline-block;font-size:12px;padding:3px 8px;border:1px solid #d1d5db;border-radius:999px;background:#fff}
      .qof-btn{cursor:pointer;border-radius:10px;padding:8px 12px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-size:13px}
      .qof-btn.qof-btn-secondary{background:#fff;color:#2563eb}
      .qof-btn:disabled{opacity:.6;cursor:not-allowed}
      .qof-muted{opacity:.7}
      .qof-input{border:1px solid #d1d5db;border-radius:10px;padding:6px 10px;font-size:13px}
      .qof-edit-box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-top:10px}
      /* ✅ Edit row semantic cells */
.qof-edit-box .qof-edit-col-name { text-align:right; }
.qof-edit-box .qof-edit-col-controls { text-align:center; }
.qof-edit-box .qof-edit-col-stock { text-align:right; }

.qof-edit-box .qof-edit-name-wrap{
  display:flex;
  gap:8px;
  align-items:flex-start;
  justify-content:flex-start;
}
.qof-edit-box .qof-edit-rownum{
  font-weight:700;
  opacity:.55;
  min-width:22px;
}
.qof-edit-box .qof-edit-del-icon{
  border:1px solid #e5e7eb;
  background:#fff;
  border-radius:10px;
  width:44px;
  height:44px;
  line-height:42px;
  font-size:18px;
  cursor:pointer;
}
.qof-edit-box .qof-edit-del-icon:disabled{opacity:.45;cursor:not-allowed}

/* ✅ MOBILE layout: stacked card per row */
@media (max-width: 768px){
  .qof-edit-box table thead{ display:none; }

  .qof-edit-box table,
  .qof-edit-box tbody,
  .qof-edit-box tr,
  .qof-edit-box td{
    display:block;
    width:100%;
  }

  .qof-edit-box .qof-edit-row{
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:10px;
    margin:10px 0;
    background:#fff;
  }

  /* Row 1: name full width */
  .qof-edit-box .qof-edit-row .qof-edit-col-name{
    padding:0 0 8px 0;
    font-weight:700;
  }

  /* Row 2: qty controls + delete icon button */
  .qof-edit-box .qof-edit-row .qof-edit-col-controls{
    padding:0 0 8px 0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
  }

  .qof-edit-box .qof-edit-row .qof-edit-col-controls .qof-edit-qty-wrap{
    flex:1 1 auto;
    justify-content:flex-start;
  }

  /* Delete icon on same row */
  .qof-edit-box .qof-edit-row .qof-edit-col-controls .qof-edit-del-icon{
    flex:0 0 auto;
  }

  /* Row 3: stock */
  .qof-edit-box .qof-edit-row .qof-edit-col-stock{
    padding:0;
    font-size:13px;
    opacity:.85;
  }

  /* keep deleted highlight on mobile too */
  .qof-edit-box .qof-edit-row.qof-edit-row-deleted{
    background:#fff7ed;
  }
}

 .qof-edit-box .qof-edit-fab{
        position: fixed;
        right: 12px;
        left: 12px;
        top: auto;
        bottom: 12px;               /* ✅ stick to bottom */
        z-index: 9000;
        display: flex;
        flex-direction: row;        /* ✅ horizontal */
        gap: 10px;
        padding: 10px;
        border-radius: 16px;
        background: rgba(255,255,255,0.92);
        border: 1px solid #e5e7eb;
        box-shadow: 0 6px 18px rgba(0,0,0,0.10);
        width: auto;                /* ✅ full-width bar */
        max-width: none;
        align-items: center;
        justify-content: space-between;
      }

      .qof-edit-box .qof-edit-fab-btn{
        width: auto;                /* ✅ text button */
        height: 46px;
        border-radius: 14px;
        border: 0;
        color: #fff;
        font-size: 15px;
        font-weight: 800;
        line-height: 46px;
        text-align: center;
        cursor: pointer;
        user-select: none;
        -webkit-tap-highlight-color: transparent;
        padding: 0 14px;
        flex: 1 1 0;                /* ✅ three equal buttons */
        white-space: nowrap;
      }
      .qof-edit-box .qof-edit-fab-btn:disabled{opacity:.45;cursor:not-allowed}

      /* Colors by action (unchanged) */
      .qof-edit-box .qof-edit-fab-btn.qof-edit-add{background:#16a34a;}
      .qof-edit-box .qof-edit-fab-btn.qof-edit-save-final{background:#2563eb;}
      .qof-edit-box .qof-edit-fab-btn.qof-edit-save-hold{background:#f59e0b;}
      .qof-edit-box .qof-back-orders{background:#6b7280;}

      @media (max-width: 768px){
        .qof-edit-box .qof-edit-fab{
          right: 8px;
          left: 8px;
          bottom: 8px;
          top: auto;
          gap: 8px;
          padding: 8px;
        }
        .qof-edit-box .qof-edit-fab-btn{
          height: 44px;
          line-height: 44px;
          border-radius: 14px;
          font-size: 14px;
          padding: 0 10px;
        }
      }
           .qof-edit-box .qof-edit-cust{direction:rtl}

/* ✅ FIX: select2 dropdown معمولاً داخل body رندر می‌شود، پس اسکوپ .qof-edit-box جواب نمی‌دهد */

/*
  ✅ iOS Safari fix:
  روی آیفون، وقتی parent دارای overflow:hidden باشد، اسکرول داخلی نتایج select2
  گاهی کار نمی‌کند. پس overflow:hidden را حذف می‌کنیم و برای اسکرول داخلی iOS
  webkit scrolling و touch-action را صریح می‌کنیم.
*/

/* حالت کلی: dropdown بزرگ */
.select2-container--open .select2-dropdown{
  height: 70vh !important;          /* ✅ ارتفاع واقعی */
  max-height: 70vh !important;

  /* ✅ iOS: اجازه بده خود dropdown/children اسکرول‌پذیر باشند */
  overflow: visible !important;
  -webkit-overflow-scrolling: touch;
  touch-action: pan-y;
}

/* ناحیه نتایج: اسکرول اصلی اینجاست */
.select2-container--open .select2-results > .select2-results__options{
  max-height: calc(70vh - 56px) !important;  /* ✅ 56px حدود سرچ باکس */
  overflow-y: auto !important;

  /* ✅ iOS momentum scrolling */
  -webkit-overflow-scrolling: touch;

  /* ✅ جلوگیری از scroll chaining */
  overscroll-behavior: contain;

  /* ✅ کمک به iOS برای تشخیص اسکرول عمودی */
  touch-action: pan-y;
}

/* سرچ باکس: کمی بزرگ‌تر و خواناتر */
.select2-search--dropdown .select2-search__field{
  padding: 10px !important;
  font-size: 16px !important;
  line-height: 1.6 !important;
}

/* موبایل: حتی بزرگ‌تر */
@media (max-width: 768px){
  .select2-container--open .select2-dropdown{
    height: 70vh !important;
    max-height: 70vh !important;

    /* ✅ keep iOS behavior on mobile */
    overflow: visible !important;
    -webkit-overflow-scrolling: touch;
    touch-action: pan-y;
  }
  .select2-container--open .select2-results > .select2-results__options{
    max-height: calc(70vh - 56px) !important;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
    touch-action: pan-y;
  }
}

.qof-orders-wrap .qof-edit-box .select2-search--dropdown .select2-search__field{
  padding: 10px !important;
  font-size: 16px !important;
  line-height: 1.6 !important;
}

.qof-orders-wrap .qof-edit-box .select2-container .select2-selection--single{
  height: 46px !important;
  border-radius: 12px !important;
  border: 1px solid #d1d5db !important;
}

.qof-orders-wrap .qof-edit-box .select2-container--default .select2-selection--single .select2-selection__rendered{
  line-height: 44px !important;
  padding-right: 12px !important;
  padding-left: 26px !important;
}

.qof-orders-wrap .qof-edit-box .select2-container--default .select2-selection--single .select2-selection__arrow{
  height: 44px !important;
}

      @media (max-width: 768px){
        .qof-edit-box .qof-edit-cust > div:first-child{grid-template-columns:1fr !important}
      }
      .qof-edit-box table{width:100%;border-collapse:collapse}
      .qof-edit-box th,.qof-edit-box td{padding:6px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
      .qof-edit-box th{text-align:right;background:#f9fafb}
      .qof-edit-box .qof-edit-row.qof-edit-row-deleted{
        background:#fff7ed; /* very light orange */
      }
      .qof-edit-box .qof-edit-qty-wrap{display:inline-flex;align-items:center;gap:6px;justify-content:center}
      .qof-edit-box .qof-edit-qty-btn{
        border:0;
        color:#fff;
        font-weight:800;
        line-height:1;
        padding:8px 10px;
        border-radius:8px;
        cursor:pointer;
        user-select:none;
        min-width:34px;
        text-align:center;
      }
      .qof-edit-box .qof-edit-qty-dec{background:#dc2626;} /* red */
      .qof-edit-box .qof-edit-qty-inc{background:#16a34a;} /* green */
      .qof-edit-box .qof-edit-qty-btn:disabled{opacity:.45;cursor:not-allowed}
      .qof-edit-row-muted{opacity:.6}
      .qof-btn.qof-btn-final{border:1px solid #2563eb;background:#2563eb;color:#fff}
      .qof-btn.qof-btn-hold{border:1px solid #f59e0b;background:#fffbeb;color:#92400e;font-weight:700}
      @media (max-width: 768px){
        .qof-table th,.qof-table td{font-size:13px}
        .qof-details{font-size:13px}
      }
    </style>

    <div class="qof-orders-wrap" data-nonce="<?php echo esc_attr($nonce); ?>" data-edit-nonce="<?php echo esc_attr($edit_nonce); ?>" data-seller="<?php echo esc_attr($seller_code); ?>">
        <div style="display:flex;justify-content:flex-end;margin:10px 0">
  <button
    id="qof-sync-pending-btn"
    style="
      padding:14px 22px;
      font-size:16px;
      font-weight:700;
      border-radius:14px;
      background:#dc2626;
      color:#fff;
      border:0;
      cursor:pointer;
    ">
    🔄 بررسی و تکمیل سفارش‌های در انتظار
  </button>
</div>

      <div style="margin:8px 0 12px">
        <span class="qof-badge">کد فروشنده: <strong><?php echo esc_html($seller_code); ?></strong></span>
        <span class="qof-muted" style="margin-inline-start:10px">نمایش عمومی سفارش‌های ثبت‌شده با همین کد</span>
      </div>

      <?php if (empty($orders)) : ?>
        <div style="color:#374151;background:#f3f4f6;border:1px solid #e5e7eb;padding:10px;border-radius:8px">هیچ سفارشی یافت نشد.</div>
      <?php else: ?>
      <div class="qof-table-wrap" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">
        <table class="qof-table">
          <thead>
            <tr>
              <th style="width:120px">شماره سفارش</th>
              <th style="width:120px">وضعیت</th>
              <th style="width:160px">تاریخ</th>
              <th style="width:180px">نام مشتری</th>
              <th style="width:140px">موبایل</th>
              <th>آدرس</th>
              <th style="width:140px">جمع کل</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($orders as $order):
              $oid   = $order->get_id();
              $status= wc_get_order_status_name( $order->get_status() );
              $has_pending = ! empty( qof_get_pending_item_ids($order) );
              $date  = $order->get_date_created() ? $order->get_date_created()->date_i18n('Y/m/d H:i') : '';
              $bn    = trim(($order->get_billing_first_name().' '.$order->get_billing_last_name()));
              $phone = $order->get_billing_phone();
              $addr1 = $order->get_billing_address_1();
              $total = $order->get_formatted_order_total();
          ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <a class="qof-order-link" data-oid="<?php echo esc_attr($oid); ?>" href="javascript:void(0)">#<?php echo esc_html($oid); ?></a>
                  <button type="button" class="qof-sync-one-order" data-oid="<?php echo esc_attr($oid); ?>" <?php echo $has_pending ? '' : 'disabled'; ?> style="border:1px solid #16a34a;background:<?php echo $has_pending ? '#ecfdf5' : '#f3f4f6'; ?>;color:<?php echo $has_pending ? '#166534' : '#6b7280'; ?>;border-radius:8px;padding:4px 8px;cursor:<?php echo $has_pending ? 'pointer' : 'not-allowed'; ?>;font-size:12px">✅ تکمیل</button>
                </div>
                <div id="qof-details-<?php echo esc_attr($oid); ?>" class="qof-details"></div>
              </td>
              <td class="qof-status-cell" data-oid="<?php echo esc_attr($oid); ?>" data-status="<?php echo esc_attr($order->get_status()); ?>"><?php echo esc_html($status); ?></td>
              <td><?php echo esc_html($date); ?></td>
              <td><?php echo esc_html($bn ?: '—'); ?></td>
              <td><?php echo esc_html($phone ?: '—'); ?></td>
              <td><?php echo esc_html($addr1 ?: '—'); ?></td>
              <td><?php echo wp_kses_post($total); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <script>
    (function(){
      const wrap = document.querySelector('.qof-orders-wrap');
      const qofOverlay = document.createElement('div');
qofOverlay.style.cssText = `
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.55);
  z-index:99999;
  display:none;
  align-items:center;
  justify-content:center;
  color:#fff;
  font-size:22px;
  font-weight:800;
`;
qofOverlay.innerHTML = '⚠️ مهم: لطفاً از این صفحه خارج نشوید';
document.body.appendChild(qofOverlay);

      if(!wrap) return;

      const nonce = wrap.getAttribute('data-nonce');
      const editNonce = wrap.getAttribute('data-edit-nonce');
      const seller = wrap.getAttribute('data-seller');
      const ajaxUrl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
      const allProducts = <?php echo wp_json_encode($edit_all_products); ?>;
      const editSelectHtmlById = <?php echo wp_json_encode($edit_id_select_html); ?>;
      const editSelectHtmlByName = <?php echo wp_json_encode($edit_name_select_html); ?>;

      // ✅ NEW: Android UX — هنگام اسکرول، کیبورد پایین برود (blur فیلد فوکوس‌شده)
      const isAndroid = /Android/i.test(navigator.userAgent || '');
      let _qofScrollBlurTick = 0;
      function qofMaybeHideKeyboardOnScroll(){
  if (!isAndroid) return;
  if (_qofScrollBlurTick) return;

  _qofScrollBlurTick = window.requestAnimationFrame(function(){
    _qofScrollBlurTick = 0;

    const ae = document.activeElement;
    if (!ae) return;

    const tag = (ae.tagName || '').toUpperCase();
    const isTextInput =
      tag === 'INPUT' ||
      tag === 'TEXTAREA' ||
      ae.isContentEditable === true;

    if (!isTextInput) return;

    const withinSelect2 =
      ae.closest &&
      (ae.closest('.select2-container') || ae.closest('.select2-dropdown'));

    // ✅ اگر داخل select2 هستیم، فقط وقتی blur کن که اسکرول واقعاً روی لیست نتایج انجام شده باشد
    if (withinSelect2) {
      // اگر فوکوس روی سرچ select2 است و کاربر دارد اسکرول می‌کند، blur کن تا کیبورد جمع شود
      const isSelect2Search = ae.classList && ae.classList.contains('select2-search__field');
      if (isSelect2Search) {
        try { ae.blur(); } catch(e){}
      }
      return;
    }

    // ✅ اسکرول صفحه (غیر از select2): blur
    try { ae.blur(); } catch(e){}
  });
}
      //window.addEventListener('scroll', qofMaybeHideKeyboardOnScroll, { passive: true });
      //if (wrap) wrap.addEventListener('scroll', qofMaybeHideKeyboardOnScroll, { passive: true });
      // ✅ NEW: اگر کاربر داخل نتایج select2 اسکرول کرد، سرچ blur شود تا کیبورد اندروید پایین برود
document.addEventListener('touchmove', function(ev){
  if (!isAndroid) return;
  const t = ev && ev.target ? ev.target : null;
  if (!t || !t.closest) return;
  if (t.closest('.select2-results__options')) {
    const ae = document.activeElement;
    if (ae && ae.classList && ae.classList.contains('select2-search__field')) {
      try { ae.blur(); } catch(e){}
    }
  }
}, { passive: true });

      function escapeHtml(str){
        return String(str == null ? '' : str)
          .replace(/&/g,'&amp;')
          .replace(/</g,'&lt;')
          .replace(/>/g,'&gt;')
          .replace(/\"/g,'&quot;')
          .replace(/'/g,'&#039;');
      }

      function nextStatusFrom(slug){
        if (slug === 'processing') return 'on-hold';
        if (slug === 'on-hold') return 'processing';
        return '';
      }
      function toggleBtnTextByCurrent(slug){
        if (slug === 'processing') return 'تغییر به در انتظار';
        if (slug === 'on-hold') return 'تغییر به در حال انجام';
        return '';
      }
      function loadingTpl(){ return '<div style="padding:6px 2px">در حال دریافت جزییات...</div>'; }

      function detailsTpl(data){
        if(!data || !data.items) return '<div style="color:#991b1b">خطا در دریافت اطلاعات.</div>';

        let html = '';
        const statusSlug = data.status_slug || '';
        const statusName = data.status_name || '';
         if (data && data.seller_name) { window.qofSellerName = String(data.seller_name); }
        const canEdit = (statusSlug === 'on-hold');
        const hasZeroQty = !!data.has_zero_qty;

        const nextSlug = nextStatusFrom(statusSlug);
        const btnText = toggleBtnTextByCurrent(statusSlug);

        html += '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:6px 0 10px">';
        if (statusName) html += '<span class="qof-badge">وضعیت: <strong class="qof-status-name" data-oid="'+escapeHtml(data.order_id||'')+'">'+escapeHtml(statusName)+'</strong></span>';

        if (nextSlug && btnText) {
          if (nextSlug === 'processing' && hasZeroQty) {
            html += '<button type="button" class="qof-btn" disabled>'+escapeHtml(btnText)+'</button>';
            html += '<span class="qof-muted">تا زمانی که آیتم با تعداد ۰ وجود دارد، امکان تغییر به پردازش نیست.</span>';
          } else {
            html += '<button type="button" class="qof-btn qof-toggle-status" data-oid="'+escapeHtml(data.order_id||'')+'">'+escapeHtml(btnText)+'</button>';
          }
        }

        if (canEdit) html += '<button type="button" class="qof-btn qof-btn-secondary qof-edit-order" data-oid="'+escapeHtml(data.order_id||'')+'">ویرایش سفارش</button>';
        html += '</div>';

     html += '<div class="qof-view-items">';
html += '<div style="margin:4px 0 8px; font-weight:600">اقلام سفارش</div>';
html += '<div style="overflow:auto"><table style="width:100%;border-collapse:collapse">';
html += '<thead><tr><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:40px">#</th><th style="text-align:right;border-bottom:1px solid #e5e7eb;padding:6px">محصول</th><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:90px">تعداد</th><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:120px">جمع جزء</th><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:120px">مبلغ خط</th></tr></thead><tbody>';
data.items.forEach(function(it, i){
  const rq = parseInt(it.req_qty || 0, 10);
  const pendingTxt = (parseInt(it.qty||0,10)===0 && rq>0) ? ('<div class="qof-muted" style="font-size:12px;margin-top:2px">تعداد درخواستی: '+rq+'</div>') : '';
  html += '<tr><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+String(i+1)+'</td><td style="padding:6px;border-bottom:1px solid #f3f4f6">'+escapeHtml(it.name||'')+pendingTxt+'</td><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+escapeHtml(it.qty||0)+'</td><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+(it.subtotal||'')+'</td><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+(it.total||'')+'</td></tr>';
});
html += '</tbody></table></div>';
html += '</div>'; // .qof-view-items


        if (canEdit) {
          html += '<div class="qof-edit-box" data-oid="'+escapeHtml(data.order_id||'')+'" style="display:none">';

          // ✅ NEW: editable customer fields (on-hold only)
          const custFull  = escapeHtml((data && data.customer_fullname) ? data.customer_fullname : '');
          const custPhone = escapeHtml((data && data.customer_phone) ? data.customer_phone : '');
          const custAddr  = escapeHtml((data && data.customer_address) ? data.customer_address : '');

          html += '<div class="qof-edit-cust" style="margin:2px 0 10px;display:grid;gap:8px">';
          html += '  <div style="display:grid;gap:6px;grid-template-columns:1fr 1fr">';
          html += '    <div style="min-width:200px">';
          html += '      <label class="qof-muted" style="display:block;margin-bottom:4px">نام و نام‌خانوادگی</label>';
          html += '      <input type="text" class="qof-input qof-edit-cust-name" value="'+custFull+'" placeholder="مثال: محسن رضایی" style="width:100%">';
          html += '    </div>';
          html += '    <div style="min-width:180px">';
          html += '      <label class="qof-muted" style="display:block;margin-bottom:4px">شماره موبایل</label>';
          html += '      <input type="tel" class="qof-input qof-edit-cust-phone" value="'+custPhone+'" placeholder="۰۹۱۲۳۴۵۶۷۸۹" style="width:100%">';
          html += '    </div>';
          html += '  </div>';
          html += '  <div>';
          html += '    <label class="qof-muted" style="display:block;margin-bottom:4px">آدرس</label>';
          html += '    <textarea class="qof-input qof-edit-cust-address" rows="2" placeholder="اختیاری/الزامی مطابق قوانین" style="width:100%;resize:vertical">'+custAddr+'</textarea>';
          html += '  </div>';
          html += '</div>';
          html += '<div class="qof-muted" style="margin:2px 0 10px">در ویرایش: عدد ۰ یعنی «در انتظار». فقط دکمه حذف، حذف واقعی انجام می‌دهد.</div>';
          html += '<div class="qof-edit-msg" style="display:none;margin:6px 0;padding:8px;border-radius:10px;border:1px solid #e5e7eb;background:#f9fafb"></div>';
          html += '<div style="overflow:auto"><table>';
html += '<thead><tr><th>محصول</th><th style="width:260px;text-align:center">تعداد / حذف</th><th style="width:160px;text-align:right">موجودی</th></tr></thead>';
html += '<tbody class="qof-edit-tbody">';

         data.items.forEach(function(it, i){
  const pid = (it && (it.id || it.id === 0)) ? String(it.id) : '';
  const stockNum = (it && (it.stock || it.stock === 0)) ? String(it.stock) : '-';
  const qty = (it && (it.qty || it.qty === 0)) ? String(it.qty) : '0';
  const rq = (it && (it.req_qty || it.req_qty === 0)) ? String(it.req_qty) : '0';

  const hasReq = (parseInt(qty,10)===0 && parseInt(rq,10)>0);
  const rqTxt = hasReq
    ? (
        '<div class="qof-muted" style="font-size:12px;margin-top:6px;display:flex;align-items:center;gap:8px">' +
          '<span>تعداد درخواستی: ' + escapeHtml(rq) + '</span>' +
          '<button type="button" class="qof-edit-apply-req" title="Apply requested quantity" ' +
            'style="border:1px solid #d1d5db;background:#fff;border-radius:8px;padding:2px 8px;cursor:pointer;font-size:14px;line-height:1">' +
            '📥' +
          '</button>' +
        '</div>'
      )
    : '';

  const qtyInputDisabledAttr = (hasReq ? 'disabled' : '');

  html += '<tr class="qof-edit-row" data-pid="'+escapeHtml(pid)+'" data-orig="'+escapeHtml(qty)+'" data-deleted="0" data-req="'+escapeHtml(rq)+'">';

  // ✅ Row 1 on mobile: product name (full width)
  html += '  <td class="qof-edit-col-name">';
  html += '    <div class="qof-edit-name-wrap">';
  html += '      <span class="qof-edit-rownum">'+String(i+1)+'.</span>';
  html += '      <div>';
  html += '        <div>'+escapeHtml(it.name||'')+'</div>';
  html +=          rqTxt;
  html += '      </div>';
  html += '    </div>';
  html += '  </td>';

  // ✅ Row 2 on mobile: qty controls + delete icon (same row)
  html += '  <td class="qof-edit-col-controls">';
  html += '    <div class="qof-edit-qty-wrap">';
  html += '      <button type="button" class="qof-edit-qty-btn qof-edit-qty-dec" aria-label="کاهش">−</button>';
  html += '      <input type="number" min="0" class="qof-input qof-edit-qty" value="'+escapeHtml(qty)+'" style="width:84px;text-align:center" '+qtyInputDisabledAttr+'>';
  html += '      <button type="button" class="qof-edit-qty-btn qof-edit-qty-inc" aria-label="افزایش">+</button>';
  html += '    </div>';
  html += '    <button type="button" class="qof-edit-remove qof-edit-del-icon" aria-label="حذف">🗑</button>';
  html += '  </td>';

  // ✅ Row 3 on mobile: stock
  html += '  <td class="qof-edit-col-stock">';
  html += '    <span class="qof-edit-stock">موجودی: '+escapeHtml(stockNum)+'</span>';
  html += '  </td>';

  html += '</tr>';
});

          html += '</tbody></table></div>';
                    html += '<div class="qof-edit-stock-line qof-muted" style="margin-top:8px;display:none"></div>';
          html += '<div class="qof-edit-controls" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">';
          html += '<select class="qof-edit-sel-id" style="min-width:220px;max-width:260px"><option value="">انتخاب ID</option>' + (editSelectHtmlById || '') + '</select>';
          html += '<select class="qof-edit-sel-name" style="min-width:260px;max-width:520px"><option value="">انتخاب محصول</option>' + (editSelectHtmlByName || '') + '</select>';

          // ✅ NEW: شمارنده تعداد قبل از افزودن
          html += '<div class="qof-edit-add-qty-wrap" style="display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:6px 8px">';
          html += '  <button type="button" class="qof-edit-add-qty-btn qof-edit-add-qty-dec" aria-label="کاهش تعداد" style="border:0;background:#dc2626;color:#fff;font-weight:900;border-radius:10px;width:38px;height:38px;cursor:pointer">−</button>';
          html += '  <input type="number" min="1" class="qof-input qof-edit-add-qty" value="1" style="width:86px;text-align:center;font-size:15px">';
          html += '  <button type="button" class="qof-edit-add-qty-btn qof-edit-add-qty-inc" aria-label="افزایش تعداد" style="border:0;background:#16a34a;color:#fff;font-weight:900;border-radius:10px;width:38px;height:38px;cursor:pointer">+</button>';
          html += '</div>';

          html += '<button type="button" class="qof-btn qof-btn-secondary qof-edit-add" style="padding:10px 14px;font-weight:800">➕ افزودن</button>';
          html += '</div>';



          // ✅ Floating action panel (bottom sticky bar)
          // Buttons (left-to-right in code) render RTL visually; keep semantic labels.
          html += '<div class="qof-edit-fab" role="toolbar" aria-label="عملیات" dir="rtl">';
          html += '  <button type="button" class="qof-edit-fab-btn qof-edit-save-final" data-oid="'+escapeHtml(data.order_id||'')+'" '+(hasZeroQty?'disabled':'')+' title="تکمیل" aria-label="تکمیل">تکمیل</button>';
          html += '  <button type="button" class="qof-edit-fab-btn qof-edit-save-hold" data-oid="'+escapeHtml(data.order_id||'')+'" title="ذخیره" aria-label="ذخیره">ذخیره</button>';
          html += '  <button type="button" class="qof-edit-fab-btn qof-back-orders" title="لغو" aria-label="لغو">لغو</button>';
          html += '</div>';
          html += '</div>';
        }


   // ✅ NEW: show sync log (when red button is used)
       if (data && data.sync_log) {
         html += '<div style="margin-top:10px;padding:10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff7ed;color:#9a3412">';
         html += '<div style="font-weight:800;margin-bottom:4px">لاگ تکمیل سفارش‌های در انتظار</div>';
         html += '<div style="white-space:pre-line;font-size:13px;line-height:1.6">' + escapeHtml(data.sync_log) + '</div>';
         html += '</div>';
       }

       if (data.totals_html) {
  html += '<div style="margin-top:8px"><span style="font-weight:600">جمع کل (اقلام موجود):</span> ' + data.totals_html + '</div>';
}
if (data.pending_total_html && data.pending_total_html !== '0') {
  html += '<div><span style="font-weight:600">جمع اقلام در انتظار:</span> ' + data.pending_total_html + '</div>';
}
if (data.combined_total_html) {
  html += '<div><span style="font-weight:700">جمع کل با اقلام در انتظار:</span> ' + data.combined_total_html + '</div>';
}
        return html;
      }

      function parseIntSafe(v, def){
        const n = parseInt(String(v == null ? '' : v), 10);
        return Number.isFinite(n) ? n : def;
      }
      function showEditMsg(editBox, msg, ok){
        const el = editBox ? editBox.querySelector('.qof-edit-msg') : null;
        if (!el) return;
        el.style.display = 'block';
        el.style.borderColor = ok ? '#16a34a' : '#991b1b';
        el.style.background = ok ? '#ecfdf5' : '#fef2f2';
        el.style.color = ok ? '#065f46' : '#991b1b';
        el.textContent = msg || '';
      }

      // dataset helpers
      function findById(id){ return allProducts.find(p => String(p.id) === String(id)); }
      function findLabelById(id){ const f = findById(id); return f ? f.label : ''; }
      function baseStockById(id){ const f = findById(id); return f ? (f.stock||0) : 0; }
      function applyStocksToData(stockMap){
        if(!stockMap) return;
        Object.keys(stockMap).forEach(function(id){
          const stock = parseIntSafe(stockMap[id], 0);
          const p = allProducts.find(x => String(x.id) === String(id));
          if(p){ p.stock = stock; }
        });
      }

      function sumEditQty(editBox, id){
        const rows = editBox ? editBox.querySelectorAll('.qof-edit-row') : [];
        let sum = 0;
        rows.forEach(function(row){
          if (String(row.getAttribute('data-pid')) !== String(id)) return;
          const qtyEl = row.querySelector('.qof-edit-qty');
          sum += Math.max(0, parseIntSafe(qtyEl ? qtyEl.value : 0, 0));
        });
        return sum;
      }
      function sumEditOrigQty(editBox, id){
        const rows = editBox ? editBox.querySelectorAll('.qof-edit-row') : [];
        let sum = 0;
        rows.forEach(function(row){
          if (String(row.getAttribute('data-pid')) !== String(id)) return;
          sum += Math.max(0, parseIntSafe(row.getAttribute('data-orig'), 0));
        });
        return sum;
      }
      function availableStock(editBox, id){
        // ✅ تغییر ۲: baseline-aware (موجودی + مقدار قبلی سفارش - مقدار فعلی)
        return Math.max(0, baseStockById(id) + sumEditOrigQty(editBox, id) - sumEditQty(editBox, id));
      }
      function getSelectedId(editBox){
        if (!editBox) return '';
        const selId = editBox.querySelector('.qof-edit-sel-id');
        const selName = editBox.querySelector('.qof-edit-sel-name');
        return (selId && selId.value) ? selId.value : ((selName && selName.value) ? selName.value : '');
      }
      function updateEditStockBadge(editBox){
        if (!editBox) return;
        const line = editBox.querySelector('.qof-edit-stock-line');
        if (!line) return;
        const pid = getSelectedId(editBox);
        if (!pid) { line.style.display = 'none'; line.textContent = ''; return; }
        const av = availableStock(editBox, pid);
        line.style.display = 'block';
        line.textContent = 'موجودی قابل انتخاب: ' + String(av);
      }

       function initEditSelect(editBox){
        const selId = editBox ? editBox.querySelector('.qof-edit-sel-id') : null;
        const selName = editBox ? editBox.querySelector('.qof-edit-sel-name') : null;
        if (!selId || !selName || editBox.getAttribute('data-inited') === '1') return;
        editBox.setAttribute('data-inited', '1');

        const $ = window.jQuery;
        const hasSelect = $ && $.fn && ($.fn.selectWoo || $.fn.select2);
        if (!hasSelect) { updateEditStockBadge(editBox); return; }

      function initSelect(el){
          const $el = $(el);

          // ✅ iPhone/iOS Safari fix:
          // When dropdown is appended to <body>, after typing in search field (especially 2nd select),
          // the results list may stop responding to scroll. Force dropdown to render inside editBox.
          const $dropdownParent = $(editBox && editBox.nodeType === 1 ? editBox : document.body);

          function normalizeSearchText(s){
            return String(s == null ? '' : s)
              .replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
              .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
              .replace(/\s+/g, ' ')
              .trim();
          }

          // ✅ Multi-word AND search: "گلستانه قرمز" => match if BOTH words exist anywhere in option text
          function qofMatcher(params, data){
            if (!params || !('term' in params) || params.term == null || params.term === '') return data;
            if (!data || typeof data.text === 'undefined') return null;

            // Handle optgroups
            if (data.children && data.children.length) {
              const term = normalizeSearchText(params.term);
              const filteredChildren = [];
              for (let i = 0; i < data.children.length; i++) {
                const child = qofMatcher({ term: term }, data.children[i]);
                if (child != null) filteredChildren.push(child);
              }
              if (filteredChildren.length) {
                const modified = $.extend({}, data, true);
                modified.children = filteredChildren;
                return modified;
              }
              return null;
            }

            const term = normalizeSearchText(params.term);
            const text = normalizeSearchText(data.text);

            const words = term.split(' ').filter(Boolean);
            for (let i = 0; i < words.length; i++) {
              if (text.indexOf(words[i]) === -1) return null;
            }
            return data;
          }

          const opts = {
            placeholder: '',
            allowClear: true,
            width: 'resolve',
            dropdownParent: $dropdownParent,
            matcher: qofMatcher
          };

          if ($.fn.selectWoo) $el.selectWoo(opts); else $el.select2(opts);
        }
        initSelect(selId); initSelect(selName);

        let syncing = false;
        function sync(fromEl, toEl){
          if (syncing) return;
          syncing = true;
          const v = fromEl.value || '';
          $(toEl).val(v).trigger('change.select2');
          syncing = false;
          updateEditStockBadge(editBox);
        }
        selId.addEventListener('change', function(){ sync(selId, selName); });
        selName.addEventListener('change', function(){ sync(selName, selId); });

        editBox.addEventListener('input', function(ev){
          const t = ev && ev.target ? ev.target : null;
          if (t && t.classList && t.classList.contains('qof-edit-qty')) updateEditStockBadge(editBox);
        });

        updateEditStockBadge(editBox);
      }
      function upsertEditRow(editBox, pid, name, stock, addQty, reqQty){
        const tbody = editBox ? editBox.querySelector('.qof-edit-tbody') : null;
        if (!tbody) return;

        const pidStr = String(pid);
        const existing = tbody.querySelector('.qof-edit-row[data-pid="'+pidStr.replace(/"/g,'')+'"]');

        function syncReqUI(row, reqQtyValue){
          if (!row) return;
          const req = Math.max(0, parseIntSafe(reqQtyValue, 0));
          row.setAttribute('data-req', String(req));

          const nameCol = row.querySelector('.qof-edit-col-name .qof-edit-name-wrap > div');
          if (!nameCol) return;

          const oldReqBox = nameCol.querySelector('.qof-edit-req-box');
          if (oldReqBox) oldReqBox.remove();

          if (req > 0) {
            const reqHtml =
              '<div class="qof-edit-req-box qof-muted" style="font-size:12px;margin-top:6px;display:flex;align-items:center;gap:8px">' +
                '<span>تعداد درخواستی: ' + escapeHtml(String(req)) + '</span>' +
                '<button type="button" class="qof-edit-apply-req" title="Apply requested quantity" ' +
                  'style="border:1px solid #d1d5db;background:#fff;border-radius:8px;padding:2px 8px;cursor:pointer;font-size:14px;line-height:1">📥</button>' +
              '</div>';
            nameCol.insertAdjacentHTML('beforeend', reqHtml);
          }
        }

        if (existing) {
          existing.setAttribute('data-deleted','0');
          existing.classList.remove('qof-edit-row-muted');
          existing.classList.remove('qof-edit-row-deleted');
          const qtyEl = existing.querySelector('.qof-edit-qty');
          const reqQtySafe = Math.max(0, parseIntSafe(reqQty, 0));
          const shouldPending = reqQtySafe > 0;
          if (shouldPending) {
            const prevReq = Math.max(0, parseIntSafe(existing.getAttribute('data-req'), 0));
            syncReqUI(existing, prevReq + reqQtySafe);
            if (qtyEl) { qtyEl.value = '0'; qtyEl.setAttribute('disabled','disabled'); }
            const incBtn = existing.querySelector('.qof-edit-qty-inc');
            const decBtn = existing.querySelector('.qof-edit-qty-dec');
            if (incBtn) incBtn.removeAttribute('disabled');
            if (decBtn) decBtn.removeAttribute('disabled');
          } else {
            syncReqUI(existing, 0);
            if (qtyEl) {
              qtyEl.removeAttribute('disabled');
              qtyEl.value = String(Math.max(0, parseIntSafe(qtyEl.value, 0) + addQty));
            }
            const incBtn = existing.querySelector('.qof-edit-qty-inc');
            const decBtn = existing.querySelector('.qof-edit-qty-dec');
            if (incBtn) incBtn.removeAttribute('disabled');
            if (decBtn) decBtn.removeAttribute('disabled');
          }
          return;
        }

const safeName = escapeHtml(name || ('#' + pidStr));
const safeStock = escapeHtml((stock || stock === 0) ? String(stock) : '-');
const rq = Math.max(0, parseIntSafe(reqQty, 0));
const hasReq = (addQty===0 && rq>0);
const rqTxt = hasReq
  ? ('<div class="qof-edit-req-box qof-muted" style="font-size:12px;margin-top:6px;display:flex;align-items:center;gap:8px"><span>تعداد درخواستی: '+escapeHtml(String(rq))+'</span><button type="button" class="qof-edit-apply-req" title="Apply requested quantity" style="border:1px solid #d1d5db;background:#fff;border-radius:8px;padding:2px 8px;cursor:pointer;font-size:14px;line-height:1">📥</button></div>')
  : '';
const qtyInputDisabledAttr = hasReq ? 'disabled' : '';

const rowHtml =
  '<tr class="qof-edit-row" data-pid="'+escapeHtml(pidStr)+'" data-orig="0" data-deleted="0" data-req="'+escapeHtml(String(rq))+'">' +

    '<td class="qof-edit-col-name">' +
      '<div class="qof-edit-name-wrap">' +
        '<span class="qof-edit-rownum">—</span>' +
        '<div>' +
          '<div>' + safeName + '</div>' +
          rqTxt +
        '</div>' +
      '</div>' +
    '</td>' +

    '<td class="qof-edit-col-controls">' +
      '<div class="qof-edit-qty-wrap">' +
        '<button type="button" class="qof-edit-qty-btn qof-edit-qty-dec" aria-label="کاهش">−</button>' +
        '<input type="number" min="0" class="qof-input qof-edit-qty" value="' + (hasReq ? '0' : escapeHtml(String(Math.max(0, addQty)))) + '" style="width:84px;text-align:center" '+qtyInputDisabledAttr+'>' +
        '<button type="button" class="qof-edit-qty-btn qof-edit-qty-inc" aria-label="افزایش">+</button>' +
      '</div>' +
      '<button type="button" class="qof-edit-remove qof-edit-del-icon" aria-label="حذف">🗑</button>' +
    '</td>' +

    '<td class="qof-edit-col-stock">' +
      '<span class="qof-edit-stock">موجودی: ' + safeStock + '</span>' +
    '</td>' +

  '</tr>';
tbody.insertAdjacentHTML('beforeend', rowHtml);


        // renumber
        const rows = tbody.querySelectorAll('.qof-edit-row');
rows.forEach(function(r, i){
  const num = r.querySelector('.qof-edit-rownum');
  if (num) num.textContent = String(i+1) + '.';
});

      }

      function collectEditItems(editBox){
        const rows = editBox ? editBox.querySelectorAll('.qof-edit-row') : [];
        const items = [];
        rows.forEach(function(row){
          const pid = parseIntSafe(row.getAttribute('data-pid'), 0);
          const qtyEl = row.querySelector('.qof-edit-qty');
          const qty = qtyEl ? parseIntSafe(qtyEl.value, 0) : 0;
          const deleted = (row.getAttribute('data-deleted') === '1') ? 1 : 0;
          const reqQty = parseIntSafe(row.getAttribute('data-req'), 0);
          if (pid > 0) items.push({ product_id: pid, qty: Math.max(0, qty), deleted: deleted, req_qty: Math.max(0, reqQty) });
        });
        return items;
      }

      wrap.addEventListener('click', function(e){
        const syncBtn = e.target.closest('#qof-sync-pending-btn');
if (syncBtn) {
  const ok = window.confirm('مطمئنی؟ همه سفارش‌های در انتظار بررسی می‌شوند و موارد قابل تامین تکمیل می‌شوند.');
  if (!ok) return;
  qofOverlay.style.display = 'flex';

  const form = new FormData();
  form.append('action', 'qof_sync_pending_orders');
  form.append('nonce', nonce);
  form.append('seller', seller);

  fetch(ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    body: form
  })
  .then(r => r.json())
  .then(res => {
    qofOverlay.style.display = 'none';
    if (res && res.success) {
      const d = res.data || {};
      alert('عملیات انجام شد.\n' +
        'تعداد سفارش بررسی‌شده: ' + String(d.checked_orders || 0) + '\n' +
        'سفارشِ تکمیل/تغییر یافته: ' + String(d.changed_orders || 0) + '\n' +
        'اقلام تخصیص‌یافته: ' + String(d.allocated_items || 0));
      location.reload();
    } else {
      alert(res?.data?.err || 'خطا در انجام عملیات');
    }
  })
  .catch(() => {
    qofOverlay.style.display = 'none';
    alert('خطا در ارتباط با سرور');
  });

  return;
}

        const syncOneBtn = e.target.closest('.qof-sync-one-order');
if (syncOneBtn) {
  const oid = String(syncOneBtn.getAttribute('data-oid') || '').trim();
  if (!oid) return;
  const ok = window.confirm('مطمئنی؟ در صورت موجود بودن، اقلام در انتظار این سفارش تکمیل می‌شود.');
  if (!ok) return;

  qofOverlay.style.display = 'flex';

  const form = new FormData();
  form.append('action', 'qof_sync_pending_orders');
  form.append('nonce', nonce);
  form.append('seller', seller);
  form.append('order_id', oid);

  fetch(ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    body: form
  })
  .then(r => r.json())
  .then(res => {
    qofOverlay.style.display = 'none';
    if (res && res.success) {
      const d = res.data || {};
      alert('بررسی سفارش انجام شد.\n' +
        'اقلام تخصیص‌یافته: ' + String(d.allocated_items || 0));
      location.reload();
    } else {
      alert(res?.data?.err || 'خطا در انجام عملیات');
    }
  })
  .catch(() => {
    qofOverlay.style.display = 'none';
    alert('خطا در ارتباط با سرور');
  });

  return;
}

        const backBtn = e.target.closest('.qof-back-orders');
        if (backBtn) {
          e.preventDefault();
          // ✅ تغییر ۶: برگشت به لیست سفارشات (همین صفحه)
          const details = e.target.closest('.qof-details');
          if (details) details.style.display = 'none';
          window.scrollTo({top: 0, behavior: 'smooth'});
          return;
        }

        const editBtn = e.target.closest('.qof-edit-order');
       if (editBtn) {
  const oid = editBtn.getAttribute('data-oid');
  if (!oid) return;
  const detailsBox = document.getElementById('qof-details-' + oid);
  const editBox = detailsBox ? detailsBox.querySelector('.qof-edit-box') : null;
  if (!editBox) return;

  const viewBox = detailsBox ? detailsBox.querySelector('.qof-view-items') : null;

  const isOpen = editBox.style.display !== 'none' && editBox.style.display !== '';
  editBox.style.display = isOpen ? 'none' : 'block';

  // ✅ فقط هنگام ویرایش: read-only مخفی شود
  if (viewBox) viewBox.style.display = isOpen ? 'block' : 'none';

  if (!isOpen) initEditSelect(editBox);
  return;
}


    const removeBtn = e.target.closest('.qof-edit-remove');
        if (removeBtn) {
          const editBox = e.target.closest('.qof-edit-box');
          const row = e.target.closest('.qof-edit-row');
          if (!row) return;
          row.setAttribute('data-deleted','1');
          const qtyEl = row.querySelector('.qof-edit-qty');
          if (qtyEl) qtyEl.value = '0';
          row.classList.add('qof-edit-row-muted');

          // ✅ NEW: soft orange background for rows marked deleted
          row.classList.add('qof-edit-row-deleted');

          if (editBox) showEditMsg(editBox, 'آیتم برای حذف واقعی علامت‌گذاری شد (ذخیره را بزنید).', true);
          if (editBox) updateEditStockBadge(editBox);
          return;
        }
        const applyReqBtn = e.target.closest('.qof-edit-apply-req');
if (applyReqBtn) {
  const editBox = e.target.closest('.qof-edit-box');
  const row = e.target.closest('.qof-edit-row');
  if (!editBox || !row) return;

  const pid = row.getAttribute('data-pid') || '';
  const reqQty = parseIntSafe(row.getAttribute('data-req'), 0);
  if (!pid || reqQty <= 0) {
    showEditMsg(editBox, 'Requested quantity not found for this row.', false);
    return;
  }

  const av = availableStock(editBox, pid);
  if (av <= 0) {
    showEditMsg(editBox, 'موجودی جهت اضافه کردن نیست', false);
    updateEditStockBadge(editBox);
    return;
  }

  const qtyEl = row.querySelector('.qof-edit-qty');
  if (!qtyEl) return;

  const finalQty = Math.max(0, Math.min(reqQty, av));
  qtyEl.value = String(finalQty);

  qtyEl.removeAttribute('disabled');
  const inc = row.querySelector('.qof-edit-qty-inc');
  const dec = row.querySelector('.qof-edit-qty-dec');
  if (inc) inc.removeAttribute('disabled');
  if (dec) dec.removeAttribute('disabled');

  row.setAttribute('data-req', '0');
  const reqBtn = row.querySelector('.qof-edit-apply-req');
  if (reqBtn && reqBtn.parentElement) {
    reqBtn.parentElement.remove();
  }

  if (reqQty > av) {
    showEditMsg(editBox, 'مقدار درخواستی به اندازه موجودی کاهش پیدا کرد. ' + av, true);
  } else {
    showEditMsg(editBox, 'تعداد درخواستی اعمال شد', true);
  }

  try {
    qtyEl.dispatchEvent(new Event('input', { bubbles: true }));
  } catch(e){}

  updateEditStockBadge(editBox);
  return;
}

const incBtn = e.target.closest('.qof-edit-qty-inc');
        const decBtn = e.target.closest('.qof-edit-qty-dec');
        if (incBtn || decBtn) {
          const editBox = e.target.closest('.qof-edit-box');
          const row = e.target.closest('.qof-edit-row');
          if (!editBox || !row) return;

          const qtyEl = row.querySelector('.qof-edit-qty');
          if (!qtyEl) return;

          const pid = row.getAttribute('data-pid') || '';
          const isInc = !!incBtn;

          if (qtyEl.disabled) {
            if (!isInc) return;
            const avPending = availableStock(editBox, pid);
            if (avPending <= 0) {
              showEditMsg(editBox, 'موجودی کافی نیست. موجودی قابل انتخاب: ' + String(avPending), false);
              updateEditStockBadge(editBox);
              return;
            }
            const reqNow = Math.max(0, parseIntSafe(row.getAttribute('data-req'), 0));
            row.setAttribute('data-req', String(reqNow + 1));
            const reqLabel = row.querySelector('.qof-edit-req-box span');
            if (reqLabel) reqLabel.textContent = 'تعداد درخواستی: ' + String(reqNow + 1);
            updateEditStockBadge(editBox);
            return;
          }

          let cur = parseIntSafe(qtyEl.value, 0);
          cur = Math.max(0, cur);

          if (isInc) {
            // ✅ respect existing baseline-aware stock availability
            const av = availableStock(editBox, pid);
            if (av <= 0) {
              showEditMsg(editBox, 'موجودی کافی نیست. موجودی قابل انتخاب: ' + String(av), false);
              updateEditStockBadge(editBox);
              return;
            }
            qtyEl.value = String(cur + 1);
          } else {
            qtyEl.value = String(Math.max(0, cur - 1));
          }

          // trigger input event so existing listeners (badge updates) run
          try { qtyEl.dispatchEvent(new Event('input', { bubbles: true })); } catch(e){}

          // hard clamp to prevent going beyond availability (safety)
          const maxNow = availableStock(editBox, pid);
          const v2 = Math.max(0, parseIntSafe(qtyEl.value, 0));
          if (v2 > (parseIntSafe(row.getAttribute('data-orig'), 0) + baseStockById(pid))) {
            // fallback clamp if something weird happens
            qtyEl.value = String(Math.max(0, v2 - 1));
          }
          if (v2 > (parseIntSafe(qtyEl.value, 0))) {
            // no-op; kept for safety
          }

          updateEditStockBadge(editBox);
          return;
        }
         // ✅ NEW: شمارنده افزودن (دکمه‌های + / -) در حالت ویرایش
        const addQtyIncBtn = e.target.closest('.qof-edit-add-qty-inc');
        const addQtyDecBtn = e.target.closest('.qof-edit-add-qty-dec');
        if (addQtyIncBtn || addQtyDecBtn) {
          const editBox = e.target.closest('.qof-edit-box');
          if (!editBox) return;

          const input = editBox.querySelector('.qof-edit-add-qty');
          if (!input) return;

          let cur = parseIntSafe(input.value, 1);
          cur = Math.max(1, cur);

          if (addQtyIncBtn) {
            input.value = String(cur + 1);
          } else {
            input.value = String(Math.max(1, cur - 1));
          }

          try { input.dispatchEvent(new Event('input', { bubbles: true })); } catch(e){}
          return;
        }

        const addBtn = e.target.closest('.qof-edit-add');
        if (addBtn) {
          const editBox = e.target.closest('.qof-edit-box');
          if (!editBox) return;
          initEditSelect(editBox);

          const pidStr = getSelectedId(editBox);
          const pid = parseIntSafe(pidStr, 0);
          if (!pid) { showEditMsg(editBox, 'ابتدا یک محصول انتخاب کنید.', false); return; }

          // ✅ NEW: خواندن مقدار شمارنده قبل از افزودن
          const qtyInput = editBox.querySelector('.qof-edit-add-qty');
          let qtyAdd = qtyInput ? parseIntSafe(qtyInput.value, 1) : 1;
          qtyAdd = Math.max(1, qtyAdd);

          const baseStock = baseStockById(pid);

          // ✅ اگر موجودی ۰ است: pending با req_qty=qtyAdd
          if (baseStock === 0) {
            const requested = qtyAdd;
            const name = findLabelById(pid) || ('#' + String(pid));
            upsertEditRow(editBox, pid, name, baseStock, 0, requested);
            showEditMsg(editBox, 'این کالا به صورت «در انتظار (qty=0)» اضافه شد. تعداد درخواستی ثبت شد. ذخیره را بزنید.', true);
            updateEditStockBadge(editBox);

            // reset
            if (qtyInput) qtyInput.value = '1';
            return;
          }

          const av = availableStock(editBox, pid);
          const name = findLabelById(pid) || ('#' + String(pid));
          if (qtyAdd > av) {
            upsertEditRow(editBox, pid, name, baseStock, 0, qtyAdd);
            showEditMsg(editBox, 'موجودی کافی نبود؛ کل تعداد درخواستی در «در انتظار» ثبت شد.', true);
            updateEditStockBadge(editBox);
            if (qtyInput) qtyInput.value = '1';
            return;
          }

          upsertEditRow(editBox, pid, name, baseStock, qtyAdd, 0);
          showEditMsg(editBox, 'آیتم اضافه شد (برای ثبت نهایی، ذخیره را بزنید).', true);
          updateEditStockBadge(editBox);

          const selId = editBox.querySelector('.qof-edit-sel-id');
          const selName = editBox.querySelector('.qof-edit-sel-name');
          const $ = window.jQuery;
          if ($ && $.fn && ($.fn.selectWoo || $.fn.select2)) {
            if (selId) $(selId).val('').trigger('change.select2');
            if (selName) $(selName).val('').trigger('change.select2');
          } else {
            if (selId) selId.value = '';
            if (selName) selName.value = '';
          }

          // reset counter
          if (qtyInput) qtyInput.value = '1';

          updateEditStockBadge(editBox);
          return;
        }
        function doSave(e, mode){
          const btn = e.target.closest(mode==='final'?'.qof-edit-save-final':'.qof-edit-save-hold');
          const oid = btn ? btn.getAttribute('data-oid') : '';
          const editBox = e.target.closest('.qof-edit-box');
          if (!oid || !editBox || !btn) return false;

          const items = collectEditItems(editBox);
          if (!items || !items.length) { showEditMsg(editBox, 'هیچ آیتمی برای ذخیره وجود ندارد.', false); return true; }

          btn.disabled = true;
          const oldText = btn.textContent;
          btn.textContent = 'در حال ذخیره...';

          const nameEl  = editBox.querySelector('.qof-edit-cust-name');
          const phoneEl = editBox.querySelector('.qof-edit-cust-phone');
          const addrEl  = editBox.querySelector('.qof-edit-cust-address');

          const custName  = (nameEl && nameEl.value ? nameEl.value : '').trim();
          const custPhone = (phoneEl && phoneEl.value ? phoneEl.value : '').trim();
          const custAddr  = (addrEl && addrEl.value ? addrEl.value : '').trim();

          // ✅ NEW: seller-based validation (match initial-create behavior)
          const sellerName = (window && window.qofSellerName) ? String(window.qofSellerName) : '';
          const isHajar = (sellerName === 'هاجر رستمی');

          function normalizeDigitsJS(s){
            return String(s||'')
              .replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
              .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
          }

          if (!isHajar) {
            if (!custName) { showEditMsg(editBox, 'نام و نام خانوادگی الزامی است.', false); btn.disabled=false; btn.textContent=oldText; return true; }
            if (!custPhone) { showEditMsg(editBox, 'شماره موبایل الزامی است.', false); btn.disabled=false; btn.textContent=oldText; return true; }
            if (!custAddr) { showEditMsg(editBox, 'آدرس الزامی است.', false); btn.disabled=false; btn.textContent=oldText; return true; }

            const phoneNorm = normalizeDigitsJS(custPhone);
            if (!/^09\d{9}$/.test(phoneNorm)) { showEditMsg(editBox, 'شماره موبایل معتبر نیست.', false); btn.disabled=false; btn.textContent=oldText; return true; }
          } else {
            // seller=Hajar: optional; if phone provided, validate it (optional requirement? => per your spec: no validation if empty, but if present and invalid should we block?)
            // You asked: "For Hajar phone may remain empty and should not be validated."
            // We'll ONLY validate if non-empty? => to match your statement strictly, we won't validate at all for Hajar.
          }

          const form = new FormData();
          form.append('action', 'qof_update_order_items');
          form.append('nonce',  editNonce);
          form.append('seller', seller);
          form.append('order_id', oid);
          form.append('items', JSON.stringify(items));
          form.append('save_mode', mode);

          // ✅ NEW: send customer fields
          form.append('cust_name',  custName);
          form.append('cust_phone', custPhone);
          form.append('address',    custAddr);

          fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form })
          .then(r => r.json())
          .then(res => {
            if (res && res.success) {
              const data = res.data || {};
              const orderData = data.order || data;
              if (data.stocks) applyStocksToData(data.stocks);

              const box = document.getElementById('qof-details-' + oid);
              if (box) { box.innerHTML = detailsTpl(orderData); box.setAttribute('data-loaded','1'); }

              const cell = wrap.querySelector('.qof-status-cell[data-oid="'+String(oid)+'"]');
              if (cell && orderData && orderData.status_name) cell.textContent = orderData.status_name;

              // total update
              const link = wrap.querySelector('.qof-order-link[data-oid="'+String(oid)+'"]');
              if (link && orderData && orderData.totals_html) {
                const tr = link.closest('tr');
                if (tr) {
                  const tds = tr.querySelectorAll('td');
                  const totalTd = tds && tds.length ? tds[tds.length - 1] : null;
                  if (totalTd) totalTd.innerHTML = orderData.totals_html;
                }
              }
            } else {
              const msg = (res && res.data && res.data.err) ? res.data.err : 'خطا در ذخیره تغییرات.';
              showEditMsg(editBox, msg, false);
            }
          })
          .catch(() => showEditMsg(editBox, 'خطا در ارتباط با سرور.', false))
          .finally(() => { btn.disabled = false; btn.textContent = oldText; });

          return true;
        }

        const saveFinalBtn = e.target.closest('.qof-edit-save-final');
        if (saveFinalBtn) { doSave(e, 'final'); return; }

        const saveHoldBtn = e.target.closest('.qof-edit-save-hold');
        if (saveHoldBtn) { doSave(e, 'hold'); return; }

        const btn = e.target.closest('.qof-toggle-status');
        if (btn) {
          const oid = btn.getAttribute('data-oid');
          if (!oid) return;
          btn.disabled = true;
          const oldText = btn.textContent;
          btn.textContent = 'در حال تغییر...';

          const form = new FormData();
          form.append('action', 'qof_toggle_order_status');
          form.append('nonce',  nonce);
          form.append('seller', seller);
          form.append('order_id', oid);

          fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form })
          .then(r => r.json())
          .then(res => {
            if(res && res.success){
              const data = res.data || {};
              const newName = data.status_name || '';
              const newSlug = data.status_slug || '';

              const box = document.getElementById('qof-details-'+oid);
              if (box) {
                const statusEl = box.querySelector('.qof-status-name');
                if (statusEl && newName) statusEl.textContent = newName;
                const nextText = toggleBtnTextByCurrent(newSlug);
                if (nextText) btn.textContent = nextText; else btn.remove();
              }

              const cell = wrap.querySelector('.qof-status-cell[data-oid="'+String(oid)+'"]');
              if (cell) {
                if (newName) cell.textContent = newName;
                if (newSlug) cell.setAttribute('data-status', newSlug);

                // refresh details
                if (box) {
                  const form2 = new FormData();
                  form2.append('action', 'qof_get_order_details');
                  form2.append('nonce',  nonce);
                  form2.append('seller', seller);
                  form2.append('order_id', oid);
                  fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form2 })
                  .then(r2 => r2.json())
                  .then(res2 => { if (res2 && res2.success) { box.innerHTML = detailsTpl(res2.data || {}); box.setAttribute('data-loaded','1'); } })
                  .catch(() => {});
                }
              }
            } else {
              const msg = (res && res.data && res.data.err) ? res.data.err : 'خطا در تغییر وضعیت.';
              alert(msg);
            }
          })
          .catch(() => alert('خطا در ارتباط با سرور.'))
          .finally(() => { btn.disabled = false; if (btn.textContent === 'در حال تغییر...') btn.textContent = oldText; });
          return;
        }

        const a = e.target.closest('.qof-order-link');
        if(!a) return;
        const oid = a.getAttribute('data-oid');
        const box = document.getElementById('qof-details-'+oid);
        if(!box) return;

        if (box.getAttribute('data-loaded') === '1'){
          box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
          return;
        }

        box.innerHTML = loadingTpl();
        box.style.display = 'block';

        const form = new FormData();
        form.append('action', 'qof_get_order_details');
        form.append('nonce',  nonce);
        form.append('seller', seller);
        form.append('order_id', oid);

        fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form })
        .then(r => r.json())
        .then(res => {
          if(res && res.success){
            box.innerHTML = detailsTpl(res.data || {});
            box.setAttribute('data-loaded','1');
          } else {
            const msg = (res && res.data && res.data.err) ? res.data.err : 'خطا در دریافت اطلاعات.';
            box.innerHTML = '<div style="color:#991b1b">'+msg+'</div>';
          }
        })
        .catch(() => { box.innerHTML = '<div style="color:#991b1b">عدم ارتباط با سرور.</div>'; });
      });
    })();
    </script>
    <?php
    return ob_get_clean();
});

/*======================================
=  Pending helper
======================================*/
function qof_get_pending_item_ids($order){
    if ( ! $order ) return [];
    $raw = $order->get_meta('_wc_qof_pending_items', true);

    if (is_string($raw) && $raw !== '' && isset($raw[0]) && $raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $raw = $decoded;
    }
    if (is_numeric($raw)) $raw = [ $raw ];
    if ( ! is_array($raw) ) return [];

    // پشتیبانی از فرمت‌های قدیمی: آرایه associative به شکل [product_id => req_qty]
    $keys = array_keys($raw);
    $is_assoc = ($keys !== range(0, count($raw) - 1));
    if ($is_assoc) {
        $ids = [];
        foreach ($keys as $k) {
            $pid = absint($k);
            if ($pid) $ids[] = $pid;
        }
        if (!empty($ids)) return array_values(array_unique($ids));
    }

    return array_values(array_unique(array_filter(array_map('absint', $raw))));
}

function qof_get_pending_req_map($order){
    if (!$order) return [];
    $raw = $order->get_meta('_wc_qof_pending_req_qty', true);
    if (!is_array($raw)) $raw = [];
    $out = [];
    foreach ($raw as $k=>$v) {
        $pid = absint($k);
        if (!$pid) continue;
        $out[$pid] = (int) qof_normalize_digits($v);
    }

    // پشتیبانی از داده‌های قدیمی: _wc_qof_pending_items به شکل [pid => req_qty]
    if (empty($out)) {
        $legacy = $order->get_meta('_wc_qof_pending_items', true);
        if (is_array($legacy)) {
            $keys = array_keys($legacy);
            $is_assoc = ($keys !== range(0, count($legacy) - 1));
            if ($is_assoc) {
                foreach ($legacy as $k => $v) {
                    $pid = absint($k);
                    $qty = (int) qof_normalize_digits($v);
                    if ($pid && $qty > 0) $out[$pid] = $qty;
                }
            }
        }
    }

    // پشتیبانی از داده‌های نیمه‌قدیمی: qty فقط داخل price_map ذخیره شده است.
    if (empty($out)) {
        $price_map = $order->get_meta('_wc_qof_pending_price_map', true);
        if (is_array($price_map)) {
            foreach ($price_map as $k => $row) {
                $pid = absint($k);
                if (!$pid || !is_array($row)) continue;
                $qty = isset($row['qty']) ? (int) qof_normalize_digits($row['qty']) : 0;
                if ($qty > 0) $out[$pid] = $qty;
            }
        }
    }

    // تضمین: اگر pending_ids داریم اما req_map ناقص است، سعی می‌کنیم از price_map تکمیل کنیم.
    $pending_ids = qof_get_pending_item_ids($order);
    if (!empty($pending_ids)) {
        $price_map = $order->get_meta('_wc_qof_pending_price_map', true);
        if (!is_array($price_map)) $price_map = [];
        foreach ($pending_ids as $pid) {
            $pid = absint($pid);
            if (!$pid || !empty($out[$pid])) continue;
            $row = $price_map[$pid] ?? null;
            if (!is_array($row)) continue;
            $qty = isset($row['qty']) ? (int) qof_normalize_digits($row['qty']) : 0;
            if ($qty > 0) $out[$pid] = $qty;
        }
    }

    return $out;
}

function qof_build_order_details_payload($order){
    if ( ! $order ) return [];

    $items_data = [];
    $seen = [];
    $pending_req = qof_get_pending_req_map($order);
    $pending_price_map = $order->get_meta('_wc_qof_pending_price_map', true);
$pending_total     = (float) $order->get_meta('_wc_qof_pending_total', true);

if (!is_array($pending_price_map)) $pending_price_map = [];

    foreach ($order->get_items('line_item') as $item) {
        $product_id   = (int) $item->get_product_id();
        $variation_id = (int) $item->get_variation_id();
        $id           = $variation_id > 0 ? $variation_id : $product_id;

        $product = $id ? wc_get_product($id) : null;
        $stock   = null;
        if ( $product && $product->managing_stock() ) $stock = (int) ( $product->get_stock_quantity() ?? 0 );

        $items_data[] = [
            'id'       => $id,
            'name'     => $item->get_name(),
            'qty'      => (int) $item->get_quantity(),
            'stock'    => $stock,
            'req_qty'  => 0,
            'subtotal' => wc_price( (float) $item->get_subtotal(), ['currency' => $order->get_currency()] ),
            'total'    => wc_price( (float) $item->get_total(),    ['currency' => $order->get_currency()] ),
        ];
        if ($id) $seen[$id] = 1;
    }

    $pending_ids = qof_get_pending_item_ids($order);
    if (!empty($pending_ids)) {
        foreach ($pending_ids as $pid) {
            if (!$pid || isset($seen[$pid])) continue;
            $product = wc_get_product($pid);
            $stock   = null;
            if ( $product && $product->managing_stock() ) $stock = (int) ( $product->get_stock_quantity() ?? 0 );
            $reqq = (int) ($pending_req[$pid] ?? 0);

$unit = isset($pending_price_map[$pid]['unit']) ? (float)$pending_price_map[$pid]['unit'] : (float)($product ? $product->get_price() : 0);
$line = isset($pending_price_map[$pid]['line']) ? (float)$pending_price_map[$pid]['line'] : ($unit * max(0,$reqq));

$items_data[] = [
    'id'         => (int) $pid,
    'name'       => $product ? qof_product_label($product) : ('#'.$pid),
    'qty'        => 0,
    'req_qty'    => $reqq,
    'stock'      => $stock,

    // ✅ NEW: نمایش مبلغ pending
    'pending_unit_html' => wc_price($unit, ['currency' => $order->get_currency()]),
    'subtotal'   => wc_price($line, ['currency' => $order->get_currency()]),
    'total'      => wc_price($line, ['currency' => $order->get_currency()]),

    'is_pending' => 1,
];

        }
    }

$bn = trim(($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()));
    if ($bn === '') $bn = (string) $order->get_meta('_wc_qof_customer_fullname', true);

    $phone = (string) $order->get_billing_phone();
    if ($phone === '') $phone = (string) $order->get_meta('_wc_qof_customer_phone', true);

    $addr1 = (string) $order->get_billing_address_1();

    // seller display name (from user_code)
    $seller_code = (string) $order->get_meta('_wc_qof_user_code', true);
    $smap = qof_sellers();
    $seller_name = isset($smap[$seller_code]) ? (string) $smap[$seller_code] : '';

 $sync_log = (string) $order->get_meta('_wc_qof_last_sync_log', true);

    return [
        'order_id'           => (int) $order->get_id(),
        'status_slug'        => (string) $order->get_status(),
        'status_name'        => wc_get_order_status_name( $order->get_status() ),
        'items'              => $items_data,
        'has_zero_qty'       => !empty($pending_ids),
        'totals_html'        => $order->get_formatted_order_total(),
        'pending_total_html' => wc_price($pending_total, ['currency' => $order->get_currency()]),
'combined_total_html'=> wc_price(((float)$order->get_total()) + $pending_total, ['currency' => $order->get_currency()]),

        // ✅ NEW: prefill customer fields in edit UI
        'customer_fullname'  => $bn,
        'customer_phone'     => $phone,
        'customer_address'   => $addr1,

        // ✅ NEW: seller resolved name for validation rules
        'seller_name'        => $seller_name,

        // ✅ NEW: show sync log (when red button is used)
        'sync_log'           => $sync_log,
    ];
}

/*======================================
=  AJAX: Get order details
======================================*/
add_action('wp_ajax_qof_get_order_details', 'qof_get_order_details');
add_action('wp_ajax_nopriv_qof_get_order_details', 'qof_get_order_details');

function qof_get_order_details(){
    try{
        if ( empty($_POST['nonce']) || ! wp_verify_nonce( wp_unslash($_POST['nonce']), 'qof_orders_nonce') ) {
            wp_send_json_error(['err'=>'خطای امنیتی (Nonce).']);
        }
        $seller   = isset($_POST['seller'])   ? sanitize_text_field( wp_unslash($_POST['seller']) )   : '';
        $order_id = isset($_POST['order_id']) ? absint( $_POST['order_id'] ) : 0;

        if (!$seller || !$order_id) wp_send_json_error(['err'=>'درخواست نامعتبر.']);

        $order = wc_get_order($order_id);
        if ( ! $order ) wp_send_json_error(['err'=>'سفارش یافت نشد.']);

        $meta_code = (string) $order->get_meta('_wc_qof_user_code', true);
        if ($meta_code === '' || $meta_code !== $seller){
            wp_send_json_error(['err'=>'دسترسی به این سفارش مجاز نیست.']);
        }

        wp_send_json_success( qof_build_order_details_payload($order) );
    } catch (Throwable $e){
        wp_send_json_error(['err'=>'خطا: '.$e->getMessage()]);
    }
}

/*======================================
=  AJAX: Toggle status processing <-> on-hold
======================================*/
add_action('wp_ajax_qof_toggle_order_status', 'qof_toggle_order_status');
add_action('wp_ajax_nopriv_qof_toggle_order_status', 'qof_toggle_order_status');
add_action('wp_ajax_qof_sync_pending_orders', 'qof_sync_pending_orders');
add_action('wp_ajax_nopriv_qof_sync_pending_orders', 'qof_sync_pending_orders');

function qof_sync_pending_orders(){
    try {
        if (
            empty($_POST['nonce']) ||
            ! wp_verify_nonce(wp_unslash($_POST['nonce']), 'qof_orders_nonce')
        ) {
            wp_send_json_error(['err' => 'خطای امنیتی']);
        }

        $seller = sanitize_text_field(wp_unslash($_POST['seller'] ?? ''));
        if ($seller === '') {
            wp_send_json_error(['err' => 'کد فروشنده نامعتبر است']);
        }

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;

        if ($order_id > 0) {
            // برای دکمه «تکمیل» تکی: سفارش را مستقیم می‌گیریم تا درگیر فیلتر status/meta_query نشود.
            $single_order = wc_get_order($order_id);
            if ( ! $single_order ) {
                wp_send_json_error(['err' => 'سفارش یافت نشد.']);
            }

            $meta_code = (string) $single_order->get_meta('_wc_qof_user_code', true);
            if ($meta_code === '' || $meta_code !== $seller) {
                wp_send_json_error(['err' => 'دسترسی به این سفارش مجاز نیست.']);
            }

            $orders = [$single_order];
        } else {
            $query_args = [
                // برخی سفارش‌های قدیمی با وضعیت pending ذخیره شده‌اند اما pending items دارند.
                // برای دکمه «بررسی و تکمیل سفارش‌های در انتظار» هر دو وضعیت را بررسی می‌کنیم.
                'status'     => ['on-hold', 'pending'],
                'limit'      => -1,
                'orderby'    => 'ID',
                'order'      => 'ASC',
                'meta_query' => [
                    [
                        'key'   => '_wc_qof_user_code',
                        'value' => $seller,
                    ]
                ],
            ];

            $orders = wc_get_orders($query_args);
        }

        $checked_orders = 0;
        $changed_orders = 0;
        $allocated_items = 0;

        foreach ($orders as $order) {
            $checked_orders++;

            $pending_ids = qof_get_pending_item_ids($order);
            if (empty($pending_ids)) continue;

            $pending_req = qof_get_pending_req_map($order);
            $changed = false;

            // ✅ جزئیات تخصیص‌ها را اینجا جمع می‌کنیم
            $alloc_lines = [];

            foreach ($pending_ids as $pid) {
                $req_qty = (int) ($pending_req[$pid] ?? 0);
                if ($req_qty <= 0) continue;

                $product = wc_get_product($pid);
                if (!$product) continue;

                // ❗ فقط تخصیص کامل مجاز است
                if ( ! qof_can_allocate_pending_qty($product, $req_qty) ) {
                    continue;
                }

                // ✅ اضافه به سفارش
                $order->add_product($product, $req_qty);

                // ✅ کم کردن موجودی بلافاصله (real-time) فقط برای کالاهای دارای مدیریت موجودی
                if ( $product->managing_stock() ) {
                    wc_update_product_stock($product, $req_qty, 'decrease');
                    $product = wc_get_product($pid);
                }

                // ✅ ثبت گزارش دقیق برای Note
                $label = qof_product_label($product);
                $alloc_lines[] = sprintf("✅ تخصیص شد: %s (#%d) × %d", $label, (int)$pid, (int)$req_qty);

                // حذف از pending
                unset($pending_req[$pid]);

                $changed = true;
                $allocated_items += $req_qty;
            }

            if (!$changed) continue;

            // بروزرسانی pending meta
            $remaining_pending = array_keys($pending_req);

            if (!empty($remaining_pending)) {
                $order->update_meta_data('_wc_qof_pending_items', $remaining_pending);
                $order->update_meta_data('_wc_qof_pending_req_qty', $pending_req);
                $calc = qof_pending_calc_map($remaining_pending, $pending_req, $order->get_currency());
                $order->update_meta_data('_wc_qof_pending_price_map', $calc['map']);
                $order->update_meta_data('_wc_qof_pending_total', (float) $calc['sum']);
            } else {
                $order->delete_meta_data('_wc_qof_pending_items');
                $order->delete_meta_data('_wc_qof_pending_req_qty');
                $order->delete_meta_data('_wc_qof_pending_price_map');
                $order->delete_meta_data('_wc_qof_pending_total');
            }

            $order->calculate_totals(false);

            // ✅ NOTE دقیق
            $ts = function_exists('wp_date') ? wp_date('Y/m/d H:i') : date_i18n('Y/m/d H:i');
            $remaining_count = is_array($remaining_pending) ? count($remaining_pending) : 0;

            $note  = "🔄 تکمیل اقلام در انتظار توسط دکمه «بررسی و تکمیل»\n";
            $note .= "⏱ زمان: {$ts}\n";
            $note .= "🧾 سفارش: #".$order->get_id()."\n";
            $note .= "—\n";
            $note .= implode("\n", $alloc_lines);
            $note .= "\n—\n";
            $note .= "مانده در انتظار: ".$remaining_count;

            // برای نمایش در پنل هم (همون جعبه لاگ) بهتره کل متن ذخیره بشه
            $order->update_meta_data('_wc_qof_last_sync_log', $note);

            // ✅ این همون Note واقعی خود سفارش است
            $order->add_order_note($note);

            // اگر pending صفر شد → processing
            if (empty($remaining_pending)) {
                $order->update_status('processing', 'تکمیل خودکار اقلام در انتظار بر اساس موجودی.');
            }

            $order->save();
            $changed_orders++;
        }

        qof_bust_products_cache();
        wp_send_json_success([
            'ok' => 1,
            'checked_orders' => (int) $checked_orders,
            'changed_orders' => (int) $changed_orders,
            'allocated_items' => (int) $allocated_items,
        ]);

    } catch (Throwable $e) {
        wp_send_json_error(['err' => $e->getMessage()]);
    }
}


function qof_toggle_order_status(){
    try{
        if ( empty($_POST['nonce']) || ! wp_verify_nonce( wp_unslash($_POST['nonce']), 'qof_orders_nonce') ) {
            wp_send_json_error(['err'=>'خطای امنیتی (Nonce).']);
        }
        $seller   = isset($_POST['seller'])   ? sanitize_text_field( wp_unslash($_POST['seller']) )   : '';
        $order_id = isset($_POST['order_id']) ? absint( $_POST['order_id'] ) : 0;

        if (!$seller || !$order_id) wp_send_json_error(['err'=>'درخواست نامعتبر.']);

        $order = wc_get_order($order_id);
        if ( ! $order ) wp_send_json_error(['err'=>'سفارش یافت نشد.']);

        $meta_code = (string) $order->get_meta('_wc_qof_user_code', true);
        if ($meta_code === '' || $meta_code !== $seller){
            wp_send_json_error(['err'=>'دسترسی به این سفارش مجاز نیست.']);
        }

        $cur = (string) $order->get_status();
        if ($cur === 'processing') $new = 'on-hold';
        elseif ($cur === 'on-hold') $new = 'processing';
        else wp_send_json_error(['err'=>'فقط سفارش‌های «در حال انجام» و «در انتظار» قابل تغییر وضعیت هستند.']);

        if ($new === 'processing' && !empty(qof_get_pending_item_ids($order))) {
            wp_send_json_error(['err'=>'تا زمانی که آیتم با تعداد ۰ در سفارش وجود دارد، تغییر وضعیت به پردازش امکان‌پذیر نیست.']);
        }

        $order->update_status($new, 'تغییر وضعیت توسط گزارش فروشنده (AJAX).', false);
        $order->save();

        $new_slug = (string) $order->get_status();
        wp_send_json_success([
            'order_id'    => $order_id,
            'status_slug' => $new_slug,
            'status_name' => wc_get_order_status_name( $new_slug ),
        ]);
    } catch (Throwable $e){
        wp_send_json_error(['err'=>'خطا: '.$e->getMessage()]);
    }
}

/*======================================
=  AJAX: Update order items (on-hold only)
=  Policy:
=   - qty=0 و deleted=0 => pending (در انتظار) بماند
=   - deleted=1 => حذف واقعی
======================================*/
add_action('wp_ajax_qof_update_order_items', 'qof_update_order_items');
add_action('wp_ajax_nopriv_qof_update_order_items', 'qof_update_order_items');

function qof_update_order_items(){
    try{
        if ( empty($_POST['nonce']) || ! wp_verify_nonce( wp_unslash($_POST['nonce']), 'qof_orders_edit_nonce') ) {
            wp_send_json_error(['err'=>'خطا: عدم اعتبارسنجی (Nonce).']);
        }

        $seller   = isset($_POST['seller'])   ? sanitize_text_field( wp_unslash($_POST['seller']) )   : '';
        $order_id = isset($_POST['order_id']) ? absint( $_POST['order_id'] ) : 0;
        $raw      = isset($_POST['items'])    ? wp_unslash($_POST['items']) : '[]';
        $items    = json_decode($raw, true);

        // ✅ تغییر ۵: حالت ذخیره
        $save_mode = isset($_POST['save_mode']) ? sanitize_text_field( wp_unslash($_POST['save_mode']) ) : 'hold';
        if ( ! in_array($save_mode, ['hold','final'], true) ) $save_mode = 'hold';
  if (!$seller || !$order_id) wp_send_json_error(['err'=>'اطلاعات ناقص است.']);
        if (!is_array($items)) wp_send_json_error(['err'=>'فرمت آیتم‌ها نامعتبر است.']);

        // ✅ NEW: read customer fields from request (edit mode)
        $cust_name  = isset($_POST['cust_name'])  ? sanitize_text_field( wp_unslash($_POST['cust_name']) ) : '';
        $cust_phone = isset($_POST['cust_phone']) ? sanitize_text_field( wp_unslash($_POST['cust_phone']) ) : '';
        $address    = isset($_POST['address'])    ? sanitize_textarea_field( wp_unslash($_POST['address']) ) : '';

        $order = wc_get_order($order_id);
        if ( ! $order ) wp_send_json_error(['err'=>'سفارش پیدا نشد.']);

        $meta_code = (string) $order->get_meta('_wc_qof_user_code', true);
        if ($meta_code === '' || $meta_code !== $seller){
            wp_send_json_error(['err'=>'دسترسی شما به این سفارش مجاز نیست.']);
        }

        if ( (string) $order->get_status() !== 'on-hold' ) {
            wp_send_json_error(['err'=>'این سفارش دیگر در وضعیت «در انتظار بررسی» نیست و قابل ویرایش نمی‌باشد.']);
        }
 $seller_map  = qof_sellers();
        $seller_name = isset($seller_map[$seller]) ? (string) $seller_map[$seller] : '';

        if ($seller_name !== 'هاجر رستمی') {
            if ($cust_name === '') {
                wp_send_json_error(['err' => 'نام و نام خانوادگی الزامی است.']);
            }
            if ($cust_phone === '') {
                wp_send_json_error(['err' => 'شماره موبایل الزامی است.']);
            }
            if ($address === '') {
                wp_send_json_error(['err' => 'آدرس الزامی است.']);
            }

            // normalize digits (server-side) before regex
            $cust_phone_norm = qof_normalize_digits($cust_phone);
            if ( ! preg_match('/^09\d{9}$/', $cust_phone_norm) ) {
                wp_send_json_error(['err' => 'شماره موبایل معتبر نیست.']);
            }
        }
        // before maps
        $before_map = [];
        foreach ($order->get_items('line_item') as $item) {
            $pid = (int) ($item->get_variation_id() ?: $item->get_product_id());
            $qty = (int) $item->get_quantity();
            if ($pid > 0 && $qty > 0) $before_map[$pid] = ($before_map[$pid] ?? 0) + $qty;
        }
        $before_pending_ids = qof_get_pending_item_ids($order);

        // after maps
        $after_map = [];
        $pending_after = [];
        $pending_req_after = []; // pid=>req_qty
        $deleted_ids = [];

        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $pid = isset($it['product_id']) ? absint($it['product_id']) : ( isset($it['id']) ? absint($it['id']) : 0 );
            $qty_raw = $it['qty'] ?? 0;
            $qty = (int) qof_normalize_digits($qty_raw);
            $deleted = !empty($it['deleted']) ? 1 : 0;

            $req_raw = $it['req_qty'] ?? 0;
            $req_qty = (int) qof_normalize_digits($req_raw);

            if ($qty < 0) wp_send_json_error(['err'=>'تعداد منفی مجاز نیست.']);
            if ($req_qty < 0) wp_send_json_error(['err'=>'تعداد درخواستی منفی مجاز نیست.']);
            if (!$pid) continue;

            if ($deleted === 1) {
                $deleted_ids[$pid] = 1;
                continue;
            }

            if ($qty === 0) {
                $pending_after[$pid] = 1;
                if ($req_qty > 0) $pending_req_after[$pid] = $req_qty;
                continue;
            }

            if ($qty <= 0) continue;
            $after_map[$pid] = ($after_map[$pid] ?? 0) + $qty;
        }

        foreach (array_keys($after_map) as $pid) unset($pending_after[$pid], $pending_req_after[$pid]);
        foreach (array_keys($deleted_ids) as $pid) unset($pending_after[$pid], $pending_req_after[$pid]);

        $pending_ids = array_values(array_unique(array_map('absint', array_keys($pending_after))));

        if (empty($after_map) && empty($pending_ids)) {
            wp_send_json_error(['err'=>'هیچ آیتمی برای ذخیره باقی نمانده است.']);
        }

        // ✅ تغییر ۵: اگر ثبت نهایی و pending داریم => خطا
        if ($save_mode === 'final' && !empty($pending_ids)) {
            wp_send_json_error(['err'=>'تا زمانی که آیتم با تعداد ۰ وجود دارد، امکان «ثبت نهایی» نیست.']);
        }

        $before_pending_ids = is_array($before_pending_ids ?? null) ? $before_pending_ids : [];
        $pending_ids        = is_array($pending_ids ?? null)        ? $pending_ids        : [];

        $touched = array_values(array_unique(array_merge(
            array_keys($before_map),
            array_keys($after_map),
            $before_pending_ids,
            $pending_ids,
            array_keys($deleted_ids)
        )));

        $product_cache = [];
        $get_product = function($pid) use (&$product_cache){
            $pid = (int) $pid;
            if (!isset($product_cache[$pid])) $product_cache[$pid] = wc_get_product($pid);
            return $product_cache[$pid];
        };

        // Validate stock for increases (delta-aware)
        $errors = [];
        foreach ($touched as $pid) {
            $product = $get_product($pid);
            if ( ! $product ) { $errors[] = "محصول #{$pid} پیدا نشد."; continue; }

            $before = (int) ($before_map[$pid] ?? 0);
            $after  = (int) ($after_map[$pid] ?? 0);
            $delta  = $after - $before;

            $cur_stock = (int) ( $product->get_stock_quantity() ?? 0 );
            if ( $delta > 0 && $delta > $cur_stock ) {
                $errors[] = sprintf('موجودی کافی نیست: %s | افزایش: %d | موجودی: %d', $product->get_name(), $delta, $cur_stock);
            }
        }
        if (!empty($errors)) wp_send_json_error(['err'=>implode("\n", $errors)]);

        // race safety: reload order status
        $order = wc_get_order($order_id);
        if ( ! $order ) wp_send_json_error(['err'=>'سفارش پیدا نشد.']);
        if ( (string) $order->get_status() !== 'on-hold' ) wp_send_json_error(['err'=>'این سفارش دیگر قابل ویرایش نیست.']);

        // diff note (بدون جزئیات pending)
        $diff_lines = [];
        foreach ($touched as $pid) {
            $b = (int) ($before_map[$pid] ?? 0);
            $a = (int) ($after_map[$pid] ?? 0);

            $p = $get_product($pid);
            $label = $p ? qof_product_label($p) : ('#'.$pid);

            if (!empty($deleted_ids[$pid])) {
                $diff_lines[] = sprintf('X %s (#%d): حذف واقعی', $label, $pid);
                continue;
            }

            if ($a === $b) {
                $was_pending = in_array((int)$pid, array_map('intval', $before_pending_ids), true);
                $is_pending  = in_array((int)$pid, array_map('intval', $pending_ids), true);
                if ($was_pending !== $is_pending) {
                    $diff_lines[] = sprintf('~ %s (#%d): pending %s', $label, $pid, $is_pending ? 'ON' : 'OFF');
                }
                continue;
            }

            if ($b === 0 && $a > 0) $diff_lines[] = sprintf('+ %s (#%d): %d', $label, $pid, $a);
            elseif ($a === 0 && $b > 0) $diff_lines[] = sprintf('- %s (#%d): %d', $label, $pid, $b);
            else $diff_lines[] = sprintf('~ %s (#%d): %d → %d', $label, $pid, $b, $a);
        }
        if (empty($diff_lines)) $diff_lines[] = 'items updated by seller via AJAX';

        $oid = (int) $order->get_id();

                try{
            /**
             * FIX: جلوگیری از افزایش/کاهش غیرضروری موجودی هنگام ویرایش بدون تغییر
             * قبلاً کل سفارش restock می‌شد و دوباره reduce می‌شد (حتی اگر هیچ qty تغییر نکرده باشد).
             * اکنون فقط «دلتا»ی هر محصول نسبت به قبل اعمال می‌شود:
             *  - اگر qty بیشتر شود => کاهش موجودی به اندازه delta
             *  - اگر qty کمتر شود یا حذف شود => افزایش موجودی به اندازه abs(delta)
             * و آیتم‌های سفارش نیز به صورت هدفمند update/remove/add می‌شوند (نه حذف و افزودن کامل).
             */

            // 1) Update order line-items with minimal operations (no full remove/re-add)
            $existing_by_pid = [];
            foreach ($order->get_items('line_item') as $item_id => $item) {
                if ( ! $item instanceof WC_Order_Item_Product ) continue;
                $pid = (int) ($item->get_variation_id() ?: $item->get_product_id());
                if ($pid <= 0) continue;
                if (!isset($existing_by_pid[$pid])) $existing_by_pid[$pid] = [];
                $existing_by_pid[$pid][] = (int) $item_id;
            }

            // Update or remove existing items
            foreach ($existing_by_pid as $pid => $item_ids) {
                $desired_qty = (int) ($after_map[$pid] ?? 0);

                // If desired qty is 0 => remove all occurrences
                if ($desired_qty <= 0) {
                    foreach ($item_ids as $iid) {
                        $order->remove_item($iid);
                    }
                    continue;
                }

                // Keep first occurrence, remove duplicates if any
                $first_id = array_shift($item_ids);
                $first_item = $order->get_item($first_id);
                if ($first_item instanceof WC_Order_Item_Product) {
                    $first_item->set_quantity($desired_qty);
                    $first_item->save();
                }

                foreach ($item_ids as $dup_id) {
                    $order->remove_item($dup_id);
                }
            }

            // Add missing items (those in after_map but not currently in order)
            foreach ($after_map as $pid => $qty) {
                $pid = (int) $pid;
                $qty = (int) $qty;
                if ($pid <= 0 || $qty <= 0) continue;
                if (isset($existing_by_pid[$pid])) continue;

                $product = $get_product($pid);
                if ($product) {
                    $order->add_product($product, $qty);
                }
            }

            // 2) Pending meta updates (no stock impact)
            if (!empty($pending_ids)) $order->update_meta_data('_wc_qof_pending_items', $pending_ids);
            else $order->delete_meta_data('_wc_qof_pending_items');

            // ✅ تغییر ۴: ذخیره تعداد درخواستی pending به متا
            $pending_req_clean = [];
            foreach ($pending_ids as $pid) {
                $pending_req_clean[(int)$pid] = (int) ($pending_req_after[$pid] ?? 0);
            }
            if (!empty($pending_req_clean)) $order->update_meta_data('_wc_qof_pending_req_qty', $pending_req_clean);
            else $order->delete_meta_data('_wc_qof_pending_req_qty');

            if (!empty($pending_ids)) {
                $calc = qof_pending_calc_map($pending_ids, $pending_req_clean, $order->get_currency());
                $order->update_meta_data('_wc_qof_pending_price_map', $calc['map']);      // pid => [unit,qty,line]
                $order->update_meta_data('_wc_qof_pending_total', (float) $calc['sum']); // جمع کل pending
            } else {
                $order->delete_meta_data('_wc_qof_pending_price_map');
                $order->delete_meta_data('_wc_qof_pending_total');
            }

            // 3) Customer fields + addresses
            $full = trim((string)$cust_name);
            $first = $full;
            $last  = '';
            if ($full !== '') {
                $parts = preg_split('/\s+/', $full);
                if (is_array($parts) && count($parts) > 1) {
                    $first = array_shift($parts);
                    $last  = trim(implode(' ', $parts));
                }
            }

            $addr = [
                'first_name' => $first,
                'last_name'  => $last,
                'address_1'  => (string)$address,
                'address_2'  => '',
                'city'       => '',
                'state'      => '',
                'postcode'   => '',
                'country'    => '',
                'phone'      => (string)$cust_phone,
                'email'      => (string) $order->get_billing_email(),
            ];
            $order->set_address($addr, 'billing');
            $order->set_address($addr, 'shipping');

            // keep plugin meta consistent
            if ($cust_name !== '')  $order->update_meta_data('_wc_qof_customer_fullname', $cust_name);
            else $order->delete_meta_data('_wc_qof_customer_fullname');

            if ($cust_phone !== '') $order->update_meta_data('_wc_qof_customer_phone', $cust_phone);
            else $order->delete_meta_data('_wc_qof_customer_phone');

            $order->calculate_totals(false);
            $order->save();

            // 4) Apply stock deltas ONLY (no full restock/re-reduce)
            $stocks = [];
            foreach ($touched as $pid) {
                $pid = (int) $pid;
                if ($pid <= 0) continue;

                $before = (int) ($before_map[$pid] ?? 0);
                $after  = (int) ($after_map[$pid] ?? 0);
                $delta  = $after - $before;

                $product = $get_product($pid);
                if ( ! $product ) {
                    $stocks[$pid] = 0;
                    continue;
                }

                // Only manage stock for products that actually manage stock
                if ($delta !== 0 && method_exists($product, 'managing_stock') && $product->managing_stock()) {
                    // Decrease stock when qty increased, increase stock when qty decreased
                    if (function_exists('wc_update_product_stock')) {
                        if ($delta > 0) {
                            wc_update_product_stock($product, $delta, 'decrease');
                        } elseif ($delta < 0) {
                            wc_update_product_stock($product, abs($delta), 'increase');
                        }
                    } else {
                        // Fallback (should rarely be needed)
                        $cur_stock = (int) ($product->get_stock_quantity() ?? 0);
                        if ($delta > 0) {
                            $product->set_stock_quantity(max(0, $cur_stock - $delta));
                        } elseif ($delta < 0) {
                            $product->set_stock_quantity($cur_stock + abs($delta));
                        }
                        $product->save();
                    }
                }

                $p_fresh = wc_get_product($pid);
                $stocks[$pid] = (int) ($p_fresh ? ($p_fresh->get_stock_quantity() ?? 0) : 0);
            }

            // ✅ تغییر ۵: بعد از ذخیره، وضعیت بر اساس دکمه
            if ($save_mode === 'final') {
                $orderX = wc_get_order($oid);
                if ($orderX) {
                    $orderX->update_status('processing', 'ذخیره و ثبت نهایی توسط فروشنده (AJAX).', false);
                    $orderX->save();
                }
            } else {
                // on-hold باقی بماند
            }

            // note (بدون pending qty)
            $seller_map2  = qof_sellers();
            $seller_name2 = isset($seller_map2[$seller]) ? $seller_map2[$seller] : '';
            $note = sprintf('ویرایش سفارش (AJAX) | کد فروشنده: %s | نام فروشنده: %s | تغییرات: %s', $seller, $seller_name2, implode(' | ', $diff_lines));
            $order2 = wc_get_order($oid);
            if ($order2) { $order2->add_order_note($note); $order2->save(); }

            qof_bust_products_cache();

            $order_final = wc_get_order($oid);
            wp_send_json_success([
                'order'  => qof_build_order_details_payload($order_final),
                'stocks' => $stocks,
            ]);

        } catch (Throwable $e){
            wp_send_json_error(['err'=>'خطا در ویرایش سفارش: '.$e->getMessage()]);
        }


    } catch (Throwable $e){
        wp_send_json_error(['err'=>'خطا: '.$e->getMessage()]);
    }
}