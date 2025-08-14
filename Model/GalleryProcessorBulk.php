<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Nacento\Connector\Api\BulkGalleryManagementInterface;
use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Api\Data\BulkResultInterface;
use Nacento\Connector\Model\Data\BulkResultFactory;
use Nacento\Connector\Model\Data\BulkSkuResultFactory;
use Nacento\Connector\Model\Data\ImageStatsFactory;
use Nacento\Connector\Model\Data\BulkStatsFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Psr\Log\LoggerInterface;
use Nacento\Connector\Model\Data\ImageEntryFactory;

class GalleryProcessorBulk implements BulkGalleryManagementInterface
{
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

    public function process(BulkRequestInterface $request): BulkResultInterface
    {
        $items  = $request->getItems() ?? [];
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

        // 1) Dedupe per SKU (last-wins) però iterarem pel valor (no per la clau numèrica)
        $map = [];
        foreach ($items as $it) {
            $sku = (string) $it->getSku();
            if ($sku === '') {
                continue;
            }
            $map[$sku] = $it; // last-wins
        }
        $uniqueItems = array_values($map);
        $stats['skus_seen'] = count($uniqueItems);

        // 2) Processa per SKU (transacció per SKU)
        $conn = $this->resource->getConnection();

        foreach ($uniqueItems as $it) {
            $sku = (string) $it->getSku();
            $skuStats  = ['inserted'=>0,'updated_value'=>0,'updated_meta'=>0,'skipped_no_change'=>0,'warnings'=>[]];
            $skuResult = $this->bulkSkuResultFactory->create(['data' => ['sku' => $sku]]);

            try {
                $product = $this->productRepository->get($sku);
                $skuResult->setData('product_id', (int) $product->getId());

                // Normalitza arrays plans a ImageEntryInterface[]
                $images = $this->normalizeImages($it->getImages());

                $conn->beginTransaction();
                // reutilitzo el processor single (V1)
                $ok = $this->singleProcessor->create($sku, $images);
                $conn->commit();

                if ($ok) {
                    $stats['ok']++;
                    $skuResult->setData('image_stats', $skuStats);
                } else {
                    $stats['error']++;
                    $skuResult->setData('error', 'unknown_error');
                }
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                if ($conn->getTransactionLevel()) {
                    $conn->rollBack();
                }
                $stats['error']++;
                $skuResult->setData('error', 'product_not_found');
            } catch (\Throwable $e) {
                if ($conn->getTransactionLevel()) {
                    $conn->rollBack();
                }
                $stats['error']++;
                $this->logger->error('[NacentoConnector][Bulk] SKU '.$sku.' error: '.$e->getMessage());
                $skuResult->setData('error', 'exception');
            }

            $imageStatsObj = $this->imageStatsFactory->create(['data' => $skuStats]);
            $skuResult->setData('image_stats', $imageStatsObj);

            $results[] = $skuResult;
        }

        $statsObj = $this->bulkStatsFactory->create(['data' => $stats]);

        return $this->bulkResultFactory->create([
            'data' => [
                'request_id' => $request->getRequestId(),
                'stats'      => $statsObj,
                'results'    => $results,
            ]
        ]);
    }

    /**
     * Converteix cada element de $images a ImageEntryInterface si cal.
     * Requereix haver injectat ImageEntryFactory $imageEntryFactory a la classe.
     *
     * @param array $images
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
            // si no casa cap, l'ometem en silenci o bé log.debug(...)
        }
        return $out;
    }

}
