<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

class BlocklistRows extends AbstractFieldArray
{
    /**
     * @var \DeployEcommerce\PreventOrderPlacement\Block\Adminhtml\Form\Field\AddressScope|null
     */
    private $addressScopeRenderer;

    private function getAddressScopeRenderer(): AddressScope
    {
        if ($this->addressScopeRenderer === null) {
            $this->addressScopeRenderer = $this->getLayout()->createBlock(
                AddressScope::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->addressScopeRenderer->setClass('address_scope_select');
        }

        return $this->addressScopeRenderer;
    }

    protected function _prepareToRender(): void
    {
        $this->addColumn('street', ['label' => __('Street'), 'class' => 'input-text']);
        $this->addColumn('city', ['label' => __('City'), 'class' => 'input-text']);
        $this->addColumn('county', ['label' => __('County'), 'class' => 'input-text']);
        $this->addColumn('postcode', ['label' => __('Postcode'), 'class' => 'input-text']);
        $this->addColumn('phone', ['label' => __('Phone'), 'class' => 'input-text']);
        $this->addColumn('address_scope', [
            'label' => __('Address Scope'),
            'renderer' => $this->getAddressScopeRenderer(),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Rule');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $scope = (string)$row->getData('address_scope');
        $optionHash = $this->getAddressScopeRenderer()->calcOptionHash($scope);

        $row->setData(
            'option_extra_attrs',
            [sprintf('option_%s', $optionHash) => 'selected="selected"']
        );
    }

    /**
     * Wrap the rendered field array with a marker div that the
     * DeployEcommerce_PreventOrderPlacement/js/blocklist-preview RequireJS
     * module uses to attach on-blur match-count previews to each row.
     */
    public function _toHtml(): string
    {
        $html = parent::_toHtml();

        if (!$this->getElement()) {
            return $html;
        }

        $endpoint = $this->getUrl('preventorderplacement/preview/estimate');
        $formKey = $this->escapeHtmlAttr($this->getFormKey());
        $elementId = $this->escapeHtmlAttr((string)$this->getElement()->getHtmlId());

        $marker = sprintf(
            '<div class="dep-blocklist-preview" '
            . 'data-blocklist-element-id="%s" '
            . 'data-blocklist-endpoint="%s" '
            . 'data-blocklist-form-key="%s"></div>',
            $elementId,
            $this->escapeHtmlAttr($endpoint),
            $formKey
        );

        $script = '<script type="text/x-magento-init">'
            . '{"*": {"DeployEcommerce_PreventOrderPlacement/js/blocklist-preview": {}}}'
            . '</script>';

        return $html . $marker . $script;
    }
}
