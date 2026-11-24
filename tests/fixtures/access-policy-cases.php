<?php

use RRZE\Appointment\Admin\AccessPolicy;
use RRZE\Appointment\Admin\AppointmentPermissions;
use RRZE\Appointment\Admin\BackendSsoAccess;

$key = AccessPolicy::OPTION_NAME;
$options[1][$key] = ['version' => 1, 'restricted' => true, 'revision' => 1, 'owners' => [7], 'managers' => [8]];
$token = AccessPolicy::token();
$extra = [];
if (in_array($scenario, ['policy-owner', 'policy-manager', 'policy-off-manager', 'policy-editor-owner'], true)) {
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
if (str_starts_with($scenario, 'policy-split-')) {
    $options[1][$key] = ['version' => 2, 'restricted' => true, 'revision' => 2, 'owners' => [],
        'access_owners' => [7], 'managers' => [8]];
    $name = substr($scenario, strlen('policy-split-'));
    $token = AccessPolicy::token();
    switch ($name) {
        case 'off':
        case 'sso':
        case 'expired':
            $options[1][BackendSsoAccess::OPTION_NAME] = $name === 'off' ? '0' : '1';
            $authenticated = $name !== 'expired';
            $result = $controller->getBookings(new WP_REST_Request());
            $cancel = $controller->cancelBooking(new WP_REST_Request());
            $extra = ['base' => AppointmentPermissions::currentUserCanManage(), 'grants' => AppointmentPermissions::grantsAccess()];
            break;
        case 'demoted':
            $editors = [7];
            $result = AppointmentPermissions::dataAccess();
            $extra['grants'] = AppointmentPermissions::grantsAccess();
            break;
        case 'delegate':
            $result = AccessPolicy::change('save', [9], [8], $token, [7]);
            break;
        case 'self-owner':
        case 'self-manager':
        case 'self-network':
        case 'self-recover':
            $super = true;
            $operation = match ($name) { 'self-network' => 'network_save', 'self-recover' => 'recover', default => 'save' };
            $owners = $name === 'self-manager' ? [9] : [7];
            $managers = $name === 'self-manager' ? [7] : [];
            $result = AccessPolicy::change($operation, $owners, $managers, $token, []);
            break;
        case 'after-removal':
            $super = true;
            $extra['first'] = AccessPolicy::change('save', [9], [8], $token, []);
            $result = AccessPolicy::change('network_save', [9], [7, 8], AccessPolicy::token(), []);
            break;
        case 'legacy-form':
            $result = AccessPolicy::change('save', [9], [8], $token);
            break;
        case 'bad-overlap':
            $options[1][$key]['managers'][] = 7;
            $result = AppointmentPermissions::dataAccess();
            break;
        case 'invalid-owner':
        case 'invalid-access-owner':
            $editors = [9];
            $owners = $name === 'invalid-owner' ? [9] : [];
            $accessOwners = $name === 'invalid-access-owner' ? [7, 9] : [7];
            $result = AccessPolicy::change('save', $owners, [8], $token, $accessOwners);
            break;
        case 'network-member-editor':
            $super = true;
            $editors = [7];
            $result = AccessPolicy::change('network_save', [9], [8], $token, [7]);
            break;
        case 'setup':
            unset($options[1][$key]);
            $result = AccessPolicy::change('setup', [], [8], AccessPolicy::token(), [7]);
            $extra['booking'] = AppointmentPermissions::currentUserCanManage();
            break;
        case 'new-format-demotion':
            $options[1][$key] = ['version' => 1, 'restricted' => true, 'revision' => 1, 'owners' => [7], 'managers' => [8]];
            $result = AccessPolicy::change('save', [7, 9], [8], AccessPolicy::token(), []);
            $editors = [7];
            $extra['booking'] = AppointmentPermissions::currentUserCanManage();
            $extra['owner'] = AccessPolicy::owner();
            break;
        case 'recover-orphan':
        case 'recover-network-only':
        case 'recover-self':
        case 'recover-active':
        case 'recover-expired':
            $multisite = $name === 'recover-network-only';
            $options[1][$key] = ['version' => 1, 'revision' => 1, 'restricted' => true, 'owners' => [9], 'managers' => [8]];
            $editors = $name === 'recover-active' ? [] : [9];
            $authenticated = $name !== 'recover-expired';
            $extra['before'] = AccessPolicy::read();
            $extra['canRecover'] = AccessPolicy::canRecover();
            $result = AccessPolicy::change('recover', $name === 'recover-self' ? [7] : [], [], AccessPolicy::token(),
                $name === 'recover-self' ? [] : [7]);
            break;
        default: throw new RuntimeException('Unknown separated responsibility scenario.');
    }
} elseif (str_starts_with($scenario, 'policy-capability-')) {
    [, , $siteType, $version] = explode('-', $scenario);
    $multisite = $siteType === 'multi';
    $editors = [7];
    $effectiveManageOptions = [7 => true];
    $options[1][BackendSsoAccess::OPTION_NAME] = '0';
    if ($version === 'v2') {
        $options[1][$key]['version'] = 2;
        $options[1][$key]['access_owners'] = [];
    }
    $extra['storedAdmin'] = get_userdata(7)->allcaps['manage_options'];
    $extra['before'] = ['owner' => AccessPolicy::owner(), 'booking' => AppointmentPermissions::currentUserCanManage()];
    $result = AccessPolicy::change('save', [7], [8], AccessPolicy::token(), []);
    $effectiveManageOptions[7] = false;
    $extra['after'] = ['owner' => AccessPolicy::owner(), 'booking' => AppointmentPermissions::currentUserCanManage()];
    $editors = [];
    $extra['deniedStoredAdmin'] = get_userdata(7)->allcaps['manage_options'];
    $extra['deniedOwner'] = AccessPolicy::owner();
} elseif (str_starts_with($scenario, 'policy-target-super-')) {
    // An ordinary site administrator selects a different account that holds network privileges.
    $otherSuperAdmins = [9];
    $editors = $scenario === 'policy-target-super-editor' ? [9] : [];
    $extra['effectiveAdmin'] = user_can(get_userdata(9), 'manage_options');
    $result = AccessPolicy::change('save', [7, 9], [8], $token, []);
} elseif (str_starts_with($scenario, 'policy-handover-')) {
    $user = 10;
    $super = true;
    $options[1][BackendSsoAccess::OPTION_NAME] = '0';
    if (str_ends_with($scenario, '-double')) {
        $options[1][$key]['managers'][] = 7;
    }
    $demoted = str_contains($scenario, '-ineligible-') ? [7] : [];
    $result = AccessPolicy::change('recover', [9], [], AccessPolicy::token(), []);
    $user = 7;
    $super = false;
    // Reinstating a member's site role exposes any independent booking grant kept at handover.
    $demoted = [];
    $extra = ['owner' => AccessPolicy::owner(), 'booking' => AppointmentPermissions::currentUserCanManage()];
} elseif (str_starts_with($scenario, 'policy-unchanged-')) {
    $operation = substr($scenario, strlen('policy-unchanged-'));
    $editors = [8];
    $options[1][$key] = ['version' => 1, 'restricted' => true, 'revision' => 14,
        'owners' => [10, 8, 7], 'managers' => [7, 9]];
    $owners = [7, 10];
    $managers = [8, 9];
    $accessOwners = [];
    $token = AccessPolicy::token();
    switch ($operation) {
        case 'network_save':
            $user = 9;
            $attributes = ['uid' => ['bob']];
            $super = true;
            break;
        case 'recover':
            $super = true;
            break;
        case 'stale':
            $options[1][$key]['revision']++;
            break;
        case 'overlap':
            $accessOwners = [7];
            break;
        case 'unauthorized':
            $user = 9;
            break;
        case 'expired':
            $authenticated = false;
            break;
    }
    if (!in_array($operation, ['network_save', 'recover'], true)) {
        $operation = 'save';
    }
    $extra['rawBefore'] = serialize($options[1][$key]);
    $result = AccessPolicy::change($operation, $owners, $managers, $token, $accessOwners);
    $extra['rawAfter'] = serialize($options[1][$key]);
} elseif (str_starts_with($scenario, 'policy-http-')) {
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
        case 'policy-http-transfer':
        case 'policy-http-transfer-manager':
            $_POST['owners'] = [9];
            $_POST['managers'] = $scenario === 'policy-http-transfer-manager' ? [7] : [];
            break;
    }
    try { (new \RRZE\Appointment\Admin\AccessSettings())->handle(); }
    catch (TestStopped $exception) { $result = new WP_Error('stopped', $exception->getMessage(), ['status' => $exception->getCode()]); }
} elseif (str_starts_with($scenario, 'policy-network-')) {
    $user = 9;
    $nonMembers = [9];
    $super = $scenario !== 'policy-network-site-admin';
    $caps['manage_network_options'] = $scenario !== 'policy-network-no-capability';
    $attributes = ['uid' => [$scenario === 'policy-network-mismatch' ? 'alice' : 'bob']];
    $authenticated = $scenario !== 'policy-network-expired';
    $options[1][BackendSsoAccess::OPTION_NAME] = '0';
    $operation = 'network_save';
    $owners = [8];
    $managers = [7];
    if (in_array($scenario, ['policy-network-setup', 'policy-network-stale-legacy', 'policy-network-expired'], true)) {
        unset($options[1][$key]);
        $operation = 'network_setup';
    }
    $token = AccessPolicy::token();
    if ($scenario === 'policy-network-stale-legacy') {
        $options[1]['rrze_appointment_settings'] = ['appointment_manager_user_ids' => [10]];
    }
    if ($scenario === 'policy-network-no-owner') {
        $owners = [];
    }
    if ($scenario === 'policy-network-ineligible') {
        $owners = [9];
    }
    $result = AccessPolicy::change($operation, $owners, $managers, $token);
    $extra['access'] = AppointmentPermissions::dataAccess();
    $extra['grants'] = AppointmentPermissions::grantsAccess();
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
            $extra['list'] = $controller->getBookings(new WP_REST_Request());
            $extra['cancel'] = $controller->cancelBooking(new WP_REST_Request());
            $extra['policy'] = AccessPolicy::read();
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
        case 'policy-deleted-manager-on-save':
            $options[1][$key]['managers'][] = 10;
            $deleted = [10];
            $extra['rawBefore'] = serialize($options[1][$key]);
            $extra['first'] = AccessPolicy::change('save', [7], [8], AccessPolicy::token(), []);
            $extra['rawAfterFirst'] = serialize($options[1][$key]);
            $extra['writesAfterFirst'] = count($writes);
            $result = AccessPolicy::change('save', [7, 9], [8], AccessPolicy::token(), []);
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
