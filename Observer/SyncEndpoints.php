<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ReportUri\CspReporting\Model\EndpointWriter;

/**
 * Writes the four native Report URI values once the whole CSP config page has saved.
 *
 * admin_system_config_changed_section_csp is dispatched by Magento\Config\Model\Config::save()
 * after both its delete and save transactions, so by now nothing else on the page can overwrite
 * what this writes. It fires for the admin form and for bin/magento config:set alike.
 */
class SyncEndpoints implements ObserverInterface
{
    /**
     * SyncEndpoints constructor.
     *
     * @param EndpointWriter $endpointWriter
     */
    public function __construct(
        private readonly EndpointWriter $endpointWriter
    ) {
    }

    /**
     * Write whatever the address field queued during the save.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $this->endpointWriter->flush();
    }
}
