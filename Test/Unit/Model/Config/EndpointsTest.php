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
 * The parser and the mode resolution are where a wrong answer is silent: a mistyped address and
 * a wrongly-dispositioned endpoint both save cleanly, and the browser never reports back that
 * its reports went nowhere.
 */
class EndpointsTest extends TestCase
{
    private const PERSONAL = 'https://abc123.report-uri.com/r/d/csp/reportOnly';
    private const TEAM = 'https://abc123.report-uri.com/r/t/csp/reportOnly';

    private ScopeConfigInterface $scopeConfig;
    private Endpoints $endpoints;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->endpoints = new Endpoints($this->scopeConfig);
    }

    /**
     * Every shape the Setup page can hand a merchant. Refusing any of them would send somebody
     * back to a page that is showing them the thing we just rejected.
     */
    public static function acceptedAddresses(): array
    {
        return [
            'csp report-only' => ['https://abc123.report-uri.com/r/d/csp/reportOnly', 'abc123', 'd'],
            'csp enforce' => ['https://abc123.report-uri.com/r/d/csp/enforce', 'abc123', 'd'],
            'csp wizard' => ['https://abc123.report-uri.com/r/d/csp/wizard', 'abc123', 'd'],
            'reporting api' => ['https://abc123.report-uri.com/a/d/g', 'abc123', 'd'],
            'team csp' => ['https://abc123.report-uri.com/r/t/csp/enforce', 'abc123', 't'],
            'team reporting api' => ['https://abc123.report-uri.com/a/t/g', 'abc123', 't'],
            'default 32-char token' => [
                'https://deadbeef00112233445566778899aabb.report-uri.com/r/d/csp/reportOnly',
                'deadbeef00112233445566778899aabb',
                'd',
            ],
            'uppercase is lowercased' => ['https://ABC123.REPORT-URI.COM/r/d/csp/reportOnly', 'abc123', 'd'],
            'trailing slash' => ['https://abc123.report-uri.com/a/d/g/', 'abc123', 'd'],
            'surrounding whitespace' => ["  https://abc123.report-uri.com/a/d/g  ", 'abc123', 'd'],
            'inside a whole Report-To header' => [
                'Report-To: {"group":"default","max_age":31536000,'
                . '"endpoints":[{"url":"https://abc123.report-uri.com/a/t/g"}],"include_subdomains":true}',
                'abc123',
                't',
            ],
        ];
    }

    #[DataProvider('acceptedAddresses')]
    public function testParsesEveryAddressTheSetupPageProduces(string $input, string $token, string $scope): void
    {
        $parsed = $this->endpoints->parse($input);

        $this->assertNotNull($parsed, 'Expected this address to be accepted.');
        $this->assertSame($token, $parsed['token']);
        $this->assertSame('report-uri.com', $parsed['host']);
        $this->assertSame($scope, $parsed['scope']);
    }

    public static function rejectedAddresses(): array
    {
        return [
            'bare subdomain' => ['abc123'],
            'someone else entirely' => ['https://abc123.evil.example/r/d/csp/reportOnly'],
            'lookalike host' => ['https://abc123.notreport-uri.com/r/d/csp/reportOnly'],
            'plain http' => ['http://abc123.report-uri.com/r/d/csp/reportOnly'],
            'token too short' => ['https://ab.report-uri.com/r/d/csp/reportOnly'],
            'token too long' => ['https://' . str_repeat('a', 33) . '.report-uri.com/r/d/csp/reportOnly'],
            'unknown account scope' => ['https://abc123.report-uri.com/r/x/csp/reportOnly'],
            'unknown disposition' => ['https://abc123.report-uri.com/r/d/csp/bogus'],
            'hyphen in token' => ['https://abc-123.report-uri.com/r/d/csp/reportOnly'],
            'empty' => [''],
            'free text' => ['just some text'],
        ];
    }

    #[DataProvider('rejectedAddresses')]
    public function testRejectsAnythingItCannotVouchFor(string $input): void
    {
        $this->assertNull($this->endpoints->parse($input));
        $this->assertFalse($this->endpoints->isValidAddress($input));
    }

    /**
     * The account scope is the half of the address that cannot be guessed, and getting it wrong
     * sends a team's reports to a personal endpoint.
     */
    public function testAccountScopeSurvivesEveryDerivation(): void
    {
        $this->assertSame(
            'https://abc123.report-uri.com/r/t/csp/reportOnly',
            $this->endpoints->reportOnlyUrl(self::TEAM)
        );
        $this->assertSame(
            'https://abc123.report-uri.com/r/t/csp/enforce',
            $this->endpoints->enforceUrl(self::TEAM)
        );
        $this->assertSame(
            'https://abc123.report-uri.com/a/t/g',
            $this->endpoints->reportingApiUrl(self::TEAM)
        );
    }

    /**
     * The CSP endpoint takes application/csp-report and the Reporting API endpoint takes
     * application/reports+json. Deriving one from the other has to change the path, not just
     * pass the address through.
     */
    public function testReportingApiAddressIsADifferentPathFromTheCspOne(): void
    {
        $this->assertSame(
            'https://abc123.report-uri.com/a/d/g',
            $this->endpoints->reportingApiUrl(self::PERSONAL)
        );
    }

    public function testDerivationsReturnNullForAnAddressItDoesNotOwn(): void
    {
        $this->assertNull($this->endpoints->reportOnlyUrl('https://abc123.evil.example/r/d/csp/reportOnly'));
        $this->assertNull($this->endpoints->enforceUrl('nonsense'));
        $this->assertNull($this->endpoints->reportingApiUrl(''));
        $this->assertSame([], $this->endpoints->valuesFor('nonsense', 'default', 0));
    }

    public function testWritesToTheFourNativeFieldsAndNoOthers(): void
    {
        $this->assertSame(
            [
                'csp/mode/storefront/report_uri',
                'csp/mode/admin/report_uri',
                'csp/mode/storefront_checkout_index_index/report_uri',
                'csp/mode/admin_sales_order_create_index/report_uri',
            ],
            $this->endpoints->allPaths()
        );
    }

    /**
     * Stock 2.4.7 and later: the two defaults report, the two payment pages enforce. The
     * disposition has to match or violations arrive tagged as the wrong kind.
     */
    public function testStockLayoutGivesEachFieldTheDispositionItsPageActuallyUses(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['csp/mode/storefront/report_only', 'default', null, '1'],
            ['csp/mode/admin/report_only', 'default', null, '1'],
            ['csp/mode/storefront_checkout_index_index/report_only', 'default', null, '0'],
            ['csp/mode/admin_sales_order_create_index/report_only', 'default', null, '0'],
        ]);

        $this->assertSame(
            [
                'csp/mode/storefront/report_uri' => 'https://abc123.report-uri.com/r/d/csp/reportOnly',
                'csp/mode/admin/report_uri' => 'https://abc123.report-uri.com/r/d/csp/reportOnly',
                'csp/mode/storefront_checkout_index_index/report_uri' => 'https://abc123.report-uri.com/r/d/csp/enforce',
                'csp/mode/admin_sales_order_create_index/report_uri' => 'https://abc123.report-uri.com/r/d/csp/enforce',
            ],
            $this->endpoints->valuesFor(self::PERSONAL, 'default', 0)
        );
    }

    /**
     * A store that switches its storefront to restrict mode should get the enforce endpoint
     * there without being asked. This is the case a "separate the enforcing pages" toggle could
     * never have answered, because it did not know which pages those were.
     */
    public function testEndpointFollowsAPageWhoseModeTheStoreHasChanged(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['csp/mode/storefront/report_only', 'default', null, '0'],
            ['csp/mode/admin/report_only', 'default', null, '1'],
            ['csp/mode/storefront_checkout_index_index/report_only', 'default', null, '0'],
            ['csp/mode/admin_sales_order_create_index/report_only', 'default', null, '0'],
        ]);

        $values = $this->endpoints->valuesFor(self::PERSONAL, 'default', 0);

        $this->assertSame('https://abc123.report-uri.com/r/d/csp/enforce', $values['csp/mode/storefront/report_uri']);
        $this->assertSame('https://abc123.report-uri.com/r/d/csp/reportOnly', $values['csp/mode/admin/report_uri']);
    }

    /**
     * A page with no report_only of its own inherits its area's. Mirrors
     * Magento\Csp\Model\Mode\ConfigManager::getConfigured(), where null means "not set here"
     * rather than "enforce".
     */
    public function testPageWithNoModeOfItsOwnInheritsItsArea(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['csp/mode/storefront/report_only', 'default', null, '1'],
            ['csp/mode/admin/report_only', 'default', null, '1'],
            ['csp/mode/storefront_checkout_index_index/report_only', 'default', null, null],
            ['csp/mode/admin_sales_order_create_index/report_only', 'default', null, null],
        ]);

        $values = $this->endpoints->valuesFor(self::PERSONAL, 'default', 0);

        $this->assertSame(
            'https://abc123.report-uri.com/r/d/csp/reportOnly',
            $values['csp/mode/storefront_checkout_index_index/report_uri'],
            'An unset page mode must fall back to its area, not default to enforcing.'
        );
    }
}
