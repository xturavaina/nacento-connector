<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Storage;

class KeyResolver
{
    /**
     * Converteix una entrada arbitrària (URL, s3://, amb/sense media/…)
     * a la "cua" sota catalog/product (sense prefixar-la), p.ex.:
     *   "0/0/0/0/0000ea..._732451_4.jpg"
     *
     * Admet entrades com:
     *  - "pub/media/catalog/product/0/0/0/0/....jpg"
     *  - "media/catalog/product/0/0/0/0/....jpg"
     *  - "catalog/product/0/0/0/0/....jpg"
     *  - "0/0/0/0/....jpg"
     *  - URLs http(s) o s3://...
     */
    public function toLmp(string $input): string
    {
        $val = trim($input);
        // Treu esquema i host si ve en URL
        $val = preg_replace('#^https?://[^/]+/#i', '', $val);
        $val = preg_replace('#^s3://[^/]+/#i', '', $val);
        $val = ltrim((string)$val, '/');

        // Normalitza si ve amb pub/media o media
        if (str_starts_with($val, 'pub/media/')) {
            $val = substr($val, 10); // len('pub/media/') = 10
        } elseif (str_starts_with($val, 'media/')) {
            $val = substr($val, 6);  // len('media/') = 6
        }

        // Si encara porta "catalog/product/", el traiem per quedar-nos només la cua
        if (str_starts_with($val, 'catalog/product/')) {
            $val = substr($val, strlen('catalog/product/'));
        }

        // Compacta múltiples "/" i neteja leading "/"
        $val = preg_replace('#/+#', '/', (string)$val);
        $val = ltrim((string)$val, '/');

        return $val; // <-- retorna només la cua
    }

    /**
     * Construeix la clau d’S3 a partir de la "cua":
     * sempre "media/catalog/product/<tail>".
     */
    public function lmpToObjectKey(string $lmp): string
    {
        $tail = ltrim($lmp, '/'); // assegura que no hi hagi slash inicial
        return 'media/catalog/product/' . $tail;
    }
}
