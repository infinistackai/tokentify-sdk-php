<?php

declare(strict_types=1);

namespace UsageMeter;

use UsageMeter\Exception\ConfigurationException;

/**
 * Tokentify-style entrypoint: flat tracking fields + denormalized group_key/group_value metadata.
 *
 * Python-style (bucket string + explicit tracking_fields; API key and URL from env like the Python SDK):
 *
 *   Tokentify::init('my-app', ['account_id', 'user_id']);
 *   Tokentify::init('my-app', ['account_id', 'user_id'], ['load_env_file' => false]); // Laravel: third merge
 *
 * Legacy full options array — `tracking_fields` is required and must list both keys:
 *
 *   Tokentify::init([
 *     'api_key' => 'TOKENTIFY_KEY',
 *     'bucket' => 'my-app',
 *     'tracking_fields' => ['account_id', 'user_id'],
 *   ]);
 *
 * Then pass the same keys in metadata on each track() call (or set them with Meter::tag()).
 */
final class Tokentify
{
    /**
     * @param string|array{
     *   api_key?: string,
     *   tracking_fields?: list<string>,
     *   bucket?: string,
     *   app_name?: string,
     *   environment?: string,
     *   load_env_file?: bool,
     *   debug?: bool,
     *   timeout?: float,
     *   verify_tls?: bool,
     *   verify_connection?: bool,
     *   verify_api_key?: bool,
     *   strict_tracking_validation?: bool,
     *   fail_on_delivery_error?: bool,
     * } $bucketOrOptions Dashboard bucket name (string), or legacy options array
     * @param list<string>|null $trackingFields Ordered keys when passed; omit only if the merged options already set `tracking_fields`. The effective list must include the exact keys `account_id` and `user_id`.
     * @param array<string, mixed> $extraOptions Merged after bucket/options (e.g. `verify_api_key` => false, `load_env_file` => false)
     */
    public static function init(string|array $bucketOrOptions, ?array $trackingFields = null, array $extraOptions = []): void
    {
        if (is_string($bucketOrOptions)) {
            $bucket = trim($bucketOrOptions);
            if ($bucket === '') {
                throw ConfigurationException::missingBucket();
            }
            $options = array_merge($extraOptions, [
                'bucket' => $bucket,
            ]);
        } else {
            $options = array_merge($extraOptions, $bucketOrOptions);
        }

        if ($trackingFields !== null) {
            $options['tracking_fields'] = $trackingFields;
        }

        if (! isset($options['tracking_fields'])) {
            throw ConfigurationException::invalidTrackingFields(
                'Tokentify::init requires explicit tracking_fields: pass them as the second argument '
                . '(Tokentify::init(\'bucket\', [\'account_id\', \'user_id\'], ...)), or set '
                . '\'tracking_fields\' in the first options array / third merge argument. '
                . 'The list must include both "account_id" and "user_id" as exact key names.'
            );
        }
        if (! is_array($options['tracking_fields'])) {
            throw ConfigurationException::invalidTrackingFields(
                'tracking_fields must be a list of string keys, e.g. ["account_id","user_id"].'
            );
        }
        $fields = [];
        foreach ($options['tracking_fields'] as $f) {
            $fields[] = (string) $f;
        }
        $fields = array_values(array_filter(array_map('trim', $fields), static fn (string $s): bool => $s !== ''));
        if ($fields === []) {
            throw ConfigurationException::invalidTrackingFields(
                'tracking_fields contained no non-empty key strings after trimming.'
            );
        }
        self::assertTrackingFieldsIncludeAccountAndUser($fields);
        $options['tracking_fields'] = $fields;
        Meter::init($options);
    }

    /**
     * @param list<string> $fields Normalized non-empty tracking key names
     */
    private static function assertTrackingFieldsIncludeAccountAndUser(array $fields): void
    {
        foreach (['account_id', 'user_id'] as $required) {
            if (! in_array($required, $fields, true)) {
                throw ConfigurationException::invalidTrackingFields(
                    sprintf(
                        'tracking_fields must include "%s" as an exact key name. Present keys: [%s].',
                        $required,
                        implode(', ', $fields)
                    )
                );
            }
        }
    }
}
