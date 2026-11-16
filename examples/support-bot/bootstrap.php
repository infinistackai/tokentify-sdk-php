<?php

declare(strict_types=1);

/*
 * Run once at startup (front controller, Laravel service provider, worker boot, ...).
 */

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Tokentify\Tokentify;

require __DIR__ . '/vendor/autoload.php';

Tokentify::loadEnv(__DIR__ . '/.env'); // read .env (existing environment variables win)
Tokentify::setup();                    // check the API key, then start tracking

/**
 * One HTTP client for OpenAI, with Tokentify metering on its handler stack.
 * Pass $handler only to swap the transport (e.g. a mock in tests).
 */
function openaiClient(?callable $handler = null): Client
{
    $stack = HandlerStack::create($handler);
    $stack->push(Tokentify::guzzleMiddleware(), 'tokentify');

    return new Client([
        'base_uri' => 'https://api.openai.com/v1/',
        'handler' => $stack,
        'headers' => ['Authorization' => 'Bearer ' . getenv('OPENAI_API_KEY')],
        'timeout' => 60,
    ]);
}
