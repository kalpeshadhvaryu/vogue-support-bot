<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Phone\PhoneMatcher;
use VogueHosting\SupportBot\Phone\PhoneNormalizer;

final class PhoneNormalizerTest extends TestCase
{
    #[DataProvider('canonicalCases')]
    public function testCanonicalForms(string $input, string $expected): void
    {
        $normalizer = new PhoneNormalizer('91');
        self::assertSame($expected, $normalizer->canonicalize($input));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function canonicalCases(): array
    {
        return [
            ['+91 98765 43210', '919876543210'],
            ['09876543210', '919876543210'],
            ['9876543210', '919876543210'],
            ['919876543210', '919876543210'],
            ['+91 098765 43210', '919876543210'],
            ['00919876543210', '919876543210'],
            ['(+91) 98765-43210', '919876543210'],
            ['+44 20 7946 0958', '442079460958'],
            ['00442079460958', '442079460958'],
            ['', ''],
            ['no digits', ''],
        ];
    }

    public function testIndianFormatsMatchEachOther(): void
    {
        $normalizer = new PhoneNormalizer('91');
        self::assertTrue($normalizer->matches('+91 98765 43210', '09876543210'));
        self::assertTrue($normalizer->matches('9876543210', '919876543210'));
        self::assertFalse($normalizer->matches('+44 20 7946 0958', '+91 9876543210'));
        self::assertFalse($normalizer->matches('', '9876543210'));
        self::assertFalse($normalizer->matches('123', '123'));
    }

    public function testSplitUsesTheConfiguredCountry(): void
    {
        $normalizer = new PhoneNormalizer('91');
        self::assertSame(
            ['countryCode' => '+91', 'phoneNumber' => '9876543210'],
            $normalizer->split('09876543210'),
        );
        self::assertNull($normalizer->split('+44 20 7946 0958'));
    }

    public function testMatcherReturnsSortedUniqueIds(): void
    {
        $matcher = new PhoneMatcher(new PhoneNormalizer('91'));
        $ids = $matcher->matchingIds('+91 98765 43210', [
            ['id' => 8, 'phonenumber' => '9876543210'],
            ['id' => 2, 'phonenumber' => '+44 20 7946 0958'],
            ['id' => 8, 'phonenumber' => '09876543210'],
            ['id' => 5, 'phonenumber' => '919876543210'],
        ]);
        self::assertSame([5, 8], $ids);
    }
}
