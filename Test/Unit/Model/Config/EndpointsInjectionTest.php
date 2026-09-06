<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReportUri\CspReporting\Model\Config\Endpoints;

/**
 * Whatever is typed into this field reaches three response headers - Content-Security-Policy,
 * Report-To and Reporting-Endpoints - so the field is a header-injection surface, and the
 * matching pattern is deliberately unanchored so it would otherwise find a valid address inside
 * a payload carrying anything else.
 *
 * Two things are asserted here. That such payloads are refused outright, and the stronger
 * property behind it: nothing derived from this field is ever echoed from the input. Every URL
 * is rebuilt from captured groups whose character classes cannot express a delimiter, a quote or
 * a control character.
 */
class EndpointsInjectionTest extends TestCase
{
    private const VALID = 'https://abc123.report-uri.com/r/d/csp/reportOnly';

    private Endpoints $endpoints;

    protected function setUp(): void
    {
        $this->endpoints = new Endpoints($this->createStub(ScopeConfigInterface::class));
    }

    public static function injectionPayloads(): array
    {
        return [
            'CRLF then a new header' => [self::VALID . "\r\nX-Injected: 1"],
            'bare LF then a new header' => [self::VALID . "\nX-Injected: 1"],
            'bare CR' => [self::VALID . "\rX-Injected: 1"],
            'CRLF before the address' => ["X-Injected: 1\r\n" . self::VALID],
            'CRLF in the middle' => ['https://abc123.report-uri.com' . "\r\n" . '/r/d/csp/reportOnly'],
            'NUL byte' => [self::VALID . "\0"],
            'NUL before the address' => ["\0" . self::VALID],
            'vertical tab' => [self::VALID . "\v"],
            'form feed' => [self::VALID . "\f"],
            'DEL' => [self::VALID . "\x7F"],
            'escaped CRLF text' => [self::VALID . '%0d%0aX-Injected:%201'],
            'extra CSP directive appended' => [self::VALID . "; script-src 'unsafe-inline'"],
            'closing quote for Reporting-Endpoints' => [self::VALID . '"; default="https://evil.example/'],
            'second endpoint in the same value' => [self::VALID . ' https://evil.example/r/d/csp/reportOnly'],
        ];
    }

    /**
     * Every one of these contains a syntactically valid address, so an unanchored pattern alone
     * would accept them.
     */
    #[DataProvider('injectionPayloads')]
    public function testRefusesAnythingCarryingMoreThanAnAddress(string $payload): void
    {
        $parsed = $this->endpoints->parse($payload);

        if ($parsed === null) {
            $this->assertFalse($this->endpoints->isValidAddress($payload));

            return;
        }

        // A payload that still parses - the trailing-text cases - must not have carried any of
        // itself into the derived URLs.
        foreach ([
            $this->endpoints->reportOnlyUrl($payload),
            $this->endpoints->enforceUrl($payload),
            $this->endpoints->reportingApiUrl($payload),
        ] as $url) {
            $this->assertIsString($url);
            $this->assertMatchesRegularExpression(
                '~^https://[a-z0-9]{4,32}\.[a-z0-9.-]+\.com/(r/[dt]/csp/(reportOnly|enforce)|a/[dt]/g)$~',
                $url,
                'A derived URL must be exactly the shape this module builds, and nothing else.'
            );
        }
    }

    /**
     * The property that makes the rest safe: output is reconstructed, not passed through.
     */
    #[DataProvider('injectionPayloads')]
    public function testNoDerivedUrlEverContainsAControlCharacter(string $payload): void
    {
        $urls = [
            $this->endpoints->reportOnlyUrl($payload),
            $this->endpoints->enforceUrl($payload),
            $this->endpoints->reportingApiUrl($payload),
        ];

        if ($urls === [null, null, null]) {
            $this->assertNull($this->endpoints->parse($payload), 'Refused outright, so nothing is derived.');

            return;
        }

        foreach ($urls as $url) {
            $this->assertNotNull($url, 'Deriving one URL but not another would be incoherent.');

            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $url);
            $this->assertStringNotContainsString('X-Injected', $url);
            $this->assertStringNotContainsString('evil.example', $url);
            $this->assertStringNotContainsString(';', $url);
            $this->assertStringNotContainsString('"', $url);
            $this->assertStringNotContainsString(' ', $url);
        }
    }

    public function testRefusesAPasteTooLargeToBeAnAddress(): void
    {
        $this->assertNull($this->endpoints->parse(self::VALID . str_repeat('a', 4096)));
        $this->assertNull($this->endpoints->parse(str_repeat('a', 4096) . self::VALID));
    }

    /**
     * A long run of host-shaped characters exercises the nested quantifier in the host group.
     * The assertion is the time bound: a pathological input must not turn matching into work.
     */
    public function testDoesNotDegradeOnAHostShapedPayload(): void
    {
        $start = microtime(true);

        for ($i = 0; $i < 200; $i++) {
            $this->endpoints->parse('https://' . str_repeat('a-a.', 200) . 'report-uri.co');
        }

        $this->assertLessThan(2.0, microtime(true) - $start, 'Matching should stay cheap on adversarial input.');
    }

    /**
     * The error a merchant sees must not quote what they typed back at them, or a rejected
     * payload gets rendered somewhere after all.
     */
    public function testTheValidAddressStillWorksAfterAllThatTightening(): void
    {
        $this->assertSame(self::VALID, $this->endpoints->reportOnlyUrl(self::VALID));
        $this->assertSame(
            'https://abc123.report-uri.com/a/d/g',
            $this->endpoints->reportingApiUrl(self::VALID)
        );
        $this->assertSame(
            'https://abc123.report-uri.com/a/t/g',
            $this->endpoints->reportingApiUrl(
                'Report-To: {"group":"default","endpoints":[{"url":"https://abc123.report-uri.com/a/t/g"}]}'
            ),
            'The legitimate multi-part paste must survive the control-character check.'
        );
    }
}
