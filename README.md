# Nacento Connector

*A custom Magento 2.4.8 module to sync product image galleries from Akeneo PIM into Magento using S3/R2 — skipping Magento’s default image processing to save bandwidth and speed up updates.*

> **ALPHA – HIGHLY EXPERIMENTAL**  
> This project is **alpha-stage** and **not suitable for production**. Use at your own risk.  
> If you have questions, please open an **Issue** – but **do not expect quick responses**.

---

## What is this?

A custom **Magento 2.4.8** module that exposes a Web API endpoint to **synchronize product image galleries** between **Akeneo PIM** and **Magento 2**, using **S3/R2** object storage as the source of truth.

The goal is to **avoid Magento’s built-in image processing and copying**, thereby **reducing bandwidth** and **speeding up gallery updates** per product.

**Internals (high level):** the module provides an API contract (`Api/CustomGalleryManagementInterface` + DTOs) and logic (`Model/GalleryProcessor`, `ResourceModel/Product/Gallery.php`) to upsert gallery entries directly, pointing to objects in **S3/R2**, and to assign roles/positions/labels without full media reprocessing.

---

## Status

- **Stage:** Alpha (breaking changes can happen anytime)
- **Target Magento:** 2.4.8 (PHP 8.1/8.2)
- **License:** MIT

---

## Features (alpha)

- REST Web API to push gallery updates from **Akeneo → Magento**  
- Uses **S3/R2 object URLs** (no binary uploads to Magento)  
- Assigns **roles** (base/small/thumbnail), **labels**, **position**, **disabled**  
- Skips Magento’s **image processing** to improve speed

> Note: Exact behavior depends on your storage layout, permissions, and CDN setup. Expect edge cases.

---

## Requirements

- Magento **2.4.8**  
- PHP **8.1** or **8.2**  
- Access to **S3/R2** bucket (public or signed URLs supported depending on your setup)  
- **Akeneo PIM** as the upstream source of product/media metadata

---

## Installation

### A) From Packagist (when published)

```bash
composer require nacento/connector:^1.0
bin/magento module:enable Nacento_Connector
bin/magento setup:upgrade
bin/magento cache:flush
```

### B) From GitHub (VCS)

Add to your Magento project's `composer.json` (repositories section):

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:xturavaina/nacento-connector.git" }
  ]
}
```

Then install and enable the module:

```bash
composer require nacento/connector:dev-main
bin/magento module:enable Nacento_Connector
bin/magento setup:upgrade
```

> **Important:** Remove any copy under `app/code/Nacento/Connector` **before** installing via Composer to avoid conflicts.

---

## Configuration (example)

Centralize storage details via env/config:

- **S3/R2 bucket** name  
- **Base URL / CDN URL** for public access  
- **ACL / signed URL** strategy if private  
- Optional: default image **roles/labels** behavior

> Exact config keys are **TBD in alpha**; inspect `etc/di.xml` and your project's env vars.  
> This module assumes your URLs are already reachable by storefront/admin.

---

## API

### Endpoint

A REST endpoint is exposed via `webapi.xml`. The **exact route** depends on your current mapping and interface. A typical pattern might look like:

```
POST /rest/V1/nacento/connector/gallery/sync
```

> Check `etc/webapi.xml` for the definitive path and service name.

### Sample Payload

```json
{
  "sku": "MY-SKU-123",
  "images": [
    {
      "url": "https://cdn.example.com/bucket/path/to/image1.jpg",
      "label": "Front",
      "position": 1,
      "disabled": false,
      "roles": ["base", "small", "thumbnail"]
    },
    {
      "url": "https://cdn.example.com/bucket/path/to/image2.jpg",
      "label": "Angle",
      "position": 2,
      "disabled": false,
      "roles": []
    }
  ],
  "replace": true
}
```

- `replace: true` → “replace the gallery with this set” (implementation detail may change in alpha).  
- `roles` may include: `base`, `small`, `thumbnail`, `swatch` (depending on theme/needs).

### Example Response

```json
{
  "sku": "MY-SKU-123",
  "updated": 2,
  "skipped": 0,
  "errors": []
}
```

---

## Caveats & Limitations (alpha)

- Assumes **S3/R2 URLs are directly consumable** by your frontend theme (CORS/CDN/headers are your responsibility).  
- Some Magento features or 3rd-party modules may **expect images in `pub/media`**; validate compatibility.  
- Roles/attributes may vary by theme or customizations.  
- Error handling & retries are **minimal** in alpha.

---

## Roadmap (subject to change)

- [ ] Config model for S3/R2 (bucket, base URL, signed URL policy)  
- [ ] Safer upserts & transactional behavior  
- [ ] Better validation and detailed error reporting  
- [ ] CLI command for batch sync/testing  
- [ ] Unit/integration tests & Magento Coding Standard  
- [ ] Akeneo OAuth client helpers (optional package)  
- [ ] Optional fallback to local media for admin previews

---

## Contributing / Issues

- **Issues welcome**, but responses may be **slow** during alpha.  
- PRs are appreciated – keep them small, focused, and include a clear description.

---

## Security

If you discover a security issue, **do not** open a public issue. Please contact the maintainer privately.

---

## License

**MIT © Nacento**
