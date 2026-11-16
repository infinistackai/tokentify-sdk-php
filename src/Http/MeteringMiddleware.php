<?php

declare(strict_types=1);

namespace Tokentify\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware that meters calls to known AI providers (streaming included).
 *
 *     $stack = GuzzleHttp\HandlerStack::create();
 *     $stack->push(Tokentify::guzzleMiddleware(), 'tokentify');
 *     $client = new GuzzleHttp\Client(['handler' => $stack]);
 *
 * Other hosts pass through untouched. Metering never throws into your request.
 */
final class MeteringMiddleware
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param callable(RequestInterface, array<string, mixed>): mixed $handler
     * @return callable(RequestInterface, array<string, mixed>): mixed
     */
    public function __invoke(callable $handler): callable
    {
        $transport = $this->transport;

        return static function (RequestInterface $request, array $options) use ($handler, $transport) {
            [$request, $ctx] = $transport->beforeRequest($request);
            $promise = $handler($request, $options);
            if ($ctx === null) {
                return $promise;
            }
            if ($promise instanceof ResponseInterface) {
                return $transport->afterResponse($ctx, $promise);
            }
            if (is_object($promise) && method_exists($promise, 'then')) {
                return $promise->then(
                    static fn ($response) => $response instanceof ResponseInterface
                        ? $transport->afterResponse($ctx, $response)
                        : $response,
                );
            }

            return $promise;
        };
    }
}
