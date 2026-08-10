<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class MailTemplate
{
    private const LAYOUT_PATH = 'build/email/layout.html';

    public static function wrap(string $content, string $subject = ''): string
    {
        $siteName = (string) get_bloginfo('name');
        $siteUrl = home_url('/');
        $language = str_replace('_', '-', (string) get_bloginfo('language')) ?: 'de';

        $layout = self::getLayout();

        return strtr($layout, [
            '___RRZE_EMAIL_LANG___' => esc_attr($language),
            '___RRZE_EMAIL_DIR___' => is_rtl() ? 'rtl' : 'ltr',
            '___RRZE_EMAIL_SUBJECT___' => esc_html($subject),
            '___RRZE_EMAIL_SITE_URL___' => esc_url($siteUrl),
            '___RRZE_EMAIL_SITE_NAME___' => esc_html($siteName),
            '___RRZE_EMAIL_LOGO___' => self::getLogoHtml($siteName),
            '___RRZE_EMAIL_CONTENT___' => $content,
            '___RRZE_EMAIL_FOOTER_LINKS___' => self::getFooterLinksHtml(),
        ]);
    }

    /**
     * Creates an appointment details table that also works in classic Outlook.
     *
     * @param array<string, string> $rows Label/value pairs. Values may contain
     *                                   the plugin's mail placeholders.
     */
    public static function detailsTable(array $rows): string
    {
        $body = '';
        foreach ($rows as $label => $value) {
            $body .= '<tr>'
                . '<th scope="row" style="width:34%;padding:10px 12px;border-bottom:1px solid #e5e9ef;color:#5f6b7a;font-size:13px;font-weight:600;line-height:20px;text-align:left;vertical-align:top;">'
                . esc_html($label)
                . '</th>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #e5e9ef;color:#1f2937;font-size:15px;line-height:22px;text-align:left;vertical-align:top;">'
                . $value
                . '</td>'
                . '</tr>';
        }

        return '<table class="rrze-email-details" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:20px 0;border-collapse:collapse;">'
            . $body
            . '</table>';
    }

    public static function actionButton(string $url, string $label): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 16px;">'
            . '<tr><td bgcolor="#04316a" style="border-radius:4px;background:#04316a;">'
            . '<a href="' . esc_attr($url) . '" target="_blank" style="display:inline-block;padding:12px 20px;color:#ffffff;font-size:15px;font-weight:700;line-height:20px;text-decoration:none;">'
            . esc_html($label)
            . '</a></td></tr></table>';
    }

    private static function getLayout(): string
    {
        $path = dirname(__DIR__) . '/' . self::LAYOUT_PATH;
        if (is_readable($path)) {
            $layout = file_get_contents($path);
            if (is_string($layout) && strpos($layout, '___RRZE_EMAIL_CONTENT___') !== false) {
                return $layout;
            }
        }

        return self::getFallbackLayout();
    }

    private static function getLogoHtml(string $siteName): string
    {
        if (has_custom_logo()) {
            $logoId = (int) get_theme_mod('custom_logo');
            $logoSrc = wp_get_attachment_image_url($logoId, 'medium');
            if ($logoSrc) {
                return '<img src="' . esc_url($logoSrc) . '" width="200" alt="' . esc_attr($siteName) . '" style="display:block;width:auto;max-width:200px;height:auto;max-height:64px;border:0;outline:none;text-decoration:none;">';
            }
        }

        return '<span style="display:inline-block;color:#1f2937;font-size:20px;font-weight:700;line-height:28px;">'
            . esc_html($siteName)
            . '</span>';
    }

    private static function getFooterLinksHtml(): string
    {
        $links = [];
        $linkStyle = 'color:#04316a;font-weight:600;text-decoration:none;';

        $privacyUrl = get_privacy_policy_url();
        if ($privacyUrl) {
            $links[] = '<a href="' . esc_url($privacyUrl) . '" target="_blank" style="' . $linkStyle . '">'
                . esc_html__('Privacy Policy', 'rrze-appointment')
                . '</a>';
        }

        $imprintUrl = TokenManager::imprintUrl();
        if ($imprintUrl) {
            $links[] = '<a href="' . esc_url($imprintUrl) . '" target="_blank" style="' . $linkStyle . '">'
                . esc_html__('Legal Notice', 'rrze-appointment')
                . '</a>';
        }

        return implode(' &nbsp;&middot;&nbsp; ', $links);
    }

    private static function getFallbackLayout(): string
    {
        return '<!DOCTYPE html><html lang="___RRZE_EMAIL_LANG___" dir="___RRZE_EMAIL_DIR___"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>___RRZE_EMAIL_SUBJECT___</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f3f5f7;">'
            . '<tr><td align="center" style="padding:32px 16px;"><table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;">'
            . '<tr><td style="padding:28px 32px 24px;border:1px solid #d7dde5;border-bottom:0;background:#ffffff;">'
            . '<a href="___RRZE_EMAIL_SITE_URL___" style="text-decoration:none;">___RRZE_EMAIL_LOGO___</a></td></tr>'
            . '<tr><td style="padding:28px 32px 32px;border:1px solid #d7dde5;border-top:4px solid #04316a;background:#ffffff;">'
            . '<p style="margin:0 0 8px;color:#04316a;font-size:12px;font-weight:700;text-transform:uppercase;">___RRZE_EMAIL_SITE_NAME___</p>'
            . '<h1 style="margin:0 0 24px;color:#1f2937;font-size:28px;line-height:36px;">___RRZE_EMAIL_SUBJECT___</h1>'
            . '<div style="font-size:15px;line-height:24px;">___RRZE_EMAIL_CONTENT___</div></td></tr>'
            . '<tr><td style="padding:20px 32px;border:1px solid #d7dde5;border-top:0;background:#e9f2fb;color:#5f6b7a;font-size:12px;line-height:20px;">'
            . '<p style="margin:0 0 4px;"><a href="___RRZE_EMAIL_SITE_URL___" style="color:#04316a;font-weight:600;text-decoration:none;">___RRZE_EMAIL_SITE_NAME___</a></p>'
            . '___RRZE_EMAIL_FOOTER_LINKS___</td></tr></table></td></tr></table></body></html>';
    }
}
