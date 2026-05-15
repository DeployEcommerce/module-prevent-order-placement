<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 */
declare(strict_types=1);

namespace DeployEcommerce\PreventOrderPlacement\Controller\Adminhtml\Preview;

use DeployEcommerce\PreventOrderPlacement\Model\RuleMatchEstimator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

class Estimate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DeployEcommerce_PreventOrderPlacement::config';

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var RuleMatchEstimator
     */
    private $estimator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        RuleMatchEstimator $estimator,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->estimator = $estimator;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        $request = $this->getRequest();
        $rule = [
            'street'        => (string)$request->getPost('street', ''),
            'city'          => (string)$request->getPost('city', ''),
            'county'        => (string)$request->getPost('county', ''),
            'postcode'      => (string)$request->getPost('postcode', ''),
            'phone'         => (string)$request->getPost('phone', ''),
            'address_scope' => (string)$request->getPost('address_scope', ''),
        ];

        try {
            $estimate = $this->estimator->estimate($rule);
        } catch (\Throwable $e) {
            $this->logger->error(
                'DeployEcommerce_PreventOrderPlacement: rule match preview failed',
                ['exception' => $e]
            );
            return $result->setHttpResponseCode(500)->setData([
                'error' => (string)__('Failed to evaluate rule. Check var/log/system.log.'),
            ]);
        }

        return $result->setData($estimate);
    }
}
