<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Plugin;

use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Model\Policy\Renderer\SimplePolicyHeaderRenderer;
use Magento\Framework\App\Response\HttpInterface as HttpResponse;
use Magento\Framework\Serialize\Serializer\Json;
use ReportUri\CspReporting\Model\Config\Endpoints;

/**
 * Points the Reporting API at the Reporting API endpoint.
 *
 * Magento builds its Report-To header out of the CSP report URI:
 *
 *     $reportToData = ['group' => 'report-endpoint', 'max_age' => 10886400,
 *                      'endpoints' => [['url' => $config->getReportUri()]]];
 *
 * and adds `report-to report-endpoint` to the policy alongside `report-uri`. That assumes one
 * address serves both mechanisms. For Report URI it does not: the CSP endpoint accepts
 * application/csp-report and the Reporting API endpoint accepts application/reports+json, and
 * each rejects the other's format.
 *
 * A browser that supports the Reporting API prefers `report-to` over `report-uri` and ignores
 * the latter entirely, so on a store that has filled the fields in correctly those reports are
 * posted to an endpoint that will not take them. Firefox and Safari keep working, because they
 * fall back to `report-uri`. The result is a store that looks configured, reports from some
 * browsers, and silently drops the rest.
 *
 * This runs after the renderer rather than before it: Magento only adds the two directives
 * inside `if (... && !$response->getHeader('Report-To'))`, so setting the header first would
 * suppress `report-uri` and `report-to` as well. That same guard is why the renderer adds the
 * directives only once even though it runs per policy.
 *
 * Both headers are set. Reporting-Endpoints is the current mechanism; Report-To is kept for
 * older browsers and because Network Error Logging is only deliverable over Report-To.
 */
class RepointReportingApi
{
    /**
     * The group name Report URI's own documentation uses throughout. The name is client-side
     * only - it is how the policy's `report-to` directive finds an endpoint, and it never
     * reaches the collector - but matching the docs means a merchant comparing their headers
     * against the setup guide sees the same string rather than Magento's internal one.
     *
     * Renaming it means rewriting the policy too: a directive naming a group that no longer
     * exists resolves to nothing, which is a quieter failure than the one being fixed here.
     */
    private const GROUP = 'default';

    private const CSP_HEADERS = [
        'Content-Security-Policy',
        'Content-Security-Policy-Report-Only',
    ];

    /**
     * RepointReportingApi constructor.
     *
     * @param Json $json
     * @param Endpoints $endpoints
     */
    public function __construct(
        private readonly Json $json,
        private readonly Endpoints $endpoints
    ) {
    }

    /**
     * Point the Reporting API headers at the Reporting API endpoint.
     *
     * @param SimplePolicyHeaderRenderer $subject
     * @param array|null $result
     * @param PolicyInterface $policy
     * @param HttpResponse $response
     * @return ?array
     */
    public function afterRender(
        SimplePolicyHeaderRenderer $subject,
        ?array $result,
        PolicyInterface $policy,
        HttpResponse $response
    ): ?array {
        $header = $response->getHeader('Report-To');

        if (!$header) {
            return $result;
        }

        $group = $this->parseGroup((string)$header->getFieldValue());

        if ($group === null) {
            return $result;
        }

        [$groupName, $cspUrl, $maxAge] = $group;
        $reportingApiUrl = $this->endpoints->reportingApiUrl($cspUrl);

        // Not a Report URI address. Another collector may legitimately serve both mechanisms
        // from one URL, so leave anything we do not recognise exactly as it is.
        if ($reportingApiUrl === null) {
            return $result;
        }

        $response->setHeader('Report-To', $this->json->serialize([
            'group' => self::GROUP,
            'max_age' => $maxAge,
            'endpoints' => [['url' => $reportingApiUrl]],
        ]), true);

        $response->setHeader(
            'Reporting-Endpoints',
            sprintf('%s="%s"', self::GROUP, $reportingApiUrl),
            true
        );

        if ($groupName !== self::GROUP) {
            $this->renameGroupInPolicies($response, $groupName);
        }

        return $result;
    }

    /**
     * Repoint the policy's `report-to` directive at the renamed group.
     *
     * Both header names are checked because a store can carry an enforced policy and a
     * report-only one at the same time - Magento_Checkout enforces while the rest of the
     * storefront reports - and each is rendered into its own header.
     *
     * @param HttpResponse $response
     * @param string $groupName
     */
    private function renameGroupInPolicies(HttpResponse $response, string $groupName): void
    {
        foreach (self::CSP_HEADERS as $name) {
            $header = $response->getHeader($name);

            if (!$header) {
                continue;
            }

            $value = (string)$header->getFieldValue();
            $updated = preg_replace(
                '/\breport-to\s+' . preg_quote($groupName, '/') . '\b/',
                'report-to ' . self::GROUP,
                $value
            );

            if (is_string($updated) && $updated !== $value) {
                $response->setHeader($name, $updated, true);
            }
        }
    }

    /**
     * Read the group name, endpoint and max age out of a Report-To header.
     *
     * @param string $headerValue
     * @return ?array
     */
    private function parseGroup(string $headerValue): ?array
    {
        try {
            $data = $this->json->unserialize($headerValue);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $groupName = $data['group'] ?? null;
        $url = $data['endpoints'][0]['url'] ?? null;

        if (!is_string($groupName) || !is_string($url)) {
            return null;
        }

        return [$groupName, $url, (int)($data['max_age'] ?? 10886400)];
    }
}
