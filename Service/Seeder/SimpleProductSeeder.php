<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service\Seeder;

use Magento\Catalog\Api\Data\ProductTierPriceInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class SimpleProductSeeder
{
    public function __construct(
        private ProductFactory $productFactory,
        private ProductRepositoryInterface $productRepository,
        private ProductTierPriceInterfaceFactory $tierPriceFactory,
        private StockRegistryInterface $stockRegistry
    ) {
    }

    public function seedProducts(array $categories, callable $log, int $targetCount = 50): int
    {
        $log(sprintf('Generando catálogo rico de productos (Meta: %d SKUs)...', $targetCount));

        $catalogDefinitions = [
            // Men's Hoodies & Jackets
            [
                'sku' => 'MC-HD-BLK-01',
                'name' => 'Apex Hybrid Performance Hoodie Black',
                'cat' => 'Men > Hoodies & Sweatshirts',
                'price' => 95.00,
                'b2b_price' => 58.00,
                'retailer_price' => 78.00,
                'special_price' => null,
                'stock' => 140,
                'img' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Sudadera técnica de alto rendimiento con acabado repelente al agua y aislamiento térmico micro-fleece. Ideal para entrenamiento y uso urbano diario.</p>'
            ],
            [
                'sku' => 'MC-HD-NVY-02',
                'name' => 'Apex Hybrid Performance Hoodie Navy',
                'cat' => 'Men > Hoodies & Sweatshirts',
                'price' => 95.00,
                'b2b_price' => 58.00,
                'retailer_price' => 78.00,
                'special_price' => 84.99,
                'stock' => 85,
                'img' => 'https://images.unsplash.com/photo-1578587018452-892bacefd3f2?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Edición azul marino de nuestra sudadera insignia. Tejido transpirable de cuatro vías con capucha ergonómica y bolsillo para smartphone.</p>'
            ],
            [
                'sku' => 'MC-JK-STORM-01',
                'name' => 'StormShield 3L Waterproof Technical Jacket',
                'cat' => 'Men > Jackets & Outerwear',
                'price' => 280.00,
                'b2b_price' => 175.00,
                'retailer_price' => 230.00,
                'special_price' => null,
                'stock' => 60,
                'img' => 'https://images.unsplash.com/photo-1548883354-7622d03aca27?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Chaqueta técnica de 3 capas con membrana impermeable y transpirable 20k/20k. Costuras totalmente termoselladas y cremalleras YKK AquaGuard.</p>'
            ],
            [
                'sku' => 'MC-TS-CORE-BLK',
                'name' => 'Core Seamless Performance Tee Black',
                'cat' => 'Men > Performance Tees',
                'price' => 45.00,
                'b2b_price' => 26.00,
                'retailer_price' => 37.00,
                'special_price' => null,
                'stock' => 220,
                'img' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Camiseta técnica sin costuras de secado ultra-rápido. Tratamiento anti-olor con iones de plata y zonas de ventilación de mapeo corporal.</p>'
            ],
            [
                'sku' => 'MC-SN-RUN-01',
                'name' => 'AeroGlide Carbon Marathon Sneakers',
                'cat' => 'Men > Sneakers & Footwear',
                'price' => 195.00,
                'b2b_price' => 120.00,
                'retailer_price' => 160.00,
                'special_price' => 175.00,
                'stock' => 95,
                'img' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Zapatillas de running para competición con placa de fibra de carbono y espuma supercrítica de retorno energético. Peso pluma de 190 gramos.</p>'
            ],
            // Women's Tops & Bottoms
            [
                'sku' => 'MC-W-TOP-01',
                'name' => 'ProFit Compression Crop Top Sand',
                'cat' => 'Women > Athletic Tops',
                'price' => 48.00,
                'b2b_price' => 28.00,
                'retailer_price' => 39.00,
                'special_price' => null,
                'stock' => 180,
                'img' => 'https://images.unsplash.com/photo-1518611012118-696072aa579a?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Top de compresión media con sujeción anatómica y banda elástica reforzada. Tejido de tacto suave con tecnología de absorción de sudor.</p>'
            ],
            [
                'sku' => 'MC-W-LEG-01',
                'name' => 'High-Waist Sculpt Flex Leggings Black',
                'cat' => 'Women > Leggings & Bottoms',
                'price' => 78.00,
                'b2b_price' => 46.00,
                'retailer_price' => 64.00,
                'special_price' => null,
                'stock' => 210,
                'img' => 'https://images.unsplash.com/photo-1506619216599-9d16d0903dfd?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Mallas de compresión gradual con cintura alta anti-deslizamiento y bolsillo oculto para llaves. Tejido 100% opaco a prueba de sentadillas.</p>'
            ],
            [
                'sku' => 'MC-W-PRK-01',
                'name' => 'Alpine Thermal Ultralight Parka Frost',
                'cat' => 'Women > Outerwear & Parkas',
                'price' => 310.00,
                'b2b_price' => 195.00,
                'retailer_price' => 255.00,
                'special_price' => null,
                'stock' => 45,
                'img' => 'https://images.unsplash.com/photo-1539533018447-63fcce667883?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Parka técnica con relleno de plumón sintético reciclado de 800 fill power. Acabado repelente al agua DWR libre de fluorocarbonos.</p>'
            ],
            [
                'sku' => 'MC-W-SHOE-01',
                'name' => 'CloudStride Cushion Running Shoes Coral',
                'cat' => 'Women > Running Shoes',
                'price' => 165.00,
                'b2b_price' => 102.00,
                'retailer_price' => 135.00,
                'special_price' => 149.99,
                'stock' => 115,
                'img' => 'https://images.unsplash.com/photo-1584735935682-2f2b69dff9d2?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Calzado de entrenamiento diario con máxima amortiguación para protección articular en tiradas largas de asfalto y pista.</p>'
            ],
            // Gear & Accessories
            [
                'sku' => 'MC-BP-TAC-01',
                'name' => 'Modular Tactical DryPack 32L Matte Black',
                'cat' => 'Gear & Accessories > Technical Backpacks',
                'price' => 145.00,
                'b2b_price' => 88.00,
                'retailer_price' => 119.00,
                'special_price' => null,
                'stock' => 90,
                'img' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Mochila impermeable construida en Cordura 500D con compartimento estanco acolchado para portátil de hasta 16" y sistema MOLLE cortado por láser.</p>'
            ],
            [
                'sku' => 'MC-WAT-PRO-01',
                'name' => 'Apex Chrono GPS Multi-Sport Smartwatch',
                'cat' => 'Gear & Accessories > Smart Watches',
                'price' => 340.00,
                'b2b_price' => 220.00,
                'retailer_price' => 280.00,
                'special_price' => null,
                'stock' => 70,
                'img' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Reloj deportivo con bisel de titanio, cristal de zafiro, mapas topográficos offline, sensor SpO2, altímetro barométrico y 21 días de batería.</p>'
            ],
            [
                'sku' => 'MC-CAP-TECH-01',
                'name' => 'Laser-Perforated Performance Cap Charcoal',
                'cat' => 'Gear & Accessories > Caps & Headwear',
                'price' => 35.00,
                'b2b_price' => 19.00,
                'retailer_price' => 28.00,
                'special_price' => null,
                'stock' => 250,
                'img' => 'https://images.unsplash.com/photo-1588850561407-ed78c282e89b?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Gorra técnica ultraligera de 5 paneles con perforaciones láser de ventilación, banda absorbente interior y cierre elástico regulable.</p>'
            ],
            [
                'sku' => 'MC-BTL-ISO-01',
                'name' => 'HydroVessel Insulated Thermal Flask 950ml',
                'cat' => 'Gear & Accessories > Water Bottles & Flasks',
                'price' => 38.00,
                'b2b_price' => 21.00,
                'retailer_price' => 31.00,
                'special_price' => null,
                'stock' => 300,
                'img' => 'https://images.unsplash.com/photo-1602143407151-7111542de6e8?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Botella térmica de acero inoxidable 18/8 de doble pared al vacío. Mantiene bebidas frías durante 24 horas o calientes durante 12 horas. Libre de BPA.</p>'
            ],
            // Electronics
            [
                'sku' => 'MC-EAR-PRO-01',
                'name' => 'PulseWave Pro ANC True Wireless Earbuds',
                'cat' => 'Electronics > Wireless Earbuds',
                'price' => 180.00,
                'b2b_price' => 110.00,
                'retailer_price' => 148.00,
                'special_price' => 159.99,
                'stock' => 130,
                'img' => 'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Auriculares inalámbricos con cancelación activa de ruido híbrida de 42dB, drivers de berilio de 10mm, resistencia al agua IPX7 y 32h de autonomía.</p>'
            ],
            [
                'sku' => 'MC-FIT-BAND-01',
                'name' => 'BioTrack X3 Advanced Health & Sleep Tracker',
                'cat' => 'Electronics > Fitness Trackers',
                'price' => 98.00,
                'b2b_price' => 59.00,
                'retailer_price' => 80.00,
                'special_price' => null,
                'stock' => 175,
                'img' => 'https://images.unsplash.com/photo-1575311373937-040b8e1fd5b6?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Pulsera biométrica con monitor continuo de variabilidad de frecuencia cardíaca (HRV), temperatura cutánea, fases del sueño y nivel de recuperación.</p>'
            ],
            [
                'sku' => 'MC-PWR-MAG-01',
                'name' => 'MagPower Graphene 20000mAh Power Bank 65W',
                'cat' => 'Electronics > Portable Power',
                'price' => 79.00,
                'b2b_price' => 45.00,
                'retailer_price' => 64.00,
                'special_price' => null,
                'stock' => 160,
                'img' => 'https://images.unsplash.com/photo-1609592426868-b788647565df?auto=format&fit=crop&w=800&q=80',
                'desc' => '<p>Batería externa de alta capacidad con salida Power Delivery USB-C de 65W, capaz de cargar laptops, tablets y smartphones a velocidad máxima.</p>'
            ]
        ];

        // Synthesize dynamic realistic items if targetCount > static list
        $synthesizedTemplates = [
            ['prefix' => 'Industrial High-Pressure Ball Valve', 'cat' => 'Gear & Accessories > Technical Backpacks', 'price' => 280.0, 'b2b' => 165.0, 'prefix_sku' => 'IND-VLV'],
            ['prefix' => 'Heavy Duty Hydraulic Fluid Pump', 'cat' => 'Gear & Accessories > Technical Backpacks', 'price' => 850.0, 'b2b' => 490.0, 'prefix_sku' => 'HYD-PMP'],
            ['prefix' => 'Variable Frequency 3-Phase AC Motor', 'cat' => 'Electronics > Portable Power', 'price' => 640.0, 'b2b' => 380.0, 'prefix_sku' => 'MTR-VFD'],
            ['prefix' => 'Multi-Gigabit PoE+ Managed Enterprise Switch', 'cat' => 'Electronics > Wireless Earbuds', 'price' => 420.0, 'b2b' => 260.0, 'prefix_sku' => 'NET-SWT'],
            ['prefix' => 'Online True Sine Wave 3000VA UPS Station', 'cat' => 'Electronics > Portable Power', 'price' => 1150.0, 'b2b' => 740.0, 'prefix_sku' => 'PWR-UPS'],
            ['prefix' => 'High-Visibility Breathable Shell Parka', 'cat' => 'Men > Jackets & Outerwear', 'price' => 180.0, 'b2b' => 110.0, 'prefix_sku' => 'APP-HVI'],
            ['prefix' => 'Steel-Toe Kevlar Puncture Resistant Work Boots', 'cat' => 'Men > Sneakers & Footwear', 'price' => 165.0, 'b2b' => 98.0, 'prefix_sku' => 'APP-BOT'],
            ['prefix' => 'Precision Digital Torque Multiplier Calibrator', 'cat' => 'Gear & Accessories > Smart Watches', 'price' => 720.0, 'b2b' => 440.0, 'prefix_sku' => 'TLS-TRQ']
        ];
        $brands = ['ApexIndustrial', 'VoltCore Systems', 'HydraFlow Pro', 'StormShield Enterprise', 'OptiMetric Global'];

        $staticCount = count($catalogDefinitions);
        if ($targetCount > $staticCount) {
            for ($i = $staticCount + 1; $i <= $targetCount; $i++) {
                $tpl = $synthesizedTemplates[$i % count($synthesizedTemplates)];
                $brand = $brands[$i % count($brands)];
                $num = 1000 + ($i % 9000);
                $sku = sprintf('%s-%d-%05d', $tpl['prefix_sku'], $num, $i);
                $name = sprintf('%s %s Series %d', $tpl['prefix'], $brand, $num);

                $catalogDefinitions[] = [
                    'sku' => $sku,
                    'name' => $name,
                    'cat' => $tpl['cat'],
                    'price' => $tpl['price'],
                    'b2b_price' => $tpl['b2b'],
                    'retailer_price' => round($tpl['price'] * 0.82, 2),
                    'special_price' => null,
                    'stock' => 120,
                    'img' => 'https://images.unsplash.com/photo-1581092335397-9583fe92d232?auto=format&fit=crop&w=800&q=80',
                    'desc' => "<p>{$name}. Diseñado para especificaciones industriales y comerciales de alta demanda. Cumple con normas ISO 9001.</p>"
                ];
            }
        }

        $count = 0;
        foreach ($catalogDefinitions as $data) {
            $catId = $categories[$data['cat']] ?? null;
            $catIds = $catId ? [$catId] : [];

            if ($this->createOrUpdateProduct($data, $catIds)) {
                $count++;
                if ($count <= 20 || $count % 500 === 0 || $count === count($catalogDefinitions)) {
                    $log(" ✓ [{$count} / " . count($catalogDefinitions) . "] Producto: {$data['sku']} - {$data['name']}");
                }
            }
        }

        return $count;
    }

    public function createOrUpdateProduct(array $data, array $categoryIds): bool
    {
        $sku = $data['sku'];

        try {
            $product = $this->productRepository->get($sku);
        } catch (\Throwable) {
            $product = $this->productFactory->create();
        }

        $product->setSku($sku);
        $product->setName($data['name']);
        $product->setTypeId(ProductType::TYPE_SIMPLE);
        $product->setAttributeSetId(4); // Default attribute set
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setVisibility(Visibility::VISIBILITY_BOTH);
        $product->setPrice($data['price']);
        $product->setWeight(1.0);
        $product->setTaxClassId(2); // Taxable Goods
        $product->setDescription($data['desc']);
        $product->setShortDescription(strip_tags($data['desc']));
        $product->setUrlKey(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $sku)));

        if (!empty($data['special_price'])) {
            $product->setSpecialPrice($data['special_price']);
        } else {
            $product->setSpecialPrice(null);
        }

        // Tier Prices for B2B Customer Groups
        $tierPrices = [];
        if (!empty($data['b2b_price'])) {
            $tpB2b = $this->tierPriceFactory->create();
            $tpB2b->setCustomerGroupId(2); // Wholesale
            $tpB2b->setQty(1);
            $tpB2b->setValue($data['b2b_price']);
            $tierPrices[] = $tpB2b;
        }

        if (!empty($data['retailer_price'])) {
            $tpRetailer = $this->tierPriceFactory->create();
            $tpRetailer->setCustomerGroupId(3); // Retailer VIP
            $tpRetailer->setQty(1);
            $tpRetailer->setValue($data['retailer_price']);
            $tierPrices[] = $tpRetailer;
        }

        if (!empty($tierPrices)) {
            $product->setTierPrices($tierPrices);
        }

        // Scope to default website
        $product->setWebsiteIds([1]);

        // Categories
        if (!empty($categoryIds)) {
            $product->setCategoryIds($categoryIds);
        }

        $savedProduct = $this->productRepository->save($product);

        // Update Stock Item
        $stockItem = $this->stockRegistry->getStockItem($savedProduct->getId());
        $stockItem->setQty($data['stock']);
        $stockItem->setIsInStock($data['stock'] > 0);
        $stockItem->setManageStock(1);
        $stockItem->setUseConfigManageStock(1);
        $this->stockRegistry->updateStockItemBySku($sku, $stockItem);

        return true;
    }
}
