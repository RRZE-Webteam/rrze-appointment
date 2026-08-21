<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

/**
 * Loads appointment hosts and consultation-hour data from RRZE FAUdir.
 */
final class FaudirPersonProvider
{
    private const PERSON_POST_TYPE = 'custom_person';
    private const PERSON_ID_META_KEY = 'person_id';
    private const EMAIL_KEYS = [
        'email',
        'emails',
        'emailAddress',
        'emailAddresses',
        'mail',
    ];
    private const NESTED_EMAIL_KEYS = [
        'email',
        'emails',
        'emailAddress',
        'emailAddresses',
        'mail',
        'mails',
        'value',
    ];

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
        $dependencyError = $this->getDependencyError();
        if ($dependencyError !== '') {
            return $this->errorResponse($dependencyError);
        }

        $api = new \RRZE\FAUdir\API(new \RRZE\FAUdir\Config());
        return [
            'error' => false,
            'message' => '',
            'data' => $this->loadPersons($this->getPersonPostIds(), $api),
        ];
    }

    /**
     * Returns a localized dependency error, or an empty string when FAUdir is available.
     */
    private function getDependencyError(): string
    {
        if (!post_type_exists(self::PERSON_POST_TYPE)) {
            return __(
                'Tip: Activate the RRZE FAUdir plugin to conveniently import person data.',
                'rrze-appointment'
            );
        }

        if (!class_exists('\RRZE\FAUdir\API') || !class_exists('\RRZE\FAUdir\Config')) {
            return __('FAUdir classes not available.', 'rrze-appointment');
        }

        return '';
    }

    /**
     * Builds the REST error schema used by dependency checks.
     *
     * @return array{error: true, message: string, data: array{}}
     */
    private function errorResponse(string $message): array
    {
        return ['error' => true, 'message' => $message, 'data' => []];
    }

    /**
     * Returns published FAUdir person post IDs in display order.
     *
     * @return array<int, int>
     */
    private function getPersonPostIds(): array
    {
        $postIds = get_posts([
            'post_type' => self::PERSON_POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);

        return array_values(array_map('intval', is_array($postIds) ? $postIds : []));
    }

    /**
     * Loads and normalizes people represented by the supplied posts.
     *
     * @param array<int, int> $postIds Published custom_person post IDs.
     * @param object          $api     FAUdir API client.
     * @return array<int, array<string, mixed>>
     */
    private function loadPersons(array $postIds, object $api): array
    {
        $persons = [];

        foreach ($postIds as $postId) {
            $faudirId = trim((string) get_post_meta($postId, self::PERSON_ID_META_KEY, true));
            if ($faudirId === '') {
                continue;
            }

            $person = $api->getPerson($faudirId);
            if (!is_array($person) || $person === []) {
                continue;
            }

            $persons[] = $this->normalizePerson($person, (int) $postId, $api);
        }

        return $persons;
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
        $givenName = sanitize_text_field((string) ($person['givenName'] ?? ''));
        $familyName = sanitize_text_field((string) ($person['familyName'] ?? ''));
        $contactData = $this->findContactData($person, $api);
        $email = sanitize_email((string) ($person['email'] ?? ''));
        if ($email === '') {
            $email = $contactData['email'] ?: $this->findPersonWorkplaceEmail($person);
        }

        return [
            'id' => $postId,
            'error' => false,
            'message' => '',
            'label' => trim($givenName . ' ' . $familyName)
                ?: sanitize_text_field((string) get_the_title($postId)),
            'honorificPrefix' => sanitize_text_field((string) ($person['honorificPrefix'] ?? '')),
            'givenName' => $givenName,
            'familyName' => $familyName,
            'email' => $email,
            'location' => $contactData['location'],
            'locationUrl' => $contactData['locationUrl'],
            'consultationHours' => $contactData['hours'],
            'hoursType' => $contactData['hoursType'],
        ];
    }

    /**
     * Finds the first workplace containing importable appointment hours.
     *
     * @param array<string, mixed> $person FAUdir person payload.
     * @param object               $api    FAUdir API client.
     * @return array{
     *     email: string,
     *     location: string,
     *     locationUrl: string,
     *     hours: array<int, array{weekday: int, from: string, to: string}>,
     *     hoursType: 'consultation'|'office'|null
     * }
     */
    private function findContactData(array $person, object $api): array
    {
        $result = $this->emptyContactData();
        $contacts = is_array($person['contacts'] ?? null) ? $person['contacts'] : [];

        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $contactDetails = $this->getContactDetails($contact, $api);
            $workplaces = is_array($contactDetails['workplaces'] ?? null)
                ? $contactDetails['workplaces']
                : [];

            foreach ($workplaces as $workplace) {
                if (!is_array($workplace)) {
                    continue;
                }

                if ($result['email'] === '') {
                    $result['email'] = $this->extractFirstWorkplaceEmail($workplace);
                }

                [$hours, $hoursType] = $this->getAppointmentHours($workplace);
                if ($hours === []) {
                    continue;
                }

                $result['hours'] = $hours;
                $result['hoursType'] = $hoursType;
                $result['location'] = $this->getWorkplaceLocation($workplace);
                $result['locationUrl'] = esc_url_raw((string) ($workplace['faumap'] ?? ''));
                return $result;
            }
        }

        return $result;
    }

    /**
     * Returns normalized contact details, falling back to the embedded payload.
     *
     * @param array<string, mixed> $contact Embedded contact payload.
     * @return array<string, mixed>
     */
    private function getContactDetails(array $contact, object $api): array
    {
        $contactId = trim((string) ($contact['identifier'] ?? ''));
        $contactDetails = $contactId !== '' ? $api->getContact($contactId) : null;

        return is_array($contactDetails) && $contactDetails !== [] ? $contactDetails : $contact;
    }

    /**
     * Returns consultation hours in preference to general office hours.
     *
     * @param array<string, mixed> $workplace FAUdir workplace payload.
     * @return array{0: array<int, array{weekday: int, from: string, to: string}>, 1: 'consultation'|'office'|null}
     */
    private function getAppointmentHours(array $workplace): array
    {
        $consultationHours = $this->normalizeHours($workplace['consultationHours'] ?? []);
        if ($consultationHours !== []) {
            return [$consultationHours, 'consultation'];
        }

        $officeHours = $this->normalizeHours($workplace['officeHours'] ?? []);
        return $officeHours === [] ? [[], null] : [$officeHours, 'office'];
    }

    /**
     * Restricts hours to the schema consumed by the editor.
     *
     * @param mixed $hours FAUdir hours payload.
     * @return array<int, array{weekday: int, from: string, to: string}>
     */
    private function normalizeHours($hours): array
    {
        if (!is_array($hours)) {
            return [];
        }

        $normalized = [];
        foreach ($hours as $entry) {
            if (!is_array($entry) || !isset($entry['weekday'], $entry['from'], $entry['to'])) {
                continue;
            }

            $weekday = (int) $entry['weekday'];
            $from = sanitize_text_field((string) $entry['from']);
            $to = sanitize_text_field((string) $entry['to']);
            if ($weekday < 0 || $weekday > 6 || ($from === '' && $to === '')) {
                continue;
            }

            $normalized[] = ['weekday' => $weekday, 'from' => $from, 'to' => $to];
        }

        return $normalized;
    }

    /**
     * Builds a plain-text workplace location.
     *
     * @param array<string, mixed> $workplace FAUdir workplace payload.
     */
    private function getWorkplaceLocation(array $workplace): string
    {
        $parts = array_map(
            static fn ($value): string => sanitize_text_field((string) $value),
            [$workplace['room'] ?? '', $workplace['street'] ?? '', $workplace['city'] ?? '']
        );

        return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Finds an email in workplaces embedded directly in a person payload.
     *
     * @param array<string, mixed> $person FAUdir person payload.
     */
    private function findPersonWorkplaceEmail(array $person): string
    {
        $workplaces = is_array($person['workplaces'] ?? null) ? $person['workplaces'] : [];
        foreach ($workplaces as $workplace) {
            if (!is_array($workplace)) {
                continue;
            }

            $email = $this->extractFirstWorkplaceEmail($workplace);
            if ($email !== '') {
                return $email;
            }
        }

        return '';
    }

    /**
     * Returns the empty normalized contact schema.
     *
     * @return array{email: string, location: string, locationUrl: string, hours: array{}, hoursType: null}
     */
    private function emptyContactData(): array
    {
        return [
            'email' => '',
            'location' => '',
            'locationUrl' => '',
            'hours' => [],
            'hoursType' => null,
        ];
    }

    /**
     * Finds the first valid address in common workplace email fields.
     *
     * @param array<string, mixed> $workplace FAUdir workplace payload.
     */
    private function extractFirstWorkplaceEmail(array $workplace): string
    {
        foreach (self::EMAIL_KEYS as $key) {
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

        foreach (self::NESTED_EMAIL_KEYS as $key) {
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
