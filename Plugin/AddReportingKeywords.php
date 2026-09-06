<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Plugin;

use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Model\Policy\Renderer\SimplePolicyHeaderRenderer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Response\HttpInterface as HttpResponse;
use Magento\Store\Model\ScopeInterface;
use ReportUri\CspReporting\Model\Config\Endpoints;
use ReportUri\CspReporting\Model\Policy\AugmentedPolicy;

/**
 * Adds the two reporting keywords to script-src.
 *
 * Neither changes what the browser allows to execute. Verified against Chromium 149 with an
 * enforcing policy: 'unsafe-inline' keeps working alongside both, while a genuine hash source
 * in the same position disables it. That distinction matters on Magento, where checkout
 * enforces from 2.4.7 and the storefront leans on 'unsafe-inline'.
 *
 * They are separate settings because their costs are not comparable:
 *
 *   'report-sample'  adds the first 40 characters of the offending script to reports that were
 *                    already being sent. No extra reports, so no extra volume. On by default.
 *
 *   'report-sha256'  asks the browser for integrity telemetry on every script of every page
 *                    load, not just on violations. The volume is proportional to traffic rather
 *                    than to problems, and it counts against the account's quota. Off by
 *                    default; a busy storefront should turn it on deliberately.
 *
 * A beforeRender plugin rather than an afterRender one: this changes a single directive, so it
 * decorates that policy on its way in instead of rewriting the assembled header on the way out.
 */
class AddReportingKeywords
{
    private const SCRIPT_SRC = 'script-src';

    private const KEYWORD_SAMPLE = "'report-sample'";
    private const KEYWORD_HASHES = "'report-sha256'";

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return array{0: PolicyInterface, 1: HttpResponse}
     */
    public function beforeRender(
        SimplePolicyHeaderRenderer $subject,
        PolicyInterface $policy,
        HttpResponse $response
    ): array {
        if ($policy->getId() !== self::SCRIPT_SRC) {
            return [$policy, $response];
        }

        $additions = [];

        if ($this->isEnabled(Endpoints::XML_PATH_REPORT_SAMPLE)) {
            $additions[] = self::KEYWORD_SAMPLE;
        }

        if ($this->isEnabled(Endpoints::XML_PATH_REPORT_HASHES)) {
            $additions[] = self::KEYWORD_HASHES;
        }

        if ($additions === []) {
            return [$policy, $response];
        }

        return [new AugmentedPolicy($policy, $additions), $response];
    }

    private function isEnabled(string $path): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE);
    }
}
