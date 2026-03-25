<?php

namespace RRZE\Appointment\Common\Settings;

defined('ABSPATH') || exit;



?>
<tr>
    <td colspan="2">
        <?php
        if (file_exists($option->getArg('default'))) {
            $lines = file($option->getArg('default'));
            if ($lines !== false) {
                echo '<style> .settings_page_rrze-answers #faqlog .form-table th {width:0;}</style><table class="wp-list-table widefat striped"><tbody>';
                foreach ($lines as $line) {
                    echo wp_kses_post('<tr><td>' . $line . '</td></tr>');
                }
                echo '</tbody></table>';
            } else {
                echo esc_html(__('Logfile is empty.', 'rrze-appointment'));
            }
        } else {
            echo esc_html(__('Logfile is empty.', 'rrze-appointment'));
        }
        ?>
    </td>
</tr>