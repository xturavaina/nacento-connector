<?php
declare(strict_types=1);
namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\ProcessGalleryMessageInterface;

class ProcessGalleryMessage extends DataObject implements ProcessGalleryMessageInterface
{
    public function getSku(): string { return (string)$this->getData('sku'); }
    public function getImages(): array { return $this->getData('images') ?? []; }
}
