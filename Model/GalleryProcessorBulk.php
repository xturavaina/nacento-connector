<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Nacento\Connector\Api\BulkGalleryManagementInterface;
use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Api\Data\BulkResultInterface;
use Nacento\Connector\Model\Data\BulkResultFactory;
use Nacento\Connector\Model\Data\BulkSkuResultFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Psr\Log\LoggerInterface;

class GalleryProcessorBulk implements BulkGalleryManagementInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ResourceConnection $resource,
        private readonly ProductAction $productAction,
        private readonly LoggerInterface $logger,
        private readonly GalleryProcessor $singleProcessor,
        private readonly Data\BulkResultFactory $bulkResultFactory,
        private readonly Data\BulkSkuResultFactory $bulkSkuResultFactory
    ) {}

    public function process(BulkRequestInterface $request): BulkResultInterface
    {
        $items = $request->getItems() ?? [];
        $stats = ['skus_seen'=>0,'ok'=>0,'error'=>0,'inserted'=>0,'updated_value'=>0,'updated_meta'=>0,'skipped_no_change'=>0];
        $results = [];

        // 1) Dedup SKUs (last-wins)
        $bySku = [];
        foreach ($items as $it) {
            $sku = $it->getSku();
            if (!$sku) { continue; }
            $bySku[$sku] = $it; // last-wins
        }

        $stats['skus_seen'] = count($bySku);

        // 2) (V1) Processa per SKU seqüencialment, transacció per SKU
        $conn = $this->resource->getConnection();

        foreach ($bySku as $sku => $it) {
            $skuStats = ['inserted'=>0,'updated_value'=>0,'updated_meta'=>0,'skipped_no_change'=>0,'warnings'=>[]];
            $skuResult = $this->bulkSkuResultFactory->create(['data' => ['sku' => $sku]]);

            try {
                $product = $this->productRepository->get($sku);
                $skuResult->setData('product_id', (int)$product->getId());

                $conn->beginTransaction();
                // Crida el processor existent per aprofitar la lògica actual
                // Nota: create() ja fa logs i retorna bool. només comptabilitzem.
                $ok = $this->singleProcessor->create($sku, $it->getImages());
                $conn->commit();

                if ($ok) {
                    $stats['ok']++;
                    // En V1 no tenim desglossat per imatge; a V2 quan migris la lògica al bulk directament
                    $skuResult->setData('image_stats', $skuStats);
                } else {
                    $stats['error']++;
                    $skuResult->setData('error', 'unknown_error');
                }
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $conn->rollBack();
                $stats['error']++;
                $skuResult->setData('error', 'product_not_found');
            } catch (\Throwable $e) {
                $conn->rollBack();
                $stats['error']++;
                $this->logger->error('[NacentoConnector][Bulk] SKU '.$sku.' error: '.$e->getMessage());
                $skuResult->setData('error', 'exception');
            }

            $results[] = $skuResult;
        }

        return $this->bulkResultFactory->create([
            'data' => [
                'request_id' => $request->getRequestId(),
                'stats' => $stats,
                'results' => $results,
            ]
        ]);
    }
}
