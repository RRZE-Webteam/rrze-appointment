<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

final class AppointmentBlock
{
    private const BLOCK_NAME = 'rrze/appointment';

    /**
     * Build a stable selector for a published appointment block.
     *
     * The selector is not trusted as booking data. It only identifies the block
     * whose attributes are loaded again from the published post.
     */
    public static function fingerprint(array $attributes): string
    {
        $normalized = self::sortRecursively($attributes);
        return hash_hmac(
            'sha256',
            (string) wp_json_encode($normalized),
            wp_salt('nonce')
        );
    }

    /**
     * Resolve and validate the booking context from a published post.
     *
     * @return array|\WP_Error
     */
    public static function resolvePublished(int $postId, string $fingerprint, string $slot)
    {
        if ($postId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            return new \WP_Error(
                'rrze_appointment_invalid_block_reference',
                __('The appointment block could not be identified.', 'rrze-appointment')
            );
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return new \WP_Error(
                'rrze_appointment_unpublished_post',
                __('This appointment is not available from a published post.', 'rrze-appointment')
            );
        }

        $matches = [];
        $visitedReusableBlocks = [];
        self::collectMatchingBlocks(
            parse_blocks($post->post_content),
            $fingerprint,
            $matches,
            $visitedReusableBlocks
        );

        if (empty($matches)) {
            return new \WP_Error(
                'rrze_appointment_block_not_found',
                __('The appointment block is no longer available.', 'rrze-appointment')
            );
        }

        foreach ($matches as $rawAttributes) {
            $attributes = self::prepareAttributes($rawAttributes);
            if (!in_array($slot, SlotGenerator::fromAttributes($attributes), true)) {
                continue;
            }

            $context = self::validateContext($post, $attributes, $slot);
            if (!is_wp_error($context)) {
                return $context;
            }

            return $context;
        }

        return new \WP_Error(
            'rrze_appointment_invalid_slot',
            __('This appointment is not part of the published appointment block.', 'rrze-appointment')
        );
    }

    private static function collectMatchingBlocks(
        array $blocks,
        string $fingerprint,
        array &$matches,
        array &$visitedReusableBlocks
    ): void {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $blockName = (string) ($block['blockName'] ?? '');
            $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            if (
                $blockName === self::BLOCK_NAME
                && hash_equals($fingerprint, self::fingerprint($attributes))
            ) {
                $matches[] = $attributes;
            }

            if ($blockName === 'core/block') {
                $reusableBlockId = (int) ($attributes['ref'] ?? 0);
                if ($reusableBlockId > 0 && empty($visitedReusableBlocks[$reusableBlockId])) {
                    $visitedReusableBlocks[$reusableBlockId] = true;
                    $reusableBlock = get_post($reusableBlockId);
                    if (
                        $reusableBlock instanceof \WP_Post
                        && $reusableBlock->post_type === 'wp_block'
                        && $reusableBlock->post_status === 'publish'
                    ) {
                        self::collectMatchingBlocks(
                            parse_blocks($reusableBlock->post_content),
                            $fingerprint,
                            $matches,
                            $visitedReusableBlocks
                        );
                    }
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::collectMatchingBlocks(
                    $block['innerBlocks'],
                    $fingerprint,
                    $matches,
                    $visitedReusableBlocks
                );
            }
        }
    }

    private static function prepareAttributes(array $attributes): array
    {
        $blockType = \WP_Block_Type_Registry::get_instance()->get_registered(self::BLOCK_NAME);
        if (!$blockType instanceof \WP_Block_Type) {
            return $attributes;
        }

        return $blockType->prepare_attributes_for_render($attributes);
    }

    /**
     * @return array|\WP_Error
     */
    private static function validateContext(\WP_Post $post, array $attributes, string $slot)
    {
        $bookingCutoff = max(0, (int) ($attributes['bookingCutoff'] ?? 0));
        $slotStart = self::getSlotStart($slot);
        if (
            !$slotStart
            || $slotStart->getTimestamp() <= current_datetime()->getTimestamp() + ($bookingCutoff * MINUTE_IN_SECONDS)
        ) {
            return new \WP_Error(
                'rrze_appointment_booking_cutoff',
                __('This appointment can no longer be booked.', 'rrze-appointment')
            );
        }

        $personId = (int) ($attributes['personId'] ?? 0);
        if ($personId < 0) {
            return new \WP_Error(
                'rrze_appointment_invalid_host',
                __('The configured host is invalid.', 'rrze-appointment')
            );
        }

        $personName = sanitize_text_field((string) ($attributes['personName'] ?? ''));
        $personEmailValue = trim((string) ($attributes['personEmail'] ?? ''));
        if ($personEmailValue !== '' && !is_email($personEmailValue)) {
            return new \WP_Error(
                'rrze_appointment_invalid_host_email',
                __('The configured host email address is invalid.', 'rrze-appointment')
            );
        }
        $personEmail = sanitize_email($personEmailValue);

        if ($personId > 0) {
            $person = get_post($personId);
            if (
                !$person instanceof \WP_Post
                || $person->post_type !== 'custom_person'
                || $person->post_status !== 'publish'
            ) {
                return new \WP_Error(
                    'rrze_appointment_invalid_host',
                    __('The configured host is not published.', 'rrze-appointment')
                );
            }

            if ($personName === '') {
                $personName = self::getPersonName($person);
            }
        }

        $templateId = (int) ($attributes['tplId'] ?? 0);
        if ($templateId < 0) {
            return new \WP_Error(
                'rrze_appointment_invalid_template',
                __('The configured mail template is invalid.', 'rrze-appointment')
            );
        }
        if ($templateId > 0) {
            $template = get_post($templateId);
            if (
                !$template instanceof \WP_Post
                || $template->post_type !== MailTemplatePost::POST_TYPE
                || $template->post_status !== 'publish'
            ) {
                return new \WP_Error(
                    'rrze_appointment_invalid_template',
                    __('The configured mail template is not published.', 'rrze-appointment')
                );
            }
        }

        $postLink = get_permalink($post);

        return [
            'title' => sanitize_text_field(
                (string) ($attributes['title'] ?? __('Appointment', 'rrze-appointment'))
            ),
            'location' => sanitize_text_field((string) ($attributes['location'] ?? '')),
            'person_id' => $personId,
            'person_name' => $personName,
            'person_email' => $personEmail,
            'tpl_id' => $templateId,
            'require_message' => !empty($attributes['requireMessage']),
            'disable_sso' => !empty($attributes['disableSso']),
            'post_link' => $postLink ? esc_url_raw($postLink) : home_url('/'),
        ];
    }

    private static function getSlotStart(string $slot): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})-\d{2}:\d{2}$/', $slot, $matches)) {
            return null;
        }

        $dateTime = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $matches[1] . ' ' . $matches[2],
            wp_timezone()
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$dateTime
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $dateTime->format('Y-m-d H:i') !== $matches[1] . ' ' . $matches[2]
        ) {
            return null;
        }

        return $dateTime;
    }

    private static function getPersonName(\WP_Post $person): string
    {
        $parts = array_filter([
            (string) get_post_meta($person->ID, 'person_honorificPrefix', true),
            (string) get_post_meta($person->ID, 'person_givenName', true),
            (string) get_post_meta($person->ID, 'person_familyName', true),
        ]);

        return sanitize_text_field(trim(implode(' ', $parts)) ?: $person->post_title);
    }

    private static function sortRecursively(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::sortRecursively($entry);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
