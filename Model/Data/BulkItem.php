<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkItemInterface;

class BulkItem extends DataObject implements BulkItemInterface
{
    public function getSku(): string { return (string)$this->getData('sku'); }
    public function setSku(string $sku): self { return $this->setData('sku', $sku); }

    public function getImages(): array { return $this->getData('images') ?? []; }
    public function setImages(array $images): self { return $this->setData('images', $images); }
}
