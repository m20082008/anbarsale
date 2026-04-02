<?php
/*--------------------------------------
| فرانت: Select2/SelectWoo + استایل‌ها
---------------------------------------*/
function wc_suf_enqueue_front_assets() {
    wp_enqueue_script('jquery');
    $use_core_selectwoo = ( wp_script_is('selectWoo', 'registered') || wp_script_is('selectWoo', 'enqueued') );
    if ( $use_core_selectwoo ) {
        wp_enqueue_script('selectWoo');
        if ( wp_style_is('select2', 'registered') ) wp_enqueue_style('select2');
    } else {
        wp_enqueue_style('wc-suf-select2', plugins_url('assets/select2.min.css', WC_SUF_PLUGIN_FILE), [], '4.1.0');
        wp_enqueue_script('wc-suf-select2', plugins_url('assets/select2.min.js', WC_SUF_PLUGIN_FILE), ['jquery'], '4.1.0', true);
    }
    $css = '
    #sel-id, #sel-name { font-size: 18px; line-height: 1.6; padding: 6px 8px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        font-size: 18px !important; line-height: 1.8 !important; padding-top: 2px !important; padding-bottom: 2px !important; font-weight: 600;
    }
    .select2-results__option { font-size: 16px; }
    .select2-search--dropdown .select2-search__field { font-size: 16px; padding: 8px; }
    .select2-container .select2-results > .select2-results__options { max-height: 85vh !important; }
    .select2-container .select2-dropdown { max-height: 90vh !important; overflow: auto !important; }
    .select2-container { z-index: 999999 !important; }
    .suf-muted { color:#6b7280; font-size:12px }
    .wc-suf-modal-overlay{ position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000000; display:none; }
    .wc-suf-modal{ position:fixed; inset:0; z-index:1000001; display:none; align-items:center; justify-content:center; padding:18px; }
    .wc-suf-modal .wc-suf-modal-card{ width:min(980px, 96vw); max-height:88vh; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,.25); display:flex; flex-direction:column; }
    .wc-suf-modal .wc-suf-modal-head{ padding:12px 14px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:10px; background:#f9fafb; }
    .wc-suf-modal .wc-suf-modal-title{ font-weight:800; }
    .wc-suf-modal .wc-suf-modal-close{ border:1px solid #ef4444; background:#ef4444; color:#fff; border-radius:10px; padding:6px 10px; cursor:pointer; font-weight:900; line-height:1; }
    .wc-suf-modal .wc-suf-modal-body{ padding:12px 14px; overflow:auto; }
    .wc-suf-modal .wc-suf-modal-foot{ padding:12px 14px; border-top:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:12px; background:#f9fafb; }
    .wc-suf-picker-row{ display:grid; grid-template-columns: 1fr 140px; gap:10px; align-items:center; padding:10px 8px; border-bottom:1px solid #f1f5f9; }
    .wc-suf-picker-row:last-child{ border-bottom:none; }
    .wc-suf-picker-name{ font-weight:600; }
    .wc-suf-picker-meta{ font-size:12px; color:#6b7280; margin-top:2px; }
    .wc-suf-picker-qty{ display:flex; align-items:center; justify-content:flex-end; gap:6px; }
    .wc-suf-picker-qty input{ width:76px; text-align:center; padding:6px; font-size:16px; border:1px solid #e5e7eb; border-radius:10px; }
    .wc-suf-picker-qty button{ padding:6px 10px; font-size:16px; cursor:pointer; border:1px solid #e5e7eb; background:#fff; border-radius:10px; }
    .wc-suf-filter-grid{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .wc-suf-filter-grid .wc-suf-filter{ display:flex; gap:6px; align-items:center; }
    .wc-suf-filter-grid label{ font-weight:700; font-size:13px; color:#111827; }
    .wc-suf-filter-grid select{ padding:9px 8px; border:1px solid #e5e7eb; border-radius:12px; font-size:13px; background:#fff; min-width:140px; max-width:180px; }
    #return-destination, #return-reason{ background:#fff !important; color:#111 !important; }
    #return-destination option, #return-reason option{ background:#fff !important; color:#111 !important; }
    ';
    wp_register_style('wc-suf-ui', false, [], '2.4.0');
    wp_enqueue_style('wc-suf-ui');
    wp_add_inline_style('wc-suf-ui', $css);
}

/*--------------------------------------
| Shortcode: [stock_update_form key="910"]
---------------------------------------*/
add_shortcode('stock_update_form', function($atts){
    wc_suf_enqueue_front_assets();
    if ( ! function_exists('wc_get_products') ) {
        return '<div dir="rtl" style="color:#b91c1c">WooCommerce فعال نیست.</div>';
    }
    if ( ! is_user_logged_in() ) {
        ob_start();
        echo '<div dir="rtl" style="max-width:420px; margin:20px auto; padding:16px; border:1px solid #e5e7eb; border-radius:12px; background:#fff">';
        echo '<h3 style="margin-top:0">ورود کاربر</h3>';
        wp_login_form([
            'echo'           => true,
            'remember'       => true,
            'redirect'       => esc_url( add_query_arg( [] ) ),
            'label_username' => 'نام کاربری',
            'label_password' => 'رمز عبور',
            'label_log_in'   => 'ورود',
        ]);
        echo '</div>';
        return ob_get_clean();
    }
    if( ! wc_suf_current_user_is_pos_manager() ){
        return '<div dir="rtl" style="color:#b91c1c">این فرم فقط برای کاربران با نقش formeditor، marjoo، sale یا tehsale در دسترس است.</div>';
    }
    $allowed_ops = wc_suf_get_allowed_ops_for_current_user();
    $is_marjoo_only = wc_suf_is_marjoo_only_user();
    $atts = shortcode_atts(['key' => ''], $atts, 'stock_update_form');
    $current_user = wp_get_current_user();
    $display_name = trim( (string) $current_user->first_name . ' ' . (string) $current_user->last_name );
    if ( $display_name === '' ) {
        $display_name = (string) ( $current_user->display_name ?: $current_user->user_login );
    }

    $cache_key = 'wc_suf_products_cache_v250';
    $products = get_transient( $cache_key );
    if ( ! is_array($products) ) {
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'type' => ['simple','variation'],
            'return' => 'objects',
        ]);
        set_transient( $cache_key, $products, MINUTE_IN_SECONDS * 5 );
    }

    $make_label = function( $p ){
        if ( $p->is_type('variation') ) {
            $parent = wc_get_product( $p->get_parent_id() );
            $base   = $parent ? $parent->get_name() : ('Variation #'.$p->get_id());
            $attrs  = wc_get_formatted_variation( $p, true, false, false );
            $attrs  = trim( wp_strip_all_tags( (string) $attrs ) );
            $label  = trim( $base . ( $attrs ? ' – ' . $attrs : '' ) );
            if ( $label === '' ) $label = $p->get_name() ?: ('#'.$p->get_id());
            return $label;
        }
        $name = $p->get_name();
        return $name !== '' ? $name : ('#'.$p->get_id());
    };

    $picker_attr_defs = wc_suf_get_picker_attribute_defs();

    global $wpdb;
    $prod_table = $wpdb->prefix.'stock_production_inventory';
    $prod_rows = $wpdb->get_results( "SELECT product_id, qty FROM `$prod_table`", ARRAY_A );
    $prod_map = [];
    if ( is_array($prod_rows) ) {
        foreach ( $prod_rows as $pr ) {
            $ppid = isset($pr['product_id']) ? absint($pr['product_id']) : 0;
            if ( ! $ppid ) continue;
            $prod_map[$ppid] = (int) ($pr['qty'] ?? 0);
        }
    }

    $bucketed = [];
    $preferred_order = [4,6,8,12];
    foreach ($products as $p){
        $pid = $p->get_id();
        $wc_stock   = (int) max(0, (int) ($p->get_stock_quantity() ?? 0));
        $prod_stock = isset($prod_map[$pid]) ? (int) $prod_map[$pid] : 0;
        $teh_stock  = 0;
        $teh_ok     = 0;

        if ( function_exists('yith_pos_stock_management') ) {
            $teh_read = wc_suf_yith_get_store_stock( $p, (int) WC_SUF_TEHRANPARS_STORE_ID );
            if ( false !== $teh_read ) {
                $teh_stock = (int) $teh_read;
                $teh_ok = 1;
            }
        }

        $label = $make_label($p);
        $row = [
            'id'           => $pid,
            'label'        => $label,
            'stock'        => $prod_stock,
            'prod_stock'   => $prod_stock,
            'wc_stock'     => $wc_stock,
            'teh_stock'    => $teh_stock,
            'teh_stock_ok' => $teh_ok,
            'search'       => wc_suf_build_search_blob( $p ),
            'attrs'        => wc_suf_collect_product_attributes_for_picker( $p ),
        ];
        $cap = wc_suf_capacity_from_product($p) ?: 0;
        $bucketed[$cap][] = $row;
    }
    foreach ($bucketed as $cap => &$list) { usort($list, fn($a,$b)=> strcasecmp($a['label'],$b['label'])); } unset($list);

    $name_select_html = '';
    $id_select_html   = '';
    $all              = [];

    $emit_group = function($cap, $rows) use (&$name_select_html,&$id_select_html,&$all){
        $group_label = ($cap ? $cap.' نفره' : 'سایر');
        $name_select_html .= '<optgroup label="'.esc_attr($group_label).'">';
        $id_select_html   .= '<optgroup label="'.esc_attr($group_label).'">';
        foreach ($rows as $row) {
            $opt_text = '['.esc_html($row['id']).' | موجودی: '.esc_html($row['stock']).'] '.esc_html($row['label']);
            $name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'. $opt_text .'</option>';
            $id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'. esc_html($row['id']).'</option>';
            $all[] = $row;
        }
        $name_select_html .= '</optgroup>';
        $id_select_html   .= '</optgroup>';
    };

    foreach ($preferred_order as $cap) if (!empty($bucketed[$cap])) $emit_group($cap, $bucketed[$cap]);
    $other_caps = array_diff(array_keys($bucketed), array_merge($preferred_order, [0]));
    sort($other_caps, SORT_NUMERIC);
    foreach ($other_caps as $cap) $emit_group($cap, $bucketed[$cap]);
    if (!empty($bucketed[0])) $emit_group(0, $bucketed[0]);

    ob_start(); ?>
    <style>
      .wc-suf-optype-buttons{display:flex; gap:10px; align-items:center; flex-wrap:wrap}
      .wc-suf-optype-btn{
        display:inline-flex; align-items:center; justify-content:center; min-height:42px;
        padding:8px 14px; border:1px solid #cbd5e1; border-radius:10px;
        background:#fff; color:#0f172a; font-weight:700; cursor:pointer; user-select:none;
        transition:all .15s ease;
      }
      .wc-suf-optype-btn input{position:absolute; opacity:0; pointer-events:none}
      .wc-suf-optype-btn:hover{border-color:#64748b; background:#f8fafc}
      .wc-suf-optype-btn.is-active{border-color:#2563eb; background:#2563eb; color:#fff; box-shadow:0 0 0 2px #bfdbfe}
      .wc-suf-optype-btn.is-disabled{opacity:.55; cursor:not-allowed}
      .wc-suf-sale-customer-wrap{display:none; flex-direction:column; gap:10px; width:100%}
      .wc-suf-sale-customer-row{display:grid; grid-template-columns:1fr 1fr; gap:10px; width:100%}
      .wc-suf-sale-customer-field{display:flex; align-items:center; gap:8px}
      .wc-suf-sale-customer-field label{min-width:120px}
      .wc-suf-sale-customer-field input,
      .wc-suf-sale-customer-field textarea{width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:10px}
      .wc-suf-sale-customer-field textarea{min-height:82px; resize:vertical; background:#fff}
      @media (max-width: 768px){
        #optype-block{gap:10px !important; padding:10px !important}
        #optype-block > div:first-child{min-width:unset !important; width:100%}
        .wc-suf-optype-buttons{display:grid; grid-template-columns:1fr; width:100%}
        .wc-suf-optype-btn{width:100%; justify-content:flex-start; padding:12px}
        .wc-suf-sale-customer-wrap{gap:8px}
        .wc-suf-sale-customer-row{grid-template-columns:1fr}
        .wc-suf-sale-customer-field{flex-direction:column; align-items:stretch}
        .wc-suf-sale-customer-field label{min-width:unset; width:100%; font-size:13px}
      }
    </style>

    <div id="stock-form" dir="rtl" style="display:grid; gap:12px; align-items:center;">
        <div style="background:#ecfeff; border:1px solid #bae6fd; color:#0f172a; border-radius:10px; padding:10px 12px; font-weight:700">کاربر: <?php echo esc_html($display_name); ?></div>

        <div id="optype-block" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; background:#f9fafb; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px">
          <div style="font-weight:700; min-width:220px">نوع عملیات موجودی / لیبل</div>
          <div class="wc-suf-optype-buttons">
          <?php if ( in_array( 'in', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="in">
            <input type="radio" name="op-type" value="in">
            <span>ورود به انبار تولید</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'out', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="out">
            <input type="radio" name="op-type" value="out">
            <span>خروج از انبار</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'transfer', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="transfer">
            <input type="radio" name="op-type" value="transfer">
            <span>انتقال بین انبارها</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'return', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="return">
            <input type="radio" name="op-type" value="return">
            <span>مرجوعی</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'sale', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="sale">
            <input type="radio" name="op-type" value="sale">
            <span>فروش</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'sale_teh', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="sale_teh">
            <input type="radio" name="op-type" value="sale_teh">
            <span>فروش برای تهرانپارس</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'onlyLabel', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="onlyLabel">
            <input type="radio" name="op-type" value="onlyLabel">
            <span>صرفاً چاپ لیبل</span>
          </label>
          <?php endif; ?>
          </div>
          <span class="suf-muted">(پس از انتخاب، قابل تغییر نیست مگر با رفرش)</span>
        </div>

            
        <div id="out-destination-wrap" style="display:none; gap:8px; align-items:center; flex-wrap:wrap">
          <label style="min-width:120px">مقصد خروج:</label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="out-destination" value="main">
            <span>خروج به انبار اصلی</span>
          </label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="out-destination" value="teh">
            <span>خروج به انبار تهران پارس</span>
          </label>
        </div>

        <div id="transfer-controls-wrap" style="display:none; gap:10px; align-items:center; flex-wrap:wrap">
          <label for="transfer-source" style="min-width:120px">انبار مبدا:</label>
          <select id="transfer-source" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:180px">
            <option value="">انتخاب انبار مبدا...</option>
            <option value="main">انبار اصلی</option>
            <option value="teh">انبار تهران پارس</option>
          </select>

          <label for="transfer-destination" style="min-width:120px">انبار مقصد:</label>
          <select id="transfer-destination" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:180px">
            <option value="">انتخاب انبار مقصد...</option>
          </select>
        </div>

        <div id="return-controls-wrap" style="display:none; gap:10px; align-items:center; flex-wrap:wrap">
          <label for="return-destination" style="min-width:120px">انبار مرجوعی:</label>
          <select id="return-destination" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:180px">
            <option value="">انتخاب انبار...</option>
            <?php if ( ! $is_marjoo_only ) : ?>
            <option value="main">انبار اصلی</option>
            <?php endif; ?>
            <option value="teh">انبار تهران پارس</option>
          </select>

          <label for="return-reason" style="min-width:120px">علت مرجوعی:</label>
          <select id="return-reason" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:280px">
            <option value="">انتخاب علت...</option>
            <option value="انصراف از خرید مشتری">۱- انصراف از خرید مشتری</option>
            <option value="تعویض طرح یا رنگ">۲- تعویض طرح یا رنگ</option>
            <option value="خرابی کالا (استوک)">۳- خرابی کالا (استوک)</option>
          </select>
        </div>
        
        <div id="sale-customer-wrap" class="wc-suf-sale-customer-wrap">
          <div class="wc-suf-sale-customer-row">
            <div class="wc-suf-sale-customer-field">
              <label for="sale-customer-mobile">شماره موبایل:</label>
              <input id="sale-customer-mobile" type="tel" placeholder="۰۹xxxxxxxxx">
            </div>
            <div class="wc-suf-sale-customer-field">
              <label for="sale-customer-name">نام و نام خانوادگی:</label>
              <input id="sale-customer-name" type="text" placeholder="مثال: علی رضایی">
            </div>
          </div>
          <div class="wc-suf-sale-customer-row">
            <div class="wc-suf-sale-customer-field" style="grid-column:1 / -1;">
              <label for="sale-customer-address">آدرس:</label>
              <textarea id="sale-customer-address" placeholder="آدرس کامل مشتری"></textarea>
            </div>
          </div>
        </div>

        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; opacity:.5" id="picker-open-block">
          <button type="button" id="btn-open-picker" style="padding:12px 18px; cursor:pointer; border:1px solid #10b981; border-radius:10px; background:#bbf7d0; color:#065f46; font-weight:700" disabled>➕ اضافه کردن محصولات</button>
          <span class="suf-muted">ابتدا نوع عملیات را انتخاب کنید، سپس محصولات را در پنجره انتخاب کنید.</span>
        </div>

        <table id="items-table" style="margin-top:10px; display:none; width:100%; border-collapse:collapse; border:1px solid #e5e7eb; font-size:14px">
          <thead>
            <tr style="background:#f3f4f6; border-bottom:1px solid #e5e7eb">
              <th style="padding:8px; text-align:right; width:110px">ID</th>
              <th style="padding:8px; text-align:right">محصول</th>
              <th style="padding:8px; text-align:center; width:140px">موجودی فعلی</th>
              <th style="padding:8px; text-align:center; width:280px">تعداد (+/−)</th>
              <th style="padding:8px; text-align:center; width:100px">حذف</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>

        <div id="items-total-wrap" style="display:none; font-weight:700; color:#1f2937">جمع کل تعداد: <span id="items-total-value">0</span></div>

        <div>
          <button type="button" id="btn-save" style="margin-top:10px; display:none; padding:12px 18px; cursor:pointer; border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:10px" disabled>✅ ثبت نهایی</button>
        </div>

        <div id="save-result" style="display:none; margin-top:8px; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; background:#f9fafb"></div>
    </div>

    <div class="wc-suf-modal-overlay" id="wc-suf-modal-overlay" aria-hidden="true"></div>
    <div class="wc-suf-modal" id="wc-suf-modal" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="wc-suf-modal-card">
        <div class="wc-suf-modal-head">
          <div>
            <div class="wc-suf-modal-title">انتخاب محصولات (جستجو + فیلتر ویژگی‌ها)</div>
            <div class="suf-muted" id="wc-suf-modal-subtitle">ابتدا جستجو کنید، سپس در صورت نیاز از فیلتر ویژگی‌ها استفاده کنید. برای هر محصول تعداد را وارد کنید و «اضافه کن» را بزنید.</div>
          </div>
          <button type="button" class="wc-suf-modal-close" id="wc-suf-modal-close" aria-label="بستن">✕</button>
        </div>

        <div class="wc-suf-modal-body">
          <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap; margin-bottom:10px">
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; flex:1; min-width:260px">
              <label for="wc-suf-picker-q" style="min-width:80px; font-weight:700">جستجو:</label>
              <input id="wc-suf-picker-q" type="text" placeholder="مثلاً توران مربع / زرشکی / کد کالا یا ID" style="flex:1; min-width:260px; padding:10px; border:1px solid #e5e7eb; border-radius:12px; font-size:16px">
              <button type="button" id="wc-suf-picker-clear" aria-label="پاک کردن جستجو" title="پاک کردن" style="width:44px; height:44px; display:inline-flex; align-items:center; justify-content:center; padding:0; border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:12px; cursor:pointer; font-size:18px; font-weight:800">✕</button>
            </div>

            <div style="width:100%; margin-top:8px">
              <div id="wc-suf-picker-filters" class="wc-suf-filter-grid"></div>
            </div>
          </div>

          <div class="suf-muted" style="margin-bottom:8px">
            نکته: جستجو نام + ورییشن (ویژگی‌ها) را پوشش می‌دهد. فیلترها همزمان با جستجو اعمال می‌شوند.
          </div>

          <div id="wc-suf-picker-results" style="border:1px solid #e5e7eb; border-radius:12px; overflow:hidden"></div>
        </div>

        <div class="wc-suf-modal-foot">
          <div class="suf-muted" id="wc-suf-picker-selected-info">هیچ موردی انتخاب نشده است.</div>
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
            <button type="button" id="wc-suf-picker-add" style="padding:12px 16px; cursor:pointer; border:1px solid #10b981; border-radius:12px; background:#10b981; color:#fff; font-weight:800">✅ اضافه کن</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    const ajaxurl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";

    jQuery(function($){
        const allProducts = <?php echo wp_json_encode($all); ?>;
        const pickerAttrDefs = <?php echo wp_json_encode($picker_attr_defs); ?>;
        const isMarjooOnly = <?php echo $is_marjoo_only ? 'true' : 'false'; ?>;
        const allowedOps = <?php echo wp_json_encode( $allowed_ops ); ?>;

        const defaultShortcodeKey = "<?php echo esc_js($atts['key']); ?>";
        const urlParams = new URLSearchParams(window.location.search);
        const urlKey = urlParams.get('key') || urlParams.get('code') || '';
        const userCode = urlKey || defaultShortcodeKey;

        const items = [];
        let opType = null;
        let outDestination = null;
        let transferSource = null;
        let transferDestination = null;
        let returnDestination = null;
        let returnReason = '';
        let saleCustomerName = '';
        let saleCustomerMobile = '';
        let saleCustomerAddress = '';
        let saleHoldOrderId = 0;

        const $overlay = $('#wc-suf-modal-overlay');
        const $modal   = $('#wc-suf-modal');
        const $q       = $('#wc-suf-picker-q');
        const $results = $('#wc-suf-picker-results');
        const $info    = $('#wc-suf-picker-selected-info');
        const $filters = $('#wc-suf-picker-filters');
        const $modalSubtitle = $('#wc-suf-modal-subtitle');

        function syncOpTypeButtonsState(){
            $('.wc-suf-optype-btn').each(function(){
                const $label = $(this);
                const $radio = $label.find('input[name="op-type"]');
                $label.toggleClass('is-active', $radio.is(':checked'));
                $label.toggleClass('is-disabled', $radio.is(':disabled') && !$radio.is(':checked'));
            });
        }

        const pickerQty = Object.create(null);
        const activeFilters = Object.create(null); // tax => selectedValueNormalized

        function escapeHtml(s){
            return String(s)
                .replaceAll('&','&amp;')
                .replaceAll('<','&lt;')
                .replaceAll('>','&gt;')
                .replaceAll('"','&quot;')
                .replaceAll("'","&#039;");
        }

        function norm(s){
            s = String(s || '').trim();
            s = s.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
            s = s.replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
            return s.toLowerCase();
        }
        function normalizeDigits(s){
            return String(s || '')
                .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
                .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        }
        function normalizeMobileInput(s){
            return normalizeDigits(s).replace(/[^\d]/g,'');
        }

        function findById(id){ return allProducts.find(p => String(p.id) === String(id)); }
        function findLabelById(id){ const f = findById(id); return f ? f.label : ''; }
        function findProductionStockById(id){ const f = findById(id); return f ? (+f.prod_stock || 0) : 0; }
        function findMainStockById(id){ const f = findById(id); return f ? (+f.wc_stock || 0) : 0; }
        function warehouseLabel(code){
            if(code === 'main') return 'انبار اصلی';
            if(code === 'teh') return 'انبار تهران پارس';
            if(code === 'production') return 'انبار تولید';
            return '';
        }
        function updateModalSubtitle(){
            let subtitle = 'ابتدا جستجو کنید، سپس در صورت نیاز از فیلتر ویژگی‌ها استفاده کنید. برای هر محصول تعداد را وارد کنید و «اضافه کن» را بزنید.';
            if(opType === 'transfer' && transferSource && transferDestination){
                subtitle = `در حال انتقال کالا از مبدا (${warehouseLabel(transferSource)}) به مقصد (${warehouseLabel(transferDestination)}) هستید.`;
            }
            $modalSubtitle.text(subtitle);
        }
        function updateProductStocksInMemory(stocks){
            if(!stocks || typeof stocks !== 'object') return;
            for(const pid in stocks){
                if(!Object.prototype.hasOwnProperty.call(stocks, pid)) continue;
                const product = findById(pid);
                const row = stocks[pid] || {};
                if(!product) continue;
                if(row.prod_stock != null){
                    product.prod_stock = +row.prod_stock || 0;
                    product.stock = +row.prod_stock || 0;
                }
                if(row.wc_stock != null){
                    product.wc_stock = +row.wc_stock || 0;
                }
                if(row.teh_stock != null){
                    product.teh_stock = +row.teh_stock || 0;
                }
                if(row.teh_stock_ok != null){
                    product.teh_stock_ok = +row.teh_stock_ok ? 1 : 0;
                }
            }
        }
        function refreshStocksBeforeResult(ids){
            const normalizedIds = Array.isArray(ids)
                ? ids.map(v => parseInt(v, 10)).filter(v => Number.isFinite(v) && v > 0)
                : [];
            if(normalizedIds.length === 0){
                return $.Deferred().resolve({}).promise();
            }
            return $.post(ajaxurl, {
                action   : 'wc_suf_refresh_stocks',
                ids      : JSON.stringify(normalizedIds),
                _wpnonce : '<?php echo wp_create_nonce('wc_suf_refresh_stocks'); ?>'
            });
        }
        let saleStocksRefreshTimer = null;
        function scheduleSaleStocksRefresh(ids, delayMs){
            if(!(opType === 'sale' || opType === 'sale_teh')) return;
            const normalizedIds = Array.isArray(ids)
                ? ids.map(v => parseInt(v, 10)).filter(v => Number.isFinite(v) && v > 0)
                : [];
            if(normalizedIds.length === 0) return;
            if(saleStocksRefreshTimer){
                clearTimeout(saleStocksRefreshTimer);
            }
            saleStocksRefreshTimer = setTimeout(function(){
                refreshStocksBeforeResult(normalizedIds).done(function(refreshRes){
                    if(refreshRes && refreshRes.success && refreshRes.data && refreshRes.data.stocks){
                        updateProductStocksInMemory(refreshRes.data.stocks);
                        renderTable();
                        renderPickerResults();
                    }
                });
            }, Math.max(0, parseInt(delayMs, 10) || 0));
        }
        function syncSaleHoldOrder(showErrors){
            if(!(opType === 'sale' || opType === 'sale_teh')) return $.Deferred().resolve({success:true}).promise();
            if(!Array.isArray(items) || items.length === 0){
                if(saleHoldOrderId){
                    return $.post(ajaxurl, {
                        action   : 'wc_suf_sync_sale_hold_order',
                        order_id : saleHoldOrderId,
                        op_type  : opType,
                        items    : JSON.stringify([]),
                        sale_customer_name : String(saleCustomerName || ''),
                        sale_customer_mobile : String(saleCustomerMobile || ''),
                        sale_customer_address : String(saleCustomerAddress || ''),
                        _wpnonce : '<?php echo wp_create_nonce('wc_suf_sync_sale_hold_order'); ?>'
                    });
                }
                return $.Deferred().resolve({success:true}).promise();
            }
            return $.post(ajaxurl, {
                action   : 'wc_suf_sync_sale_hold_order',
                order_id : saleHoldOrderId,
                op_type  : opType,
                items    : JSON.stringify(items),
                sale_customer_name : String(saleCustomerName || ''),
                sale_customer_mobile : String(saleCustomerMobile || ''),
                sale_customer_address : String(saleCustomerAddress || ''),
                _wpnonce : '<?php echo wp_create_nonce('wc_suf_sync_sale_hold_order'); ?>'
            }).done(function(res){
                if(res && res.success && res.data && res.data.order_id){
                    saleHoldOrderId = parseInt(res.data.order_id, 10) || 0;
                } else if (showErrors) {
                    alert((res && res.data && res.data.message) ? res.data.message : 'خطا در همگام‌سازی سفارش هولد.');
                }
            }).fail(function(){
                if(showErrors){
                    alert('خطای ارتباطی هنگام همگام‌سازی سفارش هولد.');
                }
            });
        }
        function getPickerMetaLine(p){
            const pid = String(p.id || '');
            const prod = (+p.prod_stock || 0);
            const destination = (opType === 'return') ? returnDestination : (opType === 'transfer' ? transferDestination : outDestination);
            if(opType === 'in'){
                return `ID: ${pid} | موجودی انبار تولید: ${prod}`;
            }
            if(opType === 'transfer'){
                const sourceStock = getTransferWarehouseStockByProduct(p, transferSource);
                const destinationStock = getTransferWarehouseStockByProduct(p, transferDestination);
                return `ID: ${pid} | موجودی مبدا: ${sourceStock} | موجودی مقصد: ${destinationStock}`;
            }
            if(opType === 'out' || opType === 'return'){
                if(destination === 'main'){
                    return `ID: ${pid} | موجودی انبار تولید: ${prod} | موجودی انبار اصلی: ${(+p.wc_stock || 0)}`;
                }
                if(destination === 'teh'){
                    const teh = (+p.teh_stock || 0);
                    const note = (+p.teh_stock_ok || 0) ? '' : ' (نامشخص)';
                    return `ID: ${pid} | موجودی انبار تولید: ${prod} | موجودی تهران پارس: ${teh}${note}`;
                }
                return `ID: ${pid} | موجودی انبار تولید: ${prod}`;
            }
            if(opType === 'sale' || opType === 'sale_teh'){
                return `ID: ${pid} | موجودی انبار تولید: ${prod} | موجودی انبار اصلی: ${(+p.wc_stock || 0)}`;
            }
            return `ID: ${pid} | موجودی انبار تولید: ${prod}`;
        }

        function canSave(){
            if(opType !== 'in' && opType !== 'out' && opType !== 'transfer' && opType !== 'return' && opType !== 'onlyLabel' && opType !== 'sale' && opType !== 'sale_teh') return false;
            if(allowedOps.indexOf(opType) === -1) return false;
            if(opType === 'out' && !outDestination) return false;
            if(opType === 'transfer' && (!transferSource || !transferDestination || transferSource === transferDestination)) return false;
            if(opType === 'return' && (!returnDestination || !returnReason)) return false;
            if((opType === 'sale' || opType === 'sale_teh') && !isSaleCustomerDataValid(false)) return false;
            if(isMarjooOnly && returnDestination !== 'teh') return false;
            return items.length > 0;
        }

        function canOpenPicker(){
            if(!opType) return false;
            if(allowedOps.indexOf(opType) === -1) return false;
            if(opType === 'out' && !outDestination) return false;
            if(opType === 'transfer' && (!transferSource || !transferDestination || transferSource === transferDestination)) return false;
            if(opType === 'return' && (!returnDestination || !returnReason)) return false;
            if((opType === 'sale' || opType === 'sale_teh') && !isSaleCustomerDataValid(false)) return false;
            if(isMarjooOnly && returnDestination !== 'teh') return false;
            return true;
        }

        function isSaleCustomerDataValid(showAlert){
            const name = String(saleCustomerName || '').trim();
            const mobile = normalizeMobileInput(saleCustomerMobile);
            const address = String(saleCustomerAddress || '').trim();
            if(name.length < 3){
                if(showAlert) alert('نام و نام خانوادگی را کامل وارد کنید.');
                return false;
            }
            if(!/^0\d{10}$/.test(mobile)){
                if(showAlert) alert('شماره موبایل باید با 0 شروع شود و دقیقاً 11 رقم باشد.');
                return false;
            }
            if(address.length < 8){
                if(showAlert) alert('آدرس مشتری را کامل وارد کنید.');
                return false;
            }
            return true;
        }

        function getTransferWarehouseStockByProduct(p, warehouse){
            if(!p) return 0;
            if(warehouse === 'main') return (+p.wc_stock || 0);
            if(warehouse === 'teh') return (+p.teh_stock || 0);
            return 0;
        }

        function findTransferSourceStockById(id){
            const p = findById(id);
            return getTransferWarehouseStockByProduct(p, transferSource);
        }

        function syncTransferDestinationOptions(){
            const $destination = $('#transfer-destination');
            const current = String($destination.val() || '');
            let options = '<option value="">انتخاب انبار مقصد...</option>';
            if(transferSource === 'main'){
                options += '<option value="teh">انبار تهران پارس</option>';
            } else if (transferSource === 'teh'){
                options += '<option value="main">انبار اصلی</option>';
            } else {
                options += '<option value="main">انبار اصلی</option><option value="teh">انبار تهران پارس</option>';
            }
            $destination.html(options);
            if(current && current !== transferSource){
                $destination.val(current);
            }
            if($destination.val() === ''){
                transferDestination = null;
            } else {
                transferDestination = String($destination.val());
            }
        }

        function getDestinationInfoById(id){
            const p = findById(id);
            if(!p) return {label:'', stock:0};
            if(outDestination === 'main'){
                return {label:'موجودی انبار اصلی', stock:(+p.wc_stock || 0)};
            }
            if(outDestination === 'teh'){
                return {label:'موجودی انبار تهران‌پارس', stock:(+p.teh_stock || 0)};
            }
            return {label:'', stock:0};
        }

        function refreshPickerOpenButton(){
            const enabled = canOpenPicker();
            const $btn = $('#btn-open-picker');
            $('#picker-open-block').css('opacity', enabled ? 1 : 0.5);
            $btn.prop('disabled', !enabled);
            if(enabled){
                $btn.css({background:'#16a34a', borderColor:'#15803d', color:'#ffffff'});
            } else {
                $btn.css({background:'#bbf7d0', borderColor:'#10b981', color:'#065f46'});
            }
        }

        function renderTable(){
            const tbody = $('#items-table tbody').empty();
            const theadRow = $('#items-table thead tr').empty();

            const isOutMain = (opType === 'out' && outDestination === 'main');
            const isOutTeh  = (opType === 'out' && outDestination === 'teh');
            const isTransfer = (opType === 'transfer');
            const isReturnMain = (opType === 'return' && returnDestination === 'main');
            const isReturnTeh  = (opType === 'return' && returnDestination === 'teh');
            const isSaleOperation = (opType === 'sale' || opType === 'sale_teh');

            theadRow.append('<th style="padding:8px; text-align:right; width:110px">ID</th>');
            theadRow.append('<th style="padding:8px; text-align:right">محصول</th>');
            theadRow.append(`<th style="padding:8px; text-align:center; width:160px">${isTransfer ? 'موجودی انبار مبدا' : 'موجودی انبار تولید'}</th>`);
            if (isOutMain){
                theadRow.append('<th style="padding:8px; text-align:center; width:170px">موجودی انبار اصلی</th>');
            } else if (isOutTeh){
                theadRow.append('<th style="padding:8px; text-align:center; width:180px">موجودی انبار تهران‌پارس</th>');
            } else if (isTransfer){
                const dstLabel = (transferDestination === 'main') ? 'موجودی انبار اصلی' : (transferDestination === 'teh' ? 'موجودی انبار تهران‌پارس' : 'موجودی انبار مقصد');
                theadRow.append(`<th style="padding:8px; text-align:center; width:180px">${escapeHtml(dstLabel)}</th>`);
            } else if (isReturnMain){
                theadRow.append('<th style="padding:8px; text-align:center; width:170px">موجودی انبار اصلی</th>');
            } else if (isReturnTeh){
                theadRow.append('<th style="padding:8px; text-align:center; width:180px">موجودی انبار تهران‌پارس</th>');
            } else if (isSaleOperation){
                theadRow.append('<th style="padding:8px; text-align:center; width:170px">موجودی انبار اصلی</th>');
            }
            theadRow.append('<th style="padding:8px; text-align:center; width:280px">تعداد (+/−)</th>');
            theadRow.append('<th style="padding:8px; text-align:center; width:100px">حذف</th>');

            if(items.length === 0){
                $('#items-table').hide();
                $('#btn-save').prop('disabled', true).hide();
                $('#items-total-wrap').hide();
                $('#items-total-value').text('0');
                return;
            }
            $('#items-table').show();
            $('#btn-save').show().prop('disabled', !canSave());
            const grandTotal = items.reduce((sum, it) => sum + (parseInt(it.qty, 10) || 0), 0);
            $('#items-total-value').text(String(grandTotal));
            $('#items-total-wrap').show();

            items.forEach((it,idx)=>{
                const tr = $('<tr style="border-top:1px solid #e5e7eb">');
                tr.append(`<td style="padding:8px">${escapeHtml(it.id)}</td>`);
                tr.append(`<td style="padding:8px">${escapeHtml(it.name)}</td>`);
                const sourceStock = isTransfer ? findTransferSourceStockById(it.id) : it.stock;
                tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(sourceStock)}</td>`);

                if (isOutMain || isOutTeh){
                    const dst = getDestinationInfoById(it.id);
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(dst.stock)}</td>`);
                } else if (isTransfer){
                    const p = findById(it.id);
                    const dstStock = getTransferWarehouseStockByProduct(p, transferDestination);
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(dstStock)}</td>`);
                } else if (isReturnMain || isReturnTeh){
                    const p = findById(it.id);
                    const returnStock = (returnDestination === 'main') ? (+p.wc_stock || 0) : (+p.teh_stock || 0);
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(returnStock)}</td>`);
                } else if (isSaleOperation){
                    const p = findById(it.id);
                    const mainStock = +((p && p.wc_stock) || 0);
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(mainStock)}</td>`);
                }

                const qtyControls = $(`
                  <td style="padding:6px; text-align:center">
                    <button class="row-dec" data-i="${idx}" style="font-size:18px; padding:4px 10px; margin-inline:4px">➖</button>
                    <input type="number" class="row-qty" data-i="${idx}" value="${escapeHtml(it.qty)}" min="1" style="width:80px; text-align:center; font-size:16px; padding:4px">
                    <button class="row-inc" data-i="${idx}" style="font-size:18px; padding:4px 10px; margin-inline:4px">➕</button>
                  </td>
                `);
                tr.append(qtyControls);
                tr.append(`<td style="padding:8px; text-align:center"><button data-i="${idx}" class="btn-del" style="cursor:pointer">❌</button></td>`);
                tbody.append(tr);
            });
        }

        function buildSubmitConfirmMessage(){
            const typeCount = items.length;
            const totalQty = items.reduce((sum, it) => sum + (parseInt(it.qty, 10) || 0), 0);

            if(opType === 'in'){
                return `در حال ورود ${typeCount} نوع محصول با مجموع ${totalQty} آیتم به انبار تولید هستید. آیا مطمئنید؟`;
            }

            if(opType === 'out'){
                let destinationLabel = 'انبار مقصد';
                if(outDestination === 'main') destinationLabel = 'انبار اصلی';
                if(outDestination === 'teh') destinationLabel = 'انبار تهران پارس';
                return `در حال خروج ${typeCount} نوع محصول و مجموعاً ${totalQty} آیتم کالا از انبار تولید به ${destinationLabel} هستید. آیا مطمئن هستید؟`;
            }
            if(opType === 'transfer'){
                const sourceLabel = transferSource === 'main' ? 'انبار اصلی' : 'انبار تهران پارس';
                const destinationLabel = transferDestination === 'main' ? 'انبار اصلی' : 'انبار تهران پارس';
                return `در حال انتقال ${typeCount} نوع محصول و مجموعاً ${totalQty} آیتم کالا از ${sourceLabel} به ${destinationLabel} هستید. آیا مطمئن هستید؟`;
            }

            if(opType === 'return'){
                let destinationLabel = 'انبار مقصد';
                if(returnDestination === 'main') destinationLabel = 'انبار اصلی';
                if(returnDestination === 'teh') destinationLabel = 'انبار تهران پارس';
                return `در حال ثبت مرجوعی ${typeCount} نوع محصول و مجموعاً ${totalQty} آیتم به ${destinationLabel} با علت «${returnReason}» هستید. آیا مطمئن هستید؟`;
            }

            return '';
        }

        function enforceOutLimit(idx){
            if(opType !== 'out') return;
            const it = items[idx];
            if(!it) return;
            if(it.qty > it.stock){ it.qty = it.stock; }
        }

        function enforceTransferLimit(idx){
            if(opType !== 'transfer') return;
            const it = items[idx];
            if(!it) return;
            const sourceStock = findTransferSourceStockById(it.id);
            if(it.qty > sourceStock){ it.qty = sourceStock; }
        }
        function enforceSaleLimit(idx){
            if(opType !== 'sale' && opType !== 'sale_teh') return;
            const it = items[idx];
            if(!it) return;
            const sourceStock = findMainStockById(it.id);
            if(it.qty > sourceStock){ it.qty = sourceStock; }
        }

        function openModal(){
            if(!opType) return;
            updateModalSubtitle();

            $overlay.show().attr('aria-hidden','false');
            $modal.css('display','flex').attr('aria-hidden','false');
            $('body').css('overflow','hidden');

            buildAttributeFilters();
            $q.trigger('focus');
            renderPickerResults();
        }

        function closeModal(){
            $overlay.hide().attr('aria-hidden','true');
            $modal.hide().attr('aria-hidden','true');
            $('body').css('overflow','');
            $q.val('');
            $results.empty();
            updateSelectedInfo();
            updateModalSubtitle();
        }

        function updateSelectedInfo(){
            let cnt = 0;
            let sum = 0;
            for (const k in pickerQty){
                const v = +pickerQty[k];
                if (v > 0){ cnt++; sum += v; }
            }
            if (cnt <= 0){
                $info.text('هیچ موردی انتخاب نشده است.');
            } else {
                $info.text(`تعداد محصولات انتخاب‌شده: ${cnt} | جمع تعداد: ${sum}`);
            }
        }

        function buildAttributeFilters(){
            $filters.empty();
            for (const k in activeFilters){
                if (Object.prototype.hasOwnProperty.call(activeFilters, k)) delete activeFilters[k];
            }

            if (!Array.isArray(pickerAttrDefs) || pickerAttrDefs.length === 0){
                return;
            }

            const optionsByTax = Object.create(null);

            for (let i=0; i<allProducts.length; i++){
                const p = allProducts[i];
                const attrs = p && p.attrs ? p.attrs : null;
                if (!attrs || typeof attrs !== 'object') continue;

                for (let d=0; d<pickerAttrDefs.length; d++){
                    const def = pickerAttrDefs[d];
                    if (!def || !def.tax) continue;
                    const tax = String(def.tax);

                    const vals = attrs[tax];
                    if (!Array.isArray(vals) || vals.length === 0) continue;

                    if (!optionsByTax[tax]) optionsByTax[tax] = Object.create(null);
                    for (let v=0; v<vals.length; v++){
                        const rawVal = String(vals[v] || '').trim();
                        if (!rawVal) continue;
                        const key = norm(rawVal);
                        if (!key) continue;
                        optionsByTax[tax][key] = rawVal;
                    }
                }
            }

            for (let d=0; d<pickerAttrDefs.length; d++){
                const def = pickerAttrDefs[d];
                if (!def || !def.tax) continue;

                const tax = String(def.tax);
                const label = String(def.label || tax);

                const bag = optionsByTax[tax];
                if (!bag || typeof bag !== 'object') continue;

                const keys = Object.keys(bag);
                if (keys.length === 0) continue;
                keys.sort((a,b) => a.localeCompare(b, 'fa'));

                const selectId = 'wc-suf-filter-' + tax.replace(/[^a-z0-9_]/gi,'_');

                const opts = [];
                opts.push(`<option value="">همه</option>`);
                for (let i=0; i<keys.length; i++){
                    const k = keys[i];
                    const display = bag[k];
                    opts.push(`<option value="${escapeHtml(k)}">${escapeHtml(display)}</option>`);
                }

                const html = `
                  <div class="wc-suf-filter">
                    <label for="${escapeHtml(selectId)}">${escapeHtml(label)}:</label>
                    <select id="${escapeHtml(selectId)}" data-tax="${escapeHtml(tax)}">
                      ${opts.join('')}
                    </select>
                  </div>
                `;
                $filters.append(html);
            }
        }

        function productMatchesFilters(p){
            for (const tax in activeFilters){
                if (!Object.prototype.hasOwnProperty.call(activeFilters, tax)) continue;
                const sel = String(activeFilters[tax] || '');
                if (!sel) continue;

                const attrs = p && p.attrs ? p.attrs : null;
                if (!attrs || typeof attrs !== 'object') return false;

                const vals = attrs[tax];
                if (!Array.isArray(vals) || vals.length === 0) return false;

                let ok = false;
                for (let i=0; i<vals.length; i++){
                    if (norm(vals[i]) === sel){
                        ok = true;
                        break;
                    }
                }
                if (!ok) return false;
            }
            return true;
        }

        function renderPickerResults(){
            const q = norm($q.val());
            const tokens = q.split(/\s+/).map(t => t.trim()).filter(Boolean);
            const matched = [];

            if (tokens.length > 0){
                for (let i=0; i<allProducts.length; i++){
                    const p = allProducts[i];
                    const hay = String(p.search || p.label || '').toLowerCase();
                    const ok = tokens.every(t => hay.includes(t));
                    if (!ok) continue;
                    if (!productMatchesFilters(p)) continue;
                    matched.push(p);
                }
            } else {
                // بدون جستجو: اگر فیلتر انتخاب شده باشد، اجازه بده نتایج بر اساس فیلتر دیده شوند
                const hasAnyFilter = Object.keys(activeFilters).some(k => (activeFilters[k] || '') !== '');
                if (hasAnyFilter){
                    for (let i=0; i<allProducts.length; i++){
                        const p = allProducts[i];
                        if (!productMatchesFilters(p)) continue;
                        matched.push(p);
                    }
                }
            }

            const showList = matched;
            if (showList.length === 0){
                const hasAnyFilter = Object.keys(activeFilters).some(k => (activeFilters[k] || '') !== '');
                const msg = (q.length || hasAnyFilter) ? 'موردی یافت نشد.' : 'برای نمایش نتایج، عبارت جستجو را وارد کنید یا یک فیلتر انتخاب کنید.';
                $results.html(`<div style="padding:14px" class="suf-muted">${escapeHtml(msg)}</div>`);
                updateSelectedInfo();
                return;
            }

            const frag = [];
            for (let i=0; i<showList.length; i++){
                const p = showList[i];
                const pid = String(p.id);
                const stock = (+p.prod_stock || 0);
                const cur = (pickerQty[pid] != null) ? +pickerQty[pid] : 0;
                const metaLine = getPickerMetaLine(p);

                frag.push(`
                    <div class="wc-suf-picker-row" data-pid="${escapeHtml(pid)}">
                      <div>
                        <div class="wc-suf-picker-name">${escapeHtml(p.label || ('#'+pid))}</div>
                        <div class="wc-suf-picker-meta">${escapeHtml(metaLine)}</div>
                      </div>
                      <div class="wc-suf-picker-qty">
                        <button type="button" class="picker-dec" data-pid="${escapeHtml(pid)}">➖</button>
                        <input type="number" min="0" class="picker-qty" data-pid="${escapeHtml(pid)}" value="${escapeHtml(cur)}" />
                        <button type="button" class="picker-inc" data-pid="${escapeHtml(pid)}">➕</button>
                      </div>
                    </div>
                `);
            }

            $results.html(frag.join(''));
            updateSelectedInfo();
        }

        function capQtyForOut(pid, qty, showAlert){
            if(opType !== 'out') return qty;
            const stock = findProductionStockById(pid);
            if (qty > stock){
                if (showAlert){
                    const name = findLabelById(pid) || ('#'+pid);
                    alert(`برای "${name}" حداکثر قابل انتخاب ${stock} عدد است (موجودی انبار تولید).`);
                }
                return stock;
            }
            return qty;
        }

        function capQtyForTransfer(pid, qty, showAlert){
            if(opType !== 'transfer') return qty;
            const stock = findTransferSourceStockById(pid);
            if (qty > stock){
                if (showAlert){
                    const name = findLabelById(pid) || ('#'+pid);
                    alert(`برای "${name}" حداکثر قابل انتخاب ${stock} عدد است (موجودی انبار مبدا).`);
                }
                return stock;
            }
            return qty;
        }
        function capQtyForSale(pid, qty, showAlert){
            if(opType !== 'sale' && opType !== 'sale_teh') return qty;
            const stock = findMainStockById(pid);
            if (qty > stock){
                if (showAlert){
                    const name = findLabelById(pid) || ('#'+pid);
                    alert(`برای "${name}" حداکثر قابل انتخاب ${stock} عدد است (موجودی انبار اصلی).`);
                }
                return stock;
            }
            return qty;
        }
        function lockReturnDestinationIfNeeded(){
            const $destination = $('#return-destination');
            const hasSelection = String($destination.val() || '') !== '';
            $destination.prop('disabled', hasSelection);
        }

        refreshPickerOpenButton();
        updateModalSubtitle();

        $('#btn-open-picker').on('click', function(){
            if(!canOpenPicker()) return;
            openModal();
        });

        $('#wc-suf-modal-close').on('click', closeModal);
        $overlay.on('click', closeModal);

        $(document).on('keydown', function(e){
            if ($modal.is(':visible') && e.key === 'Escape'){
                e.preventDefault();
                closeModal();
            }
        });

        $q.on('input', function(){
            renderPickerResults();
        });

        $filters.on('change', 'select[data-tax]', function(){
            const tax = String($(this).data('tax') || '');
            const val = String($(this).val() || '');
            if (!tax) return;
            activeFilters[tax] = val; // normalized already
            renderPickerResults();
        });

        $('#wc-suf-picker-clear').on('click', function(){
            $q.val('');
            $filters.find('select[data-tax]').val('');
            for (const k in activeFilters){
                if (Object.prototype.hasOwnProperty.call(activeFilters, k)) activeFilters[k] = '';
            }
            $results.html('<div style="padding:14px" class="suf-muted">برای نمایش نتایج، عبارت جستجو را وارد کنید یا یک فیلتر انتخاب کنید.</div>');
            updateSelectedInfo();
            $q.trigger('focus');
        });

        $results.on('click', '.picker-inc', function(){
            const pid = String($(this).data('pid'));
            let current = (+pickerQty[pid] || 0) + 1;
            current = capQtyForOut(pid, current, true);
            current = capQtyForTransfer(pid, current, true);
            current = capQtyForSale(pid, current, true);
            pickerQty[pid] = current;
            $results.find(`.picker-qty[data-pid="${pid}"]`).val(current);
            updateSelectedInfo();
        });

        $results.on('click', '.picker-dec', function(){
            const pid = String($(this).data('pid'));
            const current = Math.max(0, (+pickerQty[pid] || 0) - 1);
            pickerQty[pid] = current;
            $results.find(`.picker-qty[data-pid="${pid}"]`).val(current);
            updateSelectedInfo();
        });

        $results.on('change', '.picker-qty', function(){
            const pid = String($(this).data('pid'));
            let v = +$(this).val();
            if (!Number.isFinite(v)) v = 0;
            v = Math.max(0, Math.floor(v));
            v = capQtyForOut(pid, v, true);
            v = capQtyForTransfer(pid, v, true);
            v = capQtyForSale(pid, v, true);
            pickerQty[pid] = v;
            $(this).val(v);
            updateSelectedInfo();
        });

        $('#wc-suf-picker-add').on('click', function(){
            if(!opType) return;
            const selectedIds = Object.keys(pickerQty).filter(function(pid){
                return (+pickerQty[pid] || 0) > 0;
            });
            if (selectedIds.length === 0){
                alert('هیچ محصولی با تعداد بالاتر از صفر انتخاب نشده است.');
                return;
            }

            const $addBtn = $(this);
            const originalText = $addBtn.text();
            $addBtn.prop('disabled', true).css({opacity: 0.7, cursor: 'wait'}).text('در حال بررسی موجودی...');

            const addItemsToTable = function(){
                let addedAny = false;

                for (const pid in pickerQty){
                    let qty = +pickerQty[pid];
                    if (!qty || qty <= 0) continue;

                    const name  = findLabelById(pid) || '(بدون نام)';
                    const stock = findProductionStockById(pid);

                    if (opType === 'out' && qty > stock){
                        alert(`مقدار انتخابی برای «${name}» بیشتر از موجودی انبار تولید است.`);
                        return false;
                    }
                    if (opType === 'transfer'){
                        const sourceStock = findTransferSourceStockById(pid);
                        if (qty > sourceStock){
                            alert(`مقدار انتخابی برای «${name}» بیشتر از موجودی انبار مبدا است.`);
                            return false;
                        }
                    }
                    if (opType === 'sale' || opType === 'sale_teh'){
                        const sourceStock = findMainStockById(pid);
                        if (qty > sourceStock){
                            alert(`مقدار انتخابی برای «${name}» بیشتر از موجودی انبار اصلی است.`);
                            return false;
                        }
                    }

                    const existingIdx = items.findIndex(x => String(x.id) === String(pid));
                    if (existingIdx >= 0){
                        items[existingIdx].qty = (items[existingIdx].qty || 0) + qty;
                        items[existingIdx].stock = stock;
                        enforceOutLimit(existingIdx);
                        enforceTransferLimit(existingIdx);
                        enforceSaleLimit(existingIdx);
                    } else {
                        items.push({id: pid, name, qty, stock});
                        enforceOutLimit(items.length - 1);
                        enforceTransferLimit(items.length - 1);
                        enforceSaleLimit(items.length - 1);
                    }

                    addedAny = true;
                }

                if (!addedAny){
                    alert('هیچ محصولی با تعداد بالاتر از صفر انتخاب نشده است.');
                    return false;
                }

                for (const pid in pickerQty){
                    if (Object.prototype.hasOwnProperty.call(pickerQty, pid)) pickerQty[pid] = 0;
                }

                renderTable();
                syncSaleHoldOrder(true);
                scheduleSaleStocksRefresh(selectedIds, 1000);
                closeModal();
                return true;
            };

            const afterDone = function(){
                $addBtn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
            };

            if (opType === 'sale' || opType === 'sale_teh'){
                refreshStocksBeforeResult(selectedIds).done(function(refreshRes){
                    if(refreshRes && refreshRes.success && refreshRes.data && refreshRes.data.stocks){
                        updateProductStocksInMemory(refreshRes.data.stocks);
                        renderPickerResults();
                        renderTable();
                    }
                    addItemsToTable();
                }).fail(function(){
                    alert('به‌روزرسانی موجودی انجام نشد. لطفاً دوباره تلاش کنید.');
                }).always(afterDone);
                return;
            }

            addItemsToTable();
            afterDone();
        });

        $('input[name="op-type"]').on('change', function(){
            $('#save-result').hide().empty();
            if(opType) return;
            opType = $(this).val();

            if(allowedOps.indexOf(opType) === -1){
                opType = null;
                $(this).prop('checked', false);
                syncOpTypeButtonsState();
                alert('شما به نوع عملیات انتخابی دسترسی ندارید.');
                return;
            }

            $('input[name="op-type"]').prop('disabled', true);
            syncOpTypeButtonsState();

            if(opType === 'out'){
                $('#out-destination-wrap').css('display','flex');
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').hide();
                $('#sale-customer-wrap').hide();
            } else if (opType === 'transfer') {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').css('display','flex');
                $('#return-controls-wrap').hide();
                $('#sale-customer-wrap').hide();
                syncTransferDestinationOptions();
            } else if (opType === 'return') {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').css('display','flex');
                $('#sale-customer-wrap').hide();
            } else if (opType === 'sale' || opType === 'sale_teh') {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').hide();
                $('#sale-customer-wrap').css('display','flex');
            } else {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').hide();
                $('#sale-customer-wrap').hide();
            }

            refreshPickerOpenButton();
            updateModalSubtitle();
            renderTable();
        });

        $('input[name="out-destination"]').on('change', function(){
            if(opType !== 'out') return;
            outDestination = $(this).val() || null;
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-source').on('change', function(){
            if(opType !== 'transfer') return;
            transferSource = $(this).val() || null;
            syncTransferDestinationOptions();
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-destination').on('change', function(){
            if(opType !== 'transfer') return;
            transferDestination = $(this).val() || null;
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-source').on('change', function(){
            if(opType !== 'transfer') return;
            transferSource = $(this).val() || null;
            syncTransferDestinationOptions();
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-destination').on('change', function(){
            if(opType !== 'transfer') return;
            transferDestination = $(this).val() || null;
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#return-destination').on('change', function(){
            if(opType !== 'return') return;
            returnDestination = $(this).val() || null;
            if(isMarjooOnly && returnDestination !== 'teh'){
                returnDestination = 'teh';
                $(this).val('teh');
            }
            lockReturnDestinationIfNeeded();
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        if(isMarjooOnly){
            $('input[name="op-type"][value="return"]').prop('checked', true).trigger('change');
            $('#return-destination').val('teh').trigger('change');
        } else if (allowedOps.length === 1) {
            $('input[name="op-type"][value="' + allowedOps[0] + '"]').prop('checked', true).trigger('change');
        }

        syncOpTypeButtonsState();

        $('#return-reason').on('change', function(){
            if(opType !== 'return') return;
            returnReason = $(this).val() || '';
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
        });
        $('#sale-customer-name').on('input', function(){
            saleCustomerName = $(this).val() || '';
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            syncSaleHoldOrder(false);
        });
        let saleCustomerLookupTimer = null;
        let lastLookupMobile = '';
        $('#sale-customer-mobile').on('input', function(){
            const normalized = normalizeDigits($(this).val() || '');
            if ( normalized !== ($(this).val() || '') ) {
                $(this).val(normalized);
            }
            saleCustomerMobile = normalized;
            const mobile = normalizeMobileInput(saleCustomerMobile);
            if (saleCustomerLookupTimer) {
                clearTimeout(saleCustomerLookupTimer);
            }
            if (/^0\d{10}$/.test(mobile) && mobile !== lastLookupMobile) {
                saleCustomerLookupTimer = setTimeout(function(){
                    $.post(ajaxurl, {
                        action: 'wc_suf_lookup_sale_customer',
                        mobile: mobile,
                        _wpnonce: '<?php echo wp_create_nonce('wc_suf_lookup_sale_customer'); ?>'
                    }).done(function(res){
                        if (!res || !res.success || !res.data) return;
                        const fullName = String(res.data.full_name || '').trim();
                        if (fullName.length >= 3) {
                            saleCustomerName = fullName;
                            $('#sale-customer-name').val(fullName);
                        }
                    }).always(function(){
                        refreshPickerOpenButton();
                        $('#btn-save').prop('disabled', !canSave());
                    });
                }, 250);
                lastLookupMobile = mobile;
            }
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            syncSaleHoldOrder(false);
        });
        $('#sale-customer-address').on('input', function(){
            saleCustomerAddress = $(this).val() || '';
            refreshPickerOpenButton();
            $('#btn-save').prop('disabled', !canSave());
            syncSaleHoldOrder(false);
        });

        $('#items-table').on('click','.row-inc', function(){
            const i = +$(this).data('i'); items[i].qty++; enforceOutLimit(i); enforceTransferLimit(i); enforceSaleLimit(i); renderTable();
            syncSaleHoldOrder(true);
            scheduleSaleStocksRefresh([items[i] ? items[i].id : 0], 1000);
        });
        $('#items-table').on('click','.row-dec', function(){
            const i = +$(this).data('i'); items[i].qty = Math.max(1, items[i].qty-1); enforceOutLimit(i); enforceTransferLimit(i); enforceSaleLimit(i); renderTable();
            syncSaleHoldOrder(true);
            scheduleSaleStocksRefresh([items[i] ? items[i].id : 0], 1000);
        });
        $('#items-table').on('change','.row-qty', function(){
            const i = +$(this).data('i'); let v = +$(this).val();
            v = Math.max(1, v||1); items[i].qty = v; enforceOutLimit(i); enforceTransferLimit(i); enforceSaleLimit(i); renderTable();
            syncSaleHoldOrder(true);
            scheduleSaleStocksRefresh([items[i] ? items[i].id : 0], 1000);
        });

        $('#items-table').on('click','.btn-del',function(){
            items.splice($(this).data('i'),1);
            renderTable();
            syncSaleHoldOrder(true);
        });

        let submitting = false;
        $('#btn-save').on('click', function(){
            if (submitting) return;
            if (!canSave()) return;
            if ((opType === 'sale' || opType === 'sale_teh') && !isSaleCustomerDataValid(true)) return;

            const submittedProductIds = items.map(function(it){ return parseInt(it.id, 10); }).filter(function(v){ return Number.isFinite(v) && v > 0; });

            if (opType === 'in' || opType === 'out' || opType === 'transfer' || opType === 'return'){
                const ok = window.confirm(buildSubmitConfirmMessage());
                if (!ok){
                    return;
                }
            }

            submitting = true;
            $('#save-result').hide().empty();

            const $btn = $(this);
            const originalText = $btn.text();
            $btn.prop('disabled', true).css({opacity: 0.6, cursor: 'not-allowed'}).text('در حال ثبت...');

            $.post(ajaxurl, {
                action      : 'save_stock_update',
                items       : JSON.stringify(items),
                user_code   : userCode,
                out_destination : String(outDestination || ''),
                transfer_source : String(transferSource || ''),
                transfer_destination : String(transferDestination || ''),
                return_destination : String(returnDestination || ''),
                return_reason : String(returnReason || ''),
                sale_customer_name : String(saleCustomerName || ''),
                sale_customer_mobile : String(saleCustomerMobile || ''),
                sale_customer_address : String(saleCustomerAddress || ''),
                sale_hold_order_id : saleHoldOrderId,
                op_type     : opType,
                _wpnonce    : '<?php echo wp_create_nonce('save_stock_update'); ?>'
            }).done(function(res){
                try{
                    if(res && res.success){
                        const msg = (res.data && res.data.message) ? res.data.message : 'ثبت شد.';
                        const csvUrl = (res.data && res.data.csv_url) ? String(res.data.csv_url) : '';
                        const wordUrl = (res.data && res.data.word_url) ? String(res.data.word_url) : '';
                        const refreshIdsRaw = (res.data && Array.isArray(res.data.product_ids)) ? res.data.product_ids : submittedProductIds;

                        const finishSaveUi = function(refreshFailed){
                            items.length = 0;
                            opType = null;
                            outDestination = null;
                            transferSource = null;
                            transferDestination = null;
                            returnDestination = null;
                            returnReason = '';
                            saleCustomerName = '';
                            saleCustomerMobile = '';
                            saleCustomerAddress = '';
                            saleHoldOrderId = 0;
                            for (const pid in pickerQty){
                                if (Object.prototype.hasOwnProperty.call(pickerQty, pid)) pickerQty[pid] = 0;
                            }
                            $('input[name="op-type"]').prop('checked', false).prop('disabled', false);
                            $('input[name="out-destination"]').prop('checked', false);
                            $('#transfer-source').val('');
                            $('#transfer-destination').html('<option value="">انتخاب انبار مقصد...</option>');
                            $('#return-destination').val('');
                            $('#return-destination').prop('disabled', false);
                            $('#return-reason').val('');
                            $('#sale-customer-name').val('');
                            $('#sale-customer-mobile').val('');
                            $('#sale-customer-address').val('');
                            $('#out-destination-wrap').hide();
                            $('#transfer-controls-wrap').hide();
                            $('#return-controls-wrap').hide();
                            $('#sale-customer-wrap').hide();
                            closeModal();
                            refreshPickerOpenButton();
                            renderTable();

                            let html = '<div style="font-weight:700; color:#065f46">'+escapeHtml(msg)+'</div>';
                            if(refreshFailed){
                                html += '<div style="margin-top:8px; color:#b45309; font-weight:700">به‌روزرسانی لحظه‌ای موجودی انجام نشد؛ صفحه را یک‌بار رفرش کنید.</div>';
                            }
                            if(csvUrl){
                                html += '<div style="margin-top:8px"><a href="'+csvUrl+'" target="_blank" rel="noopener" style="color:#1d4ed8; font-weight:700">چاپ لیبل (HTML)</a></div>';
                            }
                            if(wordUrl){
                                html += '<div style="margin-top:8px"><a href="'+wordUrl+'" target="_blank" rel="noopener" style="color:#1d4ed8; font-weight:700">دانلود رسید عملیات (HTML)</a></div>';
                            }
                            $('#save-result').html(html).show();
                            alert(msg);
                        };

                        refreshStocksBeforeResult(refreshIdsRaw).done(function(refreshRes){
                            if(refreshRes && refreshRes.success && refreshRes.data && refreshRes.data.stocks){
                                updateProductStocksInMemory(refreshRes.data.stocks);
                            }
                            finishSaveUi(false);
                        }).fail(function(){
                            finishSaveUi(true);
                        }).always(function(){
                            submitting = false;
                            $btn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
                        });
                    }else{
                        alert((res && res.data && res.data.message) ? res.data.message : 'ثبت ناموفق.');
                        submitting = false; $btn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
                    }
                }catch(e){
                    alert('پاسخ نامعتبر از سرور.');
                    submitting = false; $btn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
                }
            }).fail(function(){
                alert('خطای ارتباطی هنگام ثبت.');
                submitting = false; $btn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
});
