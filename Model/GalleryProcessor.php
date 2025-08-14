<?php
/**
 * Copyright © Nacento
 */
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Nacento\Connector\Api\CustomGalleryManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Nacento\Connector\Model\ResourceModel\Product\Gallery as CustomGalleryResourceModel;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Nacento\Connector\Model\S3HeadClient;

class GalleryProcessor implements CustomGalleryManagementInterface
{
    private $productRepository;
    private $filesystem;
    private $logger;
    private $galleryResourceModel;
    private $productAttributeRepository;
    private $productAction;
    private $mediaConfig;
    private S3HeadClient $s3Head;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        Filesystem $filesystem,
        LoggerInterface $logger,
        CustomGalleryResourceModel $galleryResourceModel,
        ProductAttributeRepositoryInterface $productAttributeRepository,
        ProductAction $productAction,
        MediaConfig $mediaConfig,
        S3HeadClient $s3Head
    ) {
        $this->productRepository = $productRepository;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->galleryResourceModel = $galleryResourceModel;
        $this->productAttributeRepository = $productAttributeRepository;
        $this->productAction = $productAction;
        $this->mediaConfig = $mediaConfig;
        $this->s3Head = $s3Head;
    }

    /**
     * @inheritdoc
     */
    /**
     * Crea/actualitza entrades de galeria a partir de rutes de fitxer EXISTENTS dins /media,
     * i desa l'ETag a una taula pròpia de metadades.
     */
    public function create(string $sku, array $images): bool
    {
        $this->logger->info(sprintf(
            '[NacentoConnector] Iniciant procés massiu (Via Directa BD) per a SKU: %s. %d imatges rebudes.',
            $sku,
            count($images)
        ));

        if (empty($images)) {
            $this->logger->warning('[NacentoConnector] L\'array d\'imatges està buit. No es fa res.');
            return true;
        }

        try {
            // --- PAS 1: VERIFICACIONS INICIALS (fora del bucle per eficiència) ---
            $product          = $this->productRepository->get($sku);
            $galleryAttribute = $this->productAttributeRepository->get('media_gallery');
            $mediaDirectory   = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $rolesToUpdate    = [];

            // *** OBTENIM EL DRIVER
            $mediaDirectoryWriter = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            /** @var \Magento\Framework\Filesystem\DriverInterface|\Magento\AwsS3\Driver\AwsS3 $mediaDriver */
            $mediaDriver = $mediaDirectoryWriter->getDriver();

            // --- PAS 2: FOREACH PER A PROCESSAR CADA IMATGE ---
            foreach ($images as $imageEntry) {
                // Obtenim i netegem les dades de l'objecte d'entrada
                $filePath = ltrim($imageEntry->getFilePath() ?? '', '/\\');
                $label    = $imageEntry->getLabel() ?? '';
                $disabled = $imageEntry->isDisabled();
                $position = $imageEntry->getPosition();
                $roles    = $imageEntry->getRoles() ?? [];

                $this->logger->info(sprintf('[NacentoConnector] Processant imatge: %s', $filePath));

                // 2a. Validacions de dades mínimes
                if (empty($filePath) || empty($label)) {
                    $this->logger->error('[NacentoConnector] Ometent imatge per filePath o label buits.');
                    continue;
                }

                // 2b. Verificació d'existència del fitxer a /media
                $fullPathForValidation = $this->mediaConfig->getMediaPath($filePath);
                if (!$mediaDirectory->isExist($fullPathForValidation)) {
                    $this->logger->error(sprintf(
                        '[NacentoConnector] Ometent imatge. El fitxer no existeix a: %s',
                        $fullPathForValidation
                    ));
                    continue;
                }
                $this->logger->info(sprintf(
                    '[NacentoConnector] ÈXIT: El fitxer %s s\'ha trobat correctament, obtenint obtenint ETag (HEAD S3/R2)',
                    $filePath
                ));


                // --- utilitat local per normalitzar ETag (treure cometes) ---
                $norm = static function ($e) {
                    return $e !== null ? trim((string)$e, '"') : null;
                };

                // Obtenir l’ETag directament via HEAD d’R2/S3 (1 sola crida)

                $currentEtagNorm = null;

                if ($mediaDriver instanceof \Magento\AwsS3\Driver\AwsS3) {
                    // Media path relatiu (ex: "catalog/product/a/b/img.jpg")
                    $relative = $this->mediaConfig->getMediaPath($filePath);

                    // Una sola crida HEAD a R2/S3
                    $etag = $this->s3Head->getEtag($relative);
                    $currentEtagNorm = $etag ? $norm($etag) : null;
                }

                // 2c. Decidim si és INSERT o UPDATE (mirem si ja existeix la fila a la galeria)
                $existingImage = $this->galleryResourceModel->getExistingImage(
                    (int)$product->getId(),
                    (int)$galleryAttribute->getAttributeId(),
                    $filePath
                );


                // L’ETag guardat si ja existia
                $savedEtagNorm = isset($existingImage['s3_etag']) ? $norm($existingImage['s3_etag']) : null;

                // Dades de la TAULA CORE que són comunes per a INSERT i UPDATE (NO inclouen s3_etag)
                // hardcodejo store_id de moment
                $valueData = [
                    'entity_id' => (int)$product->getId(),
                    'label'     => $label,
                    'position'  => $position,
                    'disabled'  => (int)$disabled,
                    'store_id'  => 0,
                ];

                if ($existingImage && isset($existingImage['record_id'])) {
                    // --- CAS A: L'IMATGE EXISTEIX -> ACTUALITZEM (core) + desar ETag (meta)
                    $recordId = (int)$existingImage['record_id'];
                    $this->logger->info(sprintf(
                        '[NacentoConnector] La imatge %s ja existeix. Actualitzant record_id: %d',
                        $filePath,
                        $recordId
                    ));

                    if ($currentEtagNorm !== $savedEtagNorm) {
                        $this->logger->info(sprintf(
                            '[NacentoConnector] Canvi de contingut detectat per a %s (ETag %s → %s)',
                            $filePath,
                            (string)$savedEtagNorm,
                            (string)$currentEtagNorm
                        ));
                    }

                    // UPDATE a la taula core (label/position/disabled/store_id)
                    $this->galleryResourceModel->updateValueRecord($recordId, $valueData);

                    // UPSERT de l'ETag a la taula pròpia de metadades
                    $this->galleryResourceModel->saveMetaRecord($recordId, $currentEtagNorm);

                } else {
                    // --- CAS B: L'IMATGE NO EXISTEIX -> INSERIM (core) + desar ETag (meta)
                    $this->logger->info(sprintf(
                        '[NacentoConnector] La imatge %s és nova. Inserint a la BD.',
                        $filePath
                    ));

                    // Si l'entrada principal (main_table) no existeix, la creem i enllacem
                    $valueIdToUse = $existingImage['value_id'] ?? null;
                    if (!$valueIdToUse) {
                        $newImageData = [
                            'attribute_id' => (int)$galleryAttribute->getAttributeId(),
                            'media_type'   => 'image',
                            'value'        => $filePath
                        ];
                        $valueIdToUse = (int)$this->galleryResourceModel->insertNewRecord($newImageData);
                        $this->galleryResourceModel->createLink($valueIdToUse, (int)$product->getId());
                    }

                    // Inserim la fila de "value" (store_id 0). IMPORTANT: el mètode ha de retornar el record_id.
                    $valueData['value_id'] = $valueIdToUse;
                    $recordId = (int)$this->galleryResourceModel->insertValueRecord($valueData);

                    // UPSERT de l'ETag a la taula pròpia de metadades
                    $this->galleryResourceModel->saveMetaRecord($recordId, $currentEtagNorm);

                    $this->logger->info(sprintf(
                        '[NacentoConnector] Imatge registrada a la BD amb value_id: %d i record_id: %d',
                        $valueIdToUse,
                        $recordId
                    ));
                }

                // 2d. Acumulem els rols per a actualitzar-los al final
                foreach ($roles as $role) {
                    if (!empty($role)) {
                        $rolesToUpdate[$role] = $filePath;
                    }
                }
            }

            // --- PAS 3: GESTIÓ DE ROLS (Una única crida al final) ---
            if (!empty($rolesToUpdate)) {
                $this->logger->info('[NacentoConnector] Assignant/actualitzant tots els rols amb una única crida a ProductAction...');
                $this->productAction->updateAttributes([(int)$product->getId()], $rolesToUpdate, 0);
            }

            // --- PAS 4: NETEJA DE CACHE ---
            // em causa problemes i ProductAction ja invalida caches.
            // un try-catch simplement evita un break del fluxe.
            $this->logger->info('[NacentoConnector] ★★★ PROCÉS MASSIU (Directe a BD + Action) COMPLETAT AMB ÈXIT ★★★');

        } catch (\Exception $e) {
            $this->logger->critical(
                '[NacentoConnector] Excepció crítica durant el procés massiu: ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw new CouldNotSaveException(__("Error crític durant el procés massiu. Revisa els logs."), $e);
        }

        return true;
    }
}