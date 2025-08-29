<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkItemInterface;

class BulkItem extends DataObject implements BulkItemInterface
{
    public function getSku(): string
    {
        return (string) $this->getData('sku');
    }

    public function setSku(string $sku)
    {
        return $this->setData('sku', $sku);
    }

    public function getImages(): array
    {
        $images = $this->getData('images');
        return is_array($images) ? $images : [];
    }

    public function setImages(array $images)
    {
        return $this->setData('images', $images);
    }
}
