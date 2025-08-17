<?php
declare(strict_types=1);

namespace Nacento\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class LogConfigAfterSave implements ObserverInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ScopeConfigInterface $scopeConfig
    ) {}

    public function execute(Observer $observer)
    {
        $topic = $this->scopeConfig->getValue('nacento_connector/mq/topic');
        $ping  = $this->scopeConfig->getValue('nacento_connector/s3/ping_object_key');

        $this->logger->debug('[Nacento][AfterSave] stored values', [
            'topic' => $topic,
            'ping'  => $ping,
        ]);
    }
}
