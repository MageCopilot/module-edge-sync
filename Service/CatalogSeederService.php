<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use MageCopilot\EdgeSync\Service\Seeder\AttributeSeeder;
use MageCopilot\EdgeSync\Service\Seeder\BundleSeeder;
use MageCopilot\EdgeSync\Service\Seeder\ConfigurableSeeder;
use MageCopilot\EdgeSync\Service\Seeder\RelationshipSeeder;
use MageCopilot\EdgeSync\Service\Seeder\SimpleProductSeeder;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CatalogSeederService
{
    public function __construct(
        private CategoryFactory $categoryFactory,
        private CategoryRepositoryInterface $categoryRepository,
        private CustomerInterfaceFactory $customerFactory,
        private CustomerRepositoryInterface $customerRepository,
        private StoreManagerInterface $storeManager,
        private State $appState,
        private LoggerInterface $logger,
        private AttributeSeeder $attributeSeeder,
        private SimpleProductSeeder $simpleProductSeeder,
        private ConfigurableSeeder $configurableSeeder,
        private BundleSeeder $bundleSeeder,
        private RelationshipSeeder $relationshipSeeder,
        private ?ResourceConnection $resourceConnection = null
    ) {
    }

    /**
     * Seed catalog data
     *
     * @param callable $logCallback
     * @param int $count
     * @return array{categories: int, products: int}
     */
    public function seed(callable $logCallback, int $count = 50): array
    {
        $this->logger->info(sprintf('[MageCopilot_EdgeSync] Starting catalog seed with target count %d', $count));
        try {
            $this->appState->setAreaCode('frontend');
        } catch (\Throwable) {
        }

        $categories = $this->seedCategories($logCallback);
        $this->seedCustomers($logCallback);
        $productsCreated = $this->simpleProductSeeder->seedProducts($categories, $logCallback, $count);
        $complexCreated = $this->seedConfigurablesAndBundles($categories, $logCallback);
        $this->seedReviews($logCallback);

        return [
            'categories' => count($categories),
            'products' => $productsCreated + $complexCreated
        ];
    }

    /**
     * @param array<string, int> $categories
     * @param callable $log
     * @return int
     */
    private function seedConfigurablesAndBundles(array $categories, callable $log): int
    {
        $log('Configuring super-attributes and seeding configurable & bundle products...');

        if ($this->resourceConnection === null) {
            $this->resourceConnection = \Magento\Framework\App\ObjectManager::getInstance()->get(
                ResourceConnection::class
            );
        }
        $conn = $this->resourceConnection->getConnection();

        // 1. Ensure attributes and options
        $attrs = $this->attributeSeeder->ensureConfigurableAttributes($conn, $log);
        $colorAttrId = (int) $attrs['color']['id'];
        $colorOptions = $attrs['color']['options'];
        $sizeAttrId = (int) $attrs['size']['id'];
        $sizeOptions = $attrs['size']['options'];

        $created = 0;

        // 2. Configurable 1: Apex Hybrid Performance Hoodie
        $created += $this->configurableSeeder->seedHoodieConfigurable(
            $categories,
            $colorAttrId,
            $colorOptions,
            $sizeAttrId,
            $sizeOptions,
            $conn,
            $log
        );

        // 3. Configurable 2: AeroGlide Running Sneakers
        $created += $this->configurableSeeder->seedSneakersConfigurable(
            $categories,
            $colorAttrId,
            $colorOptions,
            $sizeAttrId,
            $sizeOptions,
            $conn,
            $log
        );

        // 4. Bundle: Pro Athlete Performance Bundle
        $created += $this->bundleSeeder->seedAthleteBundle($categories, $conn, $log);

        // 5. Cross-sell and up-sell associations
        $this->relationshipSeeder->seedCrossSellLinks($conn, $log);

        return $created;
    }

    /**
     * @param callable $log
     * @return array<string, int>
     */
    private function seedCategories(callable $log): array
    {
        $log('Verifying and generating category tree structure...');
        $store = $this->storeManager->getStore();
        $rootId = (int) ($store instanceof \Magento\Store\Model\Store ? $store->getRootCategoryId() : 2);
        if ($rootId <= 0) {
            $rootId = 2;
        }

        $categoryTree = [
            'Men' => [
                'Hoodies & Sweatshirts',
                'Jackets & Outerwear',
                'Performance Tees',
                'Sneakers & Footwear'
            ],
            'Women' => [
                'Athletic Tops',
                'Leggings & Bottoms',
                'Outerwear & Parkas',
                'Running Shoes'
            ],
            'Gear & Accessories' => [
                'Technical Backpacks',
                'Smart Watches',
                'Caps & Headwear',
                'Water Bottles & Flasks'
            ],
            'Electronics' => [
                'Wireless Earbuds',
                'Fitness Trackers',
                'Portable Power'
            ]
        ];

        $categoryMap = [];

        foreach ($categoryTree as $parentName => $subNames) {
            $parentCat = $this->findOrCreateCategory($parentName, $rootId);
            $categoryMap[$parentName] = (int) $parentCat->getId();
            $log(" [OK] Primary category: {$parentName} (ID: {$parentCat->getId()})");

            foreach ($subNames as $subName) {
                $subCat = $this->findOrCreateCategory($subName, (int) $parentCat->getId());
                $categoryMap[$parentName . ' > ' . $subName] = (int) $subCat->getId();
            }
        }

        return $categoryMap;
    }

    private function findOrCreateCategory(string $name, int $parentId): Category
    {
        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $collection */
        $collection = $this->categoryFactory->create()->getCollection();
        $cat = $collection
            ->addAttributeToFilter('name', $name)
            ->addAttributeToFilter('parent_id', (string) $parentId)
            ->getFirstItem();

        if ($cat instanceof Category && $cat->getId()) {
            return $cat;
        }

        $urlKey = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $newCat = $this->categoryFactory->create();
        $newCat->setName($name);
        $newCat->setIsActive(true);
        $newCat->setParentId($parentId);
        $newCat->setStoreId(0);
        $newCat->setUrlKey($urlKey . '-' . $parentId);
        $newCat->setIncludeInMenu(true);
        $this->categoryRepository->save($newCat);

        return $newCat;
    }

    private function seedCustomers(callable $log): void
    {
        $log('Creando cuentas de clientes de prueba (B2B y B2C)...');
        $customers = [
            [
                'email' => 'wholesale@magecopilot.com',
                'firstname' => 'Carlos',
                'lastname' => 'Mendoza B2B',
                'group_id' => 2 // Wholesale
            ],
            [
                'email' => 'vip@magecopilot.com',
                'firstname' => 'Valeria',
                'lastname' => 'Gomez VIP',
                'group_id' => 3 // Retailer
            ],
            [
                'email' => 'consumer@magecopilot.com',
                'firstname' => 'Elena',
                'lastname' => 'Rios General',
                'group_id' => 1 // General
            ]
        ];

        foreach ($customers as $cData) {
            try {
                $this->customerRepository->get($cData['email']);
                $log(" - Cliente {$cData['email']} ya existe.");
            } catch (\Throwable) {
                $customer = $this->customerFactory->create();
                $customer->setWebsiteId(1);
                $customer->setEmail($cData['email']);
                $customer->setFirstname($cData['firstname']);
                $customer->setLastname($cData['lastname']);
                $customer->setGroupId($cData['group_id']);
                $this->customerRepository->save($customer);
                $log(" [OK] Created customer {$cData['email']} [Group {$cData['group_id']}]");
            }
        }
    }

    private function seedReviews(callable $log): void
    {
        if (!$this->resourceConnection) {
            return;
        }

        $conn = $this->resourceConnection->getConnection();

        $seedData = [
            'MC-APEX-HOODIE' => [
                [
                    'nickname' => 'Alejandro V.',
                    'title' => 'Calidad inigualable y ajuste perfecto',
                    'detail' => 'El tejido técnico de algodón peinado es sumamente suave y abrigador sin resultar pesado. La talla M calza exacta según la tabla. Gran compra.',
                    'rating' => 5
                ],
                [
                    'nickname' => 'Valentina M.',
                    'title' => 'Diseño sobrio y elegante',
                    'detail' => 'Los acabados y cierres son de primera calidad. Se nota el esmero en cada detalle. Llegó en menos de 48 horas a mi domicilio.',
                    'rating' => 5
                ]
            ],
            'MC-AEROGLIDE-SNK' => [
                [
                    'nickname' => 'Carlos G.',
                    'title' => 'Comodidad absoluta para entrenar',
                    'detail' => 'Excelente amortiguación para carreras de 10K y uso diario. La plantilla ergonómica reduce el impacto notablemente.',
                    'rating' => 5
                ]
            ],
            'MC-PRO-BUNDLE' => [
                [
                    'nickname' => 'Mateo R.',
                    'title' => 'El set técnico definitivo',
                    'detail' => 'Comprar el bundle completo me ahorró casi $40 y todos los accesorios combinan perfecto. Rendimiento 10/10.',
                    'rating' => 5
                ]
            ]
        ];

        foreach ($seedData as $sku => $reviews) {
            $productId = (int) $conn->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE sku = ?", [$sku]);
            if (!$productId) {
                continue;
            }

            foreach ($reviews as $rev) {
                $exists = (int) $conn->fetchOne(
                    "SELECT r.review_id FROM review r 
                     JOIN review_detail d ON r.review_id = d.review_id 
                     WHERE r.entity_pk_value = ? AND d.title = ?",
                    [$productId, $rev['title']]
                );

                if ($exists) {
                    continue;
                }

                $conn->insert('review', [
                    'created_at' => date('Y-m-d H:i:s'),
                    'entity_id' => 1,
                    'entity_pk_value' => $productId,
                    'status_id' => 1
                ]);
                $reviewId = (int) $conn->fetchOne('SELECT LAST_INSERT_ID()');

                $conn->insert('review_detail', [
                    'review_id' => $reviewId,
                    'store_id' => 1,
                    'title' => $rev['title'],
                    'detail' => $rev['detail'],
                    'nickname' => $rev['nickname']
                ]);

                $conn->query("INSERT IGNORE INTO review_store (review_id, store_id) VALUES ({$reviewId}, 0), ({$reviewId}, 1)");

                $conn->insert('rating_option_vote', [
                    'option_id' => 5,
                    'remote_ip' => '127.0.0.1',
                    'remote_ip_long' => ip2long('127.0.0.1') ?: 0,
                    'customer_id' => 0,
                    'entity_pk_value' => $productId,
                    'rating_id' => 1,
                    'review_id' => $reviewId,
                    'percent' => 100,
                    'value' => $rev['rating']
                ]);
            }

            $countReviews = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM review WHERE entity_pk_value = ? AND status_id = 1",
                [$productId]
            );

            if ($countReviews > 0) {
                $conn->query("
                    INSERT INTO review_entity_summary (entity_pk_value, entity_type, reviews_count, rating_summary, store_id)
                    VALUES ({$productId}, 1, {$countReviews}, 100, 1)
                    ON DUPLICATE KEY UPDATE reviews_count = {$countReviews}, rating_summary = 100;
                ");
                $conn->query("
                    INSERT INTO review_entity_summary (entity_pk_value, entity_type, reviews_count, rating_summary, store_id)
                    VALUES ({$productId}, 1, {$countReviews}, 100, 0)
                    ON DUPLICATE KEY UPDATE reviews_count = {$countReviews}, rating_summary = 100;
                ");
            }
        }

        $log(' [MageCopilot] Customer reviews and verified social proof seeded for flagship products.');
    }
}
