<?php
/**
 * Copyright (c) 2019, Nosto Solutions Ltd
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Nosto Solutions Ltd <contact@nosto.com>
 * @copyright 2019 Nosto Solutions Ltd
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 *
 */

namespace Nosto\Tagging\Model\Service;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRepository;
use Nosto\Tagging\Model\ResourceModel\Magento\Product\Collection as ProductCollection;
use Nosto\Tagging\Model\ResourceModel\Magento\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Nosto\Exception\MemoryOutOfBoundsException;
use Nosto\NostoException;
use Nosto\Tagging\Api\Data\ProductIndexInterface;
use Nosto\Tagging\Helper\Data as NostoDataHelper;
use Nosto\Tagging\Helper\Scope as NostoHelperScope;
use Nosto\Tagging\Logger\Logger as NostoLogger;
use Nosto\Tagging\Model\Product\Builder as NostoProductBuilder;
use Nosto\Tagging\Model\Product\Index\Builder;
use Nosto\Tagging\Model\Product\Index\Index as NostoProductIndex;
use Nosto\Tagging\Model\Product\Index\IndexRepository;
use Nosto\Tagging\Model\ResourceModel\Product\Index\Collection as NostoIndexCollection;
use Nosto\Tagging\Model\ResourceModel\Product\Index\CollectionFactory as NostoIndexCollectionFactory;
use Nosto\Tagging\Model\Product\Repository as NostoProductRepository;
use Nosto\Tagging\Util\Benchmark;
use Nosto\Tagging\Util\Iterator;
use Nosto\Tagging\Util\Product as ProductUtil;
use Nosto\Types\Product\ProductInterface as NostoProductInterface;

class Index extends AbstractService
{
    private const PRODUCT_DATA_BATCH_SIZE = 100;
    private const PRODUCT_DELETION_BATCH_SIZE = 100;
    private const BENCHMARK_BREAKPOINT_INVALIDATE = 100;
    private const BENCHMARK_BREAKPOINT_REBUILD = 10;
    private const BENCHMARK_NAME_INVALIDATE = 'nosto_index_invalidate';
    private const BENCHMARK_NAME_REBUILD = 'nosto_index_rebuild';

    /** @var IndexRepository */
    private $indexRepository;

    /** @var Builder */
    private $indexBuilder;

    /** @var ProductRepository */
    private $productRepository;

    /** @var NostoProductBuilder */
    private $nostoProductBuilder;

    /** @var NostoHelperScope */
    private $nostoHelperScope;

    /** @var NostoIndexCollectionFactory */
    private $nostoIndexCollectionFactory;

    /** @var TimezoneInterface */
    private $magentoTimeZone;

    /** @var Sync */
    private $nostoSyncService;

    /** @var NostoProductRepository $nostoProductRepository */
    private $nostoProductRepository;

    /** @var ProductCollectionFactory $productCollectionFactory */
    private $productCollectionFactory;

    /** @var array */
    private $invalidatedProducts = [];

    /**
     * Index constructor.
     * @param IndexRepository $indexRepository
     * @param Builder $indexBuilder
     * @param ProductRepository $productRepository
     * @param NostoProductBuilder $nostoProductBuilder
     * @param NostoHelperScope $nostoHelperScope
     * @param NostoLogger $logger
     * @param NostoIndexCollectionFactory $nostoIndexCollectionFactory
     * @param NostoProductRepository $nostoProductRepository
     * @param ProductCollectionFactory $productCollectionFactory
     * @param TimezoneInterface $magentoTimeZone
     * @param NostoDataHelper $nostoDataHelper
     * @param Sync $nostoSyncService
     */
    public function __construct(
        IndexRepository $indexRepository,
        Builder $indexBuilder,
        ProductRepository $productRepository,
        NostoProductBuilder $nostoProductBuilder,
        NostoHelperScope $nostoHelperScope,
        NostoLogger $logger,
        NostoIndexCollectionFactory $nostoIndexCollectionFactory,
        NostoProductRepository $nostoProductRepository,
        ProductCollectionFactory $productCollectionFactory,
        TimezoneInterface $magentoTimeZone,
        NostoDataHelper $nostoDataHelper,
        Sync $nostoSyncService
    ) {
        parent::__construct($nostoDataHelper, $logger);
        $this->indexRepository = $indexRepository;
        $this->indexBuilder = $indexBuilder;
        $this->productRepository = $productRepository;
        $this->nostoProductBuilder = $nostoProductBuilder;
        $this->nostoHelperScope = $nostoHelperScope;
        $this->nostoIndexCollectionFactory = $nostoIndexCollectionFactory;
        $this->nostoProductRepository = $nostoProductRepository;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->magentoTimeZone = $magentoTimeZone;
        $this->nostoSyncService = $nostoSyncService;
    }

    /**
     * Handles only the first step of indexing
     * Create one if row does not exits
     * Else set row to dirty
     *
     * @param ProductCollection $collection
     * @param Store $store
     */
    public function invalidateOrCreate(ProductCollection $collection, Store $store)
    {
        $this->startBenchmark(
            self::BENCHMARK_NAME_INVALIDATE,
            self::BENCHMARK_BREAKPOINT_INVALIDATE
        );
        $iterator = new Iterator($collection);
        $iterator->each(function ($item) use ($store) {
            $this->invalidateOrCreateProductOrParent($item, $store);
            $this->tickBenchmark(self::BENCHMARK_NAME_INVALIDATE);
        });
        try {
            $this->logBenchmarkSummary(self::BENCHMARK_NAME_INVALIDATE, $store);
        } catch (\Exception $e) {
            $this->getLogger()->exception($e);
        }
    }

    /**
     * @param Product $product
     * @param Store $store
     * @throws NoSuchEntityException
     * @throws NostoException
     */
    public function invalidateOrCreateProductOrParent(Product $product, Store $store)
    {
        $parents = $this->nostoProductRepository->resolveParentProductIds($product);

        //Products has no parents and Index product itself
        if ($parents === null) {
            $this->updateOrCreateDirtyEntity($product, $store);
            return;
        }

        //Loop through product parents and Index the parents
        if (is_array($parents)) {
            $this->invalidateOrCreateParents($parents, $store);
            return;
        }

        throw new NostoException('Could not index product with id: '. $product->getId());
    }

    /**
     * @param $ids
     * @throws NoSuchEntityException
     */
    private function invalidateOrCreateParents(array $ids, Store $store)
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addIdsToFilter($ids);
        $collection->load();
        /** @var ProductInterface $product */
        foreach ($collection->getItems() as $product) {
            if (!$this->hasParentBeenInvalidated($product->getId())) {
                $this->updateOrCreateDirtyEntity($product, $store);
                $this->invalidatedProducts[] = $product->getId();
            }
        }
    }

    /**
     * @param Product $product
     * @return bool
     */
    private function hasParentBeenInvalidated($productId)
    {
        if (in_array($productId, $this->invalidatedProducts)) {
            return true;
        }
        return false;
    }

    /**
     * @param Product $product
     * @param Store $store
     * @return ProductIndexInterface|NostoProductIndex|null
     * @throws NostoException
     */
    private function updateOrCreateDirtyEntity(Product $product, Store $store)
    {
        if (!$product->isVisibleInCatalog()) {
            throw new NostoException('Cannot index invisible product!');
        }

        $indexedProduct = $this->indexRepository->getByProductIdAndStoreId($product->getId(), $store->getId());
        try {
            if ($indexedProduct instanceof ProductIndexInterface) {
                $indexedProduct->setIsDirty(true);
                $indexedProduct->setUpdatedAt($this->magentoTimeZone->date());
            } else {
                /* @var Product $fullProduct */
                $fullProduct = $this->loadMagentoProduct($product->getId(), $store->getId());
                $indexedProduct = $this->indexBuilder->build($fullProduct, $store);
                $indexedProduct->setIsDirty(false);
            }
            $this->indexRepository->save($indexedProduct);
            return $indexedProduct;
        } catch (\Exception $e) {
            $this->getLogger()->exception($e);
            return null;
        }
    }

    /**
     * @param NostoIndexCollection $collection
     * @param Store $store
     * @throws NostoException
     * @throws MemoryOutOfBoundsException
     */
    public function rebuildDirtyProducts(NostoIndexCollection $collection, Store $store)
    {
        $this->startBenchmark(
            self::BENCHMARK_NAME_REBUILD,
            self::BENCHMARK_BREAKPOINT_REBUILD
        );
        $collection->setPageSize(self::PRODUCT_DATA_BATCH_SIZE);
        $iterator = new Iterator($collection);
        $iterator->each(function ($item) {
            if ($item->getIsDirty() === NostoProductIndex::DB_VALUE_BOOLEAN_TRUE) {
                $this->rebuildDirtyProduct($item);
                $this->tickBenchmark(self::BENCHMARK_NAME_REBUILD);
            }
        });
        $this->logBenchmarkSummary(self::BENCHMARK_NAME_REBUILD, $store);
        $this->nostoSyncService->syncIndexedProducts($collection, $store);
    }

    /**
     * Rebuilds a dirty indexed product data & defines it as out of sync
     * if Nosto product data changed
     *
     * @param ProductIndexInterface $productIndex
     * @return ProductIndexInterface|null
     */
    public function rebuildDirtyProduct(ProductIndexInterface $productIndex)
    {
        try {
            /* @var Product $magentoProduct */
            $magentoProduct = $this->loadMagentoProduct(
                $productIndex->getProductId(),
                $productIndex->getStoreId()
            );
            $store = $this->nostoHelperScope->getStore($productIndex->getStoreId());
            $nostoProduct = $this->nostoProductBuilder->build($magentoProduct, $store);
            $nostoIndexedProduct = $productIndex->getNostoProduct();
            if ($nostoIndexedProduct instanceof NostoProductInterface === false ||
                (
                    $nostoProduct instanceof NostoProductInterface
                    && !ProductUtil::isEqual($nostoProduct, $nostoIndexedProduct)
                )
            ) {
                $productIndex->setNostoProduct($nostoProduct);
                $productIndex->setInSync(false);
            }
            $productIndex->setIsDirty(false);
            $this->indexRepository->save($productIndex);
            return $productIndex;
        } catch (\Exception $e) {
            $this->getLogger()->exception($e);
            return null;
        }
    }

    /**
     * @param ProductCollection $collection
     * @param array $ids
     * @param Store $store
     */
    public function markProductsAsDeletedByDiff(ProductCollection $collection, array $ids, Store $store)
    {
        $uniqueIds = array_unique($ids);
        $collection->setPageSize(self::PRODUCT_DELETION_BATCH_SIZE);
        $iterator = new Iterator($collection);
        $iterator->each(function ($magentoProduct) use ($uniqueIds) {
            $key = array_search($magentoProduct->getId(), $uniqueIds);
            if (is_numeric($key)) {
                unset($uniqueIds[$key]);
            }
        });
        // Flag the rest of the ids as deleted
        $deleted = $this->nostoIndexCollectionFactory->create()->markAsDeleted($uniqueIds, $store);
        $this->getLogger()->info(
            sprintf(
                'Marked %d indexed products as deleted for store %s',
                $deleted,
                $store->getName()
            )
        );
    }

    /**
     * Loads (or reloads) Product object
     * @param int $productId
     * @param int $storeId
     * @return ProductInterface|Product|mixed
     * @throws NoSuchEntityException
     */
    private function loadMagentoProduct(int $productId, int $storeId)
    {
        return $this->productRepository->getById(
            $productId,
            false,
            $storeId,
            true
        );
    }
}
