<?php
/*
Plugin Name: qkt-counter
Description: QR kodlarının okuma sayısını takip eden sistem.
Version: 2.0.0
Author: akltq00
*/

function qr_counter_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qr_counts';
    
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        qr_code_id VARCHAR(255) UNIQUE,
        business_name VARCHAR(255),
        count INT DEFAULT 0
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'qr_counter_install');

function qr_counter_update_count() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qr_counts';
    
    if (isset($_GET['id'])) {
        $qr_code_id = sanitize_text_field($_GET['id']);
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table_name (qr_code_id, count) VALUES (%s, 1) 
                ON DUPLICATE KEY UPDATE count = count + 1",
                $qr_code_id
            )
        );
    }
}
add_action('init', 'qr_counter_update_count');

function qr_counter_shortcode() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qr_counts';

    $results = $wpdb->get_results("SELECT qr_code_id, business_name, count FROM $table_name");

    if ($results) {
        $output = '<table border="1"><tr><th>İşletme</th><th>QR Kod ID</th><th>Okuma Sayısı</th></tr>';
        foreach ($results as $row) {
            $output .= '<tr><td>' . esc_html($row->business_name) . '</td><td>' . esc_html($row->qr_code_id) . '</td><td>' . esc_html($row->count) . '</td></tr>';
        }
        $output .= '</table>';
    } else {
        $output = 'Veri bulunamadı';
    }
    
    return $output;
}
add_shortcode('qr_counter', 'qr_counter_shortcode');

function qr_counter_admin_menu() {
    $view_roles = get_option('qr_counter_view_roles', ['administrator']);
    $manage_roles = get_option('qr_counter_manage_roles', ['administrator']);
    
    $show_menu = false;

    foreach ($view_roles as $role) {
        if (current_user_can($role)) {
            $show_menu = true;
            break;
        }
    }

    if ($show_menu) {
        add_menu_page('QR Counter', 'QR Counter', 'read', 'qr-counter', 'qr_counter_admin_page', 'dashicons-chart-bar', 6);
        add_submenu_page('qr-counter', 'Ayarlar', 'Ayarlar', 'administrator', 'qr-counter-settings', 'qr_counter_settings_page');
    }
}
add_action('admin_menu', 'qr_counter_admin_menu');

function qr_counter_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qr_counts';

    $manage_roles = get_option('qr_counter_manage_roles', ['administrator']);
    $can_manage = false;

    foreach ($manage_roles as $role) {
        if (current_user_can($role)) {
            $can_manage = true;
            break;
        }
    }

    echo '<h1>QR Counter</h1>';

    if ($can_manage && $_SERVER['REQUEST_METHOD'] == 'POST') {
        if (isset($_POST['business_name']) && isset($_POST['qr_code_id'])) {
            $business_name = sanitize_text_field($_POST['business_name']);
            $qr_code_id = sanitize_text_field($_POST['qr_code_id']);

            $wpdb->insert($table_name, array('business_name' => $business_name, 'qr_code_id' => $qr_code_id, 'count' => 0));
            echo '<div class="updated"><p>Yeni işletme ve QR kodu eklendi!</p></div>';
        }

        if (isset($_POST['reset_counts'])) {
            $wpdb->query("UPDATE $table_name SET count = 0");
            echo '<div class="updated"><p>Tüm okuma sayıları sıfırlandı!</p></div>';
        }
    }

    if ($can_manage) {
        echo '<form method="post">';
        echo '<h2>Yeni İşletme ve QR Kod Ekle</h2>';
        echo '<p>İşletme Adı: <input type="text" name="business_name" required></p>';
        echo '<p>QR Kod ID: <input type="text" name="qr_code_id" required></p>';
        echo '<p><input type="submit" value="Ekle" class="button button-primary"></p>';
        echo '</form>';

        echo '<form method="post" id="reset-form">';
        echo '<h2>Tüm Okuma Sayılarını Sıfırla</h2>';
        echo '<p><input type="submit" name="reset_counts" value="Tüm Verileri Sil" class="button button-secondary"></p>';
        echo '</form>';
    }

    $results = $wpdb->get_results("SELECT qr_code_id, business_name, count FROM $table_name");

    if ($results) {
        echo '<h2>İşletme Listesi ve Okuma Sayıları</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>İşletme</th><th>QR Kod ID</th><th>Okuma Sayısı</th></tr></thead>';
        echo '<tbody>';
        foreach ($results as $row) {
            echo '<tr><td>' . esc_html($row->business_name) . '</td><td>' . esc_html($row->qr_code_id) . '</td><td>' . esc_html($row->count) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>Veri bulunamadı</p>';
    }

    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.getElementById('reset-form');
            form.addEventListener('submit', function(event) {
                if (!confirm('Tüm okuma sayıları sıfırlanacak. Emin misiniz?')) {
                    event.preventDefault();
                }
            });
        });
    </script>
    <?php
}

function qr_counter_settings_page() {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['qr_counter_view_roles']) && isset($_POST['qr_counter_manage_roles'])) {
        $view_roles = array_map('sanitize_text_field', $_POST['qr_counter_view_roles']);
        $manage_roles = array_map('sanitize_text_field', $_POST['qr_counter_manage_roles']);
        update_option('qr_counter_view_roles', $view_roles);
        update_option('qr_counter_manage_roles', $manage_roles);
        echo '<div class="updated"><p>Ayarlar kaydedildi!</p></div>';
    }

    $view_roles = get_option('qr_counter_view_roles', ['administrator']);
    $manage_roles = get_option('qr_counter_manage_roles', ['administrator']);

    $all_roles = wp_roles()->get_names();

    echo '<h1>QR Counter Ayarları</h1>';
    echo '<form method="post">';
    echo '<h2>Görüntüleme İzinleri</h2>';
    foreach ($all_roles as $role_key => $role_name) {
        $checked = in_array($role_key, $view_roles) ? 'checked' : '';
        echo '<p><label><input type="checkbox" name="qr_counter_view_roles[]" value="' . esc_attr($role_key) . '" ' . $checked . '> ' . esc_html($role_name) . '</label></p>';
    }
    echo '<h2>İşletme Ekleme İzinleri</h2>';
    foreach ($all_roles as $role_key => $role_name) {
        $checked = in_array($role_key, $manage_roles) ? 'checked' : '';
        echo '<p><label><input type="checkbox" name="qr_counter_manage_roles[]" value="' . esc_attr($role_key) . '" ' . $checked . '> ' . esc_html($role_name) . '</label></p>';
    }
    echo '<p><input type="submit" value="Kaydet" class="button button-primary"></p>';
    echo '</form>';
}
?>
