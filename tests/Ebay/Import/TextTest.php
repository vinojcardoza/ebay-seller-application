<?php

declare(strict_types=1);

namespace Tests\Ebay\Import;

use App\Ebay\Import\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for making eBay text storable in utf8mb3 columns.
 */
#[CoversClass(Text::class)]
final class TextTest extends TestCase
{
    /**
     * Input text and what is stored.
     *
     * @return array<string, array{string, string}>
     */
    public static function forDatabaseProvider(): array
    {
        return [
            'plain text unchanged' => ['Great seller, fast delivery', 'Great seller, fast delivery'],
            '3-byte characters kept' => ['Café £6.99 – “ok” ✓', 'Café £6.99 – “ok” ✓'],
            'emoji between words' => ["Great \u{1F44D} seller", 'Great seller'],
            'emoji at the end' => ["Thanks!!\u{1F44D}\u{1F44D}", 'Thanks!!'],
            'emoji at the start' => ["\u{1F600} Lovely", 'Lovely'],
            'skin tone modifier' => ["Good \u{1F44D}\u{1F3FB} item", 'Good item'],
            'zero-width-joiner sequence' => ["Fab \u{1F468}\u{200D}\u{1F4BB}\u{FE0F} work", 'Fab work'],
            'only emoji' => ["\u{1F44D}", ''],
            'invalid UTF-8' => ["Bad \xFF byte", 'Bad ? byte'],
            'trimmed' => ['  padded  ', 'padded'],
        ];
    }

    /**
     * Characters above U+FFFF (emoji) are removed with their joiners; everything else is kept.
     */
    #[DataProvider('forDatabaseProvider')]
    public function testForDatabase(string $value, string $expected): void
    {
        $this->assertSame($expected, Text::forDatabase($value));
    }
}
