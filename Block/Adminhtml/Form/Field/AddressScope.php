<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Block\Adminhtml\Form\Field;

use DeployEcommerce\PreventOrderPlacement\Model\Config\Source\AddressScope as AddressScopeSource;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

class AddressScope extends Select
{
    /**
     * @var AddressScopeSource
     */
    private $source;

    public function __construct(
        Context $context,
        AddressScopeSource $source,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->source = $source;
    }

    public function setInputName($value): self
    {
        return $this->setName($value);
    }

    public function setInputId($value): self
    {
        return $this->setId($value);
    }

    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            foreach ($this->source->toOptionArray() as $option) {
                $this->addOption($option['value'], (string)$option['label']);
            }
        }

        return parent::_toHtml();
    }
}
