<?php

namespace RRZE\Appointment\Booking;

use RRZE\Appointment\Mail\MailTemplatePost;

defined('ABSPATH') || exit;

/**
 * Resolves trusted appointment data from published block attributes.
 *
 * Client-supplied block data is used only to locate a matching published
 * block. All booking data returned by this class is read from that block and
 * validated again on the server.
 */
final class AppointmentBlock
{
    private const BLOCK_NAME = 'rrze/appointment';
    private const FINGERPRINT_PATTERN = '/^[a-f0-9]{64}$/';
    private const SLOT_PATTERN = '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})-\d{2}:\d{2}$/';
    private const QUESTION_DATA_USE_MAX_LENGTH = 1000;

    /**
     * Returns the valid additional questions stored on a block.
     *
     * @param array<string, mixed> $attributes Raw block attributes.
     * @return array<int, array{id: string, label: string, dataUse: string, type: string, required: bool, options: array<int, string>}>
     */
    public static function getQuestions(array $attributes): array
    {
        $rawQuestions = $attributes['questions'] ?? [];
        if (!is_array($rawQuestions)) {
            return [];
        }

        $questions = [];
        $knownIds = [];
        foreach ($rawQuestions as $rawQuestion) {
            if (!is_array($rawQuestion)) {
                continue;
            }

            $question = self::normalizeQuestion($rawQuestion, $knownIds);
            if ($question === null) {
                continue;
            }

            $knownIds[$question['id']] = true;
            $questions[] = $question;
        }

        return $questions;
    }

    /**
     * Build a stable selector for a published appointment block.
     *
     * The selector is not trusted as booking data. It only identifies the block
     * whose attributes are loaded again from the published post.
     *
     * @param array<string, mixed> $attributes Block attributes to fingerprint.
     */
    public static function fingerprint(array $attributes): string
    {
        $normalized = self::sortAssociativeArraysRecursively($attributes);

        return hash_hmac(
            'sha256',
            (string) wp_json_encode($normalized),
            wp_salt('nonce')
        );
    }

    /**
     * Resolve and validate the booking context from a published post.
     *
     * @param int    $postId       Published post containing the appointment block.
     * @param string $fingerprint  HMAC fingerprint of the raw block attributes.
     * @param string $slot         Canonical appointment slot identifier.
     * @param bool   $allowNotOpen Whether to allow a future booking window.
     * @return array<string, mixed>|\WP_Error Validated booking context or an error.
     */
    public static function resolvePublished(
        int $postId,
        string $fingerprint,
        string $slot,
        bool $allowNotOpen = false
    )
    {
        if ($postId <= 0 || !preg_match(self::FINGERPRINT_PATTERN, $fingerprint)) {
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

            $context = self::validateContext($post, $attributes, $slot, $allowNotOpen);
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

    /**
     * Normalizes one additional-question definition.
     *
     * @param array<string, mixed> $rawQuestion Raw question attributes.
     * @param array<string, bool>  $knownIds    Question IDs already accepted.
     * @return array{id: string, label: string, dataUse: string, type: string, required: bool, options: array<int, string>}|null
     */
    private static function normalizeQuestion(array $rawQuestion, array $knownIds): ?array
    {
        $id = sanitize_key((string) ($rawQuestion['id'] ?? ''));
        $label = sanitize_text_field((string) ($rawQuestion['label'] ?? ''));
        $dataUse = self::normalizeQuestionDataUse($rawQuestion['dataUse'] ?? '');
        $type = ($rawQuestion['type'] ?? '') === 'select' ? 'select' : 'text';

        if ($id === '' || $label === '' || $dataUse === '' || isset($knownIds[$id])) {
            return null;
        }

        $options = $type === 'select'
            ? self::normalizeQuestionOptions($rawQuestion['options'] ?? [])
            : [];
        if ($type === 'select' && $options === []) {
            return null;
        }

        return [
            'id' => $id,
            'label' => $label,
            'dataUse' => $dataUse,
            'type' => $type,
            'required' => !empty($rawQuestion['required']),
            'options' => $options,
        ];
    }

    /**
     * Sanitizes and limits a question's data-use explanation.
     *
     * @param mixed $dataUse Raw data-use value.
     */
    private static function normalizeQuestionDataUse($dataUse): string
    {
        $sanitized = sanitize_textarea_field((string) $dataUse);
        $limited = function_exists('mb_substr')
            ? mb_substr($sanitized, 0, self::QUESTION_DATA_USE_MAX_LENGTH)
            : substr($sanitized, 0, self::QUESTION_DATA_USE_MAX_LENGTH);

        return trim($limited);
    }

    /**
     * Returns unique, non-empty scalar options in their original order.
     *
     * @param mixed $rawOptions Raw option values.
     * @return array<int, string>
     */
    private static function normalizeQuestionOptions($rawOptions): array
    {
        if (!is_array($rawOptions)) {
            return [];
        }

        $options = [];
        $knownOptions = [];
        foreach ($rawOptions as $rawOption) {
            if (!is_scalar($rawOption)) {
                continue;
            }

            $option = sanitize_text_field((string) $rawOption);
            if ($option === '' || isset($knownOptions[$option])) {
                continue;
            }

            $knownOptions[$option] = true;
            $options[] = $option;
        }

        return $options;
    }

    /**
     * Collects appointment blocks matching a fingerprint, including reusable blocks.
     *
     * @param array<int, mixed>                $blocks                Parsed blocks to inspect.
     * @param string                           $fingerprint           Expected block fingerprint.
     * @param array<int, array<string, mixed>> $matches               Matching attributes, by reference.
     * @param array<int, bool>                 $visitedReusableBlocks Reusable block IDs, by reference.
     */
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

    /**
     * Applies registered block defaults before validating attributes.
     *
     * @param array<string, mixed> $attributes Raw block attributes.
     * @return array<string, mixed>
     */
    private static function prepareAttributes(array $attributes): array
    {
        $blockType = \WP_Block_Type_Registry::get_instance()->get_registered(self::BLOCK_NAME);
        if (!$blockType instanceof \WP_Block_Type) {
            return $attributes;
        }

        return $blockType->prepare_attributes_for_render($attributes);
    }

    /**
     * Builds a validated booking context from published block attributes.
     *
     * @param \WP_Post            $post         Published post containing the block.
     * @param array<string, mixed> $attributes   Prepared block attributes.
     * @param string               $slot         Canonical appointment slot identifier.
     * @param bool                 $allowNotOpen Whether to allow a future booking window.
     * @return array<string, mixed>|\WP_Error Validated context or an error.
     */
    private static function validateContext(
        \WP_Post $post,
        array $attributes,
        string $slot,
        bool $allowNotOpen = false
    )
    {
        $bookingCutoff = max(0, (int) ($attributes['bookingCutoff'] ?? 0));
        $bookingMaxAdvance = max(0, (int) ($attributes['bookingMaxAdvance'] ?? 0));
        $slotStart = self::getSlotStart($slot);
        $now = current_datetime();
        if (
            !$slotStart
            || BookingWindow::isClosed($slotStart, $now, $bookingCutoff)
        ) {
            return new \WP_Error(
                'rrze_appointment_booking_cutoff',
                __('This appointment can no longer be booked.', 'rrze-appointment')
            );
        }
        $bookingNotOpen = BookingWindow::isNotOpen($slotStart, $now, $bookingMaxAdvance);
        if ($bookingNotOpen && !$allowNotOpen) {
            return new \WP_Error(
                'rrze_appointment_booking_not_open',
                __('This appointment cannot be booked yet.', 'rrze-appointment')
            );
        }

        $host = self::resolveHost($attributes);
        if (is_wp_error($host)) {
            return $host;
        }

        $templateId = self::resolveTemplateId($attributes);
        if (is_wp_error($templateId)) {
            return $templateId;
        }

        $postLink = get_permalink($post);

        return [
            'title' => sanitize_text_field(
                (string) ($attributes['title'] ?? __('Appointment', 'rrze-appointment'))
            ),
            'location' => sanitize_text_field((string) ($attributes['location'] ?? '')),
            'person_id' => $host['id'],
            'person_name' => $host['name'],
            'person_email' => $host['email'],
            'tpl_id' => $templateId,
            'questions' => self::getQuestions($attributes),
            'disable_sso' => !empty($attributes['disableSso']),
            'post_link' => $postLink ? esc_url_raw($postLink) : home_url('/'),
            'booking_not_open' => $bookingNotOpen,
            'booking_opens_at' => $slotStart->getTimestamp() - ($bookingMaxAdvance * MINUTE_IN_SECONDS),
            'booking_closes_at' => $slotStart->getTimestamp() - ($bookingCutoff * MINUTE_IN_SECONDS),
        ];
    }

    /**
     * Resolves and validates the configured appointment host.
     *
     * FAUdir is an optional import source. Copied contact details remain valid
     * if the source post is removed after the appointment was published.
     *
     * @param array<string, mixed> $attributes Prepared block attributes.
     * @return array{id: int, name: string, email: string}|\WP_Error
     */
    private static function resolveHost(array $attributes)
    {
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
            if (self::isPublishedPerson($person)) {
                if ($personName === '') {
                    $personName = self::getPersonName($person);
                }
            } else {
                $personId = 0;
            }
        }

        if ($personName === '') {
            return new \WP_Error(
                'rrze_appointment_missing_host',
                __('A host name is required for this appointment.', 'rrze-appointment')
            );
        }
        if ($personEmail === '') {
            return new \WP_Error(
                'rrze_appointment_missing_host_email',
                __('A host email address is required for this appointment.', 'rrze-appointment')
            );
        }

        return [
            'id' => $personId,
            'name' => $personName,
            'email' => $personEmail,
        ];
    }

    /**
     * Determines whether a post is a published FAUdir person.
     *
     * @param mixed $person Candidate post.
     */
    private static function isPublishedPerson($person): bool
    {
        return $person instanceof \WP_Post
            && $person->post_type === 'custom_person'
            && $person->post_status === 'publish';
    }

    /**
     * Resolves and validates the configured mail-template ID.
     *
     * @param array<string, mixed> $attributes Prepared block attributes.
     * @return int|\WP_Error
     */
    private static function resolveTemplateId(array $attributes)
    {
        $templateId = (int) ($attributes['tplId'] ?? 0);
        if ($templateId < 0) {
            return new \WP_Error(
                'rrze_appointment_invalid_template',
                __('The configured mail template is invalid.', 'rrze-appointment')
            );
        }
        if ($templateId === 0) {
            return 0;
        }

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

        return $templateId;
    }

    /**
     * Parses the starting date and time from a canonical slot identifier.
     */
    private static function getSlotStart(string $slot): ?\DateTimeImmutable
    {
        if (!preg_match(self::SLOT_PATTERN, $slot, $matches)) {
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

    /**
     * Builds a display name from a published FAUdir person.
     */
    private static function getPersonName(\WP_Post $person): string
    {
        $parts = array_filter([
            (string) get_post_meta($person->ID, 'person_honorificPrefix', true),
            (string) get_post_meta($person->ID, 'person_givenName', true),
            (string) get_post_meta($person->ID, 'person_familyName', true),
        ]);

        return sanitize_text_field(trim(implode(' ', $parts)) ?: $person->post_title);
    }

    /**
     * Sorts associative arrays recursively while preserving list order.
     *
     * @param array<mixed> $value Value to normalize for fingerprinting.
     * @return array<mixed>
     */
    private static function sortAssociativeArraysRecursively(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::sortAssociativeArraysRecursively($entry);
            }
        }

        if (!self::isList($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * PHP 7.4-compatible equivalent of array_is_list().
     *
     * @param array<mixed> $value Array to inspect.
     */
    private static function isList(array $value): bool
    {
        $expectedKey = 0;
        foreach ($value as $key => $_entry) {
            if ($key !== $expectedKey) {
                return false;
            }
            ++$expectedKey;
        }

        return true;
    }
}
