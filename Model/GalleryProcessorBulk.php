<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Nacento\Connector\Api\BulkGalleryManagementInterface;
use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Api\Data\BulkResultInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Psr\Log\LoggerInterface;
use Nacento\Connector\Model\Data\ImageEntryFactory;

/**
 * Handles the synchronous bulk processing of product galleries.
 * This class orchestrates the processing by iterating over each SKU and delegating the core logic
 * to the single-SKU processor, wrapping each operation in a database transaction.
 */
class GalleryProcessorBulk implements BulkGalleryManagementInterface
{
    /**
     * @param ProductRepositoryInterface $productRepository To fetch product data.
     * @param ResourceConnection $resource For direct database access and transaction control.
     * @param ProductAction $productAction For efficient bulk attribute updates (e.g., image roles).
     * @param LoggerInterface $logger For logging errors and process information.
     * @param GalleryProcessor $singleProcessor The single-SKU processor whose logic is reused.
     * @param ImageEntryFactory $imageEntryFactory To create typed ImageEntry DTOs.
     * @param Data\BulkResultFactory $bulkResultFactory To create the final bulk result DTO.
     * @param Data\BulkSkuResultFactory $bulkSkuResultFactory To create result DTOs for each SKU.
     * @param Data\ImageStatsFactory $imageStatsFactory To create image statistics DTOs.
     * @param Data\BulkStatsFactory $bulkStatsFactory To create the overall bulk statistics DTO.
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ResourceConnection $resource,
        private readonly ProductAction $productAction,
        private readonly LoggerInterface $logger,
        private readonly GalleryProcessor $singleProcessor,
        private readonly ImageEntryFactory $imageEntryFactory,
        private readonly Data\BulkResultFactory $bulkResultFactory,
        private readonly Data\BulkSkuResultFactory $bulkSkuResultFactory,
        private readonly Data\ImageStatsFactory $imageStatsFactory,
        private readonly Data\BulkStatsFactory $bulkStatsFactory
    ) {}

    /**
     * Processes a bulk request synchronously.
     * {@inheritdoc}
     */
    public function process(BulkRequestInterface $request): BulkResultInterface
    {
        $items  = $request->getItems() ?? [];
        // Initialize overall statistics for the entire batch.
        $stats  = [
            'skus_seen'        => 0,
            'ok'               => 0,
            'error'            => 0,
            'inserted'         => 0,
            'updated_value'    => 0,
            'updated_meta'     => 0,
            'skipped_no_change'=> 0,
        ];
        $results = [];

        // Step 1: Deduplicate items by SKU using a map (last-wins).
        $map = [];
        foreach ($items as $it) {
            $sku = (string) $it->getSku();
            if ($sku === '') {
                continue; // Skip items with no SKU.
            }
            $map[$sku] = $it; // The last item for a given SKU will overwrite previous ones.
        }
        $uniqueItems = array_values($map);
        $stats['skus_seen'] = count($uniqueItems);

        // Step 2: Process each unique SKU, wrapping each in its own database transaction.
        $conn = $this->resource->getConnection();

        foreach ($uniqueItems as $it) {
            $sku = (string) $it->getSku();
            $skuStats  = ['inserted'=>0,'updated_value'=>0,'updated_meta'=>0,'skipped_no_change'=>0,'warnings'=>[]];
            $skuResult = $this->bulkSkuResultFactory->create(['data' => ['sku' => $sku]]);

            try {
                // Fetch the product to ensure it exists before starting a transaction.
                $product = $this->productRepository->get($sku);
                $skuResult->setData('product_id', (int) $product->getId());

                // Ensure the image data is in a consistent format (array of ImageEntryInterface).
                $images = $this->normalizeImages($it->getImages());

                // Start a transaction to ensure atomicity for this single SKU's gallery update.
                $conn->beginTransaction();
                // Reuse the single-SKU processor to perform the core database operations.
                $ok = $this->singleProcessor->create($sku, $images);
                // If the processor completes without exceptions, commit the transaction.
                $conn->commit();

                // Update statistics based on the outcome.
                if ($ok) {
                    $stats['ok']++;
                    $skuResult->setData('image_stats', $skuStats); // Note: Current logic doesn't populate detailed image stats here.
                } else {
                    $stats['error']++;
                    $skuResult->setData('error', 'unknown_error');
                }
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                // Handle cases where the product does not exist.
                if ($conn->getTransactionLevel()) {
                    $conn->rollBack();
                }
                $stats['error']++;
                $skuResult->setData('error', 'product_not_found');
            } catch (\Throwable $e) {
                // Catch any other exception to prevent the entire bulk process from failing.
                if ($conn->getTransactionLevel()) {
                    $conn->rollBack();
                }
                $stats['error']++;
                $this->logger->error('[NacentoConnector][Bulk] SKU '.$sku.' error: '.$e->getMessage());
                $skuResult->setData('error', 'exception');
            }

            // Create and attach the statistics object for the current SKU.
            $imageStatsObj = $this->imageStatsFactory->create(['data' => $skuStats]);
            $skuResult->setData('image_stats', $imageStatsObj);

            // Add the result of this SKU to the main results array.
            $results[] = $skuResult;
        }

        // Create the final statistics object for the whole bulk operation.
        $statsObj = $this->bulkStatsFactory->create(['data' => $stats]);

        // Assemble and return the final, structured bulk result object.
        return $this->bulkResultFactory->create([
            'data' => [
                'request_id' => $request->getRequestId(),
                'stats'      => $statsObj,
                'results'    => $results,
            ]
        ]);
    }

    /**
     * Converts a mixed array of image data into a consistent array of ImageEntryInterface objects.
     * This is necessary to provide a predictable input format for the single processor.
     *
     * @param array $images An array of ImageEntryInterface, DataObject, or plain arrays.
     * @return \Nacento\Connector\Api\Data\ImageEntryInterface[]
     */
    private function normalizeImages(array $images): array
    {
        $out = [];
        foreach ($images as $img) {
            if ($img instanceof \Nacento\Connector\Api\Data\ImageEntryInterface) {
                $out[] = $img;
            } elseif ($img instanceof \Magento\Framework\DataObject) {
                $out[] = $this->imageEntryFactory->create(['data' => $img->getData()]);
            } elseif (is_array($img)) {
                $out[] = $this->imageEntryFactory->create(['data' => $img]);
            }
            // If the format does not match any of the above, it is silently skipped.
        }
        return $out;
    }

}