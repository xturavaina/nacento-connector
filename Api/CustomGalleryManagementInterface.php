<?php
/**
 * Copyright © Nacento
 */
declare(strict_types=1);

namespace Nacento\Connector\Api;

use Nacento\Connector\Api\Data\ImageEntryInterface;

interface CustomGalleryManagementInterface
{
    /**
     * Create new media gallery entries for a product from a list of pre-existing file paths.
     *
     * @param string $sku
     * @param ImageEntryInterface[] $images
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\InputException
     */
    public function create(string $sku, array $images): bool;
}