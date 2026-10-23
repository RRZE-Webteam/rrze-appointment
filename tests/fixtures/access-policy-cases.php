<?php

use RRZE\Appointment\Admin\AccessPolicy;
use RRZE\Appointment\Admin\AppointmentPermissions;
use RRZE\Appointment\Admin\BackendSsoAccess;

$key = AccessPolicy::OPTION_NAME;
$options[1][$key] = ['version' => 1, 'restricted' => true, 'revision' => 1, 'owners' => [7], 'managers' => [8]];
$token = AccessPolicy::token();
$extra = [];
if (in_array($scenario, ['policy-owner', 'policy-manager', 'policy-off-manager'], true)) {
    $fakeBookings = [['slot' => 'slot', 'date' => '2099-01-01', 'time' => '10:00-10:30', 'title' => 'Test appointment',
        'person_name' => 'Host', 'booker_name' => 'Test Patient', 'booker_email' => 'patient@example.test', 'admin_anonymized' => false]];
    $requestParams = ['slot' => 'slot'];
}
switch ($scenario) {
    case 'policy-manager': $user = 8; $provider = 'idp-b'; $caps['manage_options'] = false; break;
    case 'policy-admin': $user = 9; $attributes = ['uid' => ['bob']]; break;
    case 'policy-super': $user = 9; $super = true; break;
    case 'policy-expired': $authenticated = false; break;
    case 'policy-mismatch': $attributes = ['uid' => ['bob']]; break;
    case 'policy-outage': $ready = false; break;
    case 'policy-demoted': $demoted = [7]; break;
    case 'policy-deleted': $deleted = [7]; break;
    case 'policy-removed': $nonMembers = [7]; break;
    case 'policy-corrupt': $options[1][$key] = 'corrupt'; break;
    case 'policy-empty': $options[1][$key]['owners'] = []; break;
    case 'policy-off-admin': $options[1][BackendSsoAccess::OPTION_NAME] = '0'; $user = 9; break;
    case 'policy-off-manager': $options[1][BackendSsoAccess::OPTION_NAME] = '0'; $user = 8; break;
    case 'policy-editor-owner': $caps['manage_options'] = false; break;
}
if (str_starts_with($scenario, 'policy-http-')) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['operation' => 'save', 'policy_token' => $token, 'owners' => [7, 9], 'managers' => [8],
        '_wpnonce' => \RRZE\Appointment\Admin\AccessSettings::nonceAction('save', $token)];
    switch ($scenario) {
        case 'policy-http-nonce': $_POST['_wpnonce'] = 'invalid'; break;
        case 'policy-http-get': $_SERVER['REQUEST_METHOD'] = 'GET'; break;
        case 'policy-http-site': $site = 2; break;
        case 'policy-http-array': $_POST['owners'] = '7'; break;
        case 'policy-http-operation': $_POST['operation'] = ['save']; break;
        case 'policy-http-admin': $user = 10; break;
    }
    try { (new \RRZE\Appointment\Admin\AccessSettings())->handle(); }
    catch (TestStopped $exception) { $result = new WP_Error('stopped', $exception->getMessage(), ['status' => $exception->getCode()]); }
} elseif (in_array($scenario, ['policy-owner', 'policy-manager', 'policy-admin', 'policy-super', 'policy-expired',
    'policy-mismatch', 'policy-outage', 'policy-demoted', 'policy-deleted', 'policy-removed', 'policy-corrupt',
    'policy-empty', 'policy-off-admin', 'policy-off-manager'], true)) {
    $result = $controller->getBookings(new WP_REST_Request());
    $cancel = $controller->cancelBooking(new WP_REST_Request());
    $extra = ['base' => AppointmentPermissions::currentUserCanManage(), 'owner' => AccessPolicy::owner()];
} else {
    switch ($scenario) {
        case 'policy-setup':
        case 'policy-setup-expired':
            unset($options[1][$key]);
            $authenticated = $scenario !== 'policy-setup-expired';
            $result = AccessPolicy::change('setup', [], [8], AccessPolicy::token());
            break;
        case 'policy-enable-without-setup':
            unset($options[1][$key]);
            $result = $settings->perform('enable');
            break;
        case 'policy-editor-owner':
            $result = AccessPolicy::change('save', [7, 9], [8], $token);
            $extra['sso'] = $settings->perform('disable');
            break;
        case 'policy-manager-save':
        case 'policy-admin-save':
            $user = $scenario === 'policy-manager-save' ? 8 : 9;
            $result = AccessPolicy::change('save', [$user], [], $token);
            $extra['sso'] = $settings->perform('disable');
            break;
        case 'policy-save-expired':
            $authenticated = false;
            $result = AccessPolicy::change('save', [7, 9], [], $token);
            break;
        case 'policy-last-owner':
            $result = AccessPolicy::change('save', [], [8], $token);
            break;
        case 'policy-stale':
            $extra['first'] = AccessPolicy::change('save', [7, 9], [8], $token);
            $result = AccessPolicy::change('save', [7], [], $token);
            break;
        case 'policy-self-remove':
            $result = AccessPolicy::change('save', [9], [8], $token);
            $extra['access'] = AppointmentPermissions::dataAccess();
            break;
        case 'policy-invalid-users':
            $demoted = [9];
            $result = AccessPolicy::change('save', [7, 9], [], $token);
            break;
        case 'policy-malformed-ids':
            $result = AccessPolicy::change('save', [[7]], [], $token);
            break;
        case 'policy-recover':
        case 'policy-recover-site':
        case 'policy-recover-corrupt':
            $user = 10;
            $super = $scenario !== 'policy-recover-site';
            $authenticated = false;
            if ($scenario === 'policy-recover-corrupt') { $options[1][$key] = 'corrupt'; $token = AccessPolicy::token(); }
            $result = AccessPolicy::change('recover', [9], [10], $token);
            $extra['access'] = AppointmentPermissions::dataAccess();
            break;
        case 'policy-reset':
            $user = 10; $super = true; $authenticated = false;
            $result = $settings->perform('reset');
            $extra['access'] = AppointmentPermissions::dataAccess();
            break;
        case 'policy-old-settings':
            $result = $settings->protectManagers(['appointment_manager_user_ids' => [10], 'retention_days' => 90],
                ['appointment_manager_user_ids' => [8], 'retention_days' => 30]);
            break;
        case 'policy-sites':
            $site = 2;
            $extra['legacyAccess'] = AppointmentPermissions::currentUserCanManage();
            $result = AccessPolicy::change('setup', [], [9], AccessPolicy::token());
            break;
        case 'policy-lock-failed':
            $wpdb->fail = true;
            $result = AccessPolicy::change('save', [7, 9], [], $token);
            break;
        default: throw new RuntimeException('Unknown policy scenario.');
    }
}
