<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Model;

use Magento\Quote\Api\Data\AddressInterface;

class BlocklistMatcher
{
    public const STRING_FIELDS = ['street', 'city', 'county', 'postcode', 'phone'];

    /**
     * Pull comparable lowercased values from an address.
     *
     * @return array<string,string>
     */
    public function extractAddressValues(?AddressInterface $address): array
    {
        $values = ['street' => '', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => ''];
        if ($address === null) {
            return $values;
        }

        $street = $address->getStreet();
        if (is_array($street)) {
            $street = implode("\n", array_filter($street, 'is_scalar'));
        }

        $county = (string)$address->getRegion();
        if ($county === '') {
            $county = (string)$address->getData('region');
        }

        $values['street'] = mb_strtolower((string)$street);
        $values['city'] = mb_strtolower((string)$address->getCity());
        $values['county'] = mb_strtolower($county);
        $values['postcode'] = mb_strtolower((string)$address->getPostcode());
        $values['phone'] = self::normalizePhone((string)$address->getTelephone());

        return $values;
    }

    /**
     * Reduce a phone number to its digit characters so that "+44 7700 900123" and
     * "07700 900123" can both be substring-matched against admin-entered rule values
     * regardless of whitespace, dashes, parentheses or international prefix punctuation.
     */
    public static function normalizePhone(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Test rule against a single set of lowercased address values.
     *
     * Within a rule, all populated fields must match (AND). Empty rule fields are skipped.
     * Returns the map of fields that matched (rule-field => rule-value) when the rule fires,
     * or null when it does not.
     *
     * @param array<string,string> $rule
     * @param array<string,string> $values
     * @return array<string,string>|null
     */
    public function matchRule(array $rule, array $values): ?array
    {
        $matched = [];
        $hasCriterion = false;

        foreach (self::STRING_FIELDS as $field) {
            $needle = $rule[$field] ?? '';
            if ($needle === '') {
                continue;
            }
            $hasCriterion = true;

            $haystack = $values[$field] ?? '';
            if ($haystack === '' || !str_contains($haystack, $needle)) {
                return null;
            }

            $matched[$field] = $needle;
        }

        return $hasCriterion ? $matched : null;
    }
}
