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

    /**
     * EndpointWriter constructor.
     *
     * @param WriterInterface $configWriter
     * @param ScopeConfigInterface $scopeConfig
     * @param ReinitableConfigInterface $reinitableConfig
     * @param Endpoints $endpoints
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly Endpoints $endpoints
    ) {
    }

    /**
     * Write the four native Report URI values for one scope.
     *
     * @param string $address
     * @param string $scope
     * @param int $scopeId
     */
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
     * Address changes waiting for the config save around them to finish.
     *
     * @var array<string, array{address: string, previous: string, scope: string, scopeId: int}>
     */
    private array $pending = [];

    /**
     * Record an address change, to be written by flush() once the save around it is done.
     *
     * Writing during the address field's own save does not survive the admin form. Magento saves
     * every field on the page in one transaction (Magento\Config\Model\Config::save), and the four
     * native Report URI fields are on that page below this one - so they save after it, with
     * whatever the form posted for them. On a store that has never had them filled in, that is
     * empty, and the four values written a moment earlier are overwritten with NULL. The merchant
     * sees an address that saved and a storefront with no reporting at all.
     *
     * Several changes to one scope in a request collapse into one: the first previous address
     * (what was in force before any of them) and the last new one.
     *
     * @param string $address The address now saved; empty when it was cleared or removed
     * @param string $previousAddress The address in force before this change
     * @param string $scope
     * @param int $scopeId
     */
    public function queue(string $address, string $previousAddress, string $scope, int $scopeId): void
    {
        $key = $scope . ':' . $scopeId;

        $this->pending[$key] = [
            'address' => trim($address),
            'previous' => $this->pending[$key]['previous'] ?? trim($previousAddress),
            'scope' => $scope,
            'scopeId' => $scopeId,
        ];
    }

    /**
     * Write every queued change. Safe to call more than once; the queue empties as it goes.
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $change) {
            $this->sync($change['address'], $change['previous'], $change['scope'], $change['scopeId']);
        }
    }

    /**
     * Bring the four native values in line with the address, after everything else has saved.
     *
     * A field is written when it is empty or still holds a value this module produced, from
     * either address. Anything else is a value the merchant typed into the native field
     * themselves, and they keep it - the native fields stay theirs to override.
     *
     * Config is re-read first: the save that just finished wrote straight to storage, and the
     * dispositions depend on report_only values that may have changed on the same page.
     *
     * @param string $address
     * @param string $previousAddress
     * @param string $scope
     * @param int $scopeId
     */
    public function sync(string $address, string $previousAddress, string $scope, int $scopeId): void
    {
        $address = trim($address);

        $this->refresh();

        if ($address === '') {
            $this->clear($previousAddress, $scope, $scopeId);

            return;
        }

        $values = $this->endpoints->valuesFor($address, $scope, $scopeId);

        if ($values === []) {
            return;
        }

        $ours = array_merge($this->ourUrls($previousAddress), $this->ourUrls($address));
        $written = false;

        foreach ($values as $path => $url) {
            $current = (string)$this->scopeConfig->getValue($path, $scope, $scopeId ?: null);

            if ($current === $url) {
                continue;
            }

            if ($current === '' || in_array($current, $ours, true)) {
                $this->configWriter->save($path, $url, $scope, $scopeId);
                $written = true;
            }
        }

        if ($written) {
            $this->refresh();
        }
    }

    /**
     * Both endpoints an address can produce, or none if it is not a Report URI address.
     *
     * @param string $address
     * @return string[]
     */
    private function ourUrls(string $address): array
    {
        $address = trim($address);

        if (!$this->endpoints->isValidAddress($address)) {
            return [];
        }

        return [
            (string)$this->endpoints->reportOnlyUrl($address),
            (string)$this->endpoints->enforceUrl($address),
        ];
    }

    /**
     * Remove only the endpoints this module put there.
     *
     * Emptying the address means "stop reporting", so leaving the URLs behind would be wrong.
     * Deleting a value we did not write would be worse: a merchant who set one of the four by
     * hand keeps it, rather than finding we quietly took their configuration away. The test is
     * whether the stored value is one of the two dispositions the previous address could have
     * produced.
     *
     * @param string $previousAddress
     * @param string $scope
     * @param int $scopeId
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
     *
     * @param string $scope
     * @param int $scopeId
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
