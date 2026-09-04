<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Configuration\PluginSettings;
use function RRZE\Appointment\plugin;

defined('ABSPATH') || exit;

/**
 * Handles appointment administration and host-initiated cancellations.
 */
final class BookingsPage
{
    public const PAGE_SLUG = 'rrze-appointment-bookings';
    private const ADMIN_MENU_ICON_PATH = 'assets/svg/approval_delegation_24dp_1F1F1F_FILL0_wght400_GRAD0_opsz24.svg';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'handleCancelPost']);
    }

    public function addMenuPage(): void
    {
        add_menu_page(
            __('Appointments', 'rrze-appointment'),
            __('Appointments', 'rrze-appointment'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            self::getAdminMenuIcon(),
            30
        );
    }

    public static function screenHook(): string
    {
        return 'toplevel_page_' . self::PAGE_SLUG;
    }

    private static function getAdminMenuIcon(): string
    {
        $iconPath = plugin()->getPath() . self::ADMIN_MENU_ICON_PATH;
        if (!is_readable($iconPath)) {
            return 'dashicons-calendar-alt';
        }

        $svg = file_get_contents($iconPath);
        if (!is_string($svg) || $svg === '') {
            return 'dashicons-calendar-alt';
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Processes an administrator's booking cancellation request.
     */
    public function handleCancelPost(): void
    {
        if (
            Request::postText('rrze_appt_action', true) !== 'cancel'
            || !current_user_can('manage_options')
        ) {
            return;
        }

        check_admin_referer('rrze_appt_cancel', 'rrze_appt_cancel_nonce');

        $slot = Request::postText('cancel_slot');
        $reason = Request::postTextarea('cancellation_reason');
        if ($slot !== '') {
            Bookings::cancel($slot, $reason);
        }

        wp_redirect(add_query_arg([
            'page' => self::PAGE_SLUG,
            'cancelled' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Renders the bookings administration page.
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap rrze-appointment-settings-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Appointments', 'rrze-appointment'); ?></h1>
            <hr class="wp-header-end">
            <?php $this->renderBookings(); ?>
        </div>
        <?php
    }

    private function renderBookings(): void
    {
        if (Request::hasQueryFlag('cancelled')) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Booking cancelled and cancellation emails sent.', 'rrze-appointment') . '</p></div>';
        }

        // Filter
        $filterDate = Request::queryText('filter_date');
        $filterDateTo = Request::queryText('filter_date_to');
        $filterPerson = Request::queryInt('filter_person');
        $filterArgs = array_filter([
            'date_from' => $filterDate,
            'date_to' => $filterDateTo,
            'person_id' => $filterPerson ?: null,
        ]);
        $bookings = Bookings::getAll($filterArgs);
        $persons = Bookings::getPersonsFromBookings();
        $cancellationReasonEnabled = (bool) PluginSettings::get('cancellation_reason_enabled');
        $baseUrl = add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php'));
        ?>
        <form method="get" action="" style="margin-bottom:1rem;display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
            <label>
                <?php esc_html_e('From', 'rrze-appointment'); ?>
                <input type="date" name="filter_date" value="<?php echo esc_attr($filterDate); ?>">
            </label>
            <label>
                <?php esc_html_e('To', 'rrze-appointment'); ?>
                <input type="date" name="filter_date_to" value="<?php echo esc_attr($filterDateTo); ?>">
            </label>
            <?php if (!empty($persons)) : ?>
            <label>
                <?php esc_html_e('Person', 'rrze-appointment'); ?>
                <select name="filter_person">
                    <option value="0"><?php esc_html_e('All', 'rrze-appointment'); ?></option>
                    <?php foreach ($persons as $pid => $pname) : ?>
                        <option value="<?php echo esc_attr($pid); ?>"<?php selected($filterPerson, $pid); ?>><?php echo esc_html($pname); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'rrze-appointment'); ?></button>
            <a href="<?php echo esc_url($baseUrl); ?>" class="button"><?php esc_html_e('Reset', 'rrze-appointment'); ?></a>
        </form>

        <?php if (empty($bookings)) : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Time', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Title', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Person', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Booker', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Email', 'rrze-appointment'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="7"><?php esc_html_e('No appointments found.', 'rrze-appointment'); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Time', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Title', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Person', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Booker', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Email', 'rrze-appointment'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b) :
                        $dateFormatted = date_i18n(get_option('date_format'), strtotime($b['date']));
                    ?>
                    <tr>
                        <td><?php echo esc_html($dateFormatted); ?></td>
                        <td><?php echo esc_html(str_replace('-', ' – ', $b['time'])); ?></td>
                        <td><?php echo esc_html($b['title']); ?></td>
                        <td><?php echo esc_html($b['person_name']); ?></td>
                        <td><?php echo esc_html($b['booker_name']); ?></td>
                        <td><?php echo esc_html($b['booker_email']); ?></td>
                        <td>
                            <?php if ($cancellationReasonEnabled) : ?>
                                <details class="rrze-appt-cancellation">
                                    <summary class="button button-small">
                                        <?php esc_html_e('Cancel booking', 'rrze-appointment'); ?>
                                    </summary>
                                    <form method="post" action="" class="rrze-appt-cancellation__form">
                                        <?php wp_nonce_field('rrze_appt_cancel', 'rrze_appt_cancel_nonce'); ?>
                                        <input type="hidden" name="rrze_appt_action" value="cancel">
                                        <input type="hidden" name="cancel_slot" value="<?php echo esc_attr($b['slot']); ?>">
                                        <label for="rrze-appt-cancellation-reason-<?php echo esc_attr(md5($b['slot'])); ?>">
                                            <?php esc_html_e('Reason for cancellation', 'rrze-appointment'); ?>
                                            <span class="description"><?php esc_html_e('(optional)', 'rrze-appointment'); ?></span>
                                        </label>
                                        <textarea
                                            id="rrze-appt-cancellation-reason-<?php echo esc_attr(md5($b['slot'])); ?>"
                                            name="cancellation_reason"
                                            rows="3"
                                            maxlength="<?php echo esc_attr(Bookings::MAX_CANCELLATION_REASON_LENGTH); ?>"
                                        ></textarea>
                                        <p class="description">
                                            <?php esc_html_e('This reason will be included in the cancellation email.', 'rrze-appointment'); ?>
                                        </p>
                                        <button type="submit" class="button button-small button-link-delete">
                                            <?php esc_html_e('Cancel booking', 'rrze-appointment'); ?>
                                        </button>
                                    </form>
                                </details>
                            <?php else : ?>
                                <form method="post" action="" style="display:inline;">
                                    <?php wp_nonce_field('rrze_appt_cancel', 'rrze_appt_cancel_nonce'); ?>
                                    <input type="hidden" name="rrze_appt_action" value="cancel">
                                    <input type="hidden" name="cancel_slot" value="<?php echo esc_attr($b['slot']); ?>">
                                    <button
                                        type="submit"
                                        class="button button-small"
                                        onclick="return confirm('<?php esc_attr_e('Really cancel this booking?', 'rrze-appointment'); ?>')"
                                    >
                                        <?php esc_html_e('Cancel booking', 'rrze-appointment'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }
}
