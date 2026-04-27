<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class MailTemplate
{
    public static function wrap(string $content, string $subject = ''): string
    {
        $siteName   = get_bloginfo('name');
        $siteUrl    = home_url('/');

        // Logo
        $logoHtml = '';
        if (has_custom_logo()) {
            $logoId  = get_theme_mod('custom_logo');
            $logoSrc = wp_get_attachment_image_url($logoId, 'medium');
            if ($logoSrc) {
                $logoHtml = '<img src="' . esc_url($logoSrc) . '" alt="' . esc_attr($siteName) . '" style="max-height:60px;max-width:200px;display:block;">';
            }
        }
        if (!$logoHtml) {
            $logoHtml = '<span style="font-size:20px;font-weight:bold;color:#1e1e1e;">' . esc_html($siteName) . '</span>';
        }

        // Footer-Links
        $footerLinks = [];

        $privacyUrl = get_privacy_policy_url();
        if ($privacyUrl) {
            $footerLinks[] = '<a href="' . esc_url($privacyUrl) . '" style="color:#1e3a8a;text-decoration:none;">' . esc_html__('Privacy Policy', 'rrze-appointment') . '</a>';
        }

        $imprintUrl = TokenManager::imprintUrl();
        if ($imprintUrl) {
            $footerLinks[] = '<a href="' . esc_url($imprintUrl) . '" style="color:#1e3a8a;text-decoration:none;">' . esc_html__('Legal Notice', 'rrze-appointment') . '</a>';
        }

        $footerLinksHtml = implode(' &nbsp;&middot;&nbsp; ', $footerLinks);

        return '<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . esc_html($subject) . '</title>
</head>
<body style="margin:0;padding:0;background:#f1f1f1;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f1f1;">
  <tr>
    <td align="center" style="padding:32px 16px;">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="padding:24px 32px;border-radius:4px 4px 0 0;">
            <a href="' . esc_url($siteUrl) . '" style="text-decoration:none;">' . $logoHtml . '</a>
          </td>
        </tr>

        <!-- Content -->
        <tr>
          <td style="background:#ffffff;padding:32px;border-left:1px solid #dcdcde;border-right:1px solid #dcdcde;">
            <div style="font-size:15px;line-height:1.6;color:#1e1e1e;">' . $content . '</div>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="padding:16px 32px;border-radius:0 0 4px 4px;">
            <p style="margin:0 0 6px;font-size:12px;color:#3c434a;">
              <a href="' . esc_url($siteUrl) . '" style="color:#1e3a8a;text-decoration:none;">' . esc_html($siteName) . '</a>
            </p>
            ' . ($footerLinksHtml ? '<p style="margin:0;font-size:12px;color:#3c434a;">' . $footerLinksHtml . '</p>' : '') . '
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>';
    }
}
