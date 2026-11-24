<?php

use RRZE\Appointment\Admin\AccessPolicy;
use RRZE\Appointment\Admin\AccessSettings;
use RRZE\Appointment\Admin\BackendSsoAccess;
use RRZE\Appointment\Admin\MailTemplatesPage;
use RRZE\Appointment\Admin\SettingsPage;

/** Returns the fixture's site members only. */
function get_users(array $query): array
{
    if ($query['blog_id'] !== get_current_blog_id()) {
        return [];
    }
    return array_map('get_userdata', array_values(array_diff($GLOBALS['fixtureUserIds'], $GLOBALS['nonMembers'] ?? [])));
}

/** Escapes names, nonce fields and role values for HTML attributes. */
function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
/** Escapes action URLs in rendered forms. */
function esc_url($url): string { return esc_attr($url); }
/** Displays translated, escaped interface text. */
function esc_html_e($text, $domain): void { echo esc_html__($text, $domain); }
/** Prints WordPress's selected attribute on the current role. */
function selected($current, $value): void { if ($current === $value) { echo ' selected'; } }
/** Prints WordPress's disabled attribute for the pinned setup owner. */
function disabled($condition): void { if ($condition) { echo ' disabled'; } }
/** Prints a standard access checkbox's saved selection. */
function checked($condition): void { if ($condition) { echo ' checked'; } }
/** Omits WordPress's unrelated settings group fields from the isolated access form. */
function settings_fields($group): void {}
/** Returns a deterministic, action-bound nonce for preview and submit requests. */
function wp_create_nonce($action): string { return $action; }
/** Prints the single action of a normal role update or a recovery. */
function submit_button($label): void { echo '<button type="submit">' . esc_html($label) . '</button>'; }

$options[1][BackendSsoAccess::OPTION_NAME] = '0';
$options[1]['rrze_appointment_settings']['appointment_manager_user_ids'] = [8, 10];
$demoted = [10];
if (str_contains($scenario, 'large')) {
    $fixtureUserIds = range(7, 1018);
}
if ($scenario === 'screen-additional') {
    $demoted = [8, 10];
    $options[1]['rrze_appointment_settings']['appointment_manager_user_ids'] = [10];
}
$super = !str_contains($scenario, 'local');
$nonMembers = str_contains($scenario, 'member') ? [] : [7];
$authenticated = !str_contains($scenario, 'expired');
if (str_contains($scenario, 'configured')) {
    $options[1][AccessPolicy::OPTION_NAME] = ['restricted' => true, 'version' => 1, 'revision' => 1, 'owners' => [9], 'managers' => [8]];
}
parse_str(stream_get_contents(STDIN), $_POST);
$_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
if (str_contains($scenario, 'submit')) {
    try {
        (new AccessSettings())->handle();
    } catch (TestStopped $error) {
        echo json_encode(['status' => $error->getCode(), 'writes' => $writes]);
    }
} else {
    ob_start();
    require_once $root . '/includes/Admin/MailTemplatesPage.php';
    $page = new SettingsPage(new MailTemplatesPage());
    if (str_contains($scenario, 'no-callback')) {
        AccessSettings::renderSettings();
    } else {
        AccessSettings::renderSettings([$page, 'renderAppointmentManagersField']);
    }
    $html = ob_get_clean();
    echo json_encode(['html' => $html, 'writes' => $writes, 'reads' => $bookingReads]);
}
