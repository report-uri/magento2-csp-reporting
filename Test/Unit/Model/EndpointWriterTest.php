<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Test\Unit\Model;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use PHPUnit\Framework\TestCase;
use ReportUri\CspReporting\Model\Config\Endpoints;
use ReportUri\CspReporting\Model\EndpointWriter;

class EndpointWriterTest extends TestCase
{
    private const ADDRESS = 'https://abc123.report-uri.com/r/d/csp/reportOnly';
    private const REPORT_ONLY = 'https://abc123.report-uri.com/r/d/csp/reportOnly';
    private const ENFORCE = 'https://abc123.report-uri.com/r/d/csp/enforce';

    /** @var array<string, string> */
    private array $stored = [];
    /** @var list<array{string, string}> */
    private array $saved = [];
    /** @var list<string> */
    private array $deleted = [];

    private EndpointWriter $writer;

    protected function setUp(): void
    {
        $this->stored = [
            'csp/mode/storefront/report_only' => '1',
            'csp/mode/admin/report_only' => '1',
            'csp/mode/storefront_checkout_index_index/report_only' => '0',
            'csp/mode/admin_sales_order_create_index/report_only' => '0',
        ];
        $this->saved = [];
        $this->deleted = [];

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn($path) => $this->stored[$path] ?? null);

        $writer = $this->createStub(WriterInterface::class);
        $writer->method('save')->willReturnCallback(function ($path, $value): void {
            $this->saved[] = [$path, $value];
            $this->stored[$path] = $value;
        });
        $writer->method('delete')->willReturnCallback(function ($path): void {
            $this->deleted[] = $path;
            unset($this->stored[$path]);
        });

        $this->writer = new EndpointWriter(
            $writer,
            $scopeConfig,
            $this->createStub(ReinitableConfigInterface::class),
            new Endpoints($scopeConfig)
        );
    }

    public function testWritesAllFourFieldsWithTheRightDisposition(): void
    {
        $this->writer->apply(self::ADDRESS, 'default', 0);

        $this->assertSame([
            ['csp/mode/storefront/report_uri', self::REPORT_ONLY],
            ['csp/mode/admin/report_uri', self::REPORT_ONLY],
            ['csp/mode/storefront_checkout_index_index/report_uri', self::ENFORCE],
            ['csp/mode/admin_sales_order_create_index/report_uri', self::ENFORCE],
        ], $this->saved);
    }

    public function testWritesNothingForAnAddressItCannotParse(): void
    {
        $this->writer->apply('https://collector.example.com/csp', 'default', 0);
        $this->writer->apply('', 'default', 0);

        $this->assertSame([], $this->saved);
    }

    public function testClearRemovesTheEndpointsItWrote(): void
    {
        $this->writer->apply(self::ADDRESS, 'default', 0);
        $this->writer->clear(self::ADDRESS, 'default', 0);

        $this->assertSame([
            'csp/mode/storefront/report_uri',
            'csp/mode/admin/report_uri',
            'csp/mode/storefront_checkout_index_index/report_uri',
            'csp/mode/admin_sales_order_create_index/report_uri',
        ], $this->deleted);
    }

    /**
     * A merchant who set one of the four by hand keeps it. Clearing our field means "stop what
     * this module started", not "discard whatever is in these boxes".
     */
    public function testClearLeavesAValueTheMerchantSetThemselves(): void
    {
        $this->writer->apply(self::ADDRESS, 'default', 0);
        $this->stored['csp/mode/admin/report_uri'] = 'https://collector.example.com/csp';

        $this->writer->clear(self::ADDRESS, 'default', 0);

        $this->assertNotContains('csp/mode/admin/report_uri', $this->deleted);
        $this->assertSame('https://collector.example.com/csp', $this->stored['csp/mode/admin/report_uri']);
    }

    /**
     * The account in the address is part of the identity. Another Report URI account's endpoint
     * is no more ours to delete than a third party's.
     */
    public function testClearLeavesADifferentReportUriAccountAlone(): void
    {
        $this->stored['csp/mode/storefront/report_uri'] = 'https://other99.report-uri.com/r/d/csp/reportOnly';

        $this->writer->clear(self::ADDRESS, 'default', 0);

        $this->assertSame([], $this->deleted);
    }

    public function testClearDoesNothingWithoutAUsablePreviousAddress(): void
    {
        $this->writer->clear('', 'default', 0);
        $this->writer->clear('nonsense', 'default', 0);

        $this->assertSame([], $this->deleted);
    }

    /**
     * Ticking "Use system value" deletes the row instead of saving one, so afterSave() never
     * runs and the recompute has to work from whatever config holds afterwards.
     */
    public function testReapplyFromConfigRewritesFromTheStoredAddress(): void
    {
        $this->stored[Endpoints::XML_PATH_ADDRESS] = self::ADDRESS;

        $this->writer->reapplyFromConfig('default', 0);

        $this->assertCount(4, $this->saved);
        $this->assertSame(self::ENFORCE, $this->stored['csp/mode/storefront_checkout_index_index/report_uri']);
    }

    public function testReapplyFromConfigDoesNothingWithNoAddressStored(): void
    {
        $this->writer->reapplyFromConfig('default', 0);

        $this->assertSame([], $this->saved);
    }
}
