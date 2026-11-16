<?php

declare(strict_types=1);

namespace Tokentify\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 decorator: wrap any HTTP client so calls to AI providers are metered.
 *
 *     $http = Tokentify::httpClient(new GuzzleHttp\Client());
 *     $openai = OpenAI::factory()->withApiKey($key)->withHttpClient($http)->make();
 */
final class MeteredHttpClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Transport $transport,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        [$request, $ctx] = $this->transport->beforeRequest($request);
        $response = $this->client->sendRequest($request);

        return $ctx === null ? $response : $this->transport->afterResponse($ctx, $response);
    }

    /** The wrapped client. */
    public function inner(): ClientInterface
    {
        return $this->client;
    }
}
