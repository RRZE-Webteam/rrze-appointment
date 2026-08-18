<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

$attributes = is_array($attributes ?? null) ? $attributes : [];
$slots = SlotGenerator::fromAttributes($attributes);

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

$title = (string) ($attributes['title'] ?? '');
$location = (string) ($attributes['location'] ?? '');
$description = (string) ($attributes['description'] ?? '');
$bookingCutoff = (int) ($attributes['bookingCutoff'] ?? 0);
$disableSso = !empty($attributes['disableSso']);
$hideWeekends = !empty($attributes['hideWeekends']);
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
    class="<?php echo esc_attr(implode(' ', array_filter($classes))); ?>"
    method="post"
    action=""
    data-post-id="<?php echo esc_attr((string) $postId); ?>"
    data-block-id="<?php echo esc_attr($blockFingerprint); ?>"
    data-booking-cutoff="<?php echo esc_attr((string) $bookingCutoff); ?>"
    data-disable-sso="<?php echo $disableSso ? '1' : '0'; ?>"
    data-hide-weekends="<?php echo $hideWeekends ? '1' : '0'; ?>"
>
    <fieldset class="rrze-appointment__fieldset">
        <legend class="<?php echo $title !== '' ? 'rrze-appointment__title' : 'rrze-appointment__visually-hidden'; ?>">
            <?php echo esc_html($title !== '' ? $title : __('Appointment booking', 'rrze-appointment')); ?>
        </legend>

        <?php if ($description !== '') : ?>
            <p class="rrze-appointment__description"><?php echo esc_html($description); ?></p>
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

        <?php if (!empty($slots)) : ?>
            <div class="rrze-appointment__calendar"></div>

            <p class="rrze-appointment__availability-status is-hidden" role="status" aria-live="polite"></p>

            <div class="rrze-appointment__day-slots is-hidden">
                <h3 class="rrze-appointment__day-slots-title"><?php echo esc_html__('Times on selected day', 'rrze-appointment'); ?></h3>
                <div class="rrze-appointment__day-slots-list"></div>
            </div>

            <div class="rrze-appointment__slot-data" hidden aria-hidden="true">
                <?php foreach ($slots as $slotValue) : ?>
                    <?php
                    [, $timeRange] = array_pad(explode(' ', $slotValue, 2), 2, '');
                    if ($timeRange === '') {
                        continue;
                    }
                    ?>
                    <input
                        type="radio"
                        name="rrze_appointment_slot"
                        value="<?php echo esc_attr($slotValue); ?>"
                        data-label="<?php echo esc_attr(str_replace('-', ' - ', $timeRange)); ?>"
                    />
                <?php endforeach; ?>
            </div>

            <p class="rrze-appointment__selected-info is-hidden" role="status" aria-live="polite"></p>
        <?php else : ?>
            <p class="rrze-appointment__missing-slot"><?php echo esc_html__('No time slots available.', 'rrze-appointment'); ?></p>
        <?php endif; ?>
    </fieldset>
</form>
<?php
