<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Turns the reporting address from a Report URI Setup page into the four config values Magento
 * reads.
 *
 * Magento assembles one CSP config section from three modules: Magento_Csp declares the
 * storefront and admin defaults, Magento_Checkout adds One Page Checkout and Magento_Sales adds
 * Create Order. All four carry a Report URI field and all four ship empty.
 *
 * The two per-page groups enforce their policy from 2.4.7 while the two defaults stay
 * report-only, so they need different endpoints: one URL across all four merges violations that
 * were blocked with violations that were merely observed.
 *
 * The whole address is taken rather than assembled from parts, because two of its segments are
 * account-specific and neither is guessable from the outside:
 *
 *   - the path is /r/d/ for a personal account and /r/t/ for a team
 *   - the host is the account's own, which is not report-uri.com on every instance
 *
 * Asking the merchant to classify their own account would put both behind a question they can
 * answer wrongly, and a reporting endpoint that is merely wrong looks exactly like one that
 * works: the browser never tells the site its reports went nowhere.
 */
class Endpoints
{
    public const XML_PATH_ADDRESS = 'csp/reporturi/address';
    public const XML_PATH_REPORT_SAMPLE = 'csp/reporturi/report_sample';
    public const XML_PATH_REPORT_HASHES = 'csp/reporturi/report_hashes';

    /**
     * The four Report URI fields, each with the config that decides which endpoint it needs.
     *
     * Whether a page enforces is not a preference - it is a fact Magento already records, and
     * the disposition in the endpoint has to agree with it or violations arrive tagged as the
     * wrong kind. The resolution mirrors Magento\Csp\Model\Mode\ConfigManager::getConfigured():
     * the page's own report_only if it has one, otherwise its area default.
     *
     * Out of the box that makes the two defaults report-only and, from 2.4.7, checkout and
     * Create Order enforcing. A store that flips any of them gets the matching endpoint without
     * being asked.
     *
     * @var array<string, array{0: string, 1: string|null}> report_uri path => [mode path, area fallback]
     */
    private const TARGETS = [
        'csp/mode/storefront/report_uri' => [
            'csp/mode/storefront/report_only',
            null,
        ],
        'csp/mode/admin/report_uri' => [
            'csp/mode/admin/report_only',
            null,
        ],
        'csp/mode/storefront_checkout_index_index/report_uri' => [
            'csp/mode/storefront_checkout_index_index/report_only',
            'csp/mode/storefront/report_only',
        ],
        'csp/mode/admin_sales_order_create_index/report_uri' => [
            'csp/mode/admin_sales_order_create_index/report_only',
            'csp/mode/admin/report_only',
        ],
    ];

    public const DISPOSITION_REPORT_ONLY = 'reportOnly';
    public const DISPOSITION_ENFORCE = 'enforce';

    /**
     * Every address shape the Setup page can hand a merchant, because they will paste whichever
     * one they were looking at:
     *
     *   /r/{scope}/csp/{enforce|reportOnly|wizard}   the CSP address, all three radio options
     *   /a/{scope}/g                                 the Reporting API address
     *
     * All of them carry the same token, host and account scope, which is everything needed to
     * build the four values - so any of them is enough and none of them is a mistake worth
     * refusing.
     *
     * The token is 4-32 characters: a custom subdomain is 4-30, and the default is 32 hex
     * characters. The host is constrained to report-uri.com and its subdomains, so an address
     * for somewhere else is still refused rather than propagated into four config fields.
     *
     * Deliberately unanchored. The Reporting API address is shown inside a whole Report-To
     * header on the Setup page, so a merchant who copies that line pastes JSON with a URL in the
     * middle of it. Finding the address inside what was pasted is friendlier than rejecting a
     * value that does contain the right answer.
     */
    /**
     * Long enough for the whole Report-To header the Setup page renders, and short enough that a
     * pathological paste cannot turn the unanchored match into work. A real address is under 100
     * characters.
     */
    private const MAX_ADDRESS_LENGTH = 2048;

    /**
     * @var string
     */
    private const ADDRESS_PATTERN = '~https://'
        . '(?<token>[a-z0-9]{4,32})\.'
        . '(?<host>(?:[a-z0-9-]+\.)*report-uri\.com)'
        . '/(?:'
        . 'r/(?<cspscope>[dt])/csp/(?:enforce|reportOnly|wizard)'
        . '|'
        . 'a/(?<apiscope>[dt])/g'
        . ')~i';

    /**
     * Endpoints constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Is this an address this module recognises?
     *
     * @param string $address
     * @return bool
     */
    public function isValidAddress(string $address): bool
    {
        return $this->parse($address) !== null;
    }

    /**
     * Pull the account details out of a pasted address.
     *
     * @param string $address
     * @return ?array
     */
    public function parse(string $address): ?array
    {
        $address = trim($address);

        // Refuse before matching rather than relying on the pattern to be the only thing standing
        // between a pasted value and a response header. The pattern is unanchored, so it would
        // happily find a valid address inside a payload that also carries a CR, an LF or a NUL -
        // and everything downstream of here ends up in Content-Security-Policy, Report-To or
        // Reporting-Endpoints. Nothing legitimate on the Setup page contains a control character.
        if (strlen($address) > self::MAX_ADDRESS_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $address) === 1) {
            return null;
        }

        if (preg_match(self::ADDRESS_PATTERN, $address, $matches) !== 1) {
            return null;
        }

        // Only one of the two branches matches, so the other's scope group is empty.
        $scope = ($matches['cspscope'] ?? '') !== '' ? $matches['cspscope'] : ($matches['apiscope'] ?? '');

        return [
            'token' => strtolower($matches['token']),
            'host' => strtolower($matches['host']),
            // d = personal account, t = team. Preserved from what was pasted rather than
            // inferred; nothing else in the address distinguishes the two.
            'scope' => strtolower($scope),
        ];
    }

    /**
     * Rebuild the address with a chosen disposition.
     *
     * Token, host and account scope are left exactly as the merchant pasted them.
     *
     * @param string $address
     * @param string $disposition
     * @return ?string
     */
    public function withDisposition(string $address, string $disposition): ?string
    {
        $parts = $this->parse($address);

        if ($parts === null) {
            return null;
        }

        // Rebuilt from the captured groups, never echoed from the input. token, host and scope
        // come from character classes that cannot contain a delimiter, a quote or a control
        // character, and the disposition is one of this class's own constants - so whatever was
        // pasted, what leaves here is a URL of this exact shape.
        return sprintf(
            'https://%s.%s/r/%s/csp/%s',
            $parts['token'],
            $parts['host'],
            $parts['scope'],
            $disposition
        );
    }

    /**
     * The report-only endpoint for an address.
     *
     * @param string $address
     * @return ?string
     */
    public function reportOnlyUrl(string $address): ?string
    {
        return $this->withDisposition($address, self::DISPOSITION_REPORT_ONLY);
    }

    /**
     * The enforce endpoint for an address.
     *
     * @param string $address
     * @return ?string
     */
    public function enforceUrl(string $address): ?string
    {
        return $this->withDisposition($address, self::DISPOSITION_ENFORCE);
    }

    /**
     * The four config paths mapped to the value each should carry, for one config scope.
     *
     * @param string $address
     * @param string $scope
     * @param int $scopeId
     * @return array
     */
    public function valuesFor(string $address, string $scope, int $scopeId): array
    {
        $reportOnly = $this->reportOnlyUrl($address);
        $enforce = $this->enforceUrl($address);

        if ($reportOnly === null || $enforce === null) {
            return [];
        }

        $values = [];
        foreach (self::TARGETS as $path => [$modePath, $areaFallback]) {
            $values[$path] = $this->isReportOnly($modePath, $areaFallback, $scope, $scopeId)
                ? $reportOnly
                : $enforce;
        }

        return $values;
    }

    /**
     * Every config path this module writes.
     *
     * @return array
     */
    public function allPaths(): array
    {
        return array_keys(self::TARGETS);
    }

    /**
     * Does this page report rather than enforce?
     *
     * A page with no report_only of its own inherits its area's, which is why the fallback is
     * consulted rather than defaulted: null means "not configured here", and 0 means "enforce".
     *
     * @param string $modePath
     * @param string|null $areaFallback
     * @param string $scope
     * @param int $scopeId
     * @return bool
     */
    private function isReportOnly(string $modePath, ?string $areaFallback, string $scope, int $scopeId): bool
    {
        $value = $this->scopeConfig->getValue($modePath, $scope, $scopeId ?: null);

        if ($value === null && $areaFallback !== null) {
            $value = $this->scopeConfig->getValue($areaFallback, $scope, $scopeId ?: null);
        }

        return (bool)$value;
    }

    /**
     * The Reporting API address that pairs with a CSP address for the same account.
     *
     * Same token, same host, same account scope; only the path differs. Taken from the Setup
     * page, which renders the CSP address as /r/{scope}/csp/{disposition} and the Reporting API
     * address as /a/{scope}/g.
     *
     * The two are not interchangeable. The CSP endpoint takes application/csp-report and the
     * Reporting API endpoint takes application/reports+json; each rejects the other's format.
     *
     * @param string $cspAddress
     * @return ?string
     */
    public function reportingApiUrl(string $cspAddress): ?string
    {
        $parts = $this->parse($cspAddress);

        if ($parts === null) {
            return null;
        }

        return sprintf('https://%s.%s/a/%s/g', $parts['token'], $parts['host'], $parts['scope']);
    }

    /**
     * The configured reporting address for a scope.
     *
     * @param string|null $scopeCode
     * @return string
     */
    public function getAddress(?string $scopeCode = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_ADDRESS,
            $scopeCode === null ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_STORE,
            $scopeCode
        );

        return is_string($value) ? trim($value) : '';
    }
}
