<?php

declare(strict_types=1);

namespace UsageMeter\Exception;

/**
 * Thrown for HTTP / network failures when the SDK is configured to surface delivery errors.
 */
final class TransportException extends SdkException
{
    /**
     * @param array<string, mixed> $context
     */
    public static function collectorUnreachable(
        string $operation,
        string $url,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            'UsageMeter: transport error during ' . $operation . ' to ' . $url
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

    /**
     * @param array<string, mixed> $context
     */
    public static function badHttpStatus(
        string $operation,
        string $url,
        int $httpStatus,
        string $responseBody = '',
    ): self {
        $snippet = self::snippet($responseBody);

        return new self(
            'UsageMeter: ' . $operation . ' failed with HTTP ' . $httpStatus . ' for ' . $url
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
