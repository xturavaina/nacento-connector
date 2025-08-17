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
- **Target Magento:** 2.4.8 (PHP 8.1/8.2/8.3)
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

## Compatibility (Tested)

This module has been **only tested** under the following environment:

- **Magento Open Source:** 2.4.8
- **PHP:** 8.3-fpm
- **Web Server:** nginx 1.24
- **Message Queue:** RabbitMQ 4.1
- **Cache:** Valkey 8.1
- **Database:** MariaDB 11.4
- **Search Engine:** OpenSearch 2.12

> ⚠️ Other versions of Magento, PHP, or related services **have not been tested**.  
> Use in different environments **at your own risk**, in fact, use it in general at your own risk.

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

## S3 Bucket Layout & Paths (Required)

Magento’s S3 storage driver treats the **bucket root as `pub/media`**.
That means product images must live under:

```text
<bucket>/media/catalog/product/<files>
```

This module will not fight against this stablished behaviour so if you would like to use it, you should adapt your S3 configuration so both Magento and your PIM access the same path.

Akeneo S3 adapter based on Symfony Framework can be configured by setting a **prefix**:

```yaml
oneup_flysystem:
  adapters:
    catalog_storage_adapter:
      awss3v3:
        client: 'Aws\S3\S3Client'
        bucket: 'catalog'                 # your bucket name
        prefix: 'media/catalog/product'   # required for Magento compatibility
```

With this, Akeneo will write to:

```text
<bucket>/media/catalog/product/<files>
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
composer require nacento/connector:*
bin/magento module:enable Nacento_Connector
bin/magento setup:upgrade
bin/magento cache:flush
```



> ⚠️ ***IMPORTANT***: For the bulk async operations to work you should setup a consumer on your cron, supervisord or whatever you use to process queues.

A potenial minimalist entry for supervisord will look like:


```yaml
[program:magento-consumer-nacento-gallery]
command=php bin/magento queue:consumers:start nacento.gallery.consumer 
directory=/var/www/html
autostart=true
autorestart=true
stdout_logfile=/var/log/magento-consumers/consumer_nacento_gallery.log
stderr_logfile=/var/log/magento-consumers/consumer_nacento_gallery.err
```

However, your environtment and settings may differ. Adapt accordingly.

## Uninstallation

You can uninstall the module in two ways: removing only the code (leaving the database table intact) or performing a complete removal that also cleans up the database, the queue and the exchange in RabbitMQ

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

If queue was not deleted, you can manually remove queues or exchanges from your RabbitMQ server. This must be done to ensure a completely clean environment.

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

## HealthCheck Diagnostics Configuration (Admin)

Go to **Stores → Configuration → Nacento → Nacento Connector**:

- **Message Queue → Topic name** (optional): if empty, defaults to `nacento.gallery.process`.
- **S3/R2 → Ping object key (optional)**: if set, the health check will `HEAD` this object to validate connectivity.

> The actual S3/R2 **remote storage driver** and credentials still live in `app/etc/env.php` (`remote_storage` section). This page only adds optional diagnostics/config.

---

## Health check / Doctor (CLI)

Run a full diagnostic (DB, remote storage config, MQ mapping, optional publish):

```bash
bin/magento nacento:connector:doctor
```
---

## Message Queue & Consumers

This module uses a topic named **`nacento.gallery.process`** (publisher) and a consumer named **`nacento.gallery.consumer`** (listens to queue `nacento.gallery.process`).

Common commands:

see all consumers
```bash
bin/magento queue:consumers:list
```

start the connector consumer

```bash
bin/magento queue:consumers:start nacento.gallery.process -vvv
```

Publishing does not require a running consumer; messages will queue up and be processed when the consumer runs.

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

- Code is ridiculously bad as I am, logic is pure improvitzation, no optimitzation nor process engineering has been done, except to fit my needs. Help on this is highly appreciated.
- Assumes **S3/R2 URLs are directly consumable** by your frontend (CORS, CDN, permissions are your responsibility).
- Some Magento features or 3rd-party modules may **expect images to exist physically in `pub/media`**. Validate compatibility.
- Error handling and retries are **minimal** in the alpha stage.

---

## Roadmap (subject to change)

- [ ] Enhance the the bussiness logic, as of today, the bulk sync/async is invoking the single sku processing logic, LOL!
- [ ] Enhance the statistics returned in bulk processing results. (maybe improve integration with magento default uuid)
- [ ] Implement unit and integration tests.

---

## Troubleshooting

- **“Data in topic must be of type OperationInterface”**  
  Your topic is typed (Async/Bulk). The module publishes a valid `OperationInterface`, so this should only happen if custom topology overrides were installed. Re-run `bin/magento setup:upgrade`.

- **No messages seen in RabbitMQ logs**  
  Magento validates message type & mapping **before** connecting to AMQP. Run the doctor and check `topic_mapping` and `mq_publish`.

- **Admin config page not visible**  
  Clear cache and re-login. Ensure `etc/adminhtml/system.xml` and `etc/acl.xml` are present (see repo), and the section appears under **Nacento → Nacento Connector**.


---
## License

**MIT © Nacento**