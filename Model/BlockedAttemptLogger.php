<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Model;

use DeployEcommerce\PreventOrderPlacement\Model\ResourceModel\BlockedOrderAttempt as BlockedOrderAttemptResource;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;

class BlockedAttemptLogger
{
    /**
     * @var BlockedOrderAttemptFactory
     */
    private $attemptFactory;

    /**
     * @var BlockedOrderAttemptResource
     */
    private $attemptResource;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

    /**
     * @var Json
     */
    private $serializer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        BlockedOrderAttemptFactory $attemptFactory,
        BlockedOrderAttemptResource $attemptResource,
        RemoteAddress $remoteAddress,
        Json $serializer,
        LoggerInterface $logger
    ) {
        $this->attemptFactory = $attemptFactory;
        $this->attemptResource = $attemptResource;
        $this->remoteAddress = $remoteAddress;
        $this->serializer = $serializer;
        $this->logger = $logger;
    }

    /**
     * Persist an audit row describing a blocked order placement attempt.
     *
     * @param CartInterface $quote
     * @param array<string,string> $matchedRule Rule row that fired (post-normalization)
     * @param string $matchedScope billing|shipping
     * @param array<string,string> $inputData Lowercased address values compared
     * @param array<string,string> $matchedFields Map of rule-field => rule-value that matched
     * @param string|null $paymentMethod
     */
    public function log(
        CartInterface $quote,
        array $matchedRule,
        string $matchedScope,
        array $inputData,
        array $matchedFields,
        ?string $paymentMethod
    ): void {
        try {
            $billing = $quote->getBillingAddress();
            $shipping = $quote->getShippingAddress();

            $addresses = [
                'billing' => $billing ? $billing->getData() : null,
                'shipping' => $shipping ? $shipping->getData() : null,
            ];

            $customerEmail = $quote->getCustomerEmail();
            if (!$customerEmail && $billing && $billing->getEmail()) {
                $customerEmail = $billing->getEmail();
            }

            $reasonParts = [];
            foreach ($matchedFields as $field => $value) {
                $reasonParts[] = sprintf("%s contains '%s'", $field, $value);
            }
            $failureReason = $reasonParts ? implode('; ', $reasonParts) : '';

            $attempt = $this->attemptFactory->create();
            $attempt->setData([
                'remote_ip' => $this->remoteAddress->getRemoteAddress() ?: null,
                'quote_id' => $quote->getId() ? (int)$quote->getId() : null,
                'reserved_order_id' => $quote->getReservedOrderId() ?: null,
                'payment_method' => $paymentMethod ?: null,
                'customer_id' => $quote->getCustomerId() ? (int)$quote->getCustomerId() : null,
                'customer_email' => $customerEmail ?: null,
                'input_data' => $this->serializer->serialize($inputData),
                'addresses_json' => $this->serializer->serialize($addresses),
                'matched_rule' => $this->serializer->serialize($matchedRule),
                'matched_scope' => $matchedScope,
                'failure_reason' => $failureReason,
            ]);

            $this->attemptResource->save($attempt);
        } catch (\Throwable $e) {
            $this->logger->error(
                'DeployEcommerce_PreventOrderPlacement: failed to persist blocked attempt audit row',
                ['exception' => $e]
            );
        }
    }
}
