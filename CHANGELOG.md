# Changelog

All notable changes to `magecopilot/module-edge-sync` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-11-09

### Added
- Dynamic customer group price mapping (`price_group_*`) in product schema and data mapper.
- High-precision timestamp tracking (`updated_at_ms`) for change-data-capture synchronization.
- Sales channel stock resolution in inventory reservation sync plugin via `StockResolverInterface`.
- Configurable product parent change tracking in `mview.xml` on `catalog_product_super_link`.

### Changed
- Standardized asynchronous queue consumer commands in documentation (`magecopilot_typesense_product_sync` and `magecopilot_typesense_stock_sync`).
- Cleaned default placeholder API key in system configuration.
- Enforced strict Composer semver constraints.

## [1.0.0] - 2026-10-31

### Added
- Initial enterprise release of MageCopilot EdgeSync.
- Asynchronous CDC pipeline with RabbitMQ and MySQL AMQP fallback.
- In-memory Typesense indexer for catalog products and MSI stock allocations.
- Admin surgical sync inspector and live schema verification tools.
- AES-256 encrypted configuration storage for Typesense API keys.
