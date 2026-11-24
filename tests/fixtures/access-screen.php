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
/** Returns the untranslated label for a contextual string in the access summary. */
function _x(string $text, string $context, string $domain): string { return $text; }
/** Prints WordPress's selected attribute on the current role. */
function selected($current, $value): void { if ($current === $value) { echo ' selected'; } }
/** Prints WordPress's disabled attribute for the pinned setup owner. */
function disabled($condition): void { if ($condition) { echo ' disabled'; } }
/** Prints a standard access checkbox's saved selection. */
function checked($condition): void { if ($condition) { echo ' checked'; } }
/**
 * Prints the WordPress settings fields used by options.php to verify the form.
 *
 * @param string $group Settings group that supplies the nonce action.
 */
function settings_fields(string $group): void
{
    echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">';
    echo '<input type="hidden" name="action" value="update">';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce($group . '-options')) . '">';
}
/** Returns a deterministic, action-bound nonce for preview and submit requests. */
function wp_create_nonce($action): string { return $action; }
/** Builds a local, action-bound SSO link for the recovery login prompt. */
function wp_nonce_url($url, $action): string { return add_query_arg('_wpnonce', wp_create_nonce($action), $url); }
/** Leaves the field contents to their own tests when rendering the settings form envelope. */
function do_settings_sections(string $page): void {}
/** Prints the submit button of the settings or role form with its WordPress default label. */
function submit_button($label = 'Save Changes'): void { echo '<button type="submit">' . esc_html($label) . '</button>'; }

$options[1][BackendSsoAccess::OPTION_NAME] = str_contains($scenario, 'protected') ? '1' : '0';
$options[1]['rrze_appointment_settings']['appointment_manager_user_ids'] = [8, 10];
$demoted = [10];
$editors = [8];
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
if (str_contains($scenario, 'access-only')) {
    $options[1][AccessPolicy::OPTION_NAME] = ['restricted' => true, 'version' => 2, 'revision' => 1,
        'owners' => [], 'access_owners' => [7], 'managers' => [8]];
}
if (str_contains($scenario, 'orphan')) {
    $options[1][AccessPolicy::OPTION_NAME] = ['restricted' => true, 'version' => 1, 'revision' => 1,
        'owners' => [8], 'managers' => [9]];
}
$multisite = !str_contains($scenario, 'single');
parse_str(stream_get_contents(STDIN), $_POST);
$_GET['tab'] = 'access';
$_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
$screen = new AccessSettings();
try {
    if (str_contains($scenario, 'submit')) {
        $screen->handle();
        exit;
    }
    $screen->handleSettingsPost();
} catch (TestStopped $error) {
    echo json_encode(['status' => $error->getCode(), 'writes' => $writes]);
    exit;
}

ob_start();
require_once $root . '/includes/Admin/MailTemplatesPage.php';
$page = new SettingsPage(new MailTemplatesPage());
if ($scenario === 'screen-general-form' || $scenario === 'screen-illustrations-form') {
    $method = $scenario === 'screen-general-form' ? 'renderTabGeneral' : 'renderTabIllustrations';
    (new ReflectionMethod($page, $method))->invoke($page);
} elseif (str_contains($scenario, 'recovery')) {
    AccessSettings::renderRecovery();
} elseif (str_contains($scenario, 'no-callback')) {
    AccessSettings::renderSettings();
} else {
    AccessSettings::renderSettings([$page, 'renderAppointmentManagersField']);
}
$html = ob_get_clean();
echo json_encode(['html' => $html, 'writes' => $writes, 'reads' => $bookingReads, 'recoveryOpen' => AccessSettings::recoveryOpen()]);
