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
 * The reporting address field: validates it, then writes the four native Report URI values
 * from it.
 *
 * Those values land in the same config rows the native fields use, so the native fields keep
 * showing what is actually in force and stay editable afterwards. Nothing here hides or locks
 * them.
 */
class Address extends Value
{

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

        return $this;
    }

    public function afterSave(): self
    {
        $address = trim((string)$this->getValue());
        $scope = $this->getScope();
        $scopeId = (int)$this->getScopeId();

        if ($address === '') {
            $this->endpointWriter->clear((string)$this->getOldValue(), $scope, $scopeId);
        } else {
            $this->endpointWriter->apply($address, $scope, $scopeId);
        }

        return parent::afterSave();
    }

    /**
     * Captured before the row goes, so afterDelete() still knows which endpoints were ours.
     */
    private string $addressBeingDeleted = '';

    public function beforeDelete(): self
    {
        $this->addressBeingDeleted = trim((string)$this->getOldValue());

        return parent::beforeDelete();
    }

    /**
     * Ticking "Use system value" deletes this row rather than saving an empty one, so afterSave()
     * never runs. Clearing an address by emptying the field and clearing it by inheriting have to
     * end in the same place, or the four endpoints outlive the setting that created them.
     */
    public function afterDelete(): self
    {
        $this->endpointWriter->clear(
            $this->addressBeingDeleted,
            $this->getScope(),
            (int)$this->getScopeId()
        );

        return parent::afterDelete();
    }

}
