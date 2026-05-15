<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AddressScope implements OptionSourceInterface
{
    public const SCOPE_BILLING = 'billing';
    public const SCOPE_SHIPPING = 'shipping';
    public const SCOPE_BOTH = 'both';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::SCOPE_BILLING, 'label' => __('Billing')],
            ['value' => self::SCOPE_SHIPPING, 'label' => __('Shipping')],
            ['value' => self::SCOPE_BOTH, 'label' => __('Both')],
        ];
    }
}
