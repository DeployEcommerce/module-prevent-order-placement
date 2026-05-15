<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Plugin\Quote;

use DeployEcommerce\PreventOrderPlacement\Model\AddressBlocklist;
use DeployEcommerce\PreventOrderPlacement\Model\BlockedAttemptLogger;
use DeployEcommerce\PreventOrderPlacement\Model\BlocklistMatcher;
use DeployEcommerce\PreventOrderPlacement\Model\Config\Source\AddressScope;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class PreventOrderPlacement
{
    public const CONFIG_PATH_ENABLED = 'deployecommerce_preventorderplacement/general/enabled';

    /**
     * @var AddressBlocklist
     */
    private $blocklist;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var BlockedAttemptLogger
     */
    private $attemptLogger;

    /**
     * @var BlocklistMatcher
     */
    private $matcher;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        AddressBlocklist $blocklist,
        CartRepositoryInterface $cartRepository,
        BlockedAttemptLogger $attemptLogger,
        BlocklistMatcher $matcher,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->blocklist = $blocklist;
        $this->cartRepository = $cartRepository;
        $this->attemptLogger = $attemptLogger;
        $this->matcher = $matcher;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @param CartManagementInterface $subject
     * @param int $cartId
     * @param PaymentInterface|null $paymentMethod
     * @return array
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforePlaceOrder(
        CartManagementInterface $subject,
        $cartId,
        ?PaymentInterface $paymentMethod = null
    ): array {
        if (!$this->isEnabledForStore($this->currentStoreId())) {
            return [$cartId, $paymentMethod];
        }

        $rules = $this->blocklist->getRules();
        if (!$rules) {
            return [$cartId, $paymentMethod];
        }

        try {
            $quote = $this->cartRepository->get((int)$cartId);
        } catch (NoSuchEntityException $e) {
            return [$cartId, $paymentMethod];
        }

        $billingValues = $this->matcher->extractAddressValues($quote->getBillingAddress());
        $shippingValues = $this->matcher->extractAddressValues($quote->getShippingAddress());

        foreach ($rules as $ruleIndex => $rule) {
            $scope = $rule['address_scope'];

            $candidates = [];
            if ($scope === AddressScope::SCOPE_BILLING || $scope === AddressScope::SCOPE_BOTH) {
                $candidates[AddressScope::SCOPE_BILLING] = $billingValues;
            }
            if ($scope === AddressScope::SCOPE_SHIPPING || $scope === AddressScope::SCOPE_BOTH) {
                $candidates[AddressScope::SCOPE_SHIPPING] = $shippingValues;
            }

            foreach ($candidates as $matchedScope => $values) {
                $matchedFields = $this->matcher->matchRule($rule, $values);
                if ($matchedFields === null) {
                    continue;
                }

                $paymentCode = $paymentMethod ? $paymentMethod->getMethod() : null;
                if (!$paymentCode && $quote->getPayment()) {
                    $paymentCode = $quote->getPayment()->getMethod();
                }

                $this->logger->warning(
                    sprintf(
                        'DeployEcommerce_PreventOrderPlacement: blocked placeOrder cartId=%s ruleIndex=%s scope=%s',
                        (string)$cartId,
                        (string)$ruleIndex,
                        $matchedScope
                    )
                );

                $this->attemptLogger->log(
                    $quote,
                    $rule,
                    $matchedScope,
                    $values,
                    $matchedFields,
                    $paymentCode
                );

                throw new LocalizedException(
                    __('Your order cannot be placed. Please contact customer support.')
                );
            }
        }

        return [$cartId, $paymentMethod];
    }

    /**
     * Resolve the current store id from the request context. Falls back to 0 when not resolvable
     * (e.g. admin order placement), in which case the flag check uses the default scope.
     */
    private function currentStoreId(): int
    {
        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return 0;
        }
    }

    /**
     * Resolve the enabled flag for the supplied store view (falls back to default scope when 0).
     */
    private function isEnabledForStore(int $storeId): bool
    {
        if ($storeId > 0) {
            return $this->scopeConfig->isSetFlag(
                self::CONFIG_PATH_ENABLED,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }

        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_ENABLED);
    }

    /**
     * Reduce a phone number to its digit characters so that "+44 7700 900123" and
     * "07700 900123" can both be substring-matched against admin-entered rule values
     * regardless of whitespace, dashes, parentheses or international prefix punctuation.
     */
    public static function normalizePhone(string $value): string
    {
        return BlocklistMatcher::normalizePhone($value);
    }
}
