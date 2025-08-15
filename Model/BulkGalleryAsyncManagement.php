<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterfaceFactory;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterface;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterfaceFactory;

use Magento\AsynchronousOperations\Model\OperationFactory;

use Nacento\Connector\Api\BulkGalleryAsyncManagementInterface;
use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Api\Data\ImageEntryInterface;

/**
 * Planner asíncron per publicar lots de processat de galeries.
 *
 * - Dedupe per SKU (last-wins).
 * - Converteix les imatges (DTO/objectes) a ARRAYS plans abans de serialitzar.
 * - Programa el bulk i retorna un AsyncResponse amb bulk_uuid i item statuses.
 */
class BulkGalleryAsyncManagement implements BulkGalleryAsyncManagementInterface
{
    public function __construct(
        private readonly BulkManagementInterface $bulkManagement,
        private readonly OperationFactory $operationFactory,
        private readonly SerializerInterface $serializer,
        private readonly UserContextInterface $userContext,
        private readonly AsyncResponseInterfaceFactory $asyncResponseFactory,
        private readonly ItemStatusInterfaceFactory $itemStatusFactory,
        private readonly Random $random,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Planifica el processat bulk i retorna un AsyncResponse amb el bulk_uuid.
     *
     * @param BulkRequestInterface $request
     * @return AsyncResponseInterface
     */
    public function submit(BulkRequestInterface $request): AsyncResponseInterface
    {
        $bulkUuid = $this->uuidV4(); // també podries usar $this->random->getUniqueHash();
        $userId   = (int)($this->userContext->getUserId() ?? 0);
        $desc     = 'Nacento gallery bulk';

        // 1) Dedupe per SKU (last-wins)
        $map = [];
        foreach ($request->getItems() ?? [] as $it) {
            $sku = (string)($it->getSku() ?? '');
            if ($sku !== '') {
                $map[$sku] = $it;
            }
        }
        $unique = array_values($map);

        // 2) Construeix operacions i els item-status
        $operations = [];
        $statuses   = [];
        $seq        = 1;

        foreach ($unique as $it) {
            $sku = (string)($it->getSku() ?? '');
            if ($sku === '') {
                $statuses[] = $this->makeStatus($seq++, '', ItemStatusInterface::STATUS_REJECTED, 'Missing SKU');
                continue;
            }

            // Normalitza imatges a ARRAYS plans
            $imagesPayload = $this->imagesToPayload($it->getImages() ?? []);

            // Payload final que viatjarà al consumer
            $payload = [
                'sku'    => $sku,
                'images' => $imagesPayload,
            ];

            $operations[] = $this->operationFactory->create([
                'data' => [
                    OperationInterface::BULK_ID         => $bulkUuid,
                    OperationInterface::TOPIC_NAME      => 'nacento.gallery.process',
                    OperationInterface::SERIALIZED_DATA => $this->serializer->serialize($payload),
                    OperationInterface::STATUS          => OperationInterface::STATUS_TYPE_OPEN,
                ],
            ]);

            $statuses[] = $this->makeStatus($seq++, $sku, ItemStatusInterface::STATUS_ACCEPTED);
        }

        if (!empty($operations)) {
            $this->bulkManagement->scheduleBulk($bulkUuid, $operations, $desc, $userId);
        } else {
            $this->logger->warning('[NacentoConnector][BulkPlanner] Cap operació programada (cap SKU vàlid?)');
        }

        // 3) AsyncResponse
        $resp = $this->asyncResponseFactory->create();
        $resp->setBulkUuid($bulkUuid);
        $resp->setRequestItems($statuses);
        $resp->setErrors(false);

        return $resp;
    }

    /**
     * Converteix qualsevol col·lecció d’imatges (DTOs/arrays/DataObject) a arrays plans whitelistats.
     *
     * @param array $images
     * @return array<int,array{file_path:string,label:string,disabled:bool,position:int,roles:array}>
     */
    private function imagesToPayload(array $images): array
    {
        $out = [];

        foreach ($images as $img) {
            // Cas 1: ja és el nostre DTO
            if ($img instanceof ImageEntryInterface) {
                $out[] = [
                    'file_path' => (string)$img->getFilePath(),
                    'label'     => (string)$img->getLabel(),
                    'disabled'  => (bool)$img->isDisabled(),
                    'position'  => (int)$img->getPosition(),
                    'roles'     => array_values($img->getRoles() ?? []),
                ];
                continue;
            }

            // Cas 2: DataObject genèric
            if ($img instanceof DataObject) {
                $row = $img->getData();
                $out[] = [
                    'file_path' => (string)($row['file_path'] ?? ''),
                    'label'     => (string)($row['label'] ?? ''),
                    'disabled'  => !empty($row['disabled']),
                    'position'  => (int)($row['position'] ?? 0),
                    'roles'     => isset($row['roles']) && is_array($row['roles']) ? array_values($row['roles']) : [],
                ];
                continue;
            }

            // Cas 3: array pla
            if (is_array($img)) {
                $out[] = [
                    'file_path' => (string)($img['file_path'] ?? ''),
                    'label'     => (string)($img['label'] ?? ''),
                    'disabled'  => !empty($img['disabled']),
                    'position'  => (int)($img['position'] ?? 0),
                    'roles'     => isset($img['roles']) && is_array($img['roles']) ? array_values($img['roles']) : [],
                ];
                continue;
            }

            // Format inesperat → ignora amb log
            $this->logger->warning('[NacentoConnector][BulkPlanner] Imatge amb format inesperat ('.gettype($img).'). Ometo.');
        }

        return $out;
    }

    /**
     * Crea un ItemStatus simple.
     */
    private function makeStatus(int $id, string $sku, string $status, ?string $msg = null): ItemStatusInterface
    {
        $st = $this->itemStatusFactory->create();
        $st->setId($id);
        // hash estable per SKU (canvia-ho si vols incloure més camps)
        $st->setDataHash(md5($sku !== '' ? $sku : ('#'.$id)));
        $st->setStatus($status);
        if ($msg) {
            $st->setErrorMessage($msg);
        }
        return $st;
    }

    /**
     * Genera un UUID v4 RFC-4122 (format 8-4-4-4-12).
     */
    private function uuidV4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); // v4
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
