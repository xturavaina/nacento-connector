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

class GalleryProcessor implements CustomGalleryManagementInterface
{
    private $productRepository;
    private $filesystem;
    private $logger;
    private $galleryResourceModel;
    private $productAttributeRepository;
    private $productAction;
    private $mediaConfig;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        Filesystem $filesystem,
        LoggerInterface $logger,
        CustomGalleryResourceModel $galleryResourceModel,
        ProductAttributeRepositoryInterface $productAttributeRepository,
        ProductAction $productAction,
        MediaConfig $mediaConfig,
    ) {
        $this->productRepository = $productRepository;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->galleryResourceModel = $galleryResourceModel;
        $this->productAttributeRepository = $productAttributeRepository;
        $this->productAction = $productAction;
        $this->mediaConfig = $mediaConfig;
    }

    /**
     * @inheritdoc
     */
    public function create(string $sku, array $images): bool
    {
        $this->logger->info(sprintf('[NacentoConnector] Iniciant procés massiu (Via Directa BD) per a SKU: %s. %d imatges rebudes.', $sku, count($images)));

        if (empty($images)) {
            $this->logger->warning('[NacentoConnector] L\'array d\'imatges està buit. No es fa res.');
            return true;
        }

        try {
            // --- PAS 1: VERIFICACIONS INICIALS (fora del bucle per eficiència) ---
            $product = $this->productRepository->get($sku);
            $galleryAttribute = $this->productAttributeRepository->get('media_gallery');
            $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $rolesToUpdate = [];

            // *** OBTENIM EL DRIVER
            $mediaDirectoryWriter = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $mediaDriver = $mediaDirectoryWriter->getDriver();


            // --- PAS 2: BUCLE FOREACH PER A PROCESSAR CADA IMATGE ---
            foreach ($images as $imageEntry) {
                // Obtenim i netegem les dades de l'objecte d'entrada
                $filePath = ltrim($imageEntry->getFilePath() ?? '', '/\\');
                $label = $imageEntry->getLabel() ?? '';
                $disabled = $imageEntry->isDisabled();
                $position = $imageEntry->getPosition();
                $roles = $imageEntry->getRoles() ?? [];

                $this->logger->info(sprintf('[NacentoConnector] Processant imatge: %s', $filePath));

                // 2a. Validacions de dades mínimes
                if (empty($filePath) || empty($label)) {
                     $this->logger->error('[NacentoConnector] Ometent imatge per filePath o label buits.');
                    continue;
                }

                // 2b. Verificació d'existència del fitxer
                $fullPathForValidation = $this->mediaConfig->getMediaPath($filePath);

                if (!$mediaDirectory->isExist($fullPathForValidation)) {
                    $this->logger->error(sprintf('[NacentoConnector] Ometent imatge. El fitxer no existeix a: %s', $fullPathForValidation));
                    continue;
                }

                $this->logger->info(sprintf('[NacentoConnector] ÈXIT: El fitxer %s s\'ha trobat correctament, obtenint metadades i etag', $filePath));

                /** @var \Magento\AwsS3\Driver\AwsS3 $mediaDriver */
                $metadata = $mediaDriver->getMetadata($fullPathForValidation);
                $currentEtag = $metadata['etag'] ?? null;


                // 2c. Decidim si és INSERT o UPDATE
                $existingImage = $this->galleryResourceModel->getExistingImage(
                    (int)$product->getId(),
                    (int)$galleryAttribute->getAttributeId(),
                    $filePath
                );

                // comprovem si l'etag es null ?
                $savedEtag = $existingImage['s3_etag'] ?? null;

                // Preparem les dades de valor que són comunes per a INSERT i UPDATE
                $valueData = [
                    'entity_id' => (int)$product->getId(),
                    'label' => $label,
                    'position' => $position,
                    'disabled' => (int)$disabled,
                    'store_id' => 0,
                    's3_etag' => $currentEtag
                ];

                if ($existingImage && isset($existingImage['record_id'])) {

                    // --- CAS A: L'IMATGE EXISTEIX -> ACTUALITZEM ---
                    $this->logger->info(sprintf('[NacentoConnector] La imatge %s ja existeix. Actualitzant record_id: %d', $filePath, $existingImage['record_id']));
                    
                    if ($currentEtag !== $savedEtag) {
                        $this->logger->info(sprintf('[NacentoConnector] Canvi de contingut detectat per a %s (ETag ha canviat de %s a %s)', $filePath, $savedEtag, $currentEtag));
                        // LÒGICA DE CACHÉ D'IMATGES ??
                    }
                    
                    $this->galleryResourceModel->updateValueRecord((int)$existingImage['record_id'], $valueData);
                } else {

                    // --- CAS B: L'IMATGE NO EXISTEIX -> INSERIM ---
                    $this->logger->info(sprintf('[NacentoConnector] La imatge %s és nova. Inserint a la BD.', $filePath));


                    // Si l'entrada principal ja existeix però no té valors, reutilitzem l'ID
                    $valueIdToUse = $existingImage['value_id'] ?? null;
                    if (!$valueIdToUse) {
                        $newImageData = [ 'attribute_id' => (int)$galleryAttribute->getAttributeId(), 'media_type' => 'image', 'value' => $filePath ];
                        $valueIdToUse = (int)$this->galleryResourceModel->insertNewRecord($newImageData);
                        $this->galleryResourceModel->createLink($valueIdToUse, (int)$product->getId());
                    }
                    
                    $valueData['value_id'] = $valueIdToUse;
                    $this->galleryResourceModel->insertValueRecord($valueData);
                    $this->logger->info(sprintf('[NacentoConnector] Imatge registrada a la BD amb value_id: %d', $valueIdToUse));
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
            
            // --- PAS 4: NETEJA DE CACHÉ ---
            // em causa problemes i ProductAction ja invalida caches.
            // un try-catch simplement evita un break del fluxe.

            $this->logger->info('[NacentoConnector] ★★★ PROCÉS MASSIU (Directe a BD + Action) COMPLETAT AMB ÈXIT ★★★');

        } catch (\Exception $e) {
            $this->logger->critical('[NacentoConnector] Excepció crítica durant el procés massiu: ' . $e->getMessage(), ['exception' => $e]);
            throw new CouldNotSaveException(__("Error crític durant el procés massiu. Revisa els logs."), $e);
        }

        return true;
    }
}