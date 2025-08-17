<?php
declare(strict_types=1);

namespace Nacento\Connector\Plugin;

use Psr\Log\LoggerInterface;
use Magento\Config\Model\Config;

class LogConfigModelSave
{
    public function __construct(private LoggerInterface $logger) {}

    public function beforeSave(Config $subject)
    {
        $data = $subject->getData();
        $this->logger->debug('[Nacento][ConfigModel::save BEFORE]', [
            'section' => $subject->getSection(),
            'website' => $subject->getWebsite(),
            'store'   => $subject->getStore(),
            'has_mq'  => isset($data['groups']['mq']['fields']['topic']['value']),
            'has_s3'  => isset($data['groups']['s3']['fields']['ping_object_key']['value']),
            'topic'   => $data['groups']['mq']['fields']['topic']['value'] ?? null,
            'ping'    => $data['groups']['s3']['fields']['ping_object_key']['value'] ?? null,
        ]);
    }

    public function afterSave(Config $subject, $result)
    {
        $this->logger->debug('[Nacento][ConfigModel::save AFTER]', [
            'section' => $subject->getSection(),
            'website' => $subject->getWebsite(),
            'store'   => $subject->getStore(),
        ]);
        return $result;
    }
}
