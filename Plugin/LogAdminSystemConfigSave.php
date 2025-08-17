<?php
declare(strict_types=1);

namespace Nacento\Connector\Plugin;

use Psr\Log\LoggerInterface;

class LogAdminSystemConfigSave
{
    public function __construct(private LoggerInterface $logger) {}

    public function beforeExecute(\Magento\Config\Controller\Adminhtml\System\Config\Save $subject)
    {
        $p = $subject->getRequest()->getParams();
        unset($p['form_key'], $p['key']); // no spam
        // Escrivim només el que ens interessa
        $this->logger->debug('[Nacento][AdminSave] incoming params', [
            'section' => $p['section'] ?? null,
            'topic'   => $p['groups']['mq']['fields']['topic']['value'] ?? null,
            'ping'    => $p['groups']['s3']['fields']['ping_object_key']['value'] ?? null,
        ]);
    }
}
