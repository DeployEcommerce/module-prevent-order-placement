<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Model\ResourceModel\BlockedOrderAttempt;

use DeployEcommerce\PreventOrderPlacement\Model\BlockedOrderAttempt;
use DeployEcommerce\PreventOrderPlacement\Model\ResourceModel\BlockedOrderAttempt as BlockedOrderAttemptResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct(): void
    {
        $this->_init(BlockedOrderAttempt::class, BlockedOrderAttemptResource::class);
    }
}
