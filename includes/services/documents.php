<?php
function wc_suf_generate_batch_label_html( $batch_code, $rows ) {
    if ( empty( $rows ) || ! is_array( $rows ) ) {
        return new WP_Error( 'label_empty', 'داده‌ای برای ساخت صفحه چاپ لیبل وجود ندارد.' );
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'label_upload_dir', 'مسیر آپلود در دسترس نیست: ' . $upload['error'] );
    }

    $dir = trailingslashit( $upload['basedir'] ) . 'wc-suf-exports';
    if ( ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'label_mkdir_failed', 'ساخت پوشه فایل‌های چاپ لیبل ناموفق بود.' );
    }

    $safe_batch = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $batch_code );
    $filename   = sprintf( '%s-%s-labels.html', $safe_batch, wp_generate_password(6, false, false) );
    $filepath   = trailingslashit( $dir ) . $filename;
    $fileurl    = trailingslashit( $upload['baseurl'] ) . 'wc-suf-exports/' . $filename;

    $website_name = 'www.doukshop.ir';
    $logo_url = 'https://douk.sepandpitch.com/wp-content/uploads/2026/03/enlogo.jpg';

    $label_items = [];
    foreach ( $rows as $r ) {
        $line_count = max( 1, (int) ( $r['qty'] ?? 1 ) );
        for ( $i = 0; $i < $line_count; $i++ ) {
            $label_items[] = [
                'id'    => (string) ( $r['id'] ?? '' ),
                'name'  => (string) ( $r['name'] ?? '' ),
                'price' => (string) ( $r['price'] ?? '' ),
            ];
        }
    }

    $labels_html = '';
    foreach ( $label_items as $idx => $it ) {
        $id = preg_replace('/[^0-9A-Za-z\-\.]/', '', (string) $it['id']);
        if ( $id === '' ) {
            $id = (string) ( $it['id'] ?? '' );
        }
        $labels_html .= '<article class="wc-suf-label">'
            . '<div class="label-row name-row"><div class="name">' . esc_html( (string) $it['name'] ) . '</div></div>'
            . '<div class="label-row middle-row">'
            . '  <div class="barcode-wrap">'
            . '    <svg class="barcode" jsbarcode-format="CODE128" jsbarcode-value="' . esc_attr( $id ) . '" jsbarcode-textmargin="0" jsbarcode-fontoptions="bold"></svg>'
            . '  </div>'
            . '  <div class="price-wrap">'
            . '    <div class="price-line"><strong>قیمت:</strong> <span>' . esc_html( number_format_i18n( (float) $it['price'] ) . ' تومان' ) . '</span></div>'
            . '    <div class="code-line"><strong>کد محصول:</strong> <span>' . esc_html( $id ) . '</span></div>'
            . '  </div>'
            . '</div>'
            . '<div class="label-row footer-row">'
            . '  <div class="logo-wrap"><img src="' . esc_url( $logo_url ) . '" alt="logo"></div>'
            . '  <div class="site-wrap">' . esc_html( $website_name ) . '</div>'
            . '</div>'
            . '</article>';
    }

    $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>چاپ لیبل - ' . esc_html( $batch_code ) . '</title>'
        . '<style>'
        . '@page{size:118.11mm 29.97mm; margin:0;}'
        . 'body{margin:0; font-family:Tahoma,Arial,sans-serif; background:#fff;}'
        . '.sheet{width:118.11mm; margin:0; padding:0 1mm 1mm; box-sizing:border-box; display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); grid-auto-rows:28.92mm; row-gap:1mm; column-gap:1mm;}'
        . '.wc-suf-label{box-sizing:border-box; height:100%; border:0.35mm solid #111; border-radius:4mm; overflow:hidden; display:grid; grid-template-rows:10.5mm 10.5mm 8.97mm; padding:0.6mm 0.4mm 1.8mm;}'
        . '.label-row{display:flex; align-items:center; box-sizing:border-box;}'
        . '.name-row{justify-content:center; border-bottom:0.35mm solid #111; padding:1mm 1.5mm;}'
        . '.name{font-size:3.2mm; font-weight:700; text-align:center; line-height:1.2; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;}'
        . '.middle-row{display:grid; direction:ltr; grid-template-columns:40% 60%; border-bottom:0.35mm solid #111;}'
        . '.barcode-wrap{border-right:0.35mm solid #111; padding:0.7mm 0.6mm; display:flex; align-items:center; justify-content:center;}'
        . '.barcode{width:100%; height:100%;}'
        . '.price-wrap{padding:1mm 1.3mm; font-size:2.8mm; line-height:1.4; text-align:right; direction:rtl; display:flex; flex-direction:column; justify-content:center; gap:0.5mm;}'
        . '.price-line,.code-line{white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}'
        . '.footer-row{display:grid; grid-template-columns:17mm 1fr;}'
        . '.logo-wrap{display:flex; align-items:center; justify-content:center;padding-right: 2mm;
    padding-bottom: 2mm;}'
        . '.logo-wrap img{max-width:100%; max-height:78%; object-fit:contain; object-position:center; margin:0;margin-bottom: 1.5mm;}'
        . '.site-wrap{display:flex; align-items:center; justify-content:flex-end; padding:0 1.5mm; font-size:2.9mm;}'
        . '@media print{.sheet{margin:0;}}'
        . '</style>'
        . '<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>'
        . '</head><body>'
        . '<main class="sheet">' . $labels_html . '</main>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".barcode").forEach(function(el){try{JsBarcode(el, el.getAttribute("jsbarcode-value") || "", {format:"CODE128",displayValue:false,margin:0,height:24,width:1.2});}catch(e){}});});</script>'
        . '</body></html>';

    $bytes = file_put_contents( $filepath, $html );
    if ( false === $bytes ) {
        return new WP_Error( 'label_write_failed', 'ایجاد صفحه چاپ لیبل ناموفق بود.' );
    }

    return [
        'path' => $filepath,
        'url'  => $fileurl,
    ];
}


function wc_suf_generate_batch_word_receipt( $batch_code, $context, $rows ) {
    if ( empty($rows) || ! is_array($rows) ) {
        return new WP_Error( 'word_empty', 'داده‌ای برای ساخت رسید HTML وجود ندارد.' );
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'word_upload_dir', 'مسیر آپلود در دسترس نیست: ' . $upload['error'] );
    }

    $dir = trailingslashit( $upload['basedir'] ) . 'wc-suf-exports';
    if ( ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'word_mkdir_failed', 'ساخت پوشه فایل‌های رسید HTML ناموفق بود.' );
    }

    $safe_batch = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $batch_code );
    $filename   = sprintf( '%s-%s-receipt.html', $safe_batch, wp_generate_password(6, false, false) );
    $filepath   = trailingslashit( $dir ) . $filename;
    $fileurl    = trailingslashit( $upload['baseurl'] ) . 'wc-suf-exports/' . $filename;

    $op_type   = (string) ($context['op_type'] ?? '');
    $purpose   = (string) ($context['purpose'] ?? '');
    $user_disp = (string) ($context['user_display'] ?? '');
    $user_code = (string) ($context['user_code'] ?? '');
    $created   = (string) ($context['created_at'] ?? current_time('mysql'));
    $jalali    = wc_suf_format_jalali_datetime($created);
    $op_label  = wc_suf_op_label($op_type);
    $is_sale   = in_array( $op_type, [ 'sale', 'sale_teh' ], true );

    $sum = 0;
    $rows_html = '';
    foreach ( $rows as $i => $r ) {
        $qty = (float) ($r['qty'] ?? 0);
        $sum += $qty;
        $rows_html .= '<tr>'
            . '<td>' . esc_html( (string)($i+1) ) . '</td>'
            . '<td>' . esc_html( (string)($r['id'] ?? '') ) . '</td>'
            . '<td>' . esc_html( (string)($r['name'] ?? '') ) . '</td>'
            . '<td>' . esc_html( (string)($r['qty'] ?? 0) ) . '</td>'
            . '</tr>';
    }

    $html = '<html><head><meta charset="UTF-8"><style>'
        . 'body{font-family:Tahoma,Arial,sans-serif; direction:rtl; color:#111827; font-size:12pt;}'
        . '.box{border:1px solid #d1d5db; border-radius:10px; padding:14px;}'
        . 'h1{font-size:18pt; margin:0 0 12px 0; color:#1e3a8a;}'
        . 'table{border-collapse:collapse; width:100%; margin-top:12px;}'
        . 'th,td{border:1px solid #9ca3af; padding:6px; text-align:right;}'
        . 'th{background:#f3f4f6;}'
        . '.meta{margin:3px 0;}'
        . '</style></head><body>'
        . '<div class="box">'
        . '<h1>رسید ' . esc_html($op_label) . '</h1>'
        . '<div class="meta"><strong>' . esc_html( $is_sale ? 'شماره سفارش:' : 'کد عملیات:' ) . '</strong> ' . esc_html($batch_code) . '</div>'
        . '<div class="meta"><strong>' . esc_html( $is_sale ? 'تاریخ و ساعت:' : 'تاریخ:' ) . '</strong> ' . esc_html($jalali) . '</div>'
        . '<div class="meta"><strong>' . esc_html( $is_sale ? 'فروشنده:' : 'کاربر:' ) . '</strong> ' . esc_html($user_disp ?: 'مهمان') . '</div>'
        . ( $is_sale ? '' : '<div class="meta"><strong>کد کاربر:</strong> ' . esc_html($user_code ?: '—') . '</div>' )
        . ( $is_sale ? '' : '<div class="meta"><strong>توضیحات:</strong> ' . esc_html($purpose ?: '—') . '</div>' )
        . '<div class="meta"><strong>تعداد اقلام:</strong> ' . esc_html( (string) count($rows) ) . ' | <strong>جمع تعداد:</strong> ' . esc_html( (string) $sum ) . '</div>'
        . '<table><thead><tr><th>#</th><th>ID</th><th>نام محصول</th><th>تعداد</th></tr></thead><tbody>' . $rows_html . '</tbody></table>'
        . '</div></body></html>';

    $bytes = file_put_contents( $filepath, $html );
    if ( false === $bytes ) {
        return new WP_Error( 'word_write_failed', 'ایجاد فایل رسید HTML ناموفق بود.' );
    }

    return [ 'path' => $filepath, 'url' => $fileurl ];
}
