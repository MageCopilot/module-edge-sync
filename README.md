# MageCopilot EdgeSync

[![License: OSL-3.0](https://img.shields.io/badge/License-OSL--3.0-blue.svg)](https://opensource.org/licenses/OSL-3.0)
[![Packagist](https://img.shields.io/packagist/v/magecopilot/module-edge-sync.svg)](https://packagist.org/packages/magecopilot/module-edge-sync)

Enterprise Headless Change Data Capture (CDC) pipeline and in-memory Typesense indexer for Adobe Commerce and Mage-OS.

## Overview

MageCopilot_EdgeSync synchronizes catalog state from Magento MySQL into an in-memory Typesense cluster in real time. It captures catalog mutations asynchronously without triggering table locks or blocking transactions.

## Architecture

* Real-Time Change Data Capture: Hooks into product entities via plugins.
* Asynchronous Queue: Dispatches delta payloads to RabbitMQ (`magecopilot.edgesync.product`).
* Dynamic Currency Resolver: Real-time exchange rate calculation via Directory Currency without static rates.
* Catalog Rule Engine: Evaluates promotional rules (`catalogrule_product_price`) and B2B customer group tier prices.
* Price Window Cron: Automated cron job checking active promotions (`special_from_date` / `special_to_date`).
* Typesense Ingest: Generates denormalized JSON documents indexed in Typesense C++ in memory.

## Requirements

* PHP 8.1, 8.2, 8.3, 8.4, 8.5
* Magento 2.4.4+ / Mage-OS 1.0+
* RabbitMQ 3.9+
* Typesense 30.2+

## Installation

```bash
composer require magecopilot/module-edge-sync
bin/magento module:enable MageCopilot_EdgeSync
bin/magento setup:upgrade
```

## Configuration

Navigate to Stores > Configuration > MageCopilot > EdgeSync to configure the Typesense cluster connection and queue workers.
