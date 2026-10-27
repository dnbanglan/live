<?php
/**
 * Plugin Name: DN Bangla Newsroom Dashboard
 * Description: Secure WordPress admin dashboard services for DN Bangla cards, templates, ads, staff, schedules and logs.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: DN Bangla
 */

defined('ABSPATH') || exit;

final class DNBangla_Newsroom_Dashboard {
    private const VERSION = '0.1.0';
    private const REST_NAMESPACE = 'dnb/v1';
    private const SETTINGS_KEY = 'dnb_dashboard_settings';
    private const TEMPLATES_KEY = 'dnb_dashboard_templates';
    private const ADS_KEY = 'dnb_dashboard_ads';
    private const SCHEDULES_KEY = 'dnb_dashboard_schedules';
    private const CRON_HOOK = 'dnb_dashboard_minute_tick';

    public static function boot(): void {
        add_action('init', [self::class, 'ensure_cron']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_menu', [self::class, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_action(self::CRON_HOOK, [self::class, 'run_due_schedules']);
    }

    public static function activate(): void {
        self::create_tables();
        self::ensure_cron();
        if (!get_option(self::TEMPLATES_KEY)) {
            add_option(self::TEMPLATES_KEY, [[
                'id' => wp_generate_uuid4(),
                'name' => 'ডিফল্ট সংবাদ কার্ড',
                'type' => 'news',
                'settings' => self::default_card_settings(),
                'is_default' => true,
            ]], '', false);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $staff = $wpdb->prefix . 'dnb_staff';
        $logs = $wpdb->prefix . 'dnb_logs';
        $cards = $wpdb->prefix . 'dnb_cards';
        dbDelta("CREATE TABLE {$staff} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            staff_code varchar(80) NOT NULL,
            full_name varchar(190) NOT NULL,
            designation varchar(190) NOT NULL,
            department varchar(190) NOT NULL DEFAULT '',
            blood_group varchar(5) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            valid_until date NULL,
            photo_id bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY staff_code (staff_code)
        ) {$charset};");
        dbDelta("CREATE TABLE {$logs} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(80) NOT NULL,
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};");
        dbDelta("CREATE TABLE {$cards} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint unsigned NULL,
            template_id varchar(64) NOT NULL DEFAULT '',
            attachment_id bigint unsigned NULL,
            output_type varchar(16) NOT NULL DEFAULT 'jpg',
            created_by bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    public static function register_admin_page(): void {
        add_menu_page('ডিএন বাংলা ড্যাশবোর্ড', 'ডিএন বাংলা', 'manage_options', 'dnb-dashboard', [self::class, 'render_admin_page'], 'dashicons-format-image', 3);
    }

    public static function enqueue_admin_assets(string $hook): void {
        if ($hook !== 'toplevel_page_dnb-dashboard') {
            return;
        }
        wp_enqueue_style('dnb-hind-siliguri', 'https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap', [], self::VERSION);
        wp_enqueue_style('dnb-dashboard', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('dnb-dashboard', plugin_dir_url(__FILE__) . 'assets/admin.js', [], self::VERSION, true);
        wp_localize_script('dnb-dashboard', 'DNB', [
            'api' => esc_url_raw(rest_url(self::REST_NAMESPACE)),
            'nonce' => wp_create_nonce('wp_rest'),
            'canManage' => current_user_can('manage_options'),
            'timezone' => wp_timezone_string() ?: 'UTC',
        ]);
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('এই পেজ দেখার অনুমতি নেই।', 'dnb-dashboard'));
        }
        echo '<div class="wrap dnb-wrap"><div id="dnb-dashboard"><p>ড্যাশবোর্ড লোড হচ্ছে…</p></div></div>';
    }

    public static function register_routes(): void {
        $routes = [
            ['/status', 'GET', 'status'],
            ['/cards', 'GET', 'get_cards'],
            ['/cards', 'POST', 'save_card'],
            ['/posts', 'GET', 'posts'],
            ['/settings', 'GET', 'get_settings'],
            ['/settings', 'POST', 'save_settings'],
            ['/settings/test-wordpress', 'POST', 'test_wordpress'],
            ['/media', 'POST', 'upload_media'],
            ['/media/import', 'POST', 'import_media'],
            ['/media/(?P<id>\d+)', 'GET', 'get_media'],
            ['/templates', 'GET', 'get_templates'],
            ['/templates', 'POST', 'save_template'],
            ['/templates/(?P<id>[a-f0-9-]+)', 'DELETE', 'delete_template'],
            ['/ads', 'GET', 'get_ads'],
            ['/ads', 'POST', 'save_ad'],
            ['/ads/(?P<id>\d+)', 'DELETE', 'delete_ad'],
            ['/staff', 'GET', 'get_staff'],
            ['/staff', 'POST', 'save_staff'],
            ['/staff/(?P<id>\d+)', 'DELETE', 'delete_staff'],
            ['/schedules', 'GET', 'get_schedules'],
            ['/schedules', 'POST', 'save_schedule'],
            ['/logs', 'GET', 'get_logs'],
        ];
        foreach ($routes as [$route, $method, $callback]) {
            register_rest_route(self::REST_NAMESPACE, $route, [
                'methods' => $method,
                'callback' => [self::class, $callback],
                'permission_callback' => [self::class, 'can_manage'],
            ]);
        }
    }

    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    public static function status(): WP_REST_Response {
        global $wpdb;
        $settings = self::settings();
        $staff_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('staff'));
        $today_cards = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('cards') . ' WHERE created_at >= %s', wp_date('Y-m-d 00:00:00')));
        return rest_ensure_response([
            'wordpress' => !empty($settings['verified']) ? 'connected' : 'unverified',
            'today_cards' => $today_cards,
            'ads_count' => count(self::ads()),
            'staff_count' => $staff_count,
            'timezone' => wp_timezone_string() ?: 'UTC',
            'storage' => 'wordpress_database',
            'social' => 'not_configured',
        ]);
    }

    public static function get_cards(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $limit = min(100, max(1, absint($request->get_param('limit') ?: 30)));
        return rest_ensure_response($wpdb->get_results($wpdb->prepare('SELECT id, post_id, template_id, attachment_id, output_type, created_at FROM ' . self::table('cards') . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A));
    }

    public static function save_card(WP_REST_Request $request) {
        global $wpdb;
        $data = $request->get_json_params() ?: [];
        $post_id = absint($data['post_id'] ?? 0);
        $template_id = sanitize_text_field($data['template_id'] ?? '');
        $attachment_id = absint($data['attachment_id'] ?? 0);
        if ($post_id && $template_id) {
            $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('cards') . ' WHERE post_id = %d AND template_id = %s AND created_at >= %s', $post_id, $template_id, wp_date('Y-m-d 00:00:00')));
            if ($existing) {
                return new WP_Error('dnb_duplicate_card', 'এই সংবাদ ও টেমপ্লেট দিয়ে আজকের কার্ড আগে থেকেই রেকর্ড করা হয়েছে।', ['status' => 409]);
            }
        }
        $wpdb->insert(self::table('cards'), [
            'post_id' => $post_id ?: null,
            'template_id' => $template_id,
            'attachment_id' => $attachment_id ?: null,
            'output_type' => 'jpg',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
        if (!$wpdb->insert_id) {
            return new WP_Error('dnb_card_record_failed', 'কার্ডের রেকর্ড সংরক্ষণ করা যায়নি।', ['status' => 500]);
        }
        self::log('card_created', 'Photocard record saved.', ['post_id' => $post_id, 'template_id' => $template_id]);
        return rest_ensure_response(['id' => (int) $wpdb->insert_id, 'created_at' => current_time('mysql')]);
    }

    public static function posts(WP_REST_Request $request) {
        $settings = self::settings();
        $base = $settings['wp_url'] ?? home_url();
        $base = untrailingslashit(esc_url_raw($base));
        if (!$base || !wp_http_validate_url($base)) {
            return new WP_Error('dnb_invalid_site_url', 'WordPress সাইট URL সঠিক নয়।', ['status' => 400]);
        }
        $page = max(1, absint($request->get_param('page')));
        $per_page = min(50, max(1, absint($request->get_param('per_page') ?: 12)));
        $url = add_query_arg(['_embed' => 'true', 'per_page' => $per_page, 'page' => $page], $base . '/wp-json/wp/v2/posts');
        $response = wp_safe_remote_get($url, self::wordpress_request_args($settings));
        if (is_wp_error($response)) {
            self::log('wordpress_sync_failed', $response->get_error_message());
            return new WP_Error('dnb_wordpress_unavailable', 'WordPress সংবাদ আনা যায়নি। সংযোগ পরীক্ষা করে আবার চেষ্টা করুন।', ['status' => 502]);
        }
        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            self::log('wordpress_sync_failed', 'WordPress REST API returned HTTP ' . $status);
            return new WP_Error('dnb_wordpress_api_error', 'WordPress API ত্রুটি (HTTP ' . $status . ')।', ['status' => 502]);
        }
        $posts = array_map(static function (array $post): array {
            $media = $post['_embedded']['wp:featuredmedia'][0] ?? [];
            $terms = $post['_embedded']['wp:term'][0] ?? [];
            return [
                'id' => absint($post['id'] ?? 0),
                'title' => sanitize_text_field(wp_strip_all_tags(html_entity_decode($post['title']['rendered'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                'date' => sanitize_text_field($post['date'] ?? ''),
                'url' => esc_url_raw($post['link'] ?? ''),
                'image' => esc_url_raw($media['source_url'] ?? ''),
                'category' => sanitize_text_field($terms[0]['name'] ?? 'সংবাদ'),
            ];
        }, $data);
        update_option(self::SETTINGS_KEY, array_merge($settings, ['last_sync' => current_time('mysql'), 'verified' => true]), false);
        return rest_ensure_response([
            'items' => $posts,
            'page' => $page,
            'per_page' => $per_page,
            'total' => (int) wp_remote_retrieve_header($response, 'x-wp-total'),
            'total_pages' => (int) wp_remote_retrieve_header($response, 'x-wp-totalpages'),
        ]);
    }

    public static function get_settings(): WP_REST_Response {
        $settings = self::settings();
        return rest_ensure_response([
            'wp_url' => $settings['wp_url'] ?? home_url(),
            'wp_username' => $settings['wp_username'] ?? '',
            'has_app_password' => !empty($settings['wp_password']),
            'auto_sync' => !empty($settings['auto_sync']),
            'last_sync' => $settings['last_sync'] ?? null,
        ]);
    }

    public static function save_settings(WP_REST_Request $request) {
        $input = $request->get_json_params() ?: [];
        $current = self::settings();
        $url = esc_url_raw($input['wp_url'] ?? ($current['wp_url'] ?? home_url()));
        if (!wp_http_validate_url($url)) {
            return new WP_Error('dnb_invalid_site_url', 'সঠিক HTTPS/HTTP সাইট URL দিন।', ['status' => 400]);
        }
        $username = sanitize_text_field($input['wp_username'] ?? '');
        if (!empty($input['wp_password']) && !function_exists('openssl_encrypt')) {
            return new WP_Error('dnb_encryption_unavailable', 'এই সার্ভারে OpenSSL নেই; নিরাপদে Application Password সংরক্ষণ করা যাবে না।', ['status' => 500]);
        }
        $password = isset($input['wp_password']) && $input['wp_password'] !== ''
            ? self::encrypt_secret(sanitize_text_field($input['wp_password']))
            : ($current['wp_password'] ?? '');
        $settings = [
            'wp_url' => untrailingslashit($url),
            'wp_username' => $username,
            'wp_password' => $password,
            'auto_sync' => !empty($input['auto_sync']),
            'last_sync' => $current['last_sync'] ?? null,
            'verified' => false,
        ];
        update_option(self::SETTINGS_KEY, $settings, false);
        self::log('settings_saved', 'WordPress settings updated.');
        return rest_ensure_response(['saved' => true, 'has_app_password' => (bool) $password]);
    }

    public static function test_wordpress() {
        $settings = self::settings();
        $url = untrailingslashit($settings['wp_url'] ?? home_url()) . '/wp-json/wp/v2/posts?per_page=1';
        $response = wp_safe_remote_get($url, self::wordpress_request_args($settings));
        if (is_wp_error($response)) {
            self::log('wordpress_connection_failed', $response->get_error_message());
            return new WP_Error('dnb_connection_failed', 'সংযোগ ব্যর্থ: ' . $response->get_error_message(), ['status' => 502]);
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            self::log('wordpress_connection_failed', 'WordPress returned HTTP ' . $code);
            return new WP_Error('dnb_connection_failed', 'WordPress API HTTP ' . $code . ' দিয়েছে।', ['status' => 502]);
        }
        update_option(self::SETTINGS_KEY, array_merge($settings, ['last_sync' => current_time('mysql'), 'verified' => true]), false);
        self::log('wordpress_connection_ok', 'WordPress REST API connection test succeeded.');
        return rest_ensure_response(['connected' => true, 'http_status' => $code, 'checked_at' => current_time('mysql')]);
    }

    private static function wordpress_request_args(array $settings): array {
        $args = ['timeout' => 12, 'headers' => ['Accept' => 'application/json']];
        if (!empty($settings['wp_username']) && !empty($settings['wp_password'])) {
            $password = self::decrypt_secret($settings['wp_password']);
            if ($password !== '') {
                $args['headers']['Authorization'] = 'Basic ' . base64_encode($settings['wp_username'] . ':' . $password);
            }
        }
        return $args;
    }

    public static function upload_media() {
        if (empty($_FILES['file']['name'])) {
            return new WP_Error('dnb_file_required', 'একটি ছবি নির্বাচন করুন।', ['status' => 400]);
        }
        $file = $_FILES['file'];
        if (!empty($file['error']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('dnb_upload_failed', 'ফাইল আপলোড করা যায়নি।', ['status' => 400]);
        }
        $mime = wp_get_image_mime($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $file['size'] > wp_max_upload_size()) {
            return new WP_Error('dnb_invalid_image', 'শুধু JPG, PNG বা WebP ছবি এবং WordPress upload limit-এর মধ্যে ফাইল দিন।', ['status' => 400]);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload('file', 0);
        if (is_wp_error($attachment_id)) {
            return new WP_Error('dnb_upload_failed', 'ছবি সংরক্ষণ করা যায়নি: ' . $attachment_id->get_error_message(), ['status' => 400]);
        }
        self::log('media_uploaded', 'Image uploaded to WordPress Media Library.');
        return rest_ensure_response(['id' => $attachment_id, 'url' => wp_get_attachment_image_url($attachment_id, 'full')]);
    }

    public static function import_media(WP_REST_Request $request) {
        $url = esc_url_raw($request->get_param('url'));
        $settings = self::settings();
        $source_host = strtolower((string) wp_parse_url($settings['wp_url'] ?? home_url(), PHP_URL_HOST));
        $image_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!$url || wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || !$source_host || !hash_equals($source_host, $image_host)) {
            return new WP_Error('dnb_invalid_image_url', 'শুধু সংযুক্ত WordPress সাইটের HTTPS ছবি ব্যবহার করা যাবে।', ['status' => 400]);
        }
        $remote = wp_safe_remote_get($url, ['timeout' => 15, 'limit_response_size' => 10 * MB_IN_BYTES]);
        if (is_wp_error($remote) || wp_remote_retrieve_response_code($remote) !== 200) {
            return new WP_Error('dnb_remote_image_failed', 'WordPress feature image আনা যায়নি।', ['status' => 502]);
        }
        $mime = strtolower(trim(explode(';', (string) wp_remote_retrieve_header($remote, 'content-type'))[0]));
        $body = wp_remote_retrieve_body($remote);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !$body || strlen($body) > 10 * MB_IN_BYTES) {
            return new WP_Error('dnb_remote_image_invalid', 'WordPress feature image সমর্থিত ছবি নয় বা 10 MB-এর বেশি।', ['status' => 400]);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $temporary = wp_tempnam($url);
        if (!$temporary || file_put_contents($temporary, $body) === false) {
            return new WP_Error('dnb_remote_image_failed', 'Feature image-এর অস্থায়ী ফাইল তৈরি করা যায়নি।', ['status' => 500]);
        }
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $file = ['name' => sanitize_file_name('dnb-feature-' . time() . '.' . $extension), 'tmp_name' => $temporary];
        $attachment_id = media_handle_sideload($file, 0);
        if (is_wp_error($attachment_id)) {
            @unlink($temporary);
            return new WP_Error('dnb_remote_image_failed', 'Feature image Media Library-তে সংরক্ষণ করা যায়নি।', ['status' => 400]);
        }
        self::log('media_imported', 'WordPress featured image copied to Media Library.');
        return rest_ensure_response(['id' => $attachment_id, 'url' => wp_get_attachment_image_url($attachment_id, 'full')]);
    }

    public static function get_media(WP_REST_Request $request) {
        $attachment_id = absint($request['id']);
        if (!wp_attachment_is_image($attachment_id)) {
            return new WP_Error('dnb_media_not_found', 'ছবিটি পাওয়া যায়নি।', ['status' => 404]);
        }
        return rest_ensure_response(['id' => $attachment_id, 'url' => wp_get_attachment_image_url($attachment_id, 'full')]);
    }

    private static function encrypt_secret(string $secret): string {
        if (!function_exists('openssl_encrypt')) {
            return '';
        }
        $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $cipher === false ? '' : base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt_secret(string $encoded): string {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 29) {
            return '';
        }
        $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
        $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
        return is_string($plain) ? $plain : '';
    }

    public static function get_templates(): WP_REST_Response {
        return rest_ensure_response(get_option(self::TEMPLATES_KEY, []));
    }

    public static function save_template(WP_REST_Request $request) {
        $input = $request->get_json_params() ?: [];
        $name = sanitize_text_field($input['name'] ?? '');
        if ($name === '') {
            return new WP_Error('dnb_template_name_required', 'টেমপ্লেটের নাম আবশ্যক।', ['status' => 400]);
        }
        $templates = get_option(self::TEMPLATES_KEY, []);
        $id = sanitize_text_field($input['id'] ?? wp_generate_uuid4());
        $template = [
            'id' => $id,
            'name' => $name,
            'type' => in_array(($input['type'] ?? 'news'), ['news', 'text'], true) ? $input['type'] : 'news',
            'settings' => self::sanitize_card_settings($input['settings'] ?? []),
            'is_default' => !empty($input['is_default']),
        ];
        foreach ($templates as &$existing) {
            if (!empty($existing['id']) && hash_equals((string) $existing['id'], $id)) {
                $existing = $template;
                unset($existing);
                update_option(self::TEMPLATES_KEY, $templates, false);
                return rest_ensure_response($template);
            }
            if ($template['is_default']) {
                $existing['is_default'] = false;
            }
        }
        unset($existing);
        $templates[] = $template;
        update_option(self::TEMPLATES_KEY, $templates, false);
        self::log('template_saved', 'Template saved: ' . $name);
        return rest_ensure_response($template);
    }

    public static function delete_template(WP_REST_Request $request) {
        $id = sanitize_text_field($request['id']);
        $templates = array_values(array_filter(get_option(self::TEMPLATES_KEY, []), static fn($template) => ($template['id'] ?? '') !== $id));
        if (!$templates) {
            return new WP_Error('dnb_last_template', 'অন্তত একটি টেমপ্লেট রাখতে হবে।', ['status' => 400]);
        }
        update_option(self::TEMPLATES_KEY, $templates, false);
        self::log('template_deleted', 'Template deleted: ' . $id);
        return rest_ensure_response(['deleted' => true]);
    }

    private static function default_card_settings(): array {
        return ['top_bg' => '#ffffff', 'top_text' => '#111111', 'headline_bg' => '#840b0b', 'headline_text' => '#ffffff', 'font_size' => 80, 'margin' => 120, 'website' => 'DNBangla.com.bd', 'footer_bg' => '#1e293b', 'show_comment' => true, 'comment' => 'বিস্তারিত কমেন্টে', 'show_category' => false, 'category' => '', 'show_social' => true, 'social' => [], 'logo_mode' => 'text', 'feature_id' => 0, 'logo_id' => 0];
    }

    private static function sanitize_card_settings(array $settings): array {
        $defaults = self::default_card_settings();
        foreach (['top_bg', 'top_text', 'headline_bg', 'headline_text', 'footer_bg'] as $key) {
            if (isset($settings[$key]) && preg_match('/^#[0-9a-f]{6}$/i', (string) $settings[$key])) {
                $defaults[$key] = strtoupper($settings[$key]);
            }
        }
        $defaults['font_size'] = min(120, max(40, absint($settings['font_size'] ?? 80)));
        $defaults['margin'] = min(450, max(40, absint($settings['margin'] ?? 120)));
        $defaults['website'] = sanitize_text_field($settings['website'] ?? $defaults['website']);
        $defaults['show_comment'] = !empty($settings['show_comment']);
        $defaults['comment'] = sanitize_text_field($settings['comment'] ?? $defaults['comment']);
        $defaults['show_category'] = !empty($settings['show_category']);
        $defaults['category'] = sanitize_text_field($settings['category'] ?? '');
        $defaults['show_social'] = !empty($settings['show_social']);
        $defaults['social'] = [];
        foreach (['facebook', 'x', 'threads', 'instagram', 'youtube', 'linkedin'] as $platform) {
            $url = esc_url_raw($settings['social'][$platform] ?? '');
            $defaults['social'][$platform] = $url && wp_parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : '';
        }
        $defaults['logo_mode'] = ($settings['logo_mode'] ?? '') === 'image' ? 'image' : 'text';
        $defaults['feature_id'] = absint($settings['feature_id'] ?? 0);
        $defaults['logo_id'] = absint($settings['logo_id'] ?? 0);
        return $defaults;
    }

    public static function get_ads(): WP_REST_Response {
        return rest_ensure_response(self::ads());
    }

    private static function ads(): array {
        return get_option(self::ADS_KEY, []);
    }

    public static function save_ad(WP_REST_Request $request) {
        $name = sanitize_text_field($request->get_param('name'));
        if ($name === '') {
            return new WP_Error('dnb_ad_name_required', 'বিজ্ঞাপনের নাম আবশ্যক।', ['status' => 400]);
        }
        $attachment_id = 0;
        if (!empty($_FILES['image']['name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_id = media_handle_upload('image', 0);
            if (is_wp_error($attachment_id)) {
                return new WP_Error('dnb_upload_failed', 'ব্যানার আপলোড ব্যর্থ: ' . $attachment_id->get_error_message(), ['status' => 400]);
            }
        } elseif ($request->get_param('image_url')) {
            $remote_url = esc_url_raw($request->get_param('image_url'));
            if (wp_parse_url($remote_url, PHP_URL_SCHEME) !== 'https' || !wp_http_validate_url($remote_url)) {
                return new WP_Error('dnb_invalid_image_url', 'নিরাপদ HTTPS ছবির URL দিন।', ['status' => 400]);
            }
            $remote = wp_safe_remote_get($remote_url, ['timeout' => 15, 'limit_response_size' => 5 * MB_IN_BYTES]);
            if (is_wp_error($remote) || wp_remote_retrieve_response_code($remote) !== 200) {
                return new WP_Error('dnb_remote_image_failed', 'ছবির URL থেকে ছবি আনা যায়নি।', ['status' => 400]);
            }
            $mime = strtolower((string) wp_remote_retrieve_header($remote, 'content-type'));
            $mime = trim(explode(';', $mime)[0]);
            $body = wp_remote_retrieve_body($remote);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $body === '' || strlen($body) > 5 * MB_IN_BYTES) {
                return new WP_Error('dnb_remote_image_invalid', 'URL-এ 5 MB-এর মধ্যে JPG, PNG বা WebP থাকতে হবে।', ['status' => 400]);
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $temporary = wp_tempnam($remote_url);
            if (!$temporary || file_put_contents($temporary, $body) === false) {
                return new WP_Error('dnb_remote_image_failed', 'ছবির অস্থায়ী ফাইল তৈরি করা যায়নি।', ['status' => 500]);
            }
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            $file_array = ['name' => sanitize_file_name('dnb-ad-' . time() . '.' . $extension), 'tmp_name' => $temporary];
            $attachment_id = media_handle_sideload($file_array, 0);
            if (is_wp_error($attachment_id)) {
                @unlink($temporary);
                return new WP_Error('dnb_remote_image_failed', 'ছবিটি Media Library-তে সংরক্ষণ করা যায়নি।', ['status' => 400]);
            }
        }
        $ads = self::ads();
        $id = absint($request->get_param('id'));
        $existing = null;
        foreach ($ads as $index => $ad) {
            if ((int) $ad['id'] === $id && $id > 0) {
                $existing = $ad;
                unset($ads[$index]);
                break;
            }
        }
        $ad = [
            'id' => $id ?: time(),
            'name' => $name,
            'organization' => sanitize_text_field($request->get_param('organization')),
            'description' => sanitize_text_field($request->get_param('description')),
            'phone' => sanitize_text_field($request->get_param('phone')),
            'image_id' => $attachment_id ?: (int) ($existing['image_id'] ?? 0),
            'image_url' => $attachment_id ? wp_get_attachment_image_url($attachment_id, 'full') : esc_url_raw($existing['image_url'] ?? ''),
            'active' => $request->get_param('active') === null ? (bool) ($existing['active'] ?? true) : rest_sanitize_boolean($request->get_param('active')),
            'sort_order' => absint($request->get_param('sort_order') ?? ($existing['sort_order'] ?? count($ads))),
            'starts_at' => sanitize_text_field($request->get_param('starts_at')),
            'ends_at' => sanitize_text_field($request->get_param('ends_at')),
        ];
        $ads[] = $ad;
        usort($ads, static fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
        update_option(self::ADS_KEY, array_values($ads), false);
        self::log('ad_saved', 'Advertisement saved: ' . $name);
        return rest_ensure_response($ad);
    }

    public static function delete_ad(WP_REST_Request $request): WP_REST_Response {
        $id = absint($request['id']);
        update_option(self::ADS_KEY, array_values(array_filter(self::ads(), static fn($ad) => (int) $ad['id'] !== $id)), false);
        self::log('ad_deleted', 'Advertisement deleted: ' . $id);
        return rest_ensure_response(['deleted' => true]);
    }

    public static function get_staff(): WP_REST_Response {
        global $wpdb;
        $staff = $wpdb->get_results('SELECT * FROM ' . self::table('staff') . ' ORDER BY full_name ASC', ARRAY_A);
        foreach ($staff as &$member) {
            $member['photo_url'] = !empty($member['photo_id']) ? wp_get_attachment_image_url((int) $member['photo_id'], 'full') : '';
        }
        unset($member);
        return rest_ensure_response($staff);
    }

    public static function save_staff(WP_REST_Request $request) {
        global $wpdb;
        $data = $request->get_json_params() ?: [];
        $id = absint($data['id'] ?? 0);
        $code = sanitize_text_field($data['staff_code'] ?? '');
        $name = sanitize_text_field($data['full_name'] ?? '');
        $designation = sanitize_text_field($data['designation'] ?? '');
        if ($code === '' || $name === '' || $designation === '') {
            return new WP_Error('dnb_staff_required', 'আইডি, নাম ও পদবি আবশ্যক।', ['status' => 400]);
        }
        $duplicate = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('staff') . ' WHERE staff_code = %s AND id != %d', $code, $id));
        if ($duplicate) {
            return new WP_Error('dnb_staff_duplicate', 'এই কর্মী আইডি আগে থেকেই আছে।', ['status' => 409]);
        }
        $valid_until = sanitize_text_field($data['valid_until'] ?? '');
        $row = [
            'staff_code' => $code,
            'full_name' => $name,
            'designation' => $designation,
            'department' => sanitize_text_field($data['department'] ?? ''),
            'blood_group' => sanitize_text_field($data['blood_group'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'valid_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_until) ? $valid_until : null,
            'photo_id' => absint($data['photo_id'] ?? 0) ?: null,
            'updated_at' => current_time('mysql'),
        ];
        if ($id) {
            $wpdb->update(self::table('staff'), $row, ['id' => $id]);
        } else {
            $row['created_at'] = current_time('mysql');
            $wpdb->insert(self::table('staff'), $row);
            $id = (int) $wpdb->insert_id;
        }
        self::log('staff_saved', 'Staff record saved: ' . $code);
        return rest_ensure_response(['id' => $id] + $row);
    }

    public static function delete_staff(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $id = absint($request['id']);
        $wpdb->delete(self::table('staff'), ['id' => $id]);
        self::log('staff_deleted', 'Staff record deleted: ' . $id);
        return rest_ensure_response(['deleted' => true]);
    }

    public static function get_schedules(): WP_REST_Response {
        return rest_ensure_response(get_option(self::SCHEDULES_KEY, []));
    }

    public static function save_schedule(WP_REST_Request $request) {
        $input = $request->get_json_params() ?: [];
        $name = sanitize_text_field($input['name'] ?? '');
        $time = sanitize_text_field($input['time'] ?? '');
        if ($name === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return new WP_Error('dnb_schedule_invalid', 'শিডিউলের নাম ও ২৪-ঘণ্টার সময় (HH:MM) দিন।', ['status' => 400]);
        }
        $schedules = get_option(self::SCHEDULES_KEY, []);
        $id = sanitize_text_field($input['id'] ?? wp_generate_uuid4());
        $schedule = [
            'id' => $id,
            'name' => $name,
            'time' => $time,
            'message' => sanitize_textarea_field($input['message'] ?? ''),
            'enabled' => !empty($input['enabled']),
            'template_id' => sanitize_text_field($input['template_id'] ?? ''),
            'platforms' => array_values(array_filter(array_map('sanitize_key', (array) ($input['platforms'] ?? [])))),
        ];
        $found = false;
        foreach ($schedules as &$existing) {
            if (($existing['id'] ?? '') === $id) {
                $existing = $schedule;
                $found = true;
                break;
            }
        }
        unset($existing);
        if (!$found) {
            $schedules[] = $schedule;
        }
        update_option(self::SCHEDULES_KEY, $schedules, false);
        self::log('schedule_saved', 'Schedule saved: ' . $name);
        return rest_ensure_response($schedule);
    }

    public static function run_due_schedules(): void {
        $schedules = get_option(self::SCHEDULES_KEY, []);
        $now = new DateTimeImmutable('now', wp_timezone());
        $minute = $now->format('H:i');
        $day_key = $now->format('Y-m-d');
        foreach ($schedules as $schedule) {
            if (empty($schedule['enabled']) || ($schedule['time'] ?? '') !== $minute) {
                continue;
            }
            $run_key = 'dnb_schedule_run_' . md5(($schedule['id'] ?? '') . $day_key);
            if (get_option($run_key)) {
                continue;
            }
            add_option($run_key, current_time('mysql'), '', false);
            self::log('schedule_due', 'Schedule due: ' . sanitize_text_field($schedule['name'] ?? ''));
            do_action('dnbangla_dashboard_schedule_due', $schedule);
        }
    }

    public static function get_logs(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $limit = min(200, max(1, absint($request->get_param('limit') ?: 50)));
        $type = sanitize_key($request->get_param('type') ?: '');
        if ($type) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('logs') . ' WHERE event_type = %s ORDER BY id DESC LIMIT %d', $type, $limit), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('logs') . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A);
        }
        return rest_ensure_response($rows);
    }

    private static function settings(): array {
        $settings = get_option(self::SETTINGS_KEY, []);
        return is_array($settings) ? $settings : [];
    }

    private static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'dnb_' . $name;
    }

    private static function log(string $type, string $message, array $context = []): void {
        global $wpdb;
        $wpdb->insert(self::table('logs'), [
            'event_type' => sanitize_key($type),
            'message' => sanitize_textarea_field($message),
            'context' => $context ? wp_json_encode($context) : null,
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function ensure_cron(): void {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['dnb_every_minute'] = ['interval' => 60, 'display' => 'Every minute'];
            return $schedules;
        });
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'dnb_every_minute', self::CRON_HOOK);
        }
    }
}

register_activation_hook(__FILE__, [DNBangla_Newsroom_Dashboard::class, 'activate']);
register_deactivation_hook(__FILE__, [DNBangla_Newsroom_Dashboard::class, 'deactivate']);
DNBangla_Newsroom_Dashboard::boot();
