# Nacento Connector

*A Magento 2.4.8 module to synchronize product image galleries from a PIM, using S3/R2 as the source of truth and optimizing performance through ETag change detection and bulk processing (synchronous & asynchronous).*

> **ALPHA – HIGHLY EXPERIMENTAL**  
> This project is **alpha-stage** and **not suitable for production**. Use at your own risk.  
> If you have questions, please open an **Issue** – but **do not expect quick responses**.

---

## What is this?

This is a custom Magento 2.4.8 module that exposes a Web API to **synchronize product image galleries** between a PIM (like Akeneo) and Magento, using an S3-compatible object storage (like Cloudflare R2) as the source of truth for the image files.

The primary goal is to **bypass Magento's native image processing and copying**, drastically reducing bandwidth, processing time, and accelerating gallery updates, especially for large catalogs.

## Evolution and Key Concepts

This module has evolved from a simple single-SKU endpoint into a more comprehensive bulk processing system focused on performance and flexibility.

1.  **Bulk Processing:** In addition to the original single-product endpoint, the module now offers bulk endpoints to process hundreds or thousands of SKUs in a single request.
2.  **Synchronous vs. Asynchronous:** You can choose between synchronous processing (the response waits for everything to complete) or asynchronous processing (the request is queued via Magento's Message Queue for background processing), which is ideal for very large workloads.
3.  **ETag Change Detection:** The module uses a lightweight S3 client to perform `HEAD` requests and retrieve the **ETag** of each image. This allows it to detect if a file's content has actually changed, avoiding unnecessary database writes and only updating metadata if the file itself is unchanged.

---

## Status

- **Stage:** Alpha (breaking changes can happen anytime)
- **Target Magento:** 2.4.8 (PHP 8.1/8.2)
- **License:** MIT

---

## Features

- **Three REST Web API Endpoints:**
    - One for **single SKU** updates.
    - One for **synchronous bulk** processing.
    - One for **asynchronous bulk** processing via Magento's Message Queue.
- **Performance Optimization:**
    - Skips Magento’s image processing for improved speed.
    - Uses a dedicated **S3/R2 client** for `HEAD` requests to check **ETags**, only updating data when necessary.
- **Complete Gallery Management:**
    - Assigns **roles** (`base`, `small_image`, `thumbnail`), **labels**, **position**, and **disabled** status.
- **External Source of Truth:**
    - Works directly with file paths in S3/R2 storage, with no binary uploads to Magento.

---

## Prerequisite: Configure Remote Storage (S3/R2/MinIO)

For this module to work as intended, **Magento must be configured to use an S3-compatible remote storage driver** in `app/etc/env.php`. Example (S3/MinIO/R2 compatible):

```php
'remote_storage' => [
    'driver' => 'aws-s3',
    'config' => [
        'bucket' => 'your-catalog-bucket',
        'region' => 'auto',
        'endpoint' => 'https://<account-id>.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => true,
        'bucket_endpoint' => false,
        'credentials' => [
            'key' => 'your-access-key',
            'secret' => 'your-secret-key'
        ]
    ]
],
```

---

## Installation

Add the repository to your Magento project's `composer.json`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:xturavaina/nacento-connector.git" }
  ]
}
```

Then, install and enable the module:

```bash
composer require nacento/connector:dev-main
bin/magento module:enable Nacento_Connector
bin/magento setup:upgrade
bin/magento cache:flush
```

## Uninstallation

You can uninstall the module in two ways: removing only the code (leaving the database table intact) or performing a complete removal that also cleans up the database.

### Option 1: Remove Code Only (Standard Method)

This is the standard Magento process. It will remove the module's code, but the `nacento_media_gallery_meta` database table will be preserved in case you decide to reinstall the module later or you forgot to make a backup.

```bash
composer remove nacento/connector
bin/magento setup:upgrade
```

---

### Option 2: Complete Removal (Code + Database Table + RabbitMQ queue and Exchange)

> **Warning:** This process is irreversible and will permanently delete the `nacento_media_gallery_meta` table and all its data.

This module includes an uninstall script that cleans up its database schema. To trigger it, use Magento's `module:uninstall` command with the `--remove-data` flag. This command will:
1.  Execute the uninstall script to drop the database table.
2.  Remove the module's code.
3.  Check if the nacento.gallery.process queue and exchange are empty.
4.  If empty queue is confirmed, nacento.gallery.process and the exchange will be deleted.
5.  If one or more messages are found, the script will abort the process and manual cleanup should be executed. 
6.  Finally, update the `composer.json` and `composer.lock` files.

```bash
bin/magento module:uninstall Nacento_Connector --remove-data
```

---

### Cleaning Up RabbitMQ (Manual Step)

If queue was not deleted you can remove queues or exchanges from your RabbitMQ server. This must be done manually to ensure a completely clean environment.

Follow these steps after uninstalling the module:
```
1.  Log in to the RabbitMQ Management UI (typically at `http://your-server:15672`).
2.  Navigate to the **Queues** tab.
3.  Find and click on the queue named `nacento.gallery.process`.
4.  Scroll to the bottom of the page and click the **Delete** button.
5.  Navigate to the **Exchanges** tab.
6.  Find and click on the exchange named `nacento.gallery.process`.
7.  Scroll to the bottom of the page and click the **Delete** button.
```

---
## API Endpoints

The module exposes three distinct endpoints. Please check `etc/webapi.xml` for the definitive definitions.

### 1. Single SKU Update (Synchronous)

Ideal for one-off updates or testing.

- **Endpoint:** `POST /rest/V1/nacento-connector/products/:sku/media`
- **Sample Payload:**

```json
{
  "images": [
    {
      "file_path": "catalog/product/m/y/my-image-1.jpg",
      "label": "Front View",
      "position": 1,
      "disabled": false,
      "roles": ["base", "small_image", "thumbnail"]
    }
  ]
}
```

### 2. Bulk Processing (Synchronous)

Processes a batch of SKUs and returns the full result in the response. Suitable for small to medium-sized batches.

- **Endpoint:** `POST /rest/V1/nacento-connector/products/media/bulk`
- **Sample Payload:**

```json
{
  "request": {
    "request_id": "op-12345",
    "items": [
      {
        "sku": "SKU-001",
        "images": [{ "file_path": "...", "label": "...", "roles": ["base"] }]
      },
      {
        "sku": "SKU-002",
        "images": [{ "file_path": "...", "label": "...", "roles": ["base"] }]
      }
    ]
  }
}
```
- **Sample Response:**
```json
{
    "request_id": "op-12345",
    "stats": { "skus_seen": 2, "ok": 2, "error": 0, "inserted": 0, "updated_value": 0, "updated_meta": 0, "skipped_no_change": 0 },
    "results": [
        { "sku": "SKU-001", "product_id": 10, "image_stats": {"inserted": 0, "updated_value": 0, "updated_meta": 0, "skipped_no_change": 0, "warnings": []}, "error": null },
        { "sku": "SKU-002", "product_id": 11, "image_stats": {"inserted": 0, "updated_value": 0, "updated_meta": 0, "skipped_no_change": 0, "warnings": []}, "error": null }
    ]
}
```

### 3. Bulk Processing (Asynchronous)

Submits a batch to Magento's message queue for background processing. The response is immediate and contains a `bulk_uuid` for tracking. This is the best option for large batches.

- **Endpoint:** `POST /rest/V1/nacento-connector/products/media/bulk/async`
- **Payload:** Same as the synchronous bulk endpoint.
- **Sample Response:**
```json
{
    "bulk_uuid": "f8d3c1a3-5b8e-4a9f-8c7e-1a2b3c4d5e6f",
    "request_items": [
        { "id": 1, "data_hash": "...", "status": "accepted", "error_message": null },
        { "id": 2, "data_hash": "...", "status": "accepted", "error_message": null }
    ],
    "errors": false
}
```

---

## Caveats & Limitations

- Assumes **S3/R2 URLs are directly consumable** by your frontend (CORS, CDN, permissions are your responsibility).
- Some Magento features or 3rd-party modules may **expect images to exist physically in `pub/media`**. Validate compatibility.
- Error handling and retries are **minimal** in the alpha stage.

---

## Roadmap (subject to change)

- [ ] Enhance the statistics returned in bulk processing results.
- [ ] Add a CLI command for batch synchronization and testing.
- [ ] Implement unit and integration tests.
- [ ] Optional: Fallback to local media for admin previews.

---

## License

**MIT © Nacento**
```