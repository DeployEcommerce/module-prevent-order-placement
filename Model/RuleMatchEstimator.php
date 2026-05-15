<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Model;

use DeployEcommerce\PreventOrderPlacement\Model\Config\Source\AddressScope;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Estimates how many historical orders and active quote addresses would match a
 * blocklist rule, so the admin can spot overly-loose entries before saving them.
 *
 * Uses lowercase substring (LIKE) semantics that mirror the runtime plugin's match
 * logic. Phone values are reduced to digits via REGEXP_REPLACE on both sides.
 */
class RuleMatchEstimator
{
    public const ORDER_WINDOW_MONTHS = 12;

    /**
     * Minimum normalized length (per populated field) before a preview will be
     * estimated. Below this, the rule is considered too loose to be scanned
     * cheaply on a large historical dataset, and the preview short-circuits to
     * a warning so single-character rules don't trigger full table scans on
     * every blur.
     */
    public const MIN_CRITERION_LENGTH = 2;

    // Canonical list lives on BlocklistMatcher::STRING_FIELDS — referenced
    // here directly so the preview estimator can't drift from runtime fields.

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param array<string,string> $rule Raw or normalized rule row; normalize() is applied
     *                                   internally so callers may pass admin-entered values.
     * @return array{
     *     too_loose: bool,
     *     minimum: int,
     *     orders: array{matched:int,total:int,percent:float},
     *     quotes: array{matched:int,total:int,percent:float}
     * } When `too_loose` is true the order/quote counts are zeroed (no DB queries
     *   issued); the frontend renders the orange "too loose" warning instead.
     */
    public function estimate(array $rule): array
    {
        $normalized = self::normalizeRule($rule);

        if (self::isTooLoose($normalized)) {
            return [
                'too_loose' => true,
                'minimum'   => self::MIN_CRITERION_LENGTH,
                'orders'    => ['matched' => 0, 'total' => 0, 'percent' => 0.0],
                'quotes'    => ['matched' => 0, 'total' => 0, 'percent' => 0.0],
            ];
        }

        return [
            'too_loose' => false,
            'minimum'   => self::MIN_CRITERION_LENGTH,
            'orders'    => $this->countOrders($normalized),
            'quotes'    => $this->countQuotes($normalized),
        ];
    }

    /**
     * A rule is "too loose" for preview if any of its populated fields, after
     * normalization, has fewer than MIN_CRITERION_LENGTH characters. Empty
     * fields are skipped.
     *
     * @param array<string,string> $rule
     * @internal exposed for unit testing.
     */
    public static function isTooLoose(array $rule): bool
    {
        foreach (BlocklistMatcher::STRING_FIELDS as $field) {
            $value = isset($rule[$field]) ? (string)$rule[$field] : '';
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) < self::MIN_CRITERION_LENGTH) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,string> $rule
     */
    private function countOrders(array $rule): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $addressTable = $this->resourceConnection->getTableName('sales_order_address');

        $cutoff = $connection->quoteInto(
            'DATE_SUB(NOW(), INTERVAL ? MONTH)',
            self::ORDER_WINDOW_MONTHS
        );

        $totalSelect = $connection->select()
            ->from(['o' => $orderTable], ['cnt' => new \Zend_Db_Expr('COUNT(*)')])
            ->where("o.created_at >= $cutoff");

        $total = (int)$connection->fetchOne($totalSelect);

        if (self::ruleHasNoCriteria($rule)) {
            return $this->formatResult(0, $total);
        }

        $matchSelect = $connection->select()
            ->from(['a' => $addressTable], ['cnt' => new \Zend_Db_Expr('COUNT(DISTINCT a.parent_id)')])
            ->join(['o' => $orderTable], 'o.entity_id = a.parent_id', [])
            ->where("o.created_at >= $cutoff");

        $this->applyScope($matchSelect, $rule['address_scope']);
        $this->applyFieldFilters($matchSelect, $rule);

        $matched = (int)$connection->fetchOne($matchSelect);

        return $this->formatResult($matched, $total);
    }

    /**
     * @param array<string,string> $rule
     */
    private function countQuotes(array $rule): array
    {
        $connection = $this->resourceConnection->getConnection();
        $quoteTable = $this->resourceConnection->getTableName('quote');
        $addressTable = $this->resourceConnection->getTableName('quote_address');

        // Denominator: distinct active quotes that actually have an address row in the
        // requested scope. This aligns the population with what the matcher can ever
        // act on (a quote with no address is structurally unblockable).
        $totalSelect = $connection->select()
            ->from(['a' => $addressTable], ['cnt' => new \Zend_Db_Expr('COUNT(DISTINCT a.quote_id)')])
            ->join(['q' => $quoteTable], 'q.entity_id = a.quote_id', [])
            ->where('q.is_active = ?', 1);
        $this->applyScope($totalSelect, $rule['address_scope']);

        $total = (int)$connection->fetchOne($totalSelect);

        if (self::ruleHasNoCriteria($rule)) {
            return $this->formatResult(0, $total);
        }

        $matchSelect = $connection->select()
            ->from(['a' => $addressTable], ['cnt' => new \Zend_Db_Expr('COUNT(DISTINCT a.quote_id)')])
            ->join(['q' => $quoteTable], 'q.entity_id = a.quote_id', [])
            ->where('q.is_active = ?', 1);

        $this->applyScope($matchSelect, $rule['address_scope']);
        $this->applyFieldFilters($matchSelect, $rule);

        $matched = (int)$connection->fetchOne($matchSelect);

        return $this->formatResult($matched, $total);
    }

    private function applyScope(Select $select, string $scope): void
    {
        if ($scope === AddressScope::SCOPE_BILLING || $scope === AddressScope::SCOPE_SHIPPING) {
            $select->where('a.address_type = ?', $scope);
        }
    }

    /**
     * @param array<string,string> $rule
     */
    private function applyFieldFilters(Select $select, array $rule): void
    {
        foreach (BlocklistMatcher::STRING_FIELDS as $field) {
            $needle = $rule[$field];
            if ($needle === '') {
                continue;
            }

            $like = '%' . self::escapeLike($needle) . '%';

            if ($field === 'phone') {
                // Strip non-digits from telephone and rule before substring match.
                $select->where(
                    "REGEXP_REPLACE(a.telephone, '[^0-9]', '') LIKE ?",
                    $like
                );
                continue;
            }

            if ($field === 'county') {
                // The `region` column on both sales_order_address and quote_address stores
                // either the looked-up region name (when region_id is set) or the free-text
                // value submitted by the customer. Matching against it directly is the
                // SQL-level equivalent of the runtime matcher's getRegion()/getData('region')
                // pair.
                $select->where(
                    "LOWER(COALESCE(NULLIF(a.region, ''), '')) LIKE ?",
                    $like
                );
                continue;
            }

            $column = $this->columnForField($field);
            $select->where("LOWER($column) LIKE ?", $like);
        }
    }

    private function columnForField(string $field): string
    {
        switch ($field) {
            case 'street':
                return 'a.street';
            case 'city':
                return 'a.city';
            case 'postcode':
                return 'a.postcode';
            default:
                return 'a.' . $field;
        }
    }

    /**
     * Escape MySQL LIKE wildcards in admin-entered values so that `%` and `_`
     * are treated literally (matching the runtime str_contains semantics).
     * Exposed as a public static helper for unit tests; @internal.
     */
    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * @param array<string,string> $rule
     * @internal exposed for unit testing.
     */
    public static function ruleHasNoCriteria(array $rule): bool
    {
        foreach (BlocklistMatcher::STRING_FIELDS as $field) {
            if (!isset($rule[$field]) || (string)$rule[$field] === '') {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * @param array<string,string> $rule
     * @return array<string,string>
     * @internal exposed for unit testing.
     */
    public static function normalizeRule(array $rule): array
    {
        $normalized = [];
        foreach (BlocklistMatcher::STRING_FIELDS as $field) {
            $raw = isset($rule[$field]) ? (string)$rule[$field] : '';
            $normalized[$field] = $field === 'phone'
                ? BlocklistMatcher::normalizePhone($raw)
                : mb_strtolower(trim($raw));
        }

        $scope = isset($rule['address_scope']) ? (string)$rule['address_scope'] : '';
        if (!in_array($scope, [AddressScope::SCOPE_BILLING, AddressScope::SCOPE_SHIPPING, AddressScope::SCOPE_BOTH], true)) {
            $scope = AddressScope::SCOPE_BOTH;
        }
        $normalized['address_scope'] = $scope;

        return $normalized;
    }

    /**
     * @return array{matched:int,total:int,percent:float}
     */
    private function formatResult(int $matched, int $total): array
    {
        $percent = $total > 0 ? round(($matched / $total) * 100, 2) : 0.0;
        return [
            'matched' => $matched,
            'total' => $total,
            'percent' => $percent,
        ];
    }
}
