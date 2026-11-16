<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Tokentify\Tokentify;

/**
 * Answer a customer question. Every OpenAI call inside withTags() is recorded with the user,
 * account, session and team, plus its tokens and cost. No tracking code around the call itself.
 */
function answer(Client $openai, string $userId, string $company, string $chatId, string $question): string
{
    return Tokentify::withTags(
        ['user_id' => $userId, 'account_id' => $company, 'session_id' => $chatId, 'team' => 'support'],
        function () use ($openai, $question): string {
            // A tool the model can use. Each call is recorded as a tool event (arguments, result, duration).
            $lookupOrder = Tokentify::tool(
                fn (string $orderId): array => ['order_id' => $orderId, 'status' => 'shipped'],
                'lookup_order',
            );

            $run = Tokentify::agentRun(); // groups the tool calls of this request under one trace id
            $order = $run->run(fn () => $lookupOrder('A-1001'));

            $response = $openai->post('chat/completions', ['json' => [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Order data: ' . json_encode($order)],
                    ['role' => 'user', 'content' => $question],
                ],
            ]]);
            $data = json_decode((string) $response->getBody(), true);

            return (string) ($data['choices'][0]['message']['content'] ?? '');
        },
    );
}

/** Streaming works the same way; the call is recorded once, when the stream ends. */
function answerStreaming(Client $openai, string $userId, string $question, callable $onText): void
{
    Tokentify::withTags(['user_id' => $userId], function () use ($openai, $question, $onText): void {
        $response = $openai->post('chat/completions', [
            'stream' => true,
            'json' => [
                'model' => 'gpt-4o-mini',
                'stream' => true,
                'stream_options' => ['include_usage' => true], // OpenAI only reports stream usage when asked
                'messages' => [['role' => 'user', 'content' => $question]],
            ],
        ]);
        $body = $response->getBody();
        $buffer = '';
        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);
                if (str_starts_with($line, 'data: {')) {
                    $chunk = json_decode(substr($line, 6), true);
                    $onText((string) ($chunk['choices'][0]['delta']['content'] ?? ''));
                }
            }
        }
    });
}

/** Usage that does not go through Guzzle (an SMS, a cURL call, ...) can be recorded by hand. */
function recordSms(string $userId, int $segments): void
{
    Tokentify::track('sms', 'twilio', 'send', $segments, 'segments', metadata: ['user_id' => $userId]);
}
