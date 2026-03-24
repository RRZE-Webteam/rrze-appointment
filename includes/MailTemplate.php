<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class MailTemplate
{
    /**
     * Bettet den Inhaltsbereich in das feste HTML-Mail-Template ein.
     */
    public static function wrap(string $content, string $subject = ''): string
    {
        $siteName = esc_html(get_bloginfo('name'));
        $siteUrl  = esc_url(home_url());

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

      <!-- Wrapper -->
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="background:#003865;padding:24px 32px;border-radius:4px 4px 0 0;">
            <p style="margin:0;font-size:20px;font-weight:bold;color:#ffffff;letter-spacing:0.02em;">'
                . $siteName .
            '</p>
          </td>
        </tr>

        <!-- Content -->
        <tr>
          <td style="background:#ffffff;padding:32px;border-left:1px solid #dcdcde;border-right:1px solid #dcdcde;">
            <div style="font-size:15px;line-height:1.6;color:#1e1e1e;">'
                . $content .
            '</div>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#003865;padding:16px 32px;border-radius:0 0 4px 4px;">
            <p style="margin:0;font-size:12px;color:#c8d8e8;">
              <a href="' . $siteUrl . '" style="color:#c8d8e8;text-decoration:none;">' . $siteName . '</a>
              &nbsp;&middot;&nbsp;
              <a href="' . $siteUrl . '" style="color:#c8d8e8;text-decoration:none;">' . $siteUrl . '</a>
            </p>
          </td>
        </tr>

      </table>
      <!-- /Wrapper -->

    </td>
  </tr>
</table>
</body>
</html>';
    }
}
