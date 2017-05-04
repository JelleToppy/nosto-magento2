<?php
/**
 * Copyright (c) 2017, Nosto Solutions Ltd
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
 * @copyright 2017 Nosto Solutions Ltd
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 *
 */

namespace Nosto\Tagging\Model\Product\Sku;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Store\Api\Data\StoreInterface;
use Nosto\NostoException;
use Nosto\Tagging\Helper\Data as NostoHelperData;
use Nosto\Tagging\Helper\Price as NostoPriceHelper;
use Nosto\Tagging\Model\Product\Sku\Builder as NostoSkuBuilder;
use Psr\Log\LoggerInterface;

class Factory
{
    private $configurableType;
    private $logger;
    private $nostoHelperData;
    private $nostoPriceHelper;
    private $nostoSkuBuilder;

    /**
     * Builder constructor.
     * @param LoggerInterface $logger
     * @param ConfigurableType $configurableType
     * @param NostoHelperData $nostoHelperData
     * @param NostoPriceHelper $priceHelper
     * @param Builder $nostoSkuBuilder
     */
    public function __construct(
        LoggerInterface $logger,
        ConfigurableType $configurableType,
        NostoHelperData $nostoHelperData,
        NostoPriceHelper $priceHelper,
        NostoSkuBuilder $nostoSkuBuilder
    ) {
        $this->configurableType = $configurableType;
        $this->logger = $logger;
        $this->nostoHelperData = $nostoHelperData;
        $this->nostoPriceHelper = $priceHelper;
        $this->nostoSkuBuilder = $nostoSkuBuilder;
    }

    /**
     * @param Product $product
     * @param StoreInterface $store
     * @return \Nosto\Object\Product\Sku[]
     */
    public function build(Product $product, StoreInterface $store)
    {
        if ($product->getTypeId() === ConfigurableType::TYPE_CODE) {

            $nostoSkus = [];
            $associatedProducts = $this->configurableType->getUsedProducts($product);
            $configurableAttributes = $this->configurableType->getConfigurableAttributes($product);

            /** @var Product $associatedProduct */
            foreach ($associatedProducts as $associatedProduct) {
                try {
                    $nostoSkus[] = $this->nostoSkuBuilder->build($associatedProduct, $store,
                        $configurableAttributes);
                } catch (NostoException $e) {
                    $this->logger->error($e->__toString());
                }
            }

            return $nostoSkus;
        }
    }
}