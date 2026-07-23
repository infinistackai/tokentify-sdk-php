<?php

declare(strict_types=1);

namespace UsageMeter\Test;

use UsageMeter\LlmEvents;
use UsageMeter\Meter;
use UsageMeter\SpeechEvents;
use UsageMeter\SpeechParser;
use UsageMeter\SpeechUsage;
use UsageMeter\TokenUsage;

final class CustomMetricsMetadataTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function getTags(): array
    {
        return ['environment' => 'test'];
    }

    public function testLlmMetadataMirrorsTokenCounts(): void
    {
        $usage = new TokenUsage(1000, 200, 500, 80);
        $event = LlmEvents::buildLlmIngestEvent(
            [
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4.5',
                'endpoint' => 'messages',
            ],
            $usage,
            static fn (): array => self::getTags(),
        );
        $meta = $event['metadata'];
        $this->assertSame(1000, $meta['input_tokens']);
        $this->assertSame(200, $meta['output_tokens']);
        $this->assertSame(1200, $meta['total_tokens']);
        $this->assertSame(500, $meta['cache_read_tokens']);
        $this->assertSame(80, $meta['cache_write_tokens']);
        $this->assertSame('messages', $meta['endpoint']);
        $this->assertSame('anthropic', $meta['provider']);
    }

    public function testSpeechSttDurationAliases(): void
    {
        $usage = new SpeechUsage('stt', 'seconds', 12.5);
        $meta = $usage->toMetadata();
        $this->assertSame('stt_duration', $meta['metering_kind']);
        $this->assertSame('seconds', $meta['canonical_unit']);
        $this->assertSame(12.5, $meta['canonical_quantity']);
        $this->assertSame(12.5, $meta['audio_seconds']);
        $this->assertSame('seconds', $meta['billable_unit']);
    }

    public function testSpeechTtsCharacterAliases(): void
    {
        $usage = new SpeechUsage('tts', 'characters', 842.0);
        $meta = $usage->toMetadata();
        $this->assertSame('tts_characters', $meta['metering_kind']);
        $this->assertSame('characters', $meta['canonical_unit']);
        $this->assertSame(842, $meta['input_characters']);
    }

    public function testSpeechSttWordsAliases(): void
    {
        $usage = new SpeechUsage('stt', 'words', 156.0);
        $meta = $usage->toMetadata();
        $this->assertSame('stt_words', $meta['metering_kind']);
        $this->assertSame('words', $meta['canonical_unit']);
        $this->assertSame(156, $meta['word_count']);
        $this->assertSame(156, $meta['stt_words']);
    }

    public function testSpeechApiRequestAliases(): void
    {
        $usage = new SpeechUsage('stt', 'request', 0.0);
        $this->assertTrue($usage->shouldEmit());
        $meta = $usage->toMetadata();
        $this->assertSame('api_request', $meta['metering_kind']);
        $this->assertSame('request', $meta['canonical_unit']);
        $this->assertSame(1.0, $meta['canonical_quantity']);
        $this->assertSame(1, $meta['request_count']);
        $this->assertSame(1.0, $meta['billable_quantity']);
    }

    public function testParseSttWordsFromUsageMetadata(): void
    {
        $body = '{"text": "ignored", "usage": {"words": 156}}';
        $usage = SpeechParser::parseSttDuration($body, 'openai');
        $this->assertNotNull($usage);
        $this->assertSame('words', $usage->billableUnit);
        $this->assertSame(156.0, $usage->billableQuantity);
        $meta = $usage->toMetadata();
        $this->assertSame(156, $meta['word_count']);
        $this->assertSame('stt_words', $meta['metering_kind']);
    }

    public function testBuildSpeechIngestEventIncludesCanonicalKeys(): void
    {
        $usage = new SpeechUsage('stt', 'seconds', 3.0);
        $event = SpeechEvents::buildSpeechIngestEvent(
            [
                'provider' => 'openai',
                'model' => 'whisper-1',
                'endpoint' => 'audio/transcriptions',
            ],
            $usage,
            static fn (): array => self::getTags(),
        );
        $this->assertSame('stt_duration', $event['metadata']['metering_kind']);
        $this->assertSame(3.0, $event['metadata']['audio_seconds']);
    }

    public function testTrackCustomMapsUnitsIntoMetadata(): void
    {
        self::initMeterForTests();
        Meter::trackCustom(
            eventType: 'embedding',
            service: 'openai',
            operation: 'embed',
            units: 420,
            unitType: 'word_count',
        );
        $events = self::queuedEvents();
        $this->assertCount(1, $events);
        $this->assertSame(420, $events[0]['metadata']['word_count']);
    }

    public function testTrackLlmMetadataMirrors(): void
    {
        self::initMeterForTests();
        Meter::trackLlm([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'input_tokens' => 100,
            'output_tokens' => 50,
        ]);
        $meta = self::queuedEvents()[0]['metadata'];
        $this->assertSame(100, $meta['input_tokens']);
        $this->assertSame(50, $meta['output_tokens']);
        $this->assertSame(150, $meta['total_tokens']);
    }

    public function testTrackCustomEventIsIngestValidAndPriceable(): void
    {
        self::initMeterForTests();
        Meter::trackCustom(
            eventType: 'tts',
            service: 'openai',
            operation: 'audio/speech',
            units: 12.5,
            unitType: 'audio_seconds',
            costUsd: 0.003,
        );
        $event = self::queuedEvents()[0];
        $this->assertSame('openai', $event['provider']);
        $this->assertSame('audio/speech', $event['model']);
        $this->assertIsString($event['timestamp']);
        $this->assertSame(0, $event['input_tokens']);
        $this->assertSame(0, $event['output_tokens']);
        $this->assertSame('audio_seconds', $event['metadata']['billable_unit']);
        $this->assertSame(12.5, $event['metadata']['billable_quantity']);
        $this->assertSame(12.5, $event['metadata']['audio_seconds']);
    }

    public function testTrackCustomEventPrefersMetadataProviderModel(): void
    {
        self::initMeterForTests();
        Meter::trackCustom(
            eventType: 'embedding',
            service: 'custom',
            operation: 'generate',
            units: 420,
            unitType: 'word_count',
            metadata: ['provider' => 'elevenlabs', 'model' => 'eleven-multilingual'],
        );
        $event = self::queuedEvents()[0];
        $this->assertSame('elevenlabs', $event['provider']);
        $this->assertSame('eleven-multilingual', $event['model']);
        $this->assertSame('word_count', $event['metadata']['billable_unit']);
        $this->assertSame(420, $event['metadata']['billable_quantity']);
        $this->assertSame(420, $event['metadata']['word_count']);
    }
}
