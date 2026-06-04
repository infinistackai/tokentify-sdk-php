<?php

declare(strict_types=1);

namespace UsageMeter\Exception;

/**
 * Common surface for SDK errors so callers can log structured context.
 */
interface SdkExceptionInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getContext(): array;
}
