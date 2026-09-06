<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Test\Unit\Model\Policy;

use Magento\Csp\Api\Data\PolicyInterface;
use PHPUnit\Framework\TestCase;
use ReportUri\CspReporting\Model\Policy\AugmentedPolicy;

class AugmentedPolicyTest extends TestCase
{

    private function policy(string $id, string $value): PolicyInterface
    {
        $policy = $this->createStub(PolicyInterface::class);
        $policy->method('getId')->willReturn($id);
        $policy->method('getValue')->willReturn($value);

        return $policy;
    }

    public function testAppendsKeywordsAndLeavesTheIdAlone(): void
    {
        $augmented = new AugmentedPolicy(
            $this->policy('script-src', "'self' 'unsafe-inline'"),
            ["'report-sample'", "'report-sha256'"]
        );

        $this->assertSame('script-src', $augmented->getId());
        $this->assertSame("'self' 'unsafe-inline' 'report-sample' 'report-sha256'", $augmented->getValue());
    }

    /**
     * The renderer can be reached more than once for one response, and a policy could already
     * carry a keyword from elsewhere. A duplicate would not change what a browser does, but it
     * would leave a merchant reading their own header wondering which copy was doing something.
     */
    public function testDoesNotRepeatAKeywordThePolicyAlreadyCarries(): void
    {
        $augmented = new AugmentedPolicy(
            $this->policy('script-src', "'self' 'report-sample'"),
            ["'report-sample'", "'report-sha256'"]
        );

        $this->assertSame("'self' 'report-sample' 'report-sha256'", $augmented->getValue());
    }

    public function testReturnsTheOriginalWhenEverythingIsAlreadyPresent(): void
    {
        $augmented = new AugmentedPolicy(
            $this->policy('script-src', "'self' 'report-sample'"),
            ["'report-sample'"]
        );

        $this->assertSame("'self' 'report-sample'", $augmented->getValue());
    }

    public function testHandlesAPolicyWithNoSourcesYet(): void
    {
        $augmented = new AugmentedPolicy($this->policy('script-src', ''), ["'report-sample'"]);

        $this->assertSame("'report-sample'", $augmented->getValue());
    }
}
