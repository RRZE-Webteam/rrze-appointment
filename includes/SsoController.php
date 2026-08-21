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
    private const PERMISSIONS_CLASS = '\\RRZE\\AccessControl\\Permissions';
    private const LOGIN_FLAG = 'rrze_appt_sso';
    private const RETURN_URL_PARAMETER = 'rrze_appt_return';
    private const REQUEST_RETURN_URL_PARAMETER = 'returnTo';

    /**
     * Returns the authenticated booking identity or a login URL.
     *
     * Supports both the REST endpoint and the legacy AJAX action.
     *
     * @param mixed $request REST request for REST callbacks; null for AJAX.
     * @return WP_REST_Response|null
     */
    public function handleBookerRequest($request = null): ?WP_REST_Response
    {
        $isRestRequest = $request instanceof WP_REST_Request;

        try {
            $loginUrl = $this->buildLoginUrl($this->getReturnUrl($request));

            if (!class_exists(self::PERMISSIONS_CLASS)) {
                return $this->sendResponse([
                    'needsLogin' => true,
                    'loginUrl' => $loginUrl,
                    'data' => null,
                    'error' => __('SSO is not available.', 'rrze-appointment'),
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
                'data' => $this->normalizeBooker($serverBooker),
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
        if (self::getQueryString(self::LOGIN_FLAG) !== '1') {
            return;
        }

        $returnTo = $this->getLoginReturnUrl();

        if (!class_exists(self::PERMISSIONS_CLASS)) {
            $this->abortLogin(__('SSO is not available.', 'rrze-appointment'));
            return;
        }

        try {
            $permissionsClass = self::PERMISSIONS_CLASS;
            $permissions = new $permissionsClass();
            if (!$this->startAuthentication($permissions, $returnTo)) {
                $this->abortLogin(__('SSO is not available.', 'rrze-appointment'));
                return;
            }

            $this->redirect($returnTo);
        } catch (\Throwable $exception) {
            $this->abortLogin(__('SSO login failed.', 'rrze-appointment'));
        }
    }

    /**
     * Builds the explicit login URL for the public booking dialog.
     */
    private function buildLoginUrl(string $requestedReturnUrl): string
    {
        $homeUrl = home_url('/');
        $referer = wp_get_referer();
        $fallbackUrl = is_string($referer) && $referer !== ''
            ? wp_validate_redirect($referer, $homeUrl)
            : $homeUrl;
        $returnUrl = wp_validate_redirect($requestedReturnUrl, $fallbackUrl);

        return add_query_arg([
            self::LOGIN_FLAG => '1',
            self::RETURN_URL_PARAMETER => $returnUrl,
        ], $homeUrl);
    }

    /**
     * Reads the caller's desired post-login return URL.
     *
     * @param mixed $request REST request or null.
     */
    private function getReturnUrl($request): string
    {
        if ($request instanceof WP_REST_Request) {
            return self::normalizeString($request->get_param(self::REQUEST_RETURN_URL_PARAMETER));
        }

        return self::getRequestString($_POST, self::REQUEST_RETURN_URL_PARAMETER);
    }

    /**
     * Returns a validated destination after the explicit SSO flow.
     */
    private function getLoginReturnUrl(): string
    {
        $requestedReturnUrl = self::getQueryString(self::RETURN_URL_PARAMETER);
        if ($requestedReturnUrl === '') {
            $currentUrl = remove_query_arg([self::LOGIN_FLAG, self::RETURN_URL_PARAMETER]);
            $requestedReturnUrl = is_string($currentUrl) ? $currentUrl : '';
        }

        return wp_validate_redirect($requestedReturnUrl, home_url('/'));
    }

    /**
     * Normalizes the authenticated identity returned by AccessControl.
     *
     * @param array<string, mixed> $booker Passive SSO identity.
     * @return array{bookerEmail: string, bookerName: string}
     */
    private function normalizeBooker(array $booker): array
    {
        return [
            'bookerEmail' => sanitize_email(self::normalizeString($booker['bookerEmail'] ?? '')),
            'bookerName' => sanitize_text_field(self::normalizeString($booker['bookerName'] ?? '')),
        ];
    }

    /**
     * Sends a response in the format expected by REST or legacy AJAX clients.
     *
     * @param array<string, mixed> $response Response payload.
     * @return WP_REST_Response|null
     */
    private function sendResponse(
        array $response,
        bool $isRestRequest,
        bool $success
    ): ?WP_REST_Response {
        if ($isRestRequest) {
            return new WP_REST_Response($response, 200);
        }

        if ($success) {
            wp_send_json_success($response['data']);
            return null;
        }

        wp_send_json_error($response);
        return null;
    }

    /**
     * Starts or resumes authentication through supported AccessControl APIs.
     */
    private function startAuthentication(object $permissions, string $returnTo): bool
    {
        if ($this->isLoggedIn($permissions)) {
            return true;
        }

        $auth = method_exists($permissions, 'simplesamlAuth')
            ? $permissions->simplesamlAuth()
            : null;
        if (is_object($auth)) {
            if (method_exists($auth, 'isAuthenticated') && $auth->isAuthenticated()) {
                return true;
            }
            if (method_exists($auth, 'requireAuth')) {
                $auth->requireAuth(['ReturnTo' => $returnTo, 'KeepPost' => false]);
                return true;
            }
        }

        if (!method_exists($permissions, 'checkSSOLoggedIn')) {
            return false;
        }

        $permissions->checkSSOLoggedIn();
        return true;
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

    /**
     * Redirects to a validated local URL and stops request processing.
     */
    private function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Stops an explicit login request with a generic public error.
     */
    private function abortLogin(string $message): void
    {
        wp_die(esc_html($message), '', ['response' => 500]);
    }

    /**
     * Reads a string from an HTTP request collection.
     *
     * @param array<string, mixed> $source Request values.
     */
    private static function getRequestString(array $source, string $key): string
    {
        return self::normalizeString(wp_unslash($source[$key] ?? ''));
    }

    /**
     * Reads a string query parameter.
     */
    private static function getQueryString(string $key): string
    {
        return self::getRequestString($_GET, $key);
    }

    /**
     * Converts scalar request data to a string and rejects nested values.
     *
     * @param mixed $value Request or integration value.
     */
    private static function normalizeString($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
