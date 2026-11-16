<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/support.php';

use Tokentify\Tokentify;

$openai = openaiClient();

echo answer($openai, 'u_123', 'acme', 'chat_789', 'Where is my order A-1001?'), PHP_EOL;

answerStreaming($openai, 'u_123', 'Say hello in three languages.', function (string $text): void {
    echo $text;
});
echo PHP_EOL;

recordSms('u_123', 2);

// Queued usage is sent automatically at exit. Long-running workers can flush explicitly, e.g. per job:
Tokentify::flush();
