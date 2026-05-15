<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

use DeployEcommerce\PreventOrderPlacement\Model\BlocklistMatcher;
use DeployEcommerce\PreventOrderPlacement\Model\Config\Source\AddressScope;
use DeployEcommerce\PreventOrderPlacement\Plugin\Quote\PreventOrderPlacement;
use Magento\Quote\Api\Data\AddressInterface;

/**
 * Build a stub AddressInterface backed by a mutable data bag so tests can
 * exercise the region-fallback path (where getRegion() is empty but the raw
 * `region` data key carries the county string a Magento address persists when
 * a non-required region is keyed in by the customer).
 *
 * @param array<string,mixed> $data
 */
function makeAddress(array $data): AddressInterface
{
    return new class ($data) implements AddressInterface {
        /** @var array<string,mixed> */
        private array $data;

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public function getStreet()
        {
            return $this->data['street'] ?? null;
        }

        public function getCity()
        {
            return $this->data['city'] ?? null;
        }

        public function getRegion()
        {
            return $this->data['region_resolved'] ?? null;
        }

        public function getPostcode()
        {
            return $this->data['postcode'] ?? null;
        }

        public function getTelephone()
        {
            return $this->data['telephone'] ?? null;
        }

        public function getData($key = '', $index = null)
        {
            if ($key === '') {
                return $this->data;
            }
            return $this->data[$key] ?? null;
        }

        // Remaining AddressInterface stubs - not exercised by BlocklistMatcher.
        public function getId() { return null; }
        public function setId($id) { return $this; }
        public function getRegionId() { return null; }
        public function setRegionId($regionId) { return $this; }
        public function getRegionCode() { return null; }
        public function setRegionCode($regionCode) { return $this; }
        public function setRegion($region) { return $this; }
        public function getCountryId() { return null; }
        public function setCountryId($countryId) { return $this; }
        public function setStreet($street) { return $this; }
        public function getCompany() { return null; }
        public function setCompany($company) { return $this; }
        public function setTelephone($telephone) { return $this; }
        public function getFax() { return null; }
        public function setFax($fax) { return $this; }
        public function setPostcode($postcode) { return $this; }
        public function setCity($city) { return $this; }
        public function getFirstname() { return null; }
        public function setFirstname($firstname) { return $this; }
        public function getLastname() { return null; }
        public function setLastname($lastname) { return $this; }
        public function getMiddlename() { return null; }
        public function setMiddlename($middlename) { return $this; }
        public function getPrefix() { return null; }
        public function setPrefix($prefix) { return $this; }
        public function getSuffix() { return null; }
        public function setSuffix($suffix) { return $this; }
        public function getVatId() { return null; }
        public function setVatId($vatId) { return $this; }
        public function getCustomerId() { return null; }
        public function setCustomerId($customerId) { return $this; }
        public function getEmail() { return null; }
        public function setEmail($email) { return $this; }
        public function getSameAsBilling() { return null; }
        public function setSameAsBilling($sameAsBilling) { return $this; }
        public function getCustomerAddressId() { return null; }
        public function setCustomerAddressId($customerAddressId) { return $this; }
        public function getSaveInAddressBook() { return null; }
        public function setSaveInAddressBook($saveInAddressBook) { return $this; }
        public function getCustomAttributes() { return []; }
        public function setCustomAttributes(array $attributes) { return $this; }
        public function getCustomAttribute($attributeCode) { return null; }
        public function setCustomAttribute($attributeCode, $attributeValue) { return $this; }
        public function getExtensionAttributes() { return null; }
        public function setExtensionAttributes(\Magento\Quote\Api\Data\AddressExtensionInterface $extensionAttributes) { return $this; }
    };
}

// ---------------------------------------------------------------------------
// normalizePhone()
// ---------------------------------------------------------------------------

it('strips non-digits from an international phone number', function () {
    expect(BlocklistMatcher::normalizePhone('+44 7700 900123'))->toBe('447700900123');
});

it('strips parentheses and dashes from a domestic phone number', function () {
    expect(BlocklistMatcher::normalizePhone('(077) 0090-0123'))->toBe('07700900123');
});

it('returns an empty string for an empty input', function () {
    expect(BlocklistMatcher::normalizePhone(''))->toBe('');
});

it('returns an empty string when input has no digits', function () {
    expect(BlocklistMatcher::normalizePhone('--- (n/a) ---'))->toBe('');
});

it('exposes normalizePhone on the Plugin as a back-compat alias', function () {
    expect(PreventOrderPlacement::normalizePhone('+44 7700 900123'))
        ->toBe(BlocklistMatcher::normalizePhone('+44 7700 900123'));
});

// ---------------------------------------------------------------------------
// matchRule() - substring matching with AND-across-fields semantics
// ---------------------------------------------------------------------------

it('matches the historical fraud shipping address (street + postcode)', function () {
    $matcher = new BlocklistMatcher();

    $rule = [
        'street'   => '1 example street',
        'city'     => '',
        'county'   => '',
        'postcode' => 'zz1 1zz',
        'phone'    => '',
        'address_scope' => AddressScope::SCOPE_SHIPPING,
    ];

    $values = [
        'street'   => '1 example street',
        'city'     => 'exampleville',
        'county'   => 'exampleshire',
        'postcode' => 'zz1 1zz',
        'phone'    => '07700900123',
    ];

    expect($matcher->matchRule($rule, $values))->toBe([
        'street'   => '1 example street',
        'postcode' => 'zz1 1zz',
    ]);
});

it('does not match when the postcode differs', function () {
    $matcher = new BlocklistMatcher();

    $rule = [
        'street'   => '1 example street',
        'city'     => '',
        'county'   => '',
        'postcode' => 'zz1 1zz',
        'phone'    => '',
        'address_scope' => AddressScope::SCOPE_SHIPPING,
    ];

    $values = [
        'street'   => '1 example street',
        'city'     => 'exampleville',
        'county'   => '',
        'postcode' => 'zz9 9zz',
        'phone'    => '',
    ];

    expect($matcher->matchRule($rule, $values))->toBeNull();
});

it('does not match when the street differs', function () {
    $matcher = new BlocklistMatcher();

    $rule = [
        'street'   => '1 example street',
        'city'     => '',
        'county'   => '',
        'postcode' => 'zz1 1zz',
        'phone'    => '',
        'address_scope' => AddressScope::SCOPE_SHIPPING,
    ];

    $values = [
        'street'   => '2 example street',
        'city'     => 'exampleville',
        'county'   => '',
        'postcode' => 'zz1 1zz',
        'phone'    => '',
    ];

    expect($matcher->matchRule($rule, $values))->toBeNull();
});

it('returns null when the rule has no populated criteria', function () {
    $matcher = new BlocklistMatcher();

    $rule = [
        'street' => '', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => '',
        'address_scope' => AddressScope::SCOPE_BOTH,
    ];
    $values = [
        'street' => 'whatever', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => '',
    ];

    expect($matcher->matchRule($rule, $values))->toBeNull();
});

it('substring-matches inside a longer haystack value', function () {
    $matcher = new BlocklistMatcher();

    $rule = [
        'street' => 'example street', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => '',
        'address_scope' => AddressScope::SCOPE_BOTH,
    ];
    $values = [
        'street'   => '1 example street, flat 2b',
        'city'     => '',
        'county'   => '',
        'postcode' => '',
        'phone'    => '',
    ];

    expect($matcher->matchRule($rule, $values))->toBe(['street' => 'example street']);
});

// ---------------------------------------------------------------------------
// extractAddressValues() - region fallback + phone normalization
// ---------------------------------------------------------------------------

it('uses AddressInterface::getRegion() when populated', function () {
    $matcher = new BlocklistMatcher();
    $address = makeAddress([
        'street'           => ['1 Example Street'],
        'city'             => 'Exampleville',
        'region_resolved'  => 'Exampleshire',
        'region'           => 'should-be-ignored',
        'postcode'         => 'ZZ1 1ZZ',
        'telephone'        => '07700 900123',
    ]);

    expect($matcher->extractAddressValues($address)['county'])->toBe('exampleshire');
});

it('falls back to the raw region data key when getRegion() is empty', function () {
    $matcher = new BlocklistMatcher();
    $address = makeAddress([
        'street'          => ['1 Example Street'],
        'city'            => 'Exampleville',
        // getRegion() returns null - simulate the case where region object resolution failed
        // but the raw region string was still captured against the address.
        'region_resolved' => null,
        'region'          => 'Exampleshire',
        'postcode'        => 'ZZ1 1ZZ',
        'telephone'       => '07700 900123',
    ]);

    expect($matcher->extractAddressValues($address)['county'])->toBe('exampleshire');
});

it('returns the empty value bag when address is null', function () {
    $matcher = new BlocklistMatcher();
    expect($matcher->extractAddressValues(null))->toBe([
        'street' => '', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => '',
    ]);
});

it('flattens an array street into a newline-joined lowercase string', function () {
    $matcher = new BlocklistMatcher();
    $address = makeAddress([
        'street'   => ['1 Example Street', 'Flat 2B'],
        'city'     => 'Exampleville',
        'postcode' => 'ZZ1 1ZZ',
    ]);

    expect($matcher->extractAddressValues($address)['street'])
        ->toBe("1 example street\nflat 2b");
});

// ---------------------------------------------------------------------------
// End-to-end: phone normalization makes punctuation-different numbers match
// ---------------------------------------------------------------------------

it('allows an address through when no rule in the list matches', function () {
    $matcher = new BlocklistMatcher();

    // Three rules that all target known-bad shipping addresses or phones.
    $rules = [
        [
            'street' => '1 example street', 'city' => '', 'county' => '',
            'postcode' => 'zz1 1zz', 'phone' => '',
            'address_scope' => AddressScope::SCOPE_SHIPPING,
        ],
        [
            'street' => '', 'city' => 'samplebury', 'county' => '',
            'postcode' => '', 'phone' => '',
            'address_scope' => AddressScope::SCOPE_BOTH,
        ],
        [
            'street' => '', 'city' => '', 'county' => '',
            'postcode' => '', 'phone' => '447700900123',
            'address_scope' => AddressScope::SCOPE_BOTH,
        ],
    ];

    // Clean, unrelated customer address — different street, city, postcode, and phone.
    $cleanValues = [
        'street'   => '42 oxford street',
        'city'     => 'london',
        'county'   => 'greater london',
        'postcode' => 'w1d 1aw',
        'phone'    => '02071234567',
    ];

    // Mimic the plugin loop: every rule must return null for the address to be allowed through.
    $blocked = false;
    foreach ($rules as $rule) {
        if ($matcher->matchRule($rule, $cleanValues) !== null) {
            $blocked = true;
            break;
        }
    }

    expect($blocked)->toBeFalse();
});

it('matches a phone rule end-to-end once both sides are normalized', function () {
    $matcher = new BlocklistMatcher();

    // Rule value is also normalized at admin save time, but we test the matcher
    // contract: matcher receives normalized values for both rule and address.
    $rule = [
        'street' => '', 'city' => '', 'county' => '', 'postcode' => '',
        'phone'  => BlocklistMatcher::normalizePhone('7700 900 123'),
        'address_scope' => AddressScope::SCOPE_BOTH,
    ];

    $address = makeAddress([
        'telephone' => '+44 (7700) 900-123',
    ]);

    $values = $matcher->extractAddressValues($address);

    expect($values['phone'])->toBe('447700900123');
    expect($matcher->matchRule($rule, $values))->toBe(['phone' => '7700900123']);
});
