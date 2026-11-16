<?php

declare(strict_types=1);

namespace Tokentify\Exception;

/**
 * Thrown for HTTP / network failures when the SDK is configured to surface delivery errors.
 */
final class TransportException extends SdkException
{
    public static function collectorUnreachable(
        string $operation,
        string $url,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            'tokentify: transport error during ' . $operation . ' to ' . $url
            . ($previous ? ' — ' . $previous->getMessage() : ''),
            0,
            $previous,
            [
                'sdk_error' => 'transport_error',
                'operation' => $operation,
                'url' => $url,
            ],
        );
    }

    public static function badHttpStatus(
        string $operation,
        string $url,
        int $httpStatus,
        string $responseBody = '',
    ): self {
        $snippet = self::snippet($responseBody);

        return new self(
            'tokentify: ' . $operation . ' failed with HTTP ' . $httpStatus . ' for ' . $url
            . ($snippet !== '' ? ' — body: ' . $snippet : ''),
            0,
            null,
            [
                'sdk_error' => 'http_error',
                'operation' => $operation,
                'url' => $url,
                'http_status' => $httpStatus,
                'response_body' => $responseBody,
            ],
        );
    }

    public static function invalidApiKey(string $url, int $httpStatus, string $responseBody = ''): self
    {
        return new self(
            'tokentify: invalid or revoked API key for ingest. Set a valid TOKENTIFY_API_KEY.',
            0,
            null,
            [
                'sdk_error' => 'invalid_api_key',
                'operation' => 'POST /v1/ingest (auth check)',
                'url' => $url,
                'http_status' => $httpStatus,
                'response_body' => $responseBody,
            ],
        );
    }

    private static function snippet(string $body, int $max = 500): string
    {
        $t = trim($body);
        if ($t === '') {
            return '';
        }
        if (strlen($t) <= $max) {
            return $t;
        }

        return substr($t, 0, $max) . '…';
    }
}
