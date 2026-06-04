<?php

declare(strict_types=1);

namespace UsageMeter\Exception;

/**
 * Base exception for the PHP SDK (configuration, validation, transport).
 */
class SdkException extends \Exception implements SdkExceptionInterface
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
