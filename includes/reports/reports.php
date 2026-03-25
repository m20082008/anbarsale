<?php
/*--------------------------------------
| رندر مشترک گزارش (لیست و جزئیات)
---------------------------------------*/
function wc_suf_render_audit_html($args = []){
    $public = ! empty( $args['public'] );

    if( ! $public ){
        if( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ){
            return '<div class="wrap" dir="rtl" style="color:#b91c1c">دسترسی کافی برای مشاهده گزارش ندارید.</div>';
        }
    }

    global $wpdb;
    $table = $wpdb->prefix.'stock_audit';
    ob_start();

    if( isset($_GET['view'], $_GET['code']) && $_GET['view']==='batch' ){
        $code = sanitize_text_field( wp_unslash($_GET['code']) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE batch_code = %s ORDER BY id ASC", $code
        ) );
        ?>
        <div class="wrap" dir="rtl">
            <h1>جزئیات کد ثبت: <?php echo esc_html($code); ?></h1>
            <p><a href="<?php echo esc_url( remove_query_arg(['view','code']) ); ?>">&larr; بازگشت به فهرست</a></p>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>#</th><th>عملیات</th><th>ID</th><th>محصول</th>
                        <th>تغییر</th><th>قبل → بعد</th><th>چاپ لیبل</th>
                        <th>کاربر</th><th>کد</th><th>IP</th><th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if($rows){
                    foreach($rows as $r){
                        $user_disp = $r->user_login ? $r->user_login : ( $r->user_id ? 'user#'.$r->user_id : 'مهمان' );
                        ?>
                        <tr>
                            <td><?php echo esc_html($r->id); ?></td>
                            <td><?php echo esc_html( wc_suf_op_label($r->op_type) ); ?></td>
                            <td><?php echo esc_html($r->product_id); ?></td>
                            <td><?php echo esc_html($r->product_name ?: ''); ?></td>
                            <td><?php echo esc_html(intval($r->added_qty)); ?></td>
                            <td><?php echo esc_html(intval($r->old_qty).' → '.intval($r->new_qty)); ?></td>
                            <td><?php echo esc_html($r->print_label ? 'بله' : 'خیر'); ?></td>
                            <td><?php echo esc_html($user_disp); ?></td>
                            <td><?php echo esc_html($r->user_code ?: '—'); ?></td>
                            <td><?php echo esc_html($r->ip ?: ''); ?></td>
                            <td><?php echo esc_html( wc_suf_format_jalali_datetime($r->created_at) ); ?></td>
                        </tr>
                        <?php
                    }
                } else {
                    echo '<tr><td colspan="11" style="text-align:center">‌رکوردی یافت نشد.</td></tr>';
                }
                ?>
                </tbody>
            </table>
            <?php
            $op_for_batch = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(op_type) FROM $table WHERE batch_code=%s", $code
            ) );
            if ( in_array( $op_for_batch, ['out','out_main','out_teh'], true ) ) {
                $pur = $wpdb->get_var( $wpdb->prepare(
                    "SELECT purpose FROM $table WHERE batch_code=%s AND purpose IS NOT NULL AND purpose<>'' LIMIT 1", $code
                ) );
                if($pur){
                    echo '<p><strong>هدف خروج:</strong> '.esc_html($pur).'</p>';
                }
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    $limit = 200;
    $rows = $wpdb->get_results(
        $wpdb->prepare("
            SELECT
              batch_code,
              MIN(created_at) as created_at,
              MAX(op_type) as op_type,
              MAX(print_label) as print_label,
              MAX(user_login) as user_login,
              MAX(user_id) as user_id,
              MAX(user_code) as user_code,
              COUNT(*) as items_count,
              SUM(added_qty) as total_qty_change
            FROM $table
            GROUP BY batch_code
            ORDER BY MAX(id) DESC
            LIMIT %d
        ", $limit)
    );
    ?>
    <div class="wrap" dir="rtl">
        <h1>گزارش تغییر موجودی (گروهی)</h1>
        <p>آخرین <?php echo esc_html($limit); ?> ثبت گروهی. برای جزئیات روی کد ثبت کلیک کنید.</p>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>کد ثبت</th>
                    <th>عملیات</th>
                    <th>تعداد آیتم‌ها</th>
                    <th>جمع تغییر</th>
                    <th>چاپ لیبل</th>
                    <th>کاربر</th>
                    <th>کد (URL/Shortcode)</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if($rows){
                    foreach($rows as $r){
                        $user_disp = $r->user_login ? $r->user_login : ( $r->user_id ? 'user#'.$r->user_id : 'مهمان' );
                        $link = esc_url( add_query_arg( ['view'=>'batch','code'=>$r->batch_code] ) );
                        ?>
                        <tr>
                            <td><a href="<?php echo $link; ?>"><?php echo esc_html($r->batch_code); ?></a></td>
                            <td><?php echo esc_html( wc_suf_op_label($r->op_type) ); ?></td>
                            <td><?php echo esc_html((int)$r->items_count); ?></td>
                            <td><?php echo esc_html((int)$r->total_qty_change); ?></td>
                            <td><?php echo esc_html($r->print_label ? 'بله' : 'خیر'); ?></td>
                            <td><?php echo esc_html($user_disp); ?></td>
                            <td><?php echo esc_html($r->user_code ?: '—'); ?></td>
                            <td><?php echo esc_html( wc_suf_format_jalali_datetime($r->created_at) ); ?></td>
                        </tr>
                        <?php
                    }
                } else {
                    echo '<tr><td colspan="8" style="text-align:center">‌رکوردی یافت نشد.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}



function wc_suf_gregorian_to_jalali( $gy, $gm, $gd ) {
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [ $jy, $jm, $jd ];
}

function wc_suf_format_jalali_datetime( $mysql_datetime ) {
    $datetime_string = trim( (string) $mysql_datetime );
    if ( $datetime_string === '' ) {
        return '';
    }

    try {
        $timezone = wp_timezone();
        $dt = new DateTime( $datetime_string, $timezone );
    } catch ( Exception $e ) {
        return $datetime_string;
    }

    $gy = (int) $dt->format('Y');
    $gm = (int) $dt->format('n');
    $gd = (int) $dt->format('j');
    [$jy, $jm, $jd] = wc_suf_gregorian_to_jalali( $gy, $gm, $gd );

    return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, $dt->format('H:i:s'));
}

function wc_suf_collect_detailed_log_filters() {
    return [
        'q' => isset($_GET['q']) ? sanitize_text_field( wp_unslash($_GET['q']) ) : '',
        'from' => isset($_GET['from']) ? sanitize_text_field( wp_unslash($_GET['from']) ) : '',
        'to' => isset($_GET['to']) ? sanitize_text_field( wp_unslash($_GET['to']) ) : '',
        'batch_code' => isset($_GET['batch_code']) ? sanitize_text_field( wp_unslash($_GET['batch_code']) ) : '',
        'op' => isset($_GET['op']) ? sanitize_text_field( wp_unslash($_GET['op']) ) : '',
        'product_id' => isset($_GET['product_id']) ? absint($_GET['product_id']) : 0,
        'user_id' => isset($_GET['user_id']) ? absint($_GET['user_id']) : 0,
        'paged' => isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1,
    ];
}

function wc_suf_get_detailed_logs_rows( $filters, $limit = 100, $apply_limit = true, $offset = 0 ) {
    global $wpdb;
    $move_table = $wpdb->prefix.'stock_production_moves';
    $audit_table = $wpdb->prefix.'stock_audit';

    $q = (string) ( $filters['q'] ?? '' );
    $from = (string) ( $filters['from'] ?? '' );
    $to = (string) ( $filters['to'] ?? '' );
    $batch_filter = (string) ( $filters['batch_code'] ?? '' );
    $op_filter = (string) ( $filters['op'] ?? '' );
    $pid_filter = (int) ( $filters['product_id'] ?? 0 );
    $uid_filter = (int) ( $filters['user_id'] ?? 0 );

    $where = [];
    $params = [];

    if ( $q !== '' ) {
        $where[] = '(m.product_name LIKE %s OR m.batch_code LIKE %s OR m.operation LIKE %s)';
        $like = '%' . $wpdb->esc_like($q) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ( $batch_filter !== '' ) {
        $where[] = 'm.batch_code = %s';
        $params[] = $batch_filter;
    }

    if ( in_array( $op_filter, ['in','out','transfer','return','sale','sale_teh','sale_edit','sale_cancel','onlyLabel'], true ) ) {
        $where[] = 'm.operation = %s';
        $params[] = $op_filter;
    }

    if ( $pid_filter > 0 ) {
        $where[] = 'm.product_id = %d';
        $params[] = $pid_filter;
    }

    if ( $uid_filter > 0 ) {
        $where[] = 'm.user_id = %d';
        $params[] = $uid_filter;
    }

    if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ) {
        $where[] = 'DATE(m.created_at) >= %s';
        $params[] = $from;
    }

    if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ) {
        $where[] = 'DATE(m.created_at) <= %s';
        $params[] = $to;
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT m.*, a.csv_file_url, a.word_file_url
            FROM `$move_table` m
            LEFT JOIN (
                SELECT batch_code, MAX(csv_file_url) AS csv_file_url, MAX(word_file_url) AS word_file_url
                FROM `$audit_table`
                GROUP BY batch_code
            ) a ON a.batch_code = m.batch_code
            $where_sql
            ORDER BY m.id DESC";

    if ( $apply_limit ) {
        $sql .= " LIMIT %d OFFSET %d";
        $params[] = (int) $limit;
        $params[] = max(0, (int) $offset);
    }

    if ( empty($params) ) {
        return $wpdb->get_results( $sql );
    }
    return $wpdb->get_results( $wpdb->prepare($sql, ...$params) );
}

function wc_suf_get_detailed_logs_total_count( $filters ) {
    global $wpdb;
    $move_table = $wpdb->prefix.'stock_production_moves';

    $q = (string) ( $filters['q'] ?? '' );
    $from = (string) ( $filters['from'] ?? '' );
    $to = (string) ( $filters['to'] ?? '' );
    $batch_filter = (string) ( $filters['batch_code'] ?? '' );
    $op_filter = (string) ( $filters['op'] ?? '' );
    $pid_filter = (int) ( $filters['product_id'] ?? 0 );
    $uid_filter = (int) ( $filters['user_id'] ?? 0 );

    $where = [];
    $params = [];

    if ( $q !== '' ) {
        $where[] = '(m.product_name LIKE %s OR m.batch_code LIKE %s OR m.operation LIKE %s)';
        $like = '%' . $wpdb->esc_like($q) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ( $batch_filter !== '' ) {
        $where[] = 'm.batch_code = %s';
        $params[] = $batch_filter;
    }
    if ( in_array( $op_filter, ['in','out','transfer','return','sale','sale_teh','sale_edit','sale_cancel','onlyLabel'], true ) ) {
        $where[] = 'm.operation = %s';
        $params[] = $op_filter;
    }
    if ( $pid_filter > 0 ) {
        $where[] = 'm.product_id = %d';
        $params[] = $pid_filter;
    }
    if ( $uid_filter > 0 ) {
        $where[] = 'm.user_id = %d';
        $params[] = $uid_filter;
    }
    if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ) {
        $where[] = 'DATE(m.created_at) >= %s';
        $params[] = $from;
    }
    if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ) {
        $where[] = 'DATE(m.created_at) <= %s';
        $params[] = $to;
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT COUNT(*) FROM `$move_table` m $where_sql";
    if ( empty($params) ) {
        return (int) $wpdb->get_var( $sql );
    }
    return (int) $wpdb->get_var( $wpdb->prepare($sql, ...$params) );
}

function wc_suf_generate_simple_xlsx( $filename, $headers, $rows ) {
    if ( ! class_exists('ZipArchive') ) {
        return false;
    }

    $temp_zip = wp_tempnam( 'wc-suf-export-' );
    if ( ! $temp_zip ) {
        return false;
    }

    $zip = new ZipArchive();
    if ( true !== $zip->open( $temp_zip, ZipArchive::OVERWRITE ) ) {
        return false;
    }

    $esc = static function( $value ) {
        return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    };

    $sheet_rows = [];
    $all_rows = array_merge( [ $headers ], $rows );
    foreach ( $all_rows as $row_index => $row_cells ) {
        $cells_xml = '';
        foreach ( array_values( (array) $row_cells ) as $idx => $cell ) {
            $col = '';
            $col_idx = $idx + 1;
            while ( $col_idx > 0 ) {
                $mod = ($col_idx - 1) % 26;
                $col = chr(65 + $mod) . $col;
                $col_idx = intdiv($col_idx - 1, 26);
            }
            $ref = $col . ($row_index + 1);
            $cells_xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc($cell) . '</t></is></c>';
        }
        $sheet_rows[] = '<row r="' . ($row_index + 1) . '">' . $cells_xml . '</row>';
    }

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . implode('', $sheet_rows)
        . '</sheetData></worksheet>';

    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    nocache_headers();
    header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"' );
    header( 'Content-Length: ' . filesize($temp_zip) );
    readfile( $temp_zip );
    @unlink( $temp_zip );
    exit;
}

function wc_suf_export_detailed_logs() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'Unauthorized', 403 );
    }
    if ( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') && ! wc_suf_current_user_has_role('formeditor') ) {
        wp_die( 'Forbidden', 403 );
    }
    if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_GET['_wpnonce']) ), 'wc_suf_export_detailed_logs' ) ) {
        wp_die( 'Invalid nonce', 403 );
    }

    $format = isset($_GET['format']) ? sanitize_key( wp_unslash($_GET['format']) ) : '';
    if ( ! in_array( $format, ['pdf','xlsx'], true ) ) {
        wp_die( 'Invalid format', 400 );
    }

    $filters = wc_suf_collect_detailed_log_filters();
    $rows = wc_suf_get_detailed_logs_rows( $filters, 5000, false );

    $header_row = ['#','کد عملیات','عملیات','مقصد','ID محصول','نام محصول','قبل','تغییر','بعد','کاربر','تاریخ'];
    $export_rows = [];
    foreach ( (array) $rows as $r ) {
        $user_disp = $r->user_login ? $r->user_login : ( $r->user_id ? 'user#'.$r->user_id : 'مهمان' );
        $export_rows[] = [
            (string) $r->id,
            (string) $r->batch_code,
            (string) wc_suf_op_label($r->operation),
            (string) wc_suf_destination_label( $r->destination ?: '—' ),
            (string) $r->product_id,
            (string) ($r->product_name ?: ''),
            (string) ((float)$r->old_qty),
            (string) ((float)$r->change_qty),
            (string) ((float)$r->new_qty),
            (string) $user_disp,
            (string) wc_suf_format_jalali_datetime($r->created_at),
        ];
    }

    if ( $format === 'xlsx' ) {
        wc_suf_generate_simple_xlsx( 'detailed-logs-export.xlsx', $header_row, $export_rows );
    }

    nocache_headers();
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'Content-Disposition: inline; filename="detailed-logs-report.html"' );
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>گزارش لاگ دقیق</title>';
    echo '<style>body{font-family:tahoma,sans-serif;padding:16px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:6px;font-size:12px}th{background:#f3f4f6}h1{font-size:18px}small{color:#6b7280}</style>';
    echo '</head><body>';
    echo '<h1>گزارش لاگ دقیق (خروجی PDF)</h1>';
    echo '<small>برای ذخیره PDF از Print مرورگر استفاده کنید.</small>';
    echo '<table><thead><tr>';
    foreach ( $header_row as $col ) {
        echo '<th>' . esc_html($col) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ( $export_rows as $row ) {
        echo '<tr>';
        foreach ( $row as $cell ) {
            echo '<td>' . esc_html($cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table><script>window.print();</script></body></html>';
    exit;
}
add_action( 'admin_post_wc_suf_export_detailed_logs', 'wc_suf_export_detailed_logs' );

function wc_suf_render_detailed_logs_html( $args = [] ) {
    $public = ! empty($args['public']);
    $only_formeditor = ! empty($args['only_formeditor']);
    if ( $only_formeditor ) {
        if ( ! wc_suf_current_user_has_role('formeditor') ) {
            return '<div class="wrap" dir="rtl" style="color:#b91c1c">فقط کاربران با نقش formeditor اجازه مشاهده این صفحه را دارند.</div>';
        }
    } elseif ( ! $public && ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) {
        return '<div class="wrap" dir="rtl" style="color:#b91c1c">دسترسی کافی برای مشاهده لاگ ندارید.</div>';
    }

    global $wpdb;
    $move_table = $wpdb->prefix.'stock_production_moves';
    $filters = wc_suf_collect_detailed_log_filters();
    $q = $filters['q'];
    $from = $filters['from'];
    $to = $filters['to'];
    $batch_filter = $filters['batch_code'];
    $op_filter = $filters['op'];
    $pid_filter = (int) $filters['product_id'];
    $uid_filter = (int) $filters['user_id'];

    $limit = 100;
    $current_page = max(1, (int)($filters['paged'] ?? 1));
    $offset = ($current_page - 1) * $limit;
    $total_rows = wc_suf_get_detailed_logs_total_count( $filters );
    $total_pages = max(1, (int) ceil($total_rows / $limit));
    if ( $current_page > $total_pages ) {
        $current_page = $total_pages;
        $offset = ($current_page - 1) * $limit;
    }
    $rows = wc_suf_get_detailed_logs_rows( $filters, $limit, true, $offset );
    $batch_codes = $wpdb->get_col( "SELECT DISTINCT batch_code FROM `$move_table` WHERE batch_code IS NOT NULL AND batch_code<>'' ORDER BY id DESC LIMIT 1000" );
    $products_for_filter = $wpdb->get_results( "SELECT product_id, MAX(product_name) AS product_name FROM `$move_table` GROUP BY product_id ORDER BY MAX(id) DESC LIMIT 2000" );
    $users_for_filter = $wpdb->get_results( "SELECT user_id, MAX(user_login) AS user_login FROM `$move_table` WHERE user_id IS NOT NULL AND user_id > 0 GROUP BY user_id ORDER BY MAX(id) DESC LIMIT 500" );
    $has_filter = ( $q !== '' || $from !== '' || $to !== '' || $batch_filter !== '' || $op_filter !== '' || $pid_filter > 0 || $uid_filter > 0 );

    wp_enqueue_style('wc-suf-select2', plugins_url('assets/select2.min.css', WC_SUF_PLUGIN_FILE), [], '4.1.0');
    wp_enqueue_script('wc-suf-select2', plugins_url('assets/select2.min.js', WC_SUF_PLUGIN_FILE), ['jquery'], '4.1.0', true);

    ob_start();
    ?>
    <div class="wrap" dir="rtl">
        <h1>لاگ دقیق عملیات انبار</h1>
        <form method="get" style="margin:10px 0 14px; padding:12px; border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc; display:flex; gap:10px; flex-wrap:wrap; align-items:end">
            <?php if ( ! $public ) : ?>
                <input type="hidden" name="page" value="wc-stock-audit-detailed">
            <?php endif; ?>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">جستجو (نام محصول/کد عملیات)</label>
                <input type="text" name="q" value="<?php echo esc_attr($q); ?>" placeholder="مثلاً توران یا out_0123" style="min-width:260px; padding:8px; border:1px solid #cbd5e1; border-radius:8px">
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">از تاریخ</label>
                <input type="date" name="from" value="<?php echo esc_attr($from); ?>" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px">
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">تا تاریخ</label>
                <input type="date" name="to" value="<?php echo esc_attr($to); ?>" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px">
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">کد عملیات</label>
                <select name="batch_code" class="wc-suf-select2" style="min-width:260px">
                    <option value="">همه</option>
                    <?php foreach ( (array) $batch_codes as $bc ) : ?>
                        <option value="<?php echo esc_attr($bc); ?>" <?php selected($batch_filter, $bc); ?>><?php echo esc_html($bc); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">نوع عملیات</label>
                <select name="op" style="min-width:180px; padding:8px; border:1px solid #cbd5e1; border-radius:8px">
                    <option value="">همه</option>
                    <option value="in" <?php selected($op_filter, 'in'); ?>>ورود</option>
                    <option value="out" <?php selected($op_filter, 'out'); ?>>خروج</option>
                    <option value="transfer" <?php selected($op_filter, 'transfer'); ?>>انتقال بین انبارها</option>
                    <option value="return" <?php selected($op_filter, 'return'); ?>>مرجوعی</option>
                    <option value="sale" <?php selected($op_filter, 'sale'); ?>>فروش</option>
                    <option value="sale_teh" <?php selected($op_filter, 'sale_teh'); ?>>فروش تهرانپارس</option>
                    <option value="sale_edit" <?php selected($op_filter, 'sale_edit'); ?>>ویرایش سفارش</option>
                    <option value="sale_cancel" <?php selected($op_filter, 'sale_cancel'); ?>>لغو سفارش (برگشت موجودی)</option>
                    <option value="onlyLabel" <?php selected($op_filter, 'onlyLabel'); ?>>صرفاً جهت چاپ</option>
                </select>
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">محصول</label>
                <select name="product_id" class="wc-suf-select2" style="min-width:320px">
                    <option value="0">همه محصولات</option>
                    <?php foreach ( (array) $products_for_filter as $pf ) : $ppid = (int)($pf->product_id ?? 0); if(!$ppid) continue; ?>
                        <option value="<?php echo esc_attr($ppid); ?>" <?php selected($pid_filter, $ppid); ?>><?php echo esc_html($ppid.' | '.($pf->product_name ?: '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:700">کاربر ثبت‌کننده</label>
                <select name="user_id" class="wc-suf-select2" style="min-width:280px">
                    <option value="0">همه کاربران</option>
                    <?php foreach ( (array) $users_for_filter as $uf ) : $uid = (int)($uf->user_id ?? 0); if(!$uid) continue; $uname = trim((string)($uf->user_login ?? '')); ?>
                        <option value="<?php echo esc_attr($uid); ?>" <?php selected($uid_filter, $uid); ?>><?php echo esc_html($uid.' | '.($uname !== '' ? $uname : ('user#'.$uid))); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="button button-primary">جستجو</button>
        </form>
        <?php if ( $has_filter && ! empty($rows) ) :
            $base_args = [
                'action' => 'wc_suf_export_detailed_logs',
                'q' => $q,
                'from' => $from,
                'to' => $to,
                'batch_code' => $batch_filter,
                'op' => $op_filter,
                'product_id' => $pid_filter,
                'user_id' => $uid_filter,
                '_wpnonce' => wp_create_nonce('wc_suf_export_detailed_logs'),
            ];
            $pdf_url = add_query_arg( array_merge($base_args, ['format' => 'pdf']), admin_url('admin-post.php') );
            $xlsx_url = add_query_arg( array_merge($base_args, ['format' => 'xlsx']), admin_url('admin-post.php') );
        ?>
            <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center">
                <a class="button" href="<?php echo esc_url($pdf_url); ?>" target="_blank" rel="noopener">گزارش PDF</a>
                <a class="button button-secondary" href="<?php echo esc_url($xlsx_url); ?>">گزارش اکسل (xlsx)</a>
            </div>
        <?php endif; ?>

        <table class="widefat fixed striped">
            <thead><tr>
                <th>#</th><th>کد عملیات</th><th>عملیات</th><th>مقصد</th><th>ID</th><th>نام محصول</th><th>قبل</th><th>تغییر</th><th>بعد</th><th>کاربر</th><th>تاریخ (شمسی)</th><th>چاپ لیبل</th><th>رسید HTML</th>
            </tr></thead>
            <tbody>
            <?php if ( ! empty($rows) ) : foreach($rows as $r):
                $user_disp = $r->user_login ? $r->user_login : ( $r->user_id ? 'user#'.$r->user_id : 'مهمان' );
                $csv_link = ! empty($r->csv_file_url) ? '<a href="'.esc_url($r->csv_file_url).'" target="_blank" rel="noopener">دانلود</a>' : '—';
                $word_link = ! empty($r->word_file_url) ? '<a href="'.esc_url($r->word_file_url).'" target="_blank" rel="noopener">دانلود</a>' : '—';
                $old_display = (string) ((float)$r->old_qty);
                $new_display = (string) ((float)$r->new_qty);
                if ( $r->operation === 'out' || $r->operation === 'return' ) {
                    $dest_old = isset($r->destination_old_qty) && $r->destination_old_qty !== null ? (float) $r->destination_old_qty : null;
                    $dest_new = isset($r->destination_new_qty) && $r->destination_new_qty !== null ? (float) $r->destination_new_qty : null;
                    if ( $dest_old !== null ) {
                        $old_display .= "
مقصد: " . $dest_old;
                    }
                    if ( $dest_new !== null ) {
                        $new_display .= "
مقصد: " . $dest_new;
                    }
                }
            ?>
                <tr>
                    <td><?php echo esc_html($r->id); ?></td>
                    <td><?php echo esc_html($r->batch_code); ?></td>
                    <td><?php echo esc_html( wc_suf_op_label($r->operation) ); ?></td>
                    <td><?php echo esc_html( wc_suf_destination_label( $r->destination ?: '—' ) ); ?></td>
                    <td><?php echo esc_html($r->product_id); ?></td>
                    <td><?php echo esc_html($r->product_name ?: ''); ?></td>
                    <td><?php echo nl2br( esc_html($old_display) ); ?></td>
                    <td><?php echo esc_html((float)$r->change_qty); ?></td>
                    <td><?php echo nl2br( esc_html($new_display) ); ?></td>
                    <td><?php echo esc_html($user_disp); ?></td>
                    <td><?php echo esc_html( wc_suf_format_jalali_datetime($r->created_at) ); ?></td>
                    <td><?php echo $csv_link; ?></td>
                    <td><?php echo $word_link; ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="13" style="text-align:center">رکوردی یافت نشد.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ( $total_pages > 1 ) :
            $page_base_args = $_GET;
            unset($page_base_args['paged']);
            $window_start = max(1, $current_page - 2);
            $window_end = min($total_pages, $current_page + 2);
            $page_numbers = [1];
            for ( $i = $window_start; $i <= $window_end; $i++ ) {
                $page_numbers[] = $i;
            }
            $page_numbers[] = $total_pages;
            $page_numbers = array_values(array_unique(array_map('intval', $page_numbers)));
            sort($page_numbers);
        ?>
            <div style="margin-top:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap">
                <span style="font-weight:700">صفحه <?php echo esc_html($current_page); ?> از <?php echo esc_html($total_pages); ?> (هر صفحه <?php echo esc_html($limit); ?> رکورد)</span>
                <?php if ( $current_page > 1 ) :
                    $prev_url = add_query_arg( array_merge($page_base_args, ['paged' => $current_page - 1]) );
                ?>
                    <a class="button" href="<?php echo esc_url($prev_url); ?>">&larr; قبلی</a>
                <?php endif; ?>
                <?php
                $last_printed = 0;
                foreach ( $page_numbers as $pn ) :
                    if ( $last_printed > 0 && $pn - $last_printed > 1 ) {
                        echo '<span style="padding:0 4px">…</span>';
                    }
                    $is_current = ( $pn === $current_page );
                    $pn_url = add_query_arg( array_merge($page_base_args, ['paged' => $pn]) );
                ?>
                    <?php if ( $is_current ) : ?>
                        <span class="button button-primary" style="cursor:default"><?php echo esc_html($pn); ?></span>
                    <?php else : ?>
                        <a class="button" href="<?php echo esc_url($pn_url); ?>"><?php echo esc_html($pn); ?></a>
                    <?php endif; ?>
                <?php
                    $last_printed = $pn;
                endforeach;
                ?>
                <?php if ( $current_page < $total_pages ) :
                    $next_url = add_query_arg( array_merge($page_base_args, ['paged' => $current_page + 1]) );
                ?>
                    <a class="button" href="<?php echo esc_url($next_url); ?>">بعدی &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <script>
        jQuery(function($){ if ($.fn.select2) { $('.wc-suf-select2').select2({ width:'resolve', dir:'rtl' }); } });
        </script>
    </div>
    <?php
    return ob_get_clean();
}


function wc_suf_render_detailed_logs_page(){
    echo wc_suf_render_detailed_logs_html(['public' => false]);
}

/*--------------------------------------
| Admin: گزارش گروهی
---------------------------------------*/
add_action('admin_menu', function(){
    $cap = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
    add_menu_page(
        'لاگ دقیق عملیات',
        'لاگ دقیق عملیات',
        $cap,
        'wc-stock-audit-detailed',
        'wc_suf_render_detailed_logs_page',
        'dashicons-clipboard',
        56
    );
});
function wc_suf_render_audit_page(){
    if( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) return;
    echo wc_suf_render_audit_html();
}

/*--------------------------------------
| Shortcode گزارش فرانت: [stock_audit_report]
---------------------------------------*/
add_shortcode('stock_audit_report', function($atts){
    $atts = shortcode_atts(['public' => '0'], $atts, 'stock_audit_report');
    $is_public = ($atts['public'] === '1' || strtolower($atts['public']) === 'true');
    return wc_suf_render_audit_html(['public' => $is_public]);
});


add_shortcode('stock_detailed_logs', function($atts){
    $atts = shortcode_atts(['public' => '0'], $atts, 'stock_detailed_logs');
    $is_public = ($atts['public'] === '1' || strtolower($atts['public']) === 'true');
    return wc_suf_render_detailed_logs_html(['public' => $is_public]);
});

add_shortcode('stock_detailed_logs_formeditor', function($atts){
    $atts = shortcode_atts([], $atts, 'stock_detailed_logs_formeditor');
    return wc_suf_render_detailed_logs_html([
        'public' => true,
        'only_formeditor' => true,
    ]);
});
