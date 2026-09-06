<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Test\Unit\Plugin;

use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Model\Policy\Renderer\SimplePolicyHeaderRenderer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Response\HttpInterface;
use PHPUnit\Framework\TestCase;
use ReportUri\CspReporting\Model\Config\Endpoints;
use ReportUri\CspReporting\Plugin\AddReportingKeywords;

class AddReportingKeywordsTest extends TestCase
{
    private SimplePolicyHeaderRenderer $renderer;
    private HttpInterface $response;

    protected function setUp(): void
    {
        $this->renderer = $this->createStub(SimplePolicyHeaderRenderer::class);
        $this->response = $this->createStub(HttpInterface::class);
    }

    private function plugin(bool $sample, bool $hashes): AddReportingKeywords
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturnMap([
            [Endpoints::XML_PATH_REPORT_SAMPLE, 'store', null, $sample],
            [Endpoints::XML_PATH_REPORT_HASHES, 'store', null, $hashes],
        ]);

        return new AddReportingKeywords($config);
    }

    private function policy(string $id, string $value = "'self'"): PolicyInterface
    {
        $policy = $this->createStub(PolicyInterface::class);
        $policy->method('getId')->willReturn($id);
        $policy->method('getValue')->willReturn($value);

        return $policy;
    }

    public function testAddsBothKeywordsWhenBothAreOn(): void
    {
        [$policy] = $this->plugin(true, true)
            ->beforeRender($this->renderer, $this->policy('script-src'), $this->response);

        $this->assertSame("'self' 'report-sample' 'report-sha256'", $policy->getValue());
    }

    public function testAddsOnlyWhatIsEnabled(): void
    {
        [$policy] = $this->plugin(true, false)
            ->beforeRender($this->renderer, $this->policy('script-src'), $this->response);

        $this->assertSame("'self' 'report-sample'", $policy->getValue());
    }

    /**
     * With nothing enabled the original policy object must come back untouched, not a decorator
     * that happens to add nothing - a wrapper is still a different object to every other plugin
     * on this method.
     */
    public function testReturnsThePolicyUnwrappedWhenBothAreOff(): void
    {
        $original = $this->policy('script-src');

        [$policy] = $this->plugin(false, false)->beforeRender($this->renderer, $original, $this->response);

        $this->assertSame($original, $policy);
    }

    /**
     * These keywords are only meaningful on script-src. Adding them elsewhere would put unknown
     * source expressions into directives that never asked for them.
     */
    public function testLeavesEveryOtherDirectiveAlone(): void
    {
        foreach (['style-src', 'img-src', 'default-src', 'connect-src', 'form-action'] as $id) {
            $original = $this->policy($id);

            [$policy] = $this->plugin(true, true)->beforeRender($this->renderer, $original, $this->response);

            $this->assertSame($original, $policy, "$id should not be modified");
        }
    }

    public function testPassesTheResponseThroughUnchanged(): void
    {
        [, $response] = $this->plugin(true, true)
            ->beforeRender($this->renderer, $this->policy('script-src'), $this->response);

        $this->assertSame($this->response, $response);
    }
}
