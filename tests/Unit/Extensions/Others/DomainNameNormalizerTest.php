<?php

namespace Tests\Unit\Extensions\Others;

use InvalidArgumentException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainNameNormalizer;
use PHPUnit\Framework\TestCase;

class DomainNameNormalizerTest extends TestCase
{
    public function test_it_normalises_a_plain_sld(): void
    {
        $this->assertSame([
            'sld' => 'example',
            'tld' => '.co.za',
        ], $this->normaliser()->normalise(
            ' EXAMPLE ',
            ['.com', '.co.za'],
            '.co.za',
        ));
    }

    public function test_it_extracts_the_longest_available_tld(): void
    {
        $this->assertSame([
            'sld' => 'example',
            'tld' => '.co.za',
        ], $this->normaliser()->normalise(
            'example.co.za',
            ['.za', '.co.za'],
            '.za',
        ));
    }

    public function test_it_normalises_a_url(): void
    {
        $this->assertSame([
            'sld' => 'example',
            'tld' => '.co.za',
        ], $this->normaliser()->normalise(
            'https://example.co.za/path?source=test',
            ['.co.za'],
            '.co.za',
        ));
    }

    public function test_it_removes_an_optional_www_prefix(): void
    {
        $this->assertSame([
            'sld' => 'example',
            'tld' => '.co.za',
        ], $this->normaliser()->normalise(
            'www.example.co.za',
            ['.co.za'],
            '.co.za',
        ));
    }

    public function test_it_rejects_an_unavailable_tld(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The domain extension is not available.',
        );

        $this->normaliser()->normalise(
            'example.invalid',
            ['.co.za'],
            '.co.za',
        );
    }

    public function test_it_rejects_an_invalid_sld(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The domain name is invalid.',
        );

        $this->normaliser()->normalise(
            '-invalid-',
            ['.co.za'],
            '.co.za',
        );
    }

    private function normaliser(): DomainNameNormalizer
    {
        return new DomainNameNormalizer;
    }
}
