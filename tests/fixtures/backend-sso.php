<?php

namespace SimpleSAML\Auth {
    /** Models the SimpleSAML client without sessions, network requests or credentials. */
    class Simple
    {
        /** Reports the synthetic session state. */
        public function isAuthenticated(): bool { return $GLOBALS['authenticated']; }
        /** Returns a configured synthetic IdP. */
        public function getAuthData(string $key): string { return $GLOBALS['provider']; }
        /** Returns synthetic claims for the production RRZE-SSO helper. */
        public function getAttributes(): array { return $GLOBALS['attributes']; }
        /** Records an interactive request without leaving the test process. */
        public function requireAuth(array $options): void { $GLOBALS['authCalls'][] = $options; }
    }
}
namespace RRZE\AccessControl {
    /** Models AC initialization without WordPress or SAML infrastructure. */
    class Permissions
    {
        public mixed $simplesamlAuth = null;
        /** Creates the synthetic client or simulates an integration failure. */
        public function simplesamlAuth(): bool
        {
            if ($GLOBALS['throw']) { throw new \RuntimeException('secret dependency details'); }
            $this->simplesamlAuth = new \SimpleSAML\Auth\Simple();
            return $GLOBALS['ready'];
        }
    }
}
namespace RRZE\SSO {
    /** Supplies only synthetic identity mapping configuration. */
    class Options
    {
        /** Returns IdP-specific login scopes. */
        public static function getOptions(): object
        {
            return (object) ['force_sso' => $GLOBALS['forceSso'], 'domain_scope' => ['idp-b' => 'external']];
        }
    }
    /** Supplies the same synthetic SAML client and configured IdPs to the helper. */
    function simpleSAML(): object
    {
        return new class {
            /** Returns the synthetic client used by the real helper. */
            public function getAuthSimple(): object { return new \SimpleSAML\Auth\Simple(); }
            /** Lists recognized IdPs. */
            public function getIdentityProviders(): array { return ['idp-a' => 'University', 'idp-b' => 'Partner']; }
        };
    }
}
namespace RRZE\Appointment\Booking {
    /** Detects any unauthorized call into booking storage. */
    class Bookings
    {
        public const MAX_CANCELLATION_REASON_LENGTH = 2000;
        /** Counts data access without reading actual bookings. */
        public static function getAll(array $options = []): array { $GLOBALS['bookingReads']++; return $GLOBALS['fakeBookings'] ?? []; }
        /** Records an authorized cancellation without sending real notifications. */
        public static function cancel($slot, $reason): bool { $GLOBALS['cancellations'][] = $slot; return true; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { exit; }
    define('ABSPATH', __DIR__);
    /** Minimal WordPress error representation. */
    class WP_Error
    {
        /** Stores a safe response. */
        public function __construct(public string $code, public string $message, public array $data = []) {}
        /** Returns the response code. */
        public function get_error_code(): string { return $this->code; }
        /** Returns the safe message. */
        public function get_error_message(): string { return $this->message; }
        /** Returns response metadata. */
        public function get_error_data(): array { return $this->data; }
    }
    /** Minimal REST request carrying only a nonce and optional view. */
    class WP_REST_Request
    {
        /** Returns the synthetic REST nonce. */
        public function get_header(string $name): string { return 'nonce'; }
        /** Returns no optional query parameters. */
        public function get_param(string $name): mixed { return $GLOBALS['requestParams'][$name] ?? null; }
    }
    /** Captures successful REST responses. */
    class WP_REST_Response
    {
        /** Stores only response fields used by assertions. */
        public function __construct(public mixed $data, public int $status, public array $headers = []) {}
    }
    /** Makes wp_die observable without killing fixture assertions. */
    class TestStopped extends \RuntimeException {}
    /** Implements the error type check. */
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    /** Leaves source strings intact for stable assertions. */
    function __($message, $domain = ''): string { return $message; }
    /** Escapes safe test messages. */
    function esc_html($message): string { return htmlspecialchars($message); }
    /** Combines translation and escaping. */
    function esc_html__($message, $domain = ''): string { return esc_html($message); }
    /** Models configured capabilities. */
    function current_user_can($capability, ...$args): bool { return $GLOBALS['caps'][$capability] ?? false; }
    /** Returns the synthetic account ID. */
    function get_current_user_id(): int { return $GLOBALS['user']; }
    /** Returns the simulated subsite. */
    function get_current_blog_id(): int { return $GLOBALS['site']; }
    /** Models site membership for delegated managers. */
    function is_user_member_of_blog($id, ...$args): bool { return !in_array($id, $GLOBALS['nonMembers'] ?? [], true); }
    /** Supplies eligible synthetic accounts, with explicit deletion and role-loss controls. */
    function get_userdata($id): mixed { return in_array($id, [7, 8, 9, 10], true) && !in_array($id, $GLOBALS['deleted'] ?? [], true) ? (object) ['ID' => $id, 'display_name' => 'User ' . $id, 'user_login' => 'user' . $id] : false; }
    /** Models capability changes independently from grants. */
    function user_can($user, $cap): bool { return !in_array($user->ID, $GLOBALS['demoted'] ?? [], true) && ($user->ID !== get_current_user_id() || $cap === 'read' || $cap === 'edit_others_posts' || current_user_can($cap)); }
    /** Synthetic options have no cache, but production code must still evict it. */
    function wp_cache_delete(...$args): bool { return true; }
    /** Converts manager IDs in the production permission class. */
    function absint($value): int { return abs((int) $value); }
    /** Supplies synthetic per-site settings. */
    function get_option($name, $default = false): mixed { return $GLOBALS['options'][$GLOBALS['site']][$name] ?? $default; }
    /** Records writes and their autoload policy. */
    function update_option($name, $value, $autoload = null): bool
    {
        $GLOBALS['writes'][] = [$GLOBALS['site'], $name, $value, $autoload];
        $GLOBALS['options'][$GLOBALS['site']][$name] = $value;
        return true;
    }
    /** Recognizes site and network activations. */
    function is_plugin_active($plugin): bool { return in_array($plugin, $GLOBALS['active'][$GLOBALS['site']] ?? [], true) || in_array($plugin, $GLOBALS['networkActive'], true); }
    /** Lists installed plugins. */
    function get_plugins(): array { return $GLOBALS['installed'] ? ['rrze-ac/rrze-ac.php' => []] : []; }
    /** Models a network-only restriction. */
    function is_network_only_plugin($plugin): bool { return $GLOBALS['networkOnly']; }
    /** Applies synthetic network visibility and activation policies. */
    function apply_filters($hook, $value, ...$args): mixed
    {
        if ($hook === 'all_plugins' && !$GLOBALS['visible']) { return []; }
        return $hook === 'rrze_appointment_can_activate_access_control' ? $GLOBALS['allowed'] : $value;
    }
    /** Records activation scope and injects failure when requested. */
    function activate_plugin($plugin, $redirect, $network, $silent): mixed
    {
        $GLOBALS['activations'][] = [$GLOBALS['site'], $plugin, $network, $silent];
        if ($GLOBALS['activationFails']) { return new WP_Error('private', 'secret activation details'); }
        $GLOBALS['active'][$GLOBALS['site']][] = $plugin;
        return null;
    }
    /** Models a multisite deployment. */
    function is_multisite(): bool { return true; }
    /** Models network-admin status independently of site capabilities. */
    function is_super_admin(): bool { return $GLOBALS['super']; }
    /** Checks the nonce and binds form nonces to the simulated site. */
    function wp_verify_nonce($nonce, $action): bool { return $GLOBALS['nonce'] && ($action === 'wp_rest' || $nonce === $action); }
    /** Preserves fixture request input. */
    function wp_unslash($value): mixed { return $value; }
    /** Formats test dates without changing locale state. */
    function date_i18n($format, $timestamp): string { return date($format ?: 'Y-m-d', $timestamp); }
    /** Sanitizes synthetic cancellation reasons. */
    function sanitize_textarea_field($value): string { return strip_tags($value); }
    /** Normalizes the synthetic IdP labels as WordPress would. */
    function sanitize_title($value): string { return strtolower($value); }
    /** Resolves both canonical IdP-scoped and local accounts. */
    function get_user_by($field, $login): mixed
    {
        $accounts = ['alice' => 7, 'alice@external' => 8, 'bob' => 9];
        return isset($accounts[$login]) ? (object) ['ID' => $accounts[$login], 'user_login' => $login] : false;
    }
    /** Records validation notices without database transients. */
    function add_settings_error(...$args): void { $GLOBALS['notices'][] = $args; }
    /** Stops an HTTP action with an observable status. */
    function wp_die($message, $title = '', $args = []): void { throw new TestStopped($message, $args['response'] ?? 500); }
    /** Returns fixed site-local admin paths. */
    function admin_url($path = ''): string { return 'https://site' . get_current_blog_id() . '.test/wp-admin/' . $path; }
    /** Adds arguments to a fixed test URL using both WordPress calling conventions. */
    function add_query_arg($key, $value, $url = null): string {
        if (is_array($key)) { $url = $value; $args = $key; } else { $args = [$key => $value]; }
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
    /** Captures a successful redirect and state before the handler exits. */
    function wp_safe_redirect($url): void { echo json_encode(['redirect' => $url, 'authCalls' => $GLOBALS['authCalls'], 'writes' => $GLOBALS['writes']]); }

    $scenario = $argv[1] ?? 'allowed';
    $site = 1;
    $user = 7;
    $authenticated = true;
    $provider = 'idp-a';
    $attributes = ['uid' => ['alice']];
    $forceSso = true;
    $ready = true;
    $throw = false;
    $super = false;
    $nonce = true;
    $installed = $visible = $allowed = true;
    $networkOnly = $activationFails = false;
    $caps = ['read' => true, 'manage_options' => true, 'activate_plugins' => true, 'activate_plugin' => true, 'manage_network_options' => true];
    $active = [1 => ['rrze-ac/rrze-ac.php', 'rrze-sso/rrze-sso.php'], 2 => []];
    $networkActive = [];
    $options = [1 => ['rrze_appointment_backend_sso_required' => '1'], 2 => []];
    $writes = $activations = $authCalls = $notices = [];
    $bookingReads = 0;

    define('DB_NAME', 'test');
    require __DIR__ . '/access-database.php';
    $wpdb = new AccessTestDatabase();
    $root = dirname(__DIR__, 2);
    $helper = dirname($root) . '/rrze-sso/includes/Helper.php';
    require is_file($helper) ? $helper : __DIR__ . '/backend-sso-helper.php';
    foreach (['AppointmentException', 'Storage/OptionDatabase', 'Admin/AccessPolicy', 'Admin/AccessSettings', 'Admin/AccessNotice', 'Configuration/PluginSettings', 'Admin/AppointmentPermissions', 'Admin/BackendSsoAccess', 'Admin/BackendSsoSettings',
        'Admin/BookingsPage', 'Admin/SettingsPage', 'Controller/AdminBookingsController'] as $class) {
        require $root . '/includes/' . $class . '.php';
    }
    $access = \RRZE\Appointment\Admin\BackendSsoAccess::class;
    $settings = new \RRZE\Appointment\Admin\BackendSsoSettings();
    $controller = new \RRZE\Appointment\Controller\AdminBookingsController();
    switch ($scenario) {
        case 'disabled': $options[1][$access::OPTION_NAME] = '0'; $active[1] = []; break;
        case 'expired': case 'disable-expired': case 'reset-site': case 'reset-network': case 'managers-expired': case 'managers-malformed':
            $authenticated = false; break;
        case 'mismatch': $attributes = ['uid' => ['bob']]; break;
        case 'local': $user = 22; break;
        case 'scoped': $provider = 'idp-b'; $user = 8; break;
        case 'scoped-mismatch': $provider = 'idp-b'; break;
        case 'unknown-idp': $provider = 'unrecognized'; break;
        case 'missing-claims': $attributes = []; break;
        case 'unavailable': $ready = false; break;
        case 'exception': $throw = true; break;
        case 'force-off': $forceSso = false; break;
        case 'ac-inactive': $active[1] = ['rrze-sso/rrze-sso.php']; break;
        case 'sso-inactive': $active[1] = ['rrze-ac/rrze-ac.php']; break;
        case 'forbidden': $caps['manage_options'] = false; break;
        case 'delegated': $caps['manage_options'] = false; $options[1]['rrze_appointment_settings'] = ['appointment_manager_user_ids' => [7]]; break;
        case 'nonce': $nonce = false; break;
        case 'multisite': $site = 2; break;
        case 'super-expired': $super = true; $authenticated = false; break;
    }
    if (str_starts_with($scenario, 'policy-')) {
        require __DIR__ . '/access-policy-cases.php';
    } elseif (str_starts_with($scenario, 'activate-')) {
        $active[1] = ['rrze-sso/rrze-sso.php'];
        $options[1][$access::OPTION_NAME] = '0';
        switch ($scenario) {
            case 'activate-network': $networkActive = ['rrze-ac/rrze-ac.php']; break;
            case 'activate-present': $active[1][] = 'rrze-ac/rrze-ac.php'; break;
            case 'activate-missing': $installed = false; break;
            case 'activate-hidden': $visible = false; break;
            case 'activate-policy': $allowed = false; break;
            case 'activate-forbidden': $caps['activate_plugin'] = false; break;
            case 'activate-no-manage': $caps['manage_options'] = false; break;
            case 'activate-network-only': $networkOnly = true; break;
            case 'activate-failed': $activationFails = true; break;
        }
        $result = $settings->perform('prepare');
    } elseif (str_starts_with($scenario, 'managers-')) {
        $result = $settings->protectManagers($scenario === 'managers-malformed' ? 'bad' : ['appointment_manager_user_ids' => [9], 'retention_days' => 45],
            ['appointment_manager_user_ids' => [7], 'retention_days' => 30]);
    } elseif (str_starts_with($scenario, 'reset-') || str_starts_with($scenario, 'disable-') || $scenario === 'enable') {
        $super = $scenario === 'reset-network';
        if ($scenario === 'enable') { $options[1][$access::OPTION_NAME] = '0'; $options[1]['rrze_appointment_access_policy'] = ['version' => 1, 'restricted' => true, 'revision' => 1, 'owners' => [7], 'managers' => []]; }
        $result = $settings->perform(explode('-', $scenario)[0]);
    } elseif (str_starts_with($scenario, 'http-')) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['operation' => 'enable', 'policy_token' => \RRZE\Appointment\Admin\AccessPolicy::token()];
        $_POST['_wpnonce'] = \RRZE\Appointment\Admin\BackendSsoSettings::nonceAction('enable', 'settings', $_POST['policy_token']);
        switch ($scenario) {
            case 'http-nonce': $_POST['_wpnonce'] = 'invalid'; break;
            case 'http-cross-site': $site = 2; break;
            case 'http-array': $_POST['operation'] = ['enable']; break;
            case 'http-return': $_POST['destination'] = 'https://attacker.test'; break;
            case 'http-forbidden': $caps['manage_options'] = false; break;
            case 'http-get': $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = $_POST; break;
            case 'http-login':
                $_SERVER['REQUEST_METHOD'] = 'GET';
                $_GET = ['operation' => 'login', 'destination' => 'bookings', '_wpnonce' => \RRZE\Appointment\Admin\BackendSsoSettings::nonceAction('login', 'bookings')];
                break;
        }
        try { $settings->handle(); } catch (TestStopped $exception) { $result = new WP_Error('stopped', $exception->getMessage(), ['status' => $exception->getCode()]); }
    } else {
        $result = $controller->getBookings(new WP_REST_Request());
        $cancel = $controller->cancelBooking(new WP_REST_Request());
    }
    echo json_encode(['result' => $result, 'cancel' => $cancel ?? null, 'reads' => $bookingReads, 'writes' => $writes,
        'activations' => $activations, 'options' => $options, 'authCalls' => $authCalls, 'notices' => $notices, 'extra' => $extra ?? [], 'locked' => $wpdb->locked]);
}
