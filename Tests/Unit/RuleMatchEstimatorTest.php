<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Tests\Unit;

use DeployEcommerce\PreventOrderPlacement\Model\Config\Source\AddressScope;
use DeployEcommerce\PreventOrderPlacement\Model\RuleMatchEstimator;

it('escapes MySQL LIKE wildcards so admin values are matched literally', function () {
    expect(RuleMatchEstimator::escapeLike('100%'))->toBe('100\\%');
    expect(RuleMatchEstimator::escapeLike('a_b'))->toBe('a\\_b');
    expect(RuleMatchEstimator::escapeLike('back\\slash'))->toBe('back\\\\slash');
    expect(RuleMatchEstimator::escapeLike('plain text'))->toBe('plain text');
});

it('treats a rule with all blank fields as having no criteria', function () {
    expect(RuleMatchEstimator::ruleHasNoCriteria([
        'street' => '', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => '',
        'address_scope' => AddressScope::SCOPE_BOTH,
    ]))->toBeTrue();
});

it('treats a rule with any populated field as having criteria', function () {
    expect(RuleMatchEstimator::ruleHasNoCriteria([
        'street' => '', 'city' => 'london', 'county' => '', 'postcode' => '', 'phone' => '',
        'address_scope' => AddressScope::SCOPE_BOTH,
    ]))->toBeFalse();
});

it('treats a rule missing a key as no criteria (defensive against partial input)', function () {
    expect(RuleMatchEstimator::ruleHasNoCriteria([
        'address_scope' => AddressScope::SCOPE_BOTH,
    ]))->toBeTrue();
});

it('normalizes a rule to lowercased fields, digit-only phone, and a defaulted scope', function () {
    $normalized = RuleMatchEstimator::normalizeRule([
        'street'        => '  1 Example Street ',
        'city'          => 'Exampleville',
        'county'        => '',
        'postcode'      => 'ZZ1 1ZZ',
        'phone'         => '+44 (7700) 900-123',
        'address_scope' => '',
    ]);

    expect($normalized['street'])->toBe('1 example street');
    expect($normalized['city'])->toBe('exampleville');
    expect($normalized['county'])->toBe('');
    expect($normalized['postcode'])->toBe('zz1 1zz');
    expect($normalized['phone'])->toBe('447700900123');
    expect($normalized['address_scope'])->toBe(AddressScope::SCOPE_BOTH);
});

it('normalization preserves valid scope values', function () {
    expect(RuleMatchEstimator::normalizeRule(['address_scope' => 'shipping'])['address_scope'])
        ->toBe(AddressScope::SCOPE_SHIPPING);
    expect(RuleMatchEstimator::normalizeRule(['address_scope' => 'billing'])['address_scope'])
        ->toBe(AddressScope::SCOPE_BILLING);
    expect(RuleMatchEstimator::normalizeRule(['address_scope' => 'both'])['address_scope'])
        ->toBe(AddressScope::SCOPE_BOTH);
});

it('normalization falls back to BOTH when the scope value is unknown', function () {
    expect(RuleMatchEstimator::normalizeRule(['address_scope' => 'nonsense'])['address_scope'])
        ->toBe(AddressScope::SCOPE_BOTH);
});

it('normalization treats missing optional fields as empty strings', function () {
    $normalized = RuleMatchEstimator::normalizeRule([]);

    foreach (['street', 'city', 'county', 'postcode', 'phone'] as $field) {
        expect($normalized[$field])->toBe('');
    }
    expect($normalized['address_scope'])->toBe(AddressScope::SCOPE_BOTH);
});

it('escapeLike output, when wrapped in % … %, still matches the literal substring under MySQL semantics', function () {
    // Sanity check the produced LIKE pattern surface; MySQL would treat
    // `\%` and `\_` as literal characters, so the wildcards we inject (the
    // outer leading/trailing %) are the only true wildcards.
    $needle = '100%off';
    $like = '%' . RuleMatchEstimator::escapeLike($needle) . '%';
    expect($like)->toBe('%100\\%off%');
});

// ---------------------------------------------------------------------------
// isTooLoose() — protects the DB from per-keystroke full scans on tiny inputs
// ---------------------------------------------------------------------------

it('flags a rule with a single-character populated field as too loose', function () {
    expect(RuleMatchEstimator::isTooLoose([
        'street' => 'a', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => '',
    ]))->toBeTrue();
});

it('does not flag a rule whose populated fields all meet the minimum length', function () {
    expect(RuleMatchEstimator::isTooLoose([
        'street' => 'ab', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => '',
    ]))->toBeFalse();
});

it('flags a rule whose phone is shorter than the minimum after normalization', function () {
    // Caller is expected to pre-normalize via normalizeRule(); a single-digit
    // phone is still too loose, but a normalized empty phone is ignored.
    expect(RuleMatchEstimator::isTooLoose([
        'street' => 'london road', 'city' => '', 'county' => '',
        'postcode' => '', 'phone' => '7',
    ]))->toBeTrue();

    expect(RuleMatchEstimator::isTooLoose([
        'street' => 'london road', 'city' => '', 'county' => '',
        'postcode' => '', 'phone' => '',
    ]))->toBeFalse();
});

it('does not consider an entirely empty rule too loose (it has no populated fields to check)', function () {
    // ruleHasNoCriteria is the separate gate for "nothing to match" rules;
    // isTooLoose only fires when there IS at least one populated field that
    // is below the length threshold.
    expect(RuleMatchEstimator::isTooLoose([
        'street' => '', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => '',
    ]))->toBeFalse();
});

// ---------------------------------------------------------------------------
// estimate() — public entry point, end-to-end shape contract.
// ---------------------------------------------------------------------------

it('applies the address scope and field filters to the order select when estimating', function () {
    $resource = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
    $adapter = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
    $select = $this->createMock(\Magento\Framework\DB\Select::class);

    $resource->method('getConnection')->willReturn($adapter);
    $resource->method('getTableName')->willReturnArgument(0);
    $adapter->method('select')->willReturn($select);
    $adapter->method('quoteInto')->willReturnCallback(function ($text, $value) {
        return str_replace('?', (string)$value, $text);
    });
    $adapter->method('fetchOne')->willReturnOnConsecutiveCalls(100, 5, 50, 2);

    $whereCalls = [];
    $select->method('from')->willReturnSelf();
    $select->method('join')->willReturnSelf();
    $select->method('where')->willReturnCallback(function ($cond, $value = null) use (&$whereCalls, $select) {
        $whereCalls[] = ['cond' => $cond, 'value' => $value];
        return $select;
    });

    $estimator = new RuleMatchEstimator($resource);

    $estimator->estimate([
        'street'        => '1 example street',
        'city'          => '',
        'county'        => '',
        'postcode'      => 'zz1 1zz',
        'phone'         => '',
        'address_scope' => AddressScope::SCOPE_SHIPPING,
    ]);

    $conds = array_column($whereCalls, 'cond');
    $values = array_column($whereCalls, 'value');

    // The order match select must filter by the shipping scope and apply LIKE filters
    // on both populated rule fields. (Other where() calls cover the cutoff + active
    // quote flag for the order/quote totals respectively.)
    expect($conds)->toContain('a.address_type = ?');
    expect($values)->toContain(AddressScope::SCOPE_SHIPPING);
    expect($conds)->toContain('LOWER(a.street) LIKE ?');
    expect($values)->toContain('%1 example street%');
    expect($conds)->toContain('LOWER(a.postcode) LIKE ?');
    expect($values)->toContain('%zz1 1zz%');
});

it('applies the same address scope and field filters to the quote select when estimating', function () {
    $resource = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
    $adapter = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
    $select = $this->createMock(\Magento\Framework\DB\Select::class);

    $resource->method('getConnection')->willReturn($adapter);
    $resource->method('getTableName')->willReturnArgument(0);
    $adapter->method('select')->willReturn($select);
    $adapter->method('quoteInto')->willReturnCallback(function ($text, $value) {
        return str_replace('?', (string)$value, $text);
    });
    $adapter->method('fetchOne')->willReturnOnConsecutiveCalls(100, 5, 50, 2);

    $whereCalls = [];
    $select->method('from')->willReturnSelf();
    $select->method('join')->willReturnSelf();
    $select->method('where')->willReturnCallback(function ($cond, $value = null) use (&$whereCalls, $select) {
        $whereCalls[] = ['cond' => $cond, 'value' => $value];
        return $select;
    });

    $estimator = new RuleMatchEstimator($resource);

    $estimator->estimate([
        'street'        => '',
        'city'          => 'exampleville',
        'county'        => '',
        'postcode'      => '',
        'phone'         => '447700900123',
        'address_scope' => AddressScope::SCOPE_BILLING,
    ]);

    $conds = array_column($whereCalls, 'cond');
    $values = array_column($whereCalls, 'value');

    // Scope filter applied to the billing addresses.
    expect($conds)->toContain('a.address_type = ?');
    expect($values)->toContain(AddressScope::SCOPE_BILLING);
    // Both order and quote match selects must include the city LIKE filter
    // and the digit-stripped phone REGEXP_REPLACE filter for this rule.
    expect($conds)->toContain('LOWER(a.city) LIKE ?');
    expect($values)->toContain('%exampleville%');
    expect($conds)->toContain("REGEXP_REPLACE(a.telephone, '[^0-9]', '') LIKE ?");
    expect($values)->toContain('%447700900123%');
});

it('runs the full estimate() path through the connection and returns shaped order + quote counts', function () {
    $resource = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
    $adapter = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
    $select = $this->createMock(\Magento\Framework\DB\Select::class);

    $resource->method('getConnection')->willReturn($adapter);
    $resource->method('getTableName')->willReturnArgument(0);

    $adapter->method('select')->willReturn($select);
    $adapter->method('quoteInto')->willReturnCallback(function ($text, $value) {
        return str_replace('?', (string)$value, $text);
    });

    foreach (['from', 'where', 'join'] as $chained) {
        $select->method($chained)->willReturnSelf();
    }

    // Four fetchOne calls in order: order total, order match, quote total, quote match.
    $adapter->method('fetchOne')->willReturnOnConsecutiveCalls(100, 5, 50, 2);

    $estimator = new RuleMatchEstimator($resource);

    $result = $estimator->estimate([
        'street'        => 'example street',
        'city'          => '',
        'county'        => '',
        'postcode'      => 'zz1 1zz',
        'phone'         => '',
        'address_scope' => AddressScope::SCOPE_SHIPPING,
    ]);

    expect($result['too_loose'])->toBeFalse();
    expect($result['orders'])->toMatchArray(['matched' => 5, 'total' => 100, 'percent' => 5.0]);
    expect($result['quotes'])->toMatchArray(['matched' => 2, 'total' => 50, 'percent' => 4.0]);
});

it('estimate() returns matched=0 with the correct total when the rule has no populated fields', function () {
    $resource = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
    $adapter = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
    $select = $this->createMock(\Magento\Framework\DB\Select::class);

    $resource->method('getConnection')->willReturn($adapter);
    $resource->method('getTableName')->willReturnArgument(0);
    $adapter->method('select')->willReturn($select);
    $adapter->method('quoteInto')->willReturnCallback(function ($text, $value) {
        return str_replace('?', (string)$value, $text);
    });
    foreach (['from', 'where', 'join'] as $chained) {
        $select->method($chained)->willReturnSelf();
    }

    // Only two totals fetched (order + quote); the matched query is skipped because
    // ruleHasNoCriteria is true.
    $adapter->method('fetchOne')->willReturnOnConsecutiveCalls(80, 40);

    $estimator = new RuleMatchEstimator($resource);

    $result = $estimator->estimate([
        'street' => '', 'city' => '', 'county' => '', 'postcode' => '', 'phone' => '',
        'address_scope' => AddressScope::SCOPE_BOTH,
    ]);

    expect($result['too_loose'])->toBeFalse();
    expect($result['orders'])->toMatchArray(['matched' => 0, 'total' => 80, 'percent' => 0.0]);
    expect($result['quotes'])->toMatchArray(['matched' => 0, 'total' => 40, 'percent' => 0.0]);
});

it('short-circuits estimate() when a populated field is shorter than MIN_CRITERION_LENGTH', function () {
    // A stub ResourceConnection that explodes if the estimator tries to reach the DB:
    // proves the short-circuit avoids any query work at all.
    $resourceConnection = new class extends \Magento\Framework\App\ResourceConnection {
        public function __construct() {
            // Skip parent constructor: no DI container available in unit context.
        }
        public function getConnection($connectionName = self::DEFAULT_CONNECTION) {
            throw new \RuntimeException('estimate() should not have queried the DB for a too-loose rule');
        }
        public function getTableName($modelEntity, $connectionName = self::DEFAULT_CONNECTION) {
            throw new \RuntimeException('estimate() should not have resolved any table for a too-loose rule');
        }
    };

    $estimator = new RuleMatchEstimator($resourceConnection);

    $result = $estimator->estimate([
        'street' => 'a', 'city' => '', 'county' => '', 'postcode' => '',
        'phone' => '', 'address_scope' => AddressScope::SCOPE_BOTH,
    ]);

    expect($result['too_loose'])->toBeTrue();
    expect($result['minimum'])->toBe(RuleMatchEstimator::MIN_CRITERION_LENGTH);
    expect($result['orders'])->toMatchArray(['matched' => 0, 'total' => 0, 'percent' => 0.0]);
    expect($result['quotes'])->toMatchArray(['matched' => 0, 'total' => 0, 'percent' => 0.0]);
});
