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
            try {
                $promise = $handler($request, $options);
            } catch (\Throwable $e) {
                if ($ctx !== null) {
                    $transport->onFailure($ctx, $e);
                }
                throw $e;
            }
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
                    static function ($reason) use ($ctx, $transport) {
                        // An error response turned into an exception by an inner middleware is metered from its
                        // response; a network error / timeout is recorded as a failed call. Either way the
                        // rejection reaches the caller unchanged.
                        $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                        if ($response instanceof ResponseInterface) {
                            $transport->afterResponse($ctx, $response);
                        } elseif ($reason instanceof \Throwable) {
                            $transport->onFailure($ctx, $reason);
                        }

                        if ($reason instanceof \Throwable) {
                            throw $reason;
                        }

                        return \GuzzleHttp\Promise\Create::rejectionFor($reason);
                    },
                );
            }

            return $promise;
        };
    }
}
