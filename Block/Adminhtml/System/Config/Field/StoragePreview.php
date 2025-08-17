<?php
declare(strict_types=1);

namespace Nacento\Connector\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Nacento\Connector\Model\Storage\KeyResolver;

class StoragePreview extends Field
{
    public function __construct(
        private readonly DeploymentConfig $deployConfig,
        private readonly KeyResolver $keyResolver,
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        // CSS for small layout tweaks (see step 3)
        $this->pageConfig->addPageAsset('Nacento_Connector::css/storage-preview.css');
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $cfg     = (array)($this->deployConfig->get('remote_storage/config') ?? []);
        $driver  = (string)($this->deployConfig->get('remote_storage/driver') ?? '');
        $bucket  = (string)($cfg['bucket'] ?? '');
        $ep      = (string)($cfg['endpoint'] ?? '');
        $prefix  = 'media/catalog/product/';
        $example = $prefix . '__example__.jpg';

        $pathStyle = ($ep !== '' && $bucket !== '')
            ? rtrim($ep, '/') . '/' . $bucket . '/' . $example
            : '';
        $vhost = ($bucket !== '' && $ep !== '')
            ? preg_replace('#^https?://#', 'https://' . $bucket . '.', rtrim($ep, '/')) . '/' . $example
            : '';
        $effective = $pathStyle;

        // ⬇️ Canvia assign([...]) per addData([...])
        $this->addData([
            'driver'       => $driver,
            'bucket'       => $bucket,
            'endpoint'     => $ep,
            'keyPrefix'    => $prefix,
            'exampleKey'   => $example,
            'urlPathStyle' => $pathStyle,
            'urlVhost'     => $vhost,
            'urlEffective' => $effective,
        ]);

        $this->setTemplate('Nacento_Connector::system/config/storage_preview.phtml');
        return $this->toHtml();
    }
}
