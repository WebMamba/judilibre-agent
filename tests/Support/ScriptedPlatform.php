<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * Stands in for Anthropic in the test environment: each model round is a list of
 * streamed deltas the test scripts. (From WebMamba/demo-ag-ui.)
 */
final class ScriptedPlatform
{
    /** @var list<list<DeltaInterface>> */
    public static array $rounds = [];

    /** @var list<array{input: mixed, options: array<string, mixed>}> what the model was sent, round by round */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$rounds = [];
        self::$calls = [];
    }

    public static function create(): Platform
    {
        return MockPlatformFactory::createPlatform(static function (mixed $model, mixed $input, array $options): StreamResult {
            self::$calls[] = ['input' => $input, 'options' => $options];
            $deltas = array_shift(self::$rounds) ?? [];

            return new StreamResult((static fn (): \Generator => yield from $deltas)());
        });
    }
}
