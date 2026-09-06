<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Test\Unit\Plugin;

use Laminas\Http\Header\GenericHeader;
use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Model\Policy\Renderer\SimplePolicyHeaderRenderer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use ReportUri\CspReporting\Model\Config\Endpoints;
use ReportUri\CspReporting\Plugin\RepointReportingApi;

/**
 * Magento points its Report-To header at the CSP report URI, which for Report URI is the wrong
 * endpoint: the CSP one takes application/csp-report and the Reporting API one takes
 * application/reports+json, and each rejects the other. Since a browser that supports the
 * Reporting API prefers report-to and ignores report-uri, getting this wrong loses reports from
 * exactly the browsers that support the newer mechanism.
 */
class RepointReportingApiTest extends TestCase
{
    private const CSP_URL = 'https://abc123.report-uri.com/r/d/csp/reportOnly';
    private const API_URL = 'https://abc123.report-uri.com/a/d/g';

    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @var HttpInterface
     */
    private HttpInterface $response;
    /**
     * @var RepointReportingApi
     */
    private RepointReportingApi $plugin;
    /**
     * @var SimplePolicyHeaderRenderer
     */
    private SimplePolicyHeaderRenderer $renderer;
    /**
     * @var PolicyInterface
     */
    private PolicyInterface $policy;

    protected function setUp(): void
    {
        $this->headers = [];

        $response = $this->createStub(HttpInterface::class);
        $response->method('setHeader')->willReturnCallback(
            function ($name, $value) use ($response) {
                $this->headers[$name] = (string)$value;

                return $response;
            }
        );
        $response->method('getHeader')->willReturnCallback(
            fn($name) => isset($this->headers[$name]) ? new GenericHeader($name, $this->headers[$name]) : false
        );
        $this->response = $response;

        $this->renderer = $this->createStub(SimplePolicyHeaderRenderer::class);
        $this->policy = $this->createStub(PolicyInterface::class);
        $this->plugin = new RepointReportingApi(
            new Json(),
            new Endpoints($this->createStub(ScopeConfigInterface::class))
        );
    }

    private function magentoWroteHeaders(string $reportUri, string $cspHeader = 'Content-Security-Policy-Report-Only'): void
    {
        $this->headers['Report-To'] = json_encode([
            'group' => 'report-endpoint',
            'max_age' => 10886400,
            'endpoints' => [['url' => $reportUri]],
        ]);
        $this->headers[$cspHeader] = "default-src 'self'; report-uri {$reportUri}; report-to report-endpoint;";
    }

    private function afterMagentoRendered(): void
    {
        $this->plugin->afterRender($this->renderer, null, $this->policy, $this->response);
    }

    public function testRepointsReportToAtTheReportingApiEndpoint(): void
    {
        $this->magentoWroteHeaders(self::CSP_URL);

        $this->afterMagentoRendered();

        $reportTo = json_decode($this->headers['Report-To'], true);
        $this->assertSame(self::API_URL, $reportTo['endpoints'][0]['url']);
        $this->assertSame('default', $reportTo['group']);
        $this->assertSame(10886400, $reportTo['max_age'], 'The max age Magento chose should survive.');
    }

    public function testAddsTheModernReportingEndpointsHeader(): void
    {
        $this->magentoWroteHeaders(self::CSP_URL);

        $this->afterMagentoRendered();

        $this->assertSame('default="' . self::API_URL . '"', $this->headers['Reporting-Endpoints']);
    }

    /**
     * Renaming the group without rewriting the policy would leave `report-to report-endpoint`
     * naming a group that no longer exists, which resolves to nothing - a quieter failure than
     * the one this plugin exists to fix.
     */
    public function testRewritesTheGroupNameInThePolicyToMatch(): void
    {
        $this->magentoWroteHeaders(self::CSP_URL);

        $this->afterMagentoRendered();

        $this->assertStringContainsString(
            'report-to default',
            $this->headers['Content-Security-Policy-Report-Only']
        );
        $this->assertStringNotContainsString(
            'report-endpoint',
            $this->headers['Content-Security-Policy-Report-Only']
        );
    }

    /**
     * report-uri is the fallback for browsers with no Reporting API, and it must keep pointing at
     * the CSP endpoint - the only one that accepts the format those browsers send.
     */
    public function testLeavesTheReportUriDirectiveOnTheCspEndpoint(): void
    {
        $this->magentoWroteHeaders(self::CSP_URL);

        $this->afterMagentoRendered();

        $this->assertStringContainsString(
            'report-uri ' . self::CSP_URL,
            $this->headers['Content-Security-Policy-Report-Only']
        );
    }

    /**
     * A store can carry an enforced policy and a report-only one at once, since checkout
     * enforces while the rest of the storefront reports.
     */
    public function testRewritesTheEnforcingHeaderToo(): void
    {
        $this->magentoWroteHeaders(self::CSP_URL, 'Content-Security-Policy');

        $this->afterMagentoRendered();

        $this->assertStringContainsString('report-to default', $this->headers['Content-Security-Policy']);
    }

    public function testCarriesTheTeamPathThrough(): void
    {
        $this->magentoWroteHeaders('https://abc123.report-uri.com/r/t/csp/enforce');

        $this->afterMagentoRendered();

        $this->assertSame(
            'default="https://abc123.report-uri.com/a/t/g"',
            $this->headers['Reporting-Endpoints'],
            'A team address must not be repointed at a personal endpoint.'
        );
    }

    /**
     * Another collector may legitimately serve both mechanisms from one address, so anything we
     * do not recognise is left exactly as Magento configured it.
     */
    public function testLeavesAThirdPartyCollectorCompletelyAlone(): void
    {
        $this->magentoWroteHeaders('https://collector.example.com/csp');
        $before = $this->headers;

        $this->afterMagentoRendered();

        $this->assertSame($before, $this->headers);
        $this->assertArrayNotHasKey('Reporting-Endpoints', $this->headers);
    }

    public function testDoesNothingWhenMagentoSetNoReportTo(): void
    {
        $this->headers['Content-Security-Policy-Report-Only'] = "default-src 'self';";

        $this->afterMagentoRendered();

        $this->assertArrayNotHasKey('Report-To', $this->headers);
        $this->assertArrayNotHasKey('Reporting-Endpoints', $this->headers);
    }

    /**
     * The header is parsed, not assumed. Anything unreadable is left alone rather than replaced
     * with a guess - this runs on every storefront response, so throwing here would be an outage.
     */
    public function testSurvivesAMalformedReportToHeader(): void
    {
        foreach (['not json at all', '{}', '{"group":"x"}', '[]', '{"endpoints":[]}'] as $bad) {
            $this->headers = ['Report-To' => $bad];

            $this->afterMagentoRendered();

            $this->assertSame($bad, $this->headers['Report-To'], "Should not have touched: $bad");
            $this->assertArrayNotHasKey('Reporting-Endpoints', $this->headers);
        }
    }
}
