<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

/**
 * Loads appointment hosts and consultation-hour data from RRZE FAUdir.
 */
final class FaudirPersonProvider
{
    /**
     * Returns the REST response consumed by the block editor.
     *
     * @return \WP_REST_Response|\WP_HTTP_Response|\WP_Error
     */
    public function handleRequest()
    {
        return rest_ensure_response($this->getPersons());
    }

    /**
     * Builds the normalized FAUdir person list for the block editor.
     *
     * @return array{error: bool, message: string, data: array<int, array<string, mixed>>}
     */
    private function getPersons(): array
    {
        if (!post_type_exists('custom_person')) {
            return [
                'error' => true,
                'message' => __('Tip: Activate the RRZE FAUdir plugin to conveniently import person data.', 'rrze-appointment'),
                'data' => [],
            ];
        }

        if (!class_exists('\RRZE\FAUdir\API') || !class_exists('\RRZE\FAUdir\Config')) {
            return [
                'error' => true,
                'message' => __('FAUdir classes not available.', 'rrze-appointment'),
                'data' => [],
            ];
        }

        $postIds = get_posts([
            'post_type' => 'custom_person',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);
        $api = new \RRZE\FAUdir\API(new \RRZE\FAUdir\Config());
        $persons = [];

        foreach ($postIds as $postId) {
            $faudirId = (string) get_post_meta($postId, 'person_id', true);
            if ($faudirId === '') {
                continue;
            }

            $person = $api->getPerson($faudirId);
            if (!is_array($person) || $person === []) {
                continue;
            }

            $persons[] = $this->normalizePerson($person, (int) $postId, $api);
        }

        return ['error' => false, 'message' => '', 'data' => $persons];
    }

    /**
     * Converts a FAUdir payload into the editor's person schema.
     *
     * @param array<string, mixed> $person FAUdir person payload.
     * @param int                  $postId Related custom_person post ID.
     * @param object               $api    FAUdir API client.
     * @return array<string, mixed>
     */
    private function normalizePerson(array $person, int $postId, object $api): array
    {
        $givenName = (string) ($person['givenName'] ?? '');
        $familyName = (string) ($person['familyName'] ?? '');
        $email = sanitize_email($person['email'] ?? '');
        $consultationHours = [];
        $hoursType = null;
        $location = '';
        $locationUrl = '';

        foreach ($person['contacts'] ?? [] as $contact) {
            $contactDetails = [];
            $contactId = is_array($contact) ? ($contact['identifier'] ?? '') : '';
            if ($contactId !== '') {
                $contactDetails = $api->getContact($contactId);
            }
            if (!is_array($contactDetails) || $contactDetails === []) {
                $contactDetails = is_array($contact) ? $contact : [];
            }

            foreach ($contactDetails['workplaces'] ?? [] as $workplace) {
                $workplace = (array) $workplace;
                if ($email === '') {
                    $email = $this->extractFirstWorkplaceEmail($workplace);
                }

                if (!empty($workplace['consultationHours'])) {
                    $consultationHours = $workplace['consultationHours'];
                    $hoursType = 'consultation';
                } elseif (!empty($workplace['officeHours'])) {
                    $consultationHours = $workplace['officeHours'];
                    $hoursType = 'office';
                }

                if ($consultationHours !== []) {
                    $location = implode(', ', array_filter([
                        $workplace['room'] ?? '',
                        $workplace['street'] ?? '',
                        $workplace['city'] ?? '',
                    ]));
                    $locationUrl = (string) ($workplace['faumap'] ?? '');
                    break 2;
                }
            }
        }

        if ($email === '') {
            foreach ($person['workplaces'] ?? [] as $workplace) {
                $email = $this->extractFirstWorkplaceEmail((array) $workplace);
                if ($email !== '') {
                    break;
                }
            }
        }

        return [
            'id' => $postId,
            'error' => false,
            'message' => '',
            'label' => trim($givenName . ' ' . $familyName) ?: get_the_title($postId),
            'honorificPrefix' => (string) ($person['honorificPrefix'] ?? ''),
            'givenName' => $givenName,
            'familyName' => $familyName,
            'email' => $email,
            'location' => $location,
            'locationUrl' => $locationUrl,
            'consultationHours' => $consultationHours,
            'hoursType' => $hoursType,
        ];
    }

    /**
     * Finds the first valid address in common workplace email fields.
     *
     * @param array<string, mixed> $workplace FAUdir workplace payload.
     */
    private function extractFirstWorkplaceEmail(array $workplace): string
    {
        foreach (['email', 'emails', 'emailAddress', 'emailAddresses', 'mail'] as $key) {
            if (empty($workplace[$key])) {
                continue;
            }

            $email = $this->extractFirstEmailFromValue($workplace[$key]);
            if ($email !== '') {
                return $email;
            }
        }

        return $this->extractFirstEmailRecursive($workplace);
    }

    /**
     * Extracts an email address from a scalar or a flat list of FAUdir values.
     *
     * @param mixed $value Candidate email value.
     */
    private function extractFirstEmailFromValue($value): string
    {
        if (is_string($value)) {
            return sanitize_email($value) ?: '';
        }
        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $email = sanitize_email($entry);
                if ($email !== '') {
                    return $email;
                }
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            foreach (['email', 'value', 'mail'] as $key) {
                $email = sanitize_email((string) ($entry[$key] ?? ''));
                if ($email !== '') {
                    return $email;
                }
            }
        }

        return '';
    }

    /**
     * Recursively searches nested FAUdir payloads for an email address.
     *
     * @param mixed $value Candidate email structure.
     */
    private function extractFirstEmailRecursive($value): string
    {
        if (is_string($value)) {
            return sanitize_email($value) ?: '';
        }
        if (!is_array($value)) {
            return '';
        }

        foreach (['email', 'emails', 'emailAddress', 'emailAddresses', 'mail', 'mails', 'value'] as $key) {
            if (!empty($value[$key])) {
                $email = $this->extractFirstEmailFromValue($value[$key]);
                if ($email !== '') {
                    return $email;
                }
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested) || is_string($nested)) {
                $email = $this->extractFirstEmailRecursive($nested);
                if ($email !== '') {
                    return $email;
                }
            }
        }

        return '';
    }
}
