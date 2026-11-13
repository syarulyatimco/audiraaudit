<?php

namespace Tests\Unit;

use App\Services\TrialBalance\AmountParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountParserTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['1234.56', 123456], ['1,234.56', 123456], ['RM 1,234.56', 123456], ['(1,234.56)', -123456],
            ['1,234.56-', -123456], ['-1234.5', -123450], ['1,234.56 CR', -123456], ['1,234.56 DR', 123456],
            ['', 0], ['-', 0], ['0', 0], ['1000', 100000], ['1234.5599999999', 123456], ['1.5E3', 150000],
            [12.34, 1234], [7, 700], ['  2,000.00  ', 200000],
        ];
    }

    #[DataProvider('cases')]
    public function test_parses_accounting_formats(mixed $input, int $expected): void
    {
        $this->assertSame($expected, AmountParser::toSen($input));
    }

    public function test_rejects_garbage(): void
    {
        foreach (['abc', '12,34,5x', '1.2.3', '=SUM(A1)'] as $bad) {
            try {
                AmountParser::toSen($bad);
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_formats_negative_in_brackets(): void
    {
        $this->assertSame('(1,234.56)', AmountParser::format(-123456));
        $this->assertSame('0.05', AmountParser::format(5));
    }
}
