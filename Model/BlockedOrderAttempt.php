<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Model;

use DeployEcommerce\PreventOrderPlacement\Model\ResourceModel\BlockedOrderAttempt as BlockedOrderAttemptResource;
use Magento\Framework\Model\AbstractModel;

class BlockedOrderAttempt extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(BlockedOrderAttemptResource::class);
    }
}
