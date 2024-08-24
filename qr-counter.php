<?php
/*
Plugin Name: qkt-counter
Description: QR kodlarının okuma sayısını ve tarih/saat detaylarını takip eden sistem.
Version: 4.0.2
Author: qklta00/akltq00
*/

function qr_counter_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qr_counts';
    $details_table_name = $wpdb->prefix . 'qr_scan_details';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        qr_code_id VARCHAR(255) UNIQUE,
        business_name VARCHAR(255),
        count INT DEFAULT 0,
        timestamp DATETIME DEFAULT NULL
    ) $charset_collate;";

    $details_sql = "CREATE TABLE $details_table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        qr_code_id VARCHAR(255),
        scan_time DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    dbDelta($details_sql);
}
register_activation_hook(__FILE__, 'qr_counter_install');

function qr_counter_shortcode() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qr_counts';

    $results = $wpdb->get_results("SELECT id, qr_code_id, business_name, count FROM $table_name");

    if ($results) {
        $output = '<table border="1"><tr><th>İşletme</th><th>QR Kod ID</th><th>Okuma Sayısı</th><th>Detaylar</th></tr>';
        foreach ($results as $row) {
            $output .= '<tr><td>' . esc_html($row->business_name) . '</td><td>' . esc_html($row->qr_code_id) . '</td><td>' . esc_html($row->count) . '</td><td><button class="view-details" data-id="' . esc_attr($row->qr_code_id) . '">Gör</button><div class="details-list" id="details-list-' . esc_attr($row->qr_code_id) . '" style="display: none;"></div></td></tr>';
        }
        $output .= '</table>';
    } else {
        $output = 'Veri bulunamadı';
    }
    
    return $output;
}
add_shortcode('qr_counter', 'qr_counter_shortcode');

function qr_counter_update_count() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qr_counts';
    $details_table_name = $wpdb->prefix . 'qr_scan_details';

    if (isset($_GET['id']) && !isset($_GET['ajax'])) {
        $qr_code_id = sanitize_text_field($_GET['id']);

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table_name (qr_code_id, count, timestamp) VALUES (%s, 1, NOW()) 
                ON DUPLICATE KEY UPDATE count = count + 1, timestamp = NOW()",
                $qr_code_id
            )
        );

        $wpdb->insert($details_table_name, array('qr_code_id' => $qr_code_id));
    }
}
add_action('init', 'qr_counter_update_count');

function qr_counter_get_details() {
    global $wpdb;
    $details_table_name = $wpdb->prefix . 'qr_scan_details';

    if (isset($_GET['id'])) {
        $qr_code_id = sanitize_text_field($_GET['id']);

        $results = $wpdb->get_results(
            $wpdb->prepare("SELECT scan_time FROM $details_table_name WHERE qr_code_id = %s ORDER BY scan_time DESC", $qr_code_id)
        );

        if ($results) {
            wp_send_json_success(array('details' => $results));
        } else {
            wp_send_json_error(array('message' => 'Veri bulunamadı'));
        }
    } else {
        wp_send_json_error(array('message' => 'Geçersiz ID'));
    }
}
add_action('wp_ajax_qr_counter_get_details', 'qr_counter_get_details');
add_action('wp_ajax_nopriv_qr_counter_get_details', 'qr_counter_get_details');

function qr_counter_get_details_by_business() {
    global $wpdb;
    $details_table_name = $wpdb->prefix . 'qr_scan_details';
    $table_name = $wpdb->prefix . 'qr_counts';

    if (isset($_GET['business_name'])) {
        $business_name = sanitize_text_field($_GET['business_name']);

        $qr_codes = $wpdb->get_col(
            $wpdb->prepare("SELECT qr_code_id FROM $table_name WHERE business_name = %s", $business_name)
        );

        if ($qr_codes) {
            $all_details = [];
            foreach ($qr_codes as $qr_code_id) {
                $results = $wpdb->get_results(
                    $wpdb->prepare("SELECT scan_time FROM $details_table_name WHERE qr_code_id = %s ORDER BY scan_time DESC", $qr_code_id)
                );
                if ($results) {
                    $all_details[] = ['qr_code_id' => $qr_code_id, 'details' => $results];
                }
            }
            wp_send_json_success(array('details' => $all_details));
        } else {
            wp_send_json_error(array('message' => 'Veri bulunamadı'));
        }
    } else {
        wp_send_json_error(array('message' => 'Geçersiz İşletme Adı'));
    }
}
add_action('wp_ajax_qr_counter_get_details_by_business', 'qr_counter_get_details_by_business');
add_action('wp_ajax_nopriv_qr_counter_get_details_by_business', 'qr_counter_get_details_by_business');


function qr_counter_admin_menu() {
    $view_roles = get_option('qr_counter_view_roles', ['administrator']);
    $manage_roles = get_option('qr_counter_manage_roles', ['administrator']);
    $transcript_view_roles = get_option('qr_counter_transcript_view_roles', ['administrator']);
    
    $show_menu = false;
    $show_transcript = false;

    foreach ($view_roles as $role) {
        if (current_user_can($role)) {
            $show_menu = true;
            break;
        }
    }

    foreach ($transcript_view_roles as $role) {
        if (current_user_can($role)) {
            $show_transcript = true;
            break;
        }
    }

    if ($show_menu) {
        add_menu_page('QR Counter', 'QR Counter', 'read', 'qr-counter', 'qr_counter_admin_page', 'dashicons-chart-bar', 6);
        add_submenu_page('qr-counter', 'Ayarlar', 'Ayarlar', 'administrator', 'qr-counter-settings', 'qr_counter_settings_page');
        
        if ($show_transcript) {
            add_submenu_page('qr-counter', 'Transkript', 'Transkript', 'read', 'qr-counter-transcript', 'qr_counter_transcript_page');
        }
    }
}

add_action('admin_menu', 'qr_counter_admin_menu');

function qr_counter_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qr_counts';
    $details_table_name = $wpdb->prefix . 'qr_scan_details';

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
            $wpdb->query("TRUNCATE TABLE $details_table_name");
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

    $results = $wpdb->get_results("SELECT id, qr_code_id, business_name, count FROM $table_name");

    if ($results) {
        echo '<h2>İşletme Listesi ve Okuma Sayıları</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>İşletme</th><th>QR Kod ID</th><th>Okuma Sayısı</th></tr></thead>';
        echo '<tbody>';
        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->business_name) . '</td>';
            echo '<td>' . esc_html($row->qr_code_id) . '</td>';
            echo '<td>' . esc_html($row->count) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>Veri bulunamadı</p>';
    }

    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.view-details').forEach(function(button) {
                button.addEventListener('click', function() {
                    var qrCodeId = button.getAttribute('data-id');
                    var detailsList = document.getElementById('details-list-' + qrCodeId);

                    if (detailsList.style.display === 'none') {
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=qr_counter_get_details&id=' + qrCodeId) 
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    var details = data.data.details;
                                    var detailsHtml = details.map(detail => {
                                        return '<p>Tarih: ' + new Date(detail.scan_time).toLocaleString() + '</p>';
                                    }).join('');
                                    detailsList.innerHTML = detailsHtml;
                                    detailsList.style.display = 'block';
                                } else {
                                    alert('AJAX isteği başarısız oldu: ' + data.data.message);
                                }
                            })
                            .catch(error => {
                                console.error('AJAX hatası:', error);
                                alert('Bir hata oluştu. Lütfen daha sonra tekrar deneyin.');
                            });
                    } else {
                        detailsList.style.display = 'none';
                    }
                });
            });
        });
    </script>
    <?php
}

function qr_counter_transcript_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qr_counts';
    $details_table_name = $wpdb->prefix . 'qr_scan_details';

    $businesses = $wpdb->get_results("SELECT DISTINCT business_name FROM $table_name");

    echo '<div class="wrap">';
    echo '<h1>Transkript</h1>';
    
    echo '<form method="post" id="transcript-form">';
    echo '<label for="business-dropdown">İşletme Seçin:</label>';
    echo '<select id="business-dropdown" name="business_name">';
    echo '<option value="">Seçin</option>';
    foreach ($businesses as $business) {
        echo '<option value="' . esc_attr($business->business_name) . '">' . esc_html($business->business_name) . '</option>';
    }
    echo '</select>';
    echo '<button type="button" id="show-history" class="button button-primary">Göster</button>';
    echo '</form>';

    echo '<div id="history-details"></div>';
    echo '</div>';

    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('show-history').addEventListener('click', function() {
                var businessName = document.getElementById('business-dropdown').value;
                if (businessName) {
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=qr_counter_get_details_by_business&business_name=' + encodeURIComponent(businessName)) 
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                var allDetails = data.data.details;
                                var detailsHtml = '';
                                allDetails.forEach(function(item) {
                                    detailsHtml += '<h2>QR Kod ID: ' + item.qr_code_id + '</h2>';
                                    item.details.forEach(function(detail) {
                                        detailsHtml += '<p>Tarih: ' + new Date(detail.scan_time).toLocaleString() + '</p>';
                                    });
                                });
                                document.getElementById('history-details').innerHTML = detailsHtml;
                            } else {
                                document.getElementById('history-details').innerHTML = '<p>Veri bulunamadı: ' + data.data.message + '</p>';
                            }
                        })
                        .catch(error => {
                            console.error('AJAX hatası:', error);
                            alert('Bir hata oluştu. Lütfen daha sonra tekrar deneyin.');
                        });
                } else {
                    document.getElementById('history-details').innerHTML = '<p>İşletme seçilmedi.</p>';
                }
            });
        });
    </script>
    <?php
}

function qr_counter_settings_page() {
    ?>
    <div class="wrap">
        <h1>QR Counter Ayarları</h1>
        <form method="post" action="options.php">
            <?php settings_fields('qr_counter_settings'); ?>
            <?php do_settings_sections('qr_counter_settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Görüntüleyebilecek Roller</th>
                    <td>
                        <?php
                        $view_roles = get_option('qr_counter_view_roles', ['administrator']);
                        $editable_roles = get_editable_roles();
                        foreach ($editable_roles as $role => $details) {
                            $checked = in_array($role, $view_roles) ? 'checked' : '';
                            echo '<label><input type="checkbox" name="qr_counter_view_roles[]" value="' . esc_attr($role) . '" ' . $checked . '> ' . esc_html($details['name']) . '</label><br>';
                        }
                        ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Yönetebilecek Roller</th>
                    <td>
                        <?php
                        $manage_roles = get_option('qr_counter_manage_roles', ['administrator']);
                        foreach ($editable_roles as $role => $details) {
                            $checked = in_array($role, $manage_roles) ? 'checked' : '';
                            echo '<label><input type="checkbox" name="qr_counter_manage_roles[]" value="' . esc_attr($role) . '" ' . $checked . '> ' . esc_html($details['name']) . '</label><br>';
                        }
                        ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Transkript Görüntüleme Rolleri</th>
                    <td>
                        <?php
                        $transcript_view_roles = get_option('qr_counter_transcript_view_roles', ['administrator']);
                        foreach ($editable_roles as $role => $details) {
                            $checked = in_array($role, $transcript_view_roles) ? 'checked' : '';
                            echo '<label><input type="checkbox" name="qr_counter_transcript_view_roles[]" value="' . esc_attr($role) . '" ' . $checked . '> ' . esc_html($details['name']) . '</label><br>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function qr_counter_register_settings() {
    register_setting('qr_counter_settings', 'qr_counter_view_roles');
    register_setting('qr_counter_settings', 'qr_counter_manage_roles');
    register_setting('qr_counter_settings', 'qr_counter_transcript_view_roles');
}
add_action('admin_init', 'qr_counter_register_settings');
