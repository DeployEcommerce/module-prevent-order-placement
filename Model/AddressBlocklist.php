<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Model;

use DeployEcommerce\PreventOrderPlacement\Model\Config\Source\AddressScope;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;

class AddressBlocklist
{
    public const CONFIG_PATH_RULES = 'deployecommerce_preventorderplacement/blocklist/rules';

    // Canonical list lives on BlocklistMatcher::STRING_FIELDS to avoid drift.

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Json
     */
    private $serializer;

    public function __construct(ScopeConfigInterface $scopeConfig, Json $serializer)
    {
        $this->scopeConfig = $scopeConfig;
        $this->serializer = $serializer;
    }

    /**
     * @return array<int, array{street:string,city:string,county:string,postcode:string,phone:string,address_scope:string}>
     */
    public function getRules(): array
    {
        $raw = $this->scopeConfig->getValue(self::CONFIG_PATH_RULES, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $rules = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rule = [];
            foreach (BlocklistMatcher::STRING_FIELDS as $field) {
                $rule[$field] = isset($row[$field]) ? (string)$row[$field] : '';
            }
            $scope = isset($row['address_scope']) ? (string)$row['address_scope'] : AddressScope::SCOPE_BOTH;
            if (!in_array($scope, [AddressScope::SCOPE_BILLING, AddressScope::SCOPE_SHIPPING, AddressScope::SCOPE_BOTH], true)) {
                $scope = AddressScope::SCOPE_BOTH;
            }
            $rule['address_scope'] = $scope;
            $rules[] = $rule;
        }

        return $rules;
    }
}
