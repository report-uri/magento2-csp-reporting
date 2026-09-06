<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 */
declare(strict_types=1);

namespace ReportUri\CspReporting\Model\Policy;

use Magento\Csp\Api\Data\PolicyInterface;

/**
 * A policy with extra source expressions appended to its value.
 *
 * Magento\Csp\Model\Policy\FetchPolicy has a fixed set of flags - self, inline, eval,
 * strict-dynamic, unsafe-hashes, nonces and hashes - and no way to carry any other keyword, so
 * the reporting keywords cannot be expressed as one. Decorating the policy on its way to the
 * renderer keeps the change to the single directive it applies to, rather than rewriting the
 * assembled header string and hoping the parse holds.
 *
 * The renderer reads only getId() and getValue(), so delegating the rest is sufficient.
 */
class AugmentedPolicy implements PolicyInterface
{

    /**
     * @param string[] $additions source expressions to append, already quoted
     */
    public function __construct(
        private readonly PolicyInterface $policy,
        private readonly array $additions
    ) {
    }

    public function getId(): string
    {
        return $this->policy->getId();
    }

    public function getValue(): string
    {
        $value = $this->policy->getValue();
        $existing = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Re-adding a keyword the policy already carries would be harmless to a browser but
        // would show up in the header, where a merchant reading it would reasonably wonder
        // which of the two was doing something.
        $new = array_values(array_diff($this->additions, $existing));

        if ($new === []) {
            return $value;
        }

        return trim($value . ' ' . implode(' ', $new));
    }
}
