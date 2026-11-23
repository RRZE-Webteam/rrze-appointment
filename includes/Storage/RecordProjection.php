<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/** Converts the five historical arrays into reservation, booking and opening aggregates. */
final class RecordProjection
{
    public const SLOTS = 'rrze_appointment_booked_slots';
    public const META = 'rrze_appointment_booked_slots_meta';
    public const PENDING = 'rrze_appointment_pending_slots';
    public const CANCEL = 'rrze_appointment_cancel_tokens';
    public const OPENINGS = 'rrze_appointment_booking_opening_notifications';

    /**
     * Groups fields by business identity, including legacy string and slot-based tokens.
     * Unlinked old cancellation tokens remain readable and can be removed normally.
     *
     * @param array<string,array> $view Complete logical option contents.
     * @return array<string,array{type:string,target:string,fields:array}> Groups keyed by owner.
     */
    public static function groups(#[\SensitiveParameter] array $view): array
    {
        $groups = [];
        $pendingSlots = [];
        foreach ($view[self::SLOTS] as $slot) {
            if (!is_string($slot) || $slot === '') {
                throw new AppointmentException('Invalid appointment slot in storage.');
            }
            self::add($groups, 'booking', $slot, self::SLOTS, $slot, $slot);
        }
        foreach ($view[self::META] as $slot => $entry) {
            self::add($groups, 'booking', (string) $slot, self::META, $slot, $entry);
        }
        foreach ($view[self::PENDING] as $token => $entry) {
            self::add($groups, 'pending', (string) $token, self::PENDING, $token, $entry);
            if (is_array($entry) && is_string($entry['slot'] ?? null)) {
                $pendingSlots[$entry['slot']][(string) $token] = $entry;
            }
        }
        foreach ($view[self::OPENINGS] as $token => $entry) {
            self::add($groups, 'opening', (string) $token, self::OPENINGS, $token, $entry);
        }
        foreach ($view[self::CANCEL] as $token => $value) {
            [$type, $target] = self::cancelOwner((string) $token, $value, $pendingSlots);
            self::add($groups, $type, $target, self::CANCEL, $token, $value);
        }
        return $groups;
    }

    /** Returns the type and owner of a legacy cancellation token. */
    private static function cancelOwner(#[\SensitiveParameter] string $token, #[\SensitiveParameter] mixed $value, #[\SensitiveParameter] array $pendingSlots): array
    {
        if (is_string($value) && $value !== '') {
            return ['booking', $value];
        }
        if (is_array($value) && ($value['type'] ?? '') === 'pending') {
            if (is_string($value['pending_token'] ?? null) && $value['pending_token'] !== '') {
                return ['pending', $value['pending_token']];
            }
            $candidates = $pendingSlots[$value['slot'] ?? ''] ?? [];
            foreach ($candidates as $id => $pending) {
                if (($pending['cancel_token'] ?? '') === $token) {
                    return ['pending', (string) $id];
                }
            }
            if (count($candidates) === 1) {
                return ['pending', (string) array_key_first($candidates)];
            }
        } elseif (is_array($value) && is_string($value['slot'] ?? null) && $value['slot'] !== '') {
            return ['booking', $value['slot']];
        }
        return ['orphan', $token];
    }

    /** Adds an individual logical field to its owning aggregate. */
    private static function add(#[\SensitiveParameter] array &$groups, string $type, #[\SensitiveParameter] string $target, string $area, #[\SensitiveParameter] string|int $key, #[\SensitiveParameter] mixed $value): void
    {
        if ($target === '') {
            throw new AppointmentException('Invalid appointment record identity.');
        }
        $owner = self::owner($type, $target);
        $groups[$owner] ??= ['type' => $type, 'target' => $target, 'fields' => []];
        $groups[$owner]['fields'][$area][$key] = $value;
    }

    /** Returns an unambiguous in-memory key; never used in physical option names. */
    public static function owner(string $type, #[\SensitiveParameter] string $target): string
    {
        return $type . "\0" . $target;
    }

    /** Extracts the business entry passed to the job planner, preserving all historical fields. */
    public static function entry(#[\SensitiveParameter] array $group): array
    {
        $area = match ($group['type']) {
            'pending' => self::PENDING,
            'booking' => self::META,
            'opening' => self::OPENINGS,
            default => throw new AppointmentException('An appointment action token has no owning record.'),
        };
        $entry = $group['fields'][$area][$group['target']] ?? null;
        if ($entry === null && $group['type'] === 'booking' && isset($group['fields'][self::SLOTS])) {
            return [];
        }
        if (!is_array($entry)) {
            throw new AppointmentException('The appointment record has no valid business data.');
        }
        return $entry;
    }

    /** Removes a cell from an old array while preserving other entries and their keys. */
    public static function remove(#[\SensitiveParameter] array &$view, string $area, #[\SensitiveParameter] string|int $key): void
    {
        if ($area === self::SLOTS) {
            foreach ($view[$area] as $position => $slot) {
                if ($slot === (string) $key) {
                    unset($view[$area][$position]);
                }
            }
        } else {
            unset($view[$area][$key]);
        }
    }

    /** Adds V2 fields to a source view, rejecting competing active records. */
    public static function overlay(#[\SensitiveParameter] array &$view, #[\SensitiveParameter] array $group): void
    {
        foreach ($group['fields'] as $area => $fields) {
            foreach ($fields as $key => $value) {
                if ($area === self::SLOTS) {
                    if (in_array($value, $view[$area], true)) {
                        throw self::conflict();
                    }
                    $view[$area][] = $value;
                } else {
                    if (array_key_exists($key, $view[$area])) {
                        throw self::conflict();
                    }
                    $view[$area][$key] = $value;
                }
            }
        }
    }

    /**
     * Indexes slots and action tokens by the owner keys supplied in $groups.
     *
     * In the active view each occupied slot maps to one owner. A pending reservation
     * stops occupying its slot when it expires. The persisted index keeps a list of
     * owners per slot, including expired reservations. In both modes a token key
     * includes its action prefix and maps to one owner, regardless of expiry.
     *
     * @param array<string,array{type:string,target:string,fields:array<string,array>}> $groups Groups keyed by logical owner or immutable record ID.
     * @param bool $activeOnly True for the current occupancy check, false for the persisted index.
     * @return array{slots:array<string,string>|array<string,list<string>>,tokens:array<string,string>} Lookups using the input owner keys.
     * @throws AppointmentException When owners share an action token or claim the same active slot in the occupancy check.
     */
    public static function lookups(#[\SensitiveParameter] array $groups, bool $activeOnly): array
    {
        $slots = $tokens = [];
        foreach ($groups as $owner => $group) {
            $type = $group['type'];
            $target = $group['target'];
            $fields = $group['fields'];
            if ($type === 'booking' && (isset($fields[self::SLOTS]) || isset($fields[self::META]))) {
                if ($activeOnly) {
                    self::claim($slots, $target, $owner);
                } else {
                    $slots[$target][] = $owner;
                }
                $meta = $fields[self::META][$target] ?? [];
                if (is_array($meta) && !empty($meta['waitlist_optout_token'])) {
                    self::claim($tokens, 'waitlist:' . $meta['waitlist_optout_token'], $owner);
                }
            } elseif ($type === 'pending' && isset($fields[self::PENDING][$target])) {
                $entry = $fields[self::PENDING][$target];
                if (is_array($entry) && is_string($entry['slot'] ?? null)
                    && (!$activeOnly || (int) ($entry['expires'] ?? 0) > time())) {
                    // Expired rows may coexist with a fresh reservation. The persisted
                    // index keeps them addressable by token, but never caches an expiry decision.
                    if ($activeOnly) {
                        self::claim($slots, $entry['slot'], $owner);
                    } else {
                        $slots[$entry['slot']][] = $owner;
                    }
                }
                self::claim($tokens, 'confirm:' . $target, $owner);
            } elseif ($type === 'opening') {
                self::claim($tokens, 'open:' . $target, $owner);
                $entry = $fields[self::OPENINGS][$target] ?? [];
                if (is_array($entry) && !empty($entry['status_token'])) {
                    self::claim($tokens, 'status:' . $entry['status_token'], $owner);
                }
            }
            foreach (array_keys($fields[self::CANCEL] ?? []) as $token) {
                self::claim($tokens, 'cancel:' . $token, $owner);
            }
        }
        return ['slots' => $slots, 'tokens' => $tokens];
    }

    /** Records an index lookup, rejecting a second claimant rather than hiding it. */
    private static function claim(#[\SensitiveParameter] array &$owners, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $owner): void
    {
        if (isset($owners[$key]) && $owners[$key] !== $owner) {
            throw self::conflict();
        }
        $owners[$key] = $owner;
    }

    /** Returns a non-sensitive error for conflicting reservations or action links. */
    public static function conflict(): AppointmentException
    {
        return new AppointmentException('Conflicting appointment records need attention before this booking can continue.');
    }
}
