<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Booking\AppointmentBlock;
use RRZE\Appointment\Booking\MonthlyAvailability;

defined('ABSPATH') || exit;

$attributes = is_array($attributes ?? null) ? $attributes : [];

$sourceAttributes = $attributes;
if (
    isset($block)
    && $block instanceof \WP_Block
    && is_array($block->parsed_block['attrs'] ?? null)
) {
    $sourceAttributes = $block->parsed_block['attrs'];
}
$postId = isset($block) && $block instanceof \WP_Block
    ? (int) ($block->context['postId'] ?? 0)
    : 0;
if ($postId <= 0) {
    $postId = (int) get_the_ID();
}
$blockFingerprint = AppointmentBlock::fingerprint($sourceAttributes);
$calendarPost = $postId > 0 ? get_post($postId) : null;
$readOnly = $calendarPost instanceof \WP_Post && $calendarPost->post_status === 'private';
$preview = is_preview() && current_user_can('edit_post', $postId);
if ($calendarPost instanceof \WP_Post && ($readOnly || !$preview) && !AppointmentBlock::canViewCalendar($calendarPost)) {
    return;
}
$instanceId = wp_unique_prefixed_id('rrze_appt_month_' . $postId . '_' . $blockFingerprint . '_');
$requestedMonth = $_GET[$instanceId] ?? '';

$loadError = false;
try {
    $calendar = (new MonthlyAvailability())->initial($attributes, $preview, is_string($requestedMonth) ? $requestedMonth : '');
} catch (AppointmentException $exception) {
    $calendar = [];
    $loadError = true;
}
$linked = $requestedMonth === ($calendar['months'][0] ?? null);
$links = array_filter($calendar['links'] ?? []);
unset($calendar['links']);

$title = (string) ($attributes['title'] ?? '');
$location = (string) ($attributes['location'] ?? '');
$description = (string) ($attributes['description'] ?? '');
$bookingCutoff = (int) ($attributes['bookingCutoff'] ?? 0);
$bookingMaxAdvance = $readOnly ? 0 : max(0, (int) ($attributes['bookingMaxAdvance'] ?? 0));
$disableSso = !empty($attributes['disableSso']);
$hideWeekends = !empty($attributes['hideWeekends']);
$showSlotsAsList = !empty($attributes['showSlotsAsList']);
$locationUrl = (string) ($attributes['locationUrl'] ?? '');
$color = (string) ($attributes['color'] ?? '');
$style = (string) ($attributes['style'] ?? 'light');

$classes = ['rrze-appointment'];
$classes[] = $style === 'dark' ? 'is-style-dark' : 'is-style-light';
if ($color !== '') {
    $classes[] = 'is-' . sanitize_html_class($color);
}

$locationIsUrl = preg_match('#^https?://#i', $location) === 1;
?>
<form
    id="<?php echo esc_attr($instanceId); ?>"
    class="<?php echo esc_attr(implode(' ', array_filter($classes))); ?>"
    method="post"
    action=""
    data-post-id="<?php echo esc_attr((string) $postId); ?>"
    data-block-id="<?php echo esc_attr($blockFingerprint); ?>"
    data-booking-cutoff="<?php echo esc_attr((string) $bookingCutoff); ?>"
    data-booking-max-advance="<?php echo esc_attr((string) $bookingMaxAdvance); ?>"
    data-disable-sso="<?php echo $disableSso ? '1' : '0'; ?>"
    data-hide-weekends="<?php echo $hideWeekends ? '1' : '0'; ?>"
    data-show-slots-as-list="<?php echo $showSlotsAsList ? '1' : '0'; ?>"
    data-read-only="<?php echo $readOnly ? '1' : '0'; ?>"
>
    <fieldset class="rrze-appointment__fieldset">
        <legend class="<?php echo $title !== '' ? 'rrze-appointment__title' : 'rrze-appointment__visually-hidden'; ?>">
            <?php echo esc_html($title !== '' ? $title : __('Appointment booking', 'rrze-appointment')); ?>
        </legend>

        <?php if ($description !== '') : ?>
            <p class="rrze-appointment__description"><?php echo wp_kses($description, ['br' => []]); ?></p>
        <?php endif; ?>

        <?php if ($location !== '') : ?>
            <p class="rrze-appointment__location">
                <?php echo esc_html__('Location:', 'rrze-appointment'); ?>
                <?php if ($locationIsUrl) : ?>
                    <a href="<?php echo esc_url($location); ?>"><?php echo esc_html($location); ?></a>
                <?php elseif ($locationUrl !== '') : ?>
                    <a href="<?php echo esc_url($locationUrl); ?>"><?php echo esc_html($location); ?></a>
                <?php else : ?>
                    <?php echo esc_html($location); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($calendar['months'])) : ?>
            <?php if (!$showSlotsAsList) : ?>
                <ul
                    class="rrze-appointment__calendar-legend"
                    aria-label="<?php echo esc_attr__('Calendar legend', 'rrze-appointment'); ?>"
                >
                    <li class="rrze-appointment__calendar-legend-item">
                        <span class="rrze-appointment__calendar-legend-swatch is-available" aria-hidden="true"></span>
                        <span><?php esc_html_e('Available', 'rrze-appointment'); ?></span>
                    </li>
                    <li class="rrze-appointment__calendar-legend-item">
                        <span class="rrze-appointment__calendar-legend-swatch is-selected" aria-hidden="true"></span>
                        <span><?php esc_html_e('Selected', 'rrze-appointment'); ?></span>
                    </li>
                    <?php if ($bookingMaxAdvance > 0) : ?>
                        <li class="rrze-appointment__calendar-legend-item">
                            <span class="rrze-appointment__calendar-legend-swatch is-not-open" aria-hidden="true"></span>
                            <span><?php esc_html_e('Not yet bookable', 'rrze-appointment'); ?></span>
                        </li>
                    <?php endif; ?>
                    <li class="rrze-appointment__calendar-legend-item">
                        <span class="rrze-appointment__calendar-legend-swatch is-unavailable" aria-hidden="true"></span>
                        <span><?php esc_html_e('Past or booked', 'rrze-appointment'); ?></span>
                    </li>
                    <li class="rrze-appointment__calendar-legend-item">
                        <span class="rrze-appointment__calendar-legend-swatch is-today" aria-hidden="true"></span>
                        <span><?php esc_html_e('Today', 'rrze-appointment'); ?></span>
                    </li>
                </ul>
                <div class="rrze-appointment__calendar"></div>
            <?php endif; ?>

            <p class="rrze-appointment__availability-status is-hidden" role="status" aria-live="polite" tabindex="-1"></p>

            <?php if ($showSlotsAsList) : ?>
                <div class="rrze-appointment__date-list"></div>
            <?php else : ?>
                <div class="rrze-appointment__day-slots is-hidden">
                    <h3 class="rrze-appointment__day-slots-title"><?php echo esc_html__('Times on selected day', 'rrze-appointment'); ?></h3>
                    <div class="rrze-appointment__day-slots-list"></div>
                </div>
            <?php endif; ?>

            <div class="rrze-appointment__slot-data" hidden>
                <script type="application/json"><?php echo wp_json_encode($calendar, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
                <?php if (!$readOnly && $calendar['slots']) : ?>
                    <input type="hidden" name="rrze_appointment_slot" />
                    <?php foreach ($calendar['slots'] as $slot => $timestamp) : ?>
                        <input type="radio" name="rrze_appointment_slot" value="<?php echo esc_attr($slot); ?>" />
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if (!$calendar['slots']) : ?>
                <p class="rrze-appointment__fallback-times"><?php esc_html_e('No time slots available.', 'rrze-appointment'); ?></p>
            <?php elseif ($readOnly) : ?>
                <?php
                $timesByDay = [];
                foreach ($calendar['slots'] as $slot => $timestamp) {
                    $timesByDay[substr($slot, 0, 10)][] = str_replace('-', ' – ', substr($slot, 11));
                }
                ?>
                <dl class="rrze-appointment__fallback-times">
                    <?php foreach ($timesByDay as $date => $times) : ?>
                        <dt><time datetime="<?php echo esc_attr($date); ?>"><?php echo esc_html(mysql2date(get_option('date_format'), $date)); ?></time></dt>
                        <dd><?php echo esc_html(implode(', ', $times)); ?></dd>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?php if ($links) : ?>
                <nav class="rrze-appointment__month-links" <?php if ($linked) : ?>data-linked-page<?php endif; ?> aria-label="<?php esc_attr_e('Choose an appointment date', 'rrze-appointment'); ?>">
                    <?php foreach ($links as $direction => $month) : ?>
                        <a href="<?php echo esc_url(add_query_arg($instanceId, $month) . '#' . $instanceId); ?>"><?php
                            echo esc_html($direction === 'previous' ? __('Previous month', 'rrze-appointment') : __('Next month', 'rrze-appointment'));
                            echo ' ' . esc_html(mysql2date('F Y', $month . '-01'));
                        ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <?php if ($showSlotsAsList) : ?>
                <button type="button" class="rrze-appointment__more" hidden><?php esc_html_e('More appointments', 'rrze-appointment'); ?></button>
            <?php endif; ?>
            <button type="button" class="rrze-appointment__retry" hidden><?php esc_html_e('Try again', 'rrze-appointment'); ?></button>

            <p class="rrze-appointment__selected-info is-hidden" role="status" aria-live="polite"></p>
        <?php else : ?>
            <p class="rrze-appointment__missing-slot"><?php echo $loadError ? esc_html__('Unable to load appointments. Please reload the page.', 'rrze-appointment') : esc_html__('No time slots available.', 'rrze-appointment'); ?></p>
        <?php endif; ?>
    </fieldset>
</form>
<?php
