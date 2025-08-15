<?php
declare(strict_types=1);
namespace Nacento\Connector\Api\Data;

interface ProcessGalleryMessageInterface
{
    /** @return string */
    public function getSku(): string;

    /** @return \Nacento\Connector\Api\Data\ImageEntryInterface[] */
    public function getImages(): array;
}
