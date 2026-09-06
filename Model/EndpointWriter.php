<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Model;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use ReportUri\CspReporting\Model\Config\Endpoints;

/**
 * Applies the four native CSP Report URI values for one config scope.
 *
 * Which disposition each of the four gets is not asked, it is read: Endpoints resolves each
 * field against the report_only Magento already holds for that page, the same way
 * Magento\Csp\Model\Mode\ConfigManager does when it renders the header.
 */
class EndpointWriter
{

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly Endpoints $endpoints
    ) {
    }

    public function apply(string $address, string $scope, int $scopeId): void
    {
        $values = $this->endpoints->valuesFor(trim($address), $scope, $scopeId);

        if ($values === []) {
            return;
        }

        foreach ($values as $path => $url) {
            $this->configWriter->save($path, $url, $scope, $scopeId);
        }

        $this->refresh();
    }

    /**
     * Remove only the endpoints this module put there.
     *
     * Emptying the address means "stop reporting", so leaving the URLs behind would be wrong.
     * Deleting a value we did not write would be worse: a merchant who set one of the four by
     * hand keeps it, rather than finding we quietly took their configuration away. The test is
     * whether the stored value is one of the two dispositions the previous address could have
     * produced.
     */
    public function clear(string $previousAddress, string $scope, int $scopeId): void
    {
        $previousAddress = trim($previousAddress);

        if (!$this->endpoints->isValidAddress($previousAddress)) {
            return;
        }

        $ours = [
            (string)$this->endpoints->reportOnlyUrl($previousAddress),
            (string)$this->endpoints->enforceUrl($previousAddress),
        ];

        foreach ($this->endpoints->allPaths() as $path) {
            $current = (string)$this->scopeConfig->getValue($path, $scope, $scopeId ?: null);

            if (in_array($current, $ours, true)) {
                $this->configWriter->delete($path, $scope, $scopeId);
            }
        }

        $this->refresh();
    }

    /**
     * Re-apply from whatever config now holds, after a row has been removed rather than saved.
     *
     * Ticking "Use system value" deletes the row instead of writing one, so Magento routes the
     * field to delete() and afterSave() never runs (Magento\Config\Model\Config::_processGroup).
     * Without this the four endpoints keep whatever the last save gave them while the group that
     * produced them reads as unset - a store still reporting from a screen that says it is not.
     */
    public function reapplyFromConfig(string $scope, int $scopeId): void
    {
        // The deletion has happened but the in-memory config predates it.
        $this->refresh();

        $scopeId = $scopeId ?: 0;
        $address = trim((string)$this->scopeConfig->getValue(Endpoints::XML_PATH_ADDRESS, $scope, $scopeId ?: null));

        if ($address === '' || !$this->endpoints->isValidAddress($address)) {
            return;
        }

        $this->apply($address, $scope, $scopeId);
    }


    /**
     * The four values were written straight to storage rather than through the form, so the
     * in-memory config still holds the previous ones. Without this, anything reading them later
     * in the same request - including the page that renders the native fields back to the
     * merchant - shows the state from before the save.
     */
    private function refresh(): void
    {
        $this->reinitableConfig->reinit();
    }
}
