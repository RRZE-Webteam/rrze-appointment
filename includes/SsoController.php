<?php

namespace RRZE\Appointment;

use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * Handles passive SSO status checks and explicit login redirects.
 */
final class SsoController
{
    /**
     * Returns the authenticated booking identity or a login URL.
     *
     * Supports both the REST endpoint and the legacy AJAX action.
     *
     * @param mixed $request REST request for REST callbacks; null for AJAX.
     * @return WP_REST_Response|void
     */
    public function handleBookerRequest($request = null)
    {
        $isRestRequest = $request instanceof WP_REST_Request;

        try {
            $requestReturnTo = $this->getReturnUrl($request, $isRestRequest);
            $redirectUrl = wp_validate_redirect($requestReturnTo, wp_get_referer() ?: home_url('/'));
            $loginUrl = add_query_arg([
                'rrze_appt_sso' => '1',
                'rrze_appt_return' => $redirectUrl,
            ], home_url('/'));

            if (!class_exists('\RRZE\AccessControl\Permissions')) {
                return $this->sendResponse([
                    'needsLogin' => true,
                    'loginUrl' => $loginUrl,
                    'data' => null,
                    'error' => 'AccessControl not available',
                ], $isRestRequest, false);
            }

            $serverBooker = Rights::get();
            if (empty($serverBooker['authenticated'])) {
                return $this->sendResponse([
                    'needsLogin' => true,
                    'loginUrl' => $loginUrl,
                    'data' => ['bookerEmail' => '', 'bookerName' => ''],
                ], $isRestRequest, false);
            }

            return $this->sendResponse([
                'needsLogin' => false,
                'loginUrl' => '',
                'data' => [
                    'bookerEmail' => $serverBooker['bookerEmail'] ?? '',
                    'bookerName' => $serverBooker['bookerName'] ?? '',
                ],
            ], $isRestRequest, true);
        } catch (\Throwable $exception) {
            return $this->sendResponse([
                'needsLogin' => true,
                'loginUrl' => '',
                'error' => __('SSO login failed.', 'rrze-appointment'),
                'data' => null,
            ], $isRestRequest, false);
        }
    }

    /**
     * Starts the explicit SSO flow requested by the public booking dialog.
     */
    public function handleLogin(): void
    {
        if (empty($_GET['rrze_appt_sso'])) {
            return;
        }

        $returnToParameter = isset($_GET['rrze_appt_return'])
            ? wp_unslash($_GET['rrze_appt_return'])
            : '';
        $returnTo = $returnToParameter ?: remove_query_arg(['rrze_appt_sso', 'rrze_appt_return']);
        $returnTo = wp_validate_redirect($returnTo, home_url('/'));

        if (!class_exists('\RRZE\AccessControl\Permissions')) {
            wp_die(esc_html__('SSO is not available.', 'rrze-appointment'), '', ['response' => 500]);
        }

        try {
            $permissions = new \RRZE\AccessControl\Permissions();
            if ($this->isLoggedIn($permissions)) {
                wp_safe_redirect($returnTo);
                exit;
            }

            $auth = method_exists($permissions, 'simplesamlAuth')
                ? $permissions->simplesamlAuth()
                : null;
            if (is_object($auth)) {
                if (method_exists($auth, 'isAuthenticated') && $auth->isAuthenticated()) {
                    wp_safe_redirect($returnTo);
                    exit;
                }
                if (method_exists($auth, 'requireAuth')) {
                    $auth->requireAuth(['ReturnTo' => $returnTo, 'KeepPost' => false]);
                    wp_safe_redirect($returnTo);
                    exit;
                }
            }

            if (method_exists($permissions, 'checkSSOLoggedIn')) {
                $permissions->checkSSOLoggedIn();
                wp_safe_redirect($returnTo);
                exit;
            }

            wp_die(esc_html__('SSO is not available.', 'rrze-appointment'), '', ['response' => 500]);
        } catch (\Throwable $exception) {
            wp_die(esc_html__('SSO login failed.', 'rrze-appointment'), '', ['response' => 500]);
        }
    }

    /**
     * Reads and sanitizes the caller's desired post-login return URL.
     *
     * @param mixed $request       REST request or null.
     * @param bool  $isRestRequest Whether the caller is the REST endpoint.
     */
    private function getReturnUrl($request, bool $isRestRequest): string
    {
        if ($isRestRequest) {
            return (string) ($request->get_param('returnTo') ?? '');
        }

        return isset($_POST['returnTo'])
            ? sanitize_text_field(wp_unslash($_POST['returnTo']))
            : '';
    }

    /**
     * Sends a response in the format expected by REST or legacy AJAX clients.
     *
     * @param array<string, mixed> $response Response payload.
     * @return WP_REST_Response|void
     */
    private function sendResponse(array $response, bool $isRestRequest, bool $success)
    {
        if ($isRestRequest) {
            return new WP_REST_Response($response, 200);
        }

        if ($success) {
            wp_send_json_success($response['data']);
        }

        wp_send_json_error($response);
    }

    /**
     * Performs the passive access-control login check without starting auth.
     */
    private function isLoggedIn(object $permissions): bool
    {
        if (!method_exists($permissions, 'checkSSOLoggedIn')) {
            return false;
        }

        try {
            return (bool) $permissions->checkSSOLoggedIn();
        } catch (\Throwable $exception) {
            return false;
        }
    }
}
