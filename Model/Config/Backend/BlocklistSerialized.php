<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Model\Config\Backend;

use DeployEcommerce\PreventOrderPlacement\Model\BlocklistMatcher;
use DeployEcommerce\PreventOrderPlacement\Model\Config\Source\AddressScope;
use DeployEcommerce\PreventOrderPlacement\Plugin\Quote\PreventOrderPlacement;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;

class BlocklistSerialized extends ConfigValue
{
    // Canonical list lives on BlocklistMatcher so save-time normalization, the
    // runtime matcher, and the preview estimator all stay in lock-step.

    /**
     * @var Json
     */
    private $serializer;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        Json $serializer,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data
        );

        $this->serializer = $serializer;
    }

    /**
     * Normalize on save: drop placeholder + empty rows; trim + lowercase string fields.
     */
    public function beforeSave()
    {
        $value = $this->getValue();

        // Programmatic saves may pass the already-serialized JSON string; unserialize first
        // so we normalize the same way as a form-submit array. An empty/non-string value
        // becomes an empty list to clear the rules.
        if (is_string($value)) {
            if ($value === '') {
                $value = [];
            } else {
                try {
                    $value = $this->serializer->unserialize($value);
                } catch (\InvalidArgumentException $e) {
                    $value = [];
                }
            }
        }

        if (!is_array($value)) {
            $value = [];
        }

        unset($value['__empty']);

        $clean = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (BlocklistMatcher::STRING_FIELDS as $field) {
                $raw = isset($row[$field]) ? (string)$row[$field] : '';
                $row[$field] = $field === 'phone'
                    ? PreventOrderPlacement::normalizePhone($raw)
                    : mb_strtolower(trim($raw));
            }

            $scope = isset($row['address_scope']) ? (string)$row['address_scope'] : '';
            if (!in_array($scope, [AddressScope::SCOPE_BILLING, AddressScope::SCOPE_SHIPPING, AddressScope::SCOPE_BOTH], true)) {
                $scope = AddressScope::SCOPE_BOTH;
            }
            $row['address_scope'] = $scope;

            $hasValue = false;
            foreach (BlocklistMatcher::STRING_FIELDS as $field) {
                if ($row[$field] !== '') {
                    $hasValue = true;
                    break;
                }
            }

            if (!$hasValue) {
                continue;
            }

            $clean[] = [
                'street' => $row['street'],
                'city' => $row['city'],
                'county' => $row['county'],
                'postcode' => $row['postcode'],
                'phone' => $row['phone'],
                'address_scope' => $row['address_scope'],
            ];
        }

        $this->setValue($this->serializer->serialize($clean));

        return parent::beforeSave();
    }

    /**
     * Decode for admin form display.
     */
    protected function _afterLoad()
    {
        $value = $this->getValue();
        if (is_string($value) && $value !== '') {
            try {
                $decoded = $this->serializer->unserialize($value);
            } catch (\InvalidArgumentException $e) {
                $decoded = [];
            }
            $this->setValue(is_array($decoded) ? $decoded : []);
        }
    }
}
