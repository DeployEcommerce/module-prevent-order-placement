<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class BlockedOrderAttempt extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('deployecommerce_preventorderplacement_blocked_attempt', 'entity_id');
    }
}
