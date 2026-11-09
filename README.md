# MageCopilot EdgeSync

[![License: OSL-3.0](https://img.shields.io/badge/License-OSL--3.0-blue.svg)](https://opensource.org/licenses/OSL-3.0)
[![Packagist](https://img.shields.io/packagist/v/magecopilot/module-edge-sync.svg)](https://packagist.org/packages/magecopilot/module-edge-sync)

Enterprise catalog synchronization and change data capture (CDC) engine for Magento 2, Adobe Commerce, and Mage-OS, designed to feed in-memory Typesense clusters with minimal database overhead.

## Overview

MageCopilot_EdgeSync continuously replicates catalog data from Magento into Typesense. Rather than running slow full reindexes or issuing locking table queries, EdgeSync uses Magento MView changelogs, targeted EAV plugins, and RabbitMQ message queues to capture mutations in real time.

All catalog documents are denormalized and structured for sub-millisecond facet navigation and instant search.

## Key Features

- Real-Time CDC: Captures product saves, stock reservations, deletions, and indirect EAV mutations through MView subscriptions and targeted service plugins.
- Complex Product Resolution: Full support for Simple, Configurable (with swatches and child variants), Bundle (with dynamic salable option calculations), and Grouped products.
- Multi-Website MSI Support: Resolves salable quantity and availability per website sales channel using Magento Inventory Sales APIs (`StockResolverInterface`).
- B2B & Tier Pricing: Indexes customer group specific prices, active catalog rules (`catalogrule_product_price`), and tiered quantity breaks.
- RabbitMQ Topology with DLQ: Uses AMQP message routing with an isolated Dead Letter Exchange (`magecopilot.dlx`) and Dead Letter Queue (`magecopilot_typesense_sync_dlq`) for unprocessable messages.
- Concurrency Protection: Every document tracks `updated_at_ms` timestamps to ensure older queue messages cannot overwrite newer catalog states.
- Clean Purging: Automatically deletes documents from Typesense when products are removed, disabled, or set to not visible individually.

## Architecture

```
Magento Admin / API / Cron
          |
          v
   MView & Plugins (CDC)
          |
          v
     RabbitMQ Exchange
          |
          +--> magecopilot_typesense_product_sync
          +--> magecopilot_typesense_stock_sync
                    |
                    v
          EdgeSync Consumer
                    |
                    v
           Typesense Cluster (RAM)
```

## Requirements

- PHP 8.1, 8.2, 8.3, 8.4, or 8.5
- Magento Open Source / Adobe Commerce 2.4.4+ or Mage-OS 1.0+
- RabbitMQ 3.9+
- Typesense 27.0+ or 30.2+

## Installation

Install via Composer:

```bash
composer require magecopilot/module-edge-sync
bin/magento module:enable MageCopilot_EdgeSync
bin/magento setup:upgrade
bin/magento setup:di:compile
```

## Configuration

In the Magento Admin Panel, go to:
**Stores > Configuration > MageCopilot > EdgeSync**

1. General Settings:
   - Enable EdgeSync: Set to Yes.
   - Typesense Host: The URL of your Typesense cluster (for example, `http://127.0.0.1:8108`).
   - Typesense API Key: Admin API key for Typesense (stored encrypted using Magento AES-256).
   - Collection Name: Target collection name (defaults to `products`).
   - Batch Size: Number of records per bulk import batch (default: `250`).

2. Vector Embedding (Optional):
   - Configure external providers (OpenAI or custom endpoints) if semantic search or hybrid search is needed.

## CLI Commands

Reindex the catalog into Typesense:

```bash
# Full reindex of all enabled and visible products
bin/magento indexer:reindex typesense_products

# Check indexer status
bin/magento indexer:status typesense_products
```

Start the queue consumer for background delta synchronization:

```bash
bin/magento queue:consumers:start magecopilot.edgesync.product
```

## Testing

Run unit tests via PHPUnit:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml app/code/MageCopilot/EdgeSync/Test/Unit
```

Run static analysis:

```bash
vendor/bin/phpstan analyse --level 1 app/code/MageCopilot/EdgeSync
vendor/bin/phpcs --standard=Magento2 app/code/MageCopilot/EdgeSync
```

## License

Open Software License v3.0 (OSL-3.0).
