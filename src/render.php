<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

$attributes = is_array($attributes ?? null) ? $attributes : [];
$slots = SlotGenerator::fromAttributes($attributes);

$title = (string) ($attributes['title'] ?? '');
$location = (string) ($attributes['location'] ?? '');
$description = (string) ($attributes['description'] ?? '');
$personId = (int) ($attributes['personId'] ?? 0);
$personEmail = (string) ($attributes['personEmail'] ?? '');
$tplId = (int) ($attributes['tplId'] ?? 0);
$bookingCutoff = (int) ($attributes['bookingCutoff'] ?? 0);
$requireMessage = !empty($attributes['requireMessage']);
$locationUrl = (string) ($attributes['locationUrl'] ?? '');
$color = (string) ($attributes['color'] ?? '');
$style = (string) ($attributes['style'] ?? 'light');

$classes = ['rrze-appointment'];
$classes[] = $style === 'dark' ? 'is-style-dark' : 'is-style-light';
if ($color !== '') {
    $classes[] = 'is-' . sanitize_html_class($color);
}

$groups = [];
foreach ($slots as $slotValue) {
    [$date, $timeRange] = array_pad(explode(' ', $slotValue, 2), 2, '');
    if ($date === '' || $timeRange === '') {
        continue;
    }
    if (!isset($groups[$date])) {
        $groups[$date] = [];
    }
    $groups[$date][] = [
        'value' => $slotValue,
        'timeRange' => str_replace('-', ' - ', $timeRange),
    ];
}
ksort($groups);
foreach ($groups as &$dateSlots) {
    usort(
        $dateSlots,
        static fn(array $a, array $b): int => strcmp($a['value'], $b['value'])
    );
}
unset($dateSlots);

$locationIsUrl = preg_match('#^https?://#i', $location) === 1;
?>
<form
    class="<?php echo esc_attr(implode(' ', array_filter($classes))); ?>"
    method="post"
    action=""
    data-title="<?php echo esc_attr($title); ?>"
    data-location="<?php echo esc_attr($location); ?>"
    data-person-id="<?php echo esc_attr((string) $personId); ?>"
    data-person-email="<?php echo esc_attr($personEmail); ?>"
    data-tpl-id="<?php echo esc_attr((string) $tplId); ?>"
    data-booking-cutoff="<?php echo esc_attr((string) $bookingCutoff); ?>"
    data-require-message="<?php echo $requireMessage ? '1' : '0'; ?>"
>
    <fieldset class="rrze-appointment__fieldset">
        <?php if ($title !== '') : ?>
            <legend class="rrze-appointment__title"><?php echo esc_html($title); ?></legend>
        <?php endif; ?>

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

        <?php if (!empty($groups)) : ?>
            <div class="rrze-appointment__calendar"></div>

            <div class="rrze-appointment__day-slots is-hidden">
                <p class="rrze-appointment__day-slots-title"><?php echo esc_html__('Times on selected day', 'rrze-appointment'); ?></p>
                <div class="rrze-appointment__day-slots-list"></div>
            </div>

            <div class="rrze-appointment__accordion rrze-appointment__slots-grouped" data-accordion="open">
                <button type="button" class="rrze-appointment__accordion-toggle" aria-expanded="true">
                    <?php echo esc_html__('All appointments', 'rrze-appointment'); ?>
                </button>
                <div class="rrze-appointment__accordion-content">
                    <?php
                    $groupIndex = 0;
                    foreach ($groups as $date => $dateSlots) :
                        $isOpen = $groupIndex === 0;
                        ?>
                        <div class="rrze-appointment__date-group" data-accordion="<?php echo $isOpen ? 'open' : 'closed'; ?>">
                            <button type="button" class="rrze-appointment__date-group-toggle" aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>">
                                <?php echo esc_html(wp_date(get_option('date_format'), strtotime($date))); ?>
                            </button>
                            <div class="rrze-appointment__slot-grid" data-date="<?php echo esc_attr($date); ?>">
                                <?php foreach ($dateSlots as $slot) : ?>
                                    <div class="rrze-appointment__slot-item">
                                        <input
                                            class="rrze-appointment__slot-radio"
                                            type="radio"
                                            name="rrze_appointment_slot"
                                            value="<?php echo esc_attr($slot['value']); ?>"
                                            data-label="<?php echo esc_attr($slot['timeRange']); ?>"
                                            required
                                        />
                                        <button type="button" class="rrze-appointment__slot-button" data-slot-value="<?php echo esc_attr($slot['value']); ?>">
                                            <?php echo esc_html($slot['timeRange']); ?>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php
                        $groupIndex++;
                    endforeach;
                    ?>
                </div>
            </div>

            <div class="rrze-appointment__selected-info is-hidden" aria-live="polite"></div>
        <?php else : ?>
            <p class="rrze-appointment__missing-slot"><?php echo esc_html__('No time slots available.', 'rrze-appointment'); ?></p>
        <?php endif; ?>
    </fieldset>
</form>
<?php
