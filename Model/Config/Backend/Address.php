<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use ReportUri\CspReporting\Model\Config\Endpoints;
use ReportUri\CspReporting\Model\EndpointWriter;

/**
 * The reporting address field: validates it, then has the four native Report URI values written
 * from it.
 *
 * Those values land in the same config rows the native fields use, so the native fields keep
 * showing what is actually in force and stay editable afterwards. Nothing here hides or locks
 * them.
 *
 * Nothing is written from inside this field's own save. The native fields are on the same admin
 * page and save after it in the same transaction, with whatever the form posted - so a value
 * written here would be overwritten a moment later. The change is queued instead and written
 * once the whole page has saved: by ReportUri\CspReporting\Observer\SyncEndpoints for the admin
 * form and bin/magento config:set, and by afterCommitCallback() for a direct save of this value.
 */
class Address extends Value
{

    /**
     * The address in force before this save or delete, captured while config still holds it.
     *
     * @var string
     */
    private string $previousAddress = '';

    /**
     * Address constructor.
     *
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param EndpointWriter $endpointWriter
     * @param Endpoints $endpoints
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly EndpointWriter $endpointWriter,
        private readonly Endpoints $endpoints,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Validate and normalise the pasted address.
     *
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $value = trim((string)$this->getValue());

        if ($value !== '' && !$this->endpoints->isValidAddress($value)) {
            throw new LocalizedException(
                __(
                    'That is not a Report URI address. Copy either the CSP address or the '
                    . 'Reporting API address from your Setup page, for example '
                    . 'https://abc123.report-uri.com/r/d/csp/reportOnly'
                )
            );
        }

        // Store it normalised, so the value the merchant sees back is the one actually written
        // to the four fields.
        if ($value !== '') {
            $value = (string)$this->endpoints->reportOnlyUrl($value);
        }

        $this->setValue($value);
        $this->previousAddress = trim((string)$this->getOldValue());

        return parent::beforeSave();
    }

    /**
     * Queue the four native fields to match, once the rest of the page has saved.
     */
    public function afterSave(): self
    {
        $this->endpointWriter->queue(
            trim((string)$this->getValue()),
            $this->previousAddress,
            (string)$this->getScope(),
            (int)$this->getScopeId()
        );

        return parent::afterSave();
    }

    /**
     * Write the queued change for a save that did not come through Magento's config model.
     *
     * Runs when the outermost transaction commits. For the admin form and config:set that is
     * after every field on the page has saved, so writing here is already late enough - the
     * observer that runs afterwards then finds an empty queue.
     */
    public function afterCommitCallback(): self
    {
        $this->endpointWriter->flush();

        return parent::afterCommitCallback();
    }

    /**
     * Remember the address before the row is removed.
     */
    public function beforeDelete(): self
    {
        $this->previousAddress = trim((string)$this->getOldValue());

        return parent::beforeDelete();
    }

    /**
     * Ticking "Use system value" deletes this row rather than saving an empty one, so afterSave()
     * never runs. Clearing an address by emptying the field and clearing it by inheriting have to
     * end in the same place, or the four endpoints outlive the setting that created them.
     *
     * Deliberately not flushed on commit: Magento commits its deletes before its saves, so the
     * native fields have not been written yet. SyncEndpoints flushes this after both.
     */
    public function afterDelete(): self
    {
        $this->endpointWriter->queue('', $this->previousAddress, (string)$this->getScope(), (int)$this->getScopeId());

        return parent::afterDelete();
    }
}
