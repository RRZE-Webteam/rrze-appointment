<?php

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Storage\BookingCipher;
use RRZE\Appointment\Storage\EncryptionKey;

define('ABSPATH', __DIR__);
require dirname(__DIR__, 2) . '/includes/AppointmentException.php';
require dirname(__DIR__, 2) . '/includes/Storage/EncryptionKey.php';
require dirname(__DIR__, 2) . '/includes/Storage/BookingCipher.php';

/**
 * Runs an isolated crypto scenario using only synthetic input, without WordPress.
 *
 * @param array<string, mixed> $input Scenario and public test values supplied through stdin.
 * @return mixed Scenario result; failures are reported by the outer exception boundary.
 * @throws AppointmentException When validation or authentication fails.
 */
function runEncryptionScenario(array $input): mixed
{
    if ($input['action'] === 'configuration') {
        if (array_key_exists('configuredKey', $input)) {
            define(EncryptionKey::CONFIGURATION_CONSTANT, $input['configuredKey']);
        }
        $key = EncryptionKey::fromConfiguration();
        return [
            'id' => $key->identifier($input['site']),
            'bytes' => strlen($key->forStorage($input['site'])),
        ];
    }

    $key = EncryptionKey::fromBase64($input['key']);
    if ($input['action'] === 'debug') {
        ob_start();
        var_dump($key);
        return ob_get_clean();
    }
    if ($input['action'] === 'serialize') {
        return serialize($key);
    }

    $cipher = new BookingCipher($key, $input['site']);
    $storage = $input['storage'];
    if ($input['action'] === 'decrypt') {
        return $cipher->decrypt($input['stored'], $storage);
    }

    $payload = $input['payload'] ?? [];
    if ($input['action'] === 'invalidPayload') {
        switch ($input['kind']) {
            case 'object':
                $payload = ['value' => new stdClass()];
                break;
            case 'resource':
                $payload = ['value' => STDIN];
                break;
            case 'recursive':
                $payload['value'] = &$payload;
                break;
            case 'utf8':
                $payload = ['value' => "\xB1\x31"];
                break;
            case 'infinite':
                $payload = ['value' => INF];
                break;
        }
    }
    if ($input['action'] === 'sparse') {
        $payload = [0 => 'first', 4 => 'last', 'nested' => [2 => true, 9 => 1.0, 'null' => null]];
    }

    $first = $cipher->encrypt($payload, $storage);
    $second = $cipher->encrypt($payload, $storage);
    $decoded = $cipher->decrypt($first, $storage);
    return [
        'first' => $first,
        'second' => $second,
        'payload' => $decoded,
        'identical' => $decoded === $payload,
    ];
}

$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
try {
    echo json_encode(['ok' => true, 'value' => runEncryptionScenario($input)], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error' => get_class($exception),
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ], JSON_THROW_ON_ERROR);
}
