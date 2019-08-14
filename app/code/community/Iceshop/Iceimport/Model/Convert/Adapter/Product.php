<?php
/**
 * Import Product with additional attributes
 *
 *
 */


class Iceshop_Iceimport_Model_Convert_Adapter_Product extends Mage_Catalog_Model_Convert_Adapter_Product
{
    protected $_categoryCache = array();
    protected $_connRes = null;
    protected $_tablePrefix = '';

    public function __construct()
    {

        $this->_connRes = Mage::getSingleton('core/resource')->getConnection('core_write');
        $tablePrefix = (array)Mage::getConfig()->getTablePrefix();
        if (!empty($tablePrefix)) {
            $this->_tablePrefix = $tablePrefix[0];
        }

    }

    public function saveRow(array $importData)
    {
        // separate import data to eav & static
        $sortedProductData = $this->_mapAttributes($importData);
        $productData = $sortedProductData['productData'];
        $iceimportAttributes = $sortedProductData['iceimportAttributes'];
        $failedAttributes = $sortedProductData['failedAttributes'];

        //Init session values to count total products and determine the last call of saveRow method
        $session = Mage::getSingleton("core/session");
        $import_total = $session->getData("import_total");
        $counter = $session->getData("counter");

        if (!isset($counter)) {
            $session->setData("counter", 1);
            $counter = $session->getData("counter");
        }

        if (!isset($import_total)) {
            $batchId = Mage::getSingleton('core/app')->getRequest()->getPost('batch_id', 0);
            $batchModel = Mage::getModel('dataflow/batch')->load($batchId);
            $batchImportModel = $batchModel->getBatchImportModel();
            $importIds = $batchImportModel->getIdCollection();
            $import_total = count($importIds);
            $session->setData("imqport_total", (int)$import_total);
        } else if (isset($counter) && isset($import_total)) {
            if ($counter < $import_total) {
                $session->setData("counter", (int)++$counter);
            }
        }

        // mark product ice_import generic
        $productData['varchar']['is_iceimport'] = 1;
        // set website id
        if (empty($iceimportAttributes['websites'])) {
            $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'website');
            Mage::throwException($message);
        }
        $website = Mage::app()->getWebsite(trim($iceimportAttributes['websites']));
        $websiteId = $website->getId();

        // set store id
        if (empty($iceimportAttributes['store'])) {
            if (!is_null($this->getBatchParams('store'))) {
                $store = $this->getStoreById($this->getBatchParams('store'));
            } else {
                $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'store');
                Mage::throwException($message);
            }
        }
        $store = $this->getStoreByCode($iceimportAttributes['store']);
        if ($store === false) {
            $message = Mage::helper('catalog')->__('Skip import row, store "%s" not exists', $iceimportAttributes['store']);
            Mage::throwException($message);
        }
        $storeId = $store->getId();

        // set type
        if (empty($iceimportAttributes['type'])) {
            $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'type');
            Mage::throwException($message);
        }
        $productType = $iceimportAttributes['type'];

        // set attribute set
        if (empty($iceimportAttributes['attribute_set'])) {
            $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'attribute_set');
            Mage::throwException($message);
        }
        $attribute_set = $iceimportAttributes['attribute_set'];

        // set sku
        if (empty($iceimportAttributes['sku'])) {
            $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'sku');
            Mage::throwException($message);
        }
        $sku = $iceimportAttributes['sku'];

        // set category, unspsc, unspsc path
        if (empty($iceimportAttributes['categories'])) {
            $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'categories');
            Mage::throwException($message);
        }
        $category = $iceimportAttributes['categories'];
        if (empty($iceimportAttributes['unspsc'])) {
            $message = Mage::helper('catalog')->__('Skip import. Category UNSPSC not defined in store');
            Mage::throwException($message);
        }
        $unspsc = $iceimportAttributes['unspsc'];
        if (empty($iceimportAttributes['unspsc_path'])) {
            $message = Mage::helper('catalog')->__('Skip import. Category UNSPSC path not defined in store');
            Mage::throwException($message);
        }
        $unspscPath = $iceimportAttributes['unspsc_path'];

        // set in / out of stock
        $isInStock = 0;
        if (!empty($iceimportAttributes['is_in_stock'])) {
            $isInStock = $iceimportAttributes['is_in_stock'];
        }

        // set qty
        $qty = 0;
        if (!empty($iceimportAttributes['qty'])) {
            $qty = $iceimportAttributes['qty'];
        }

        // set price
        $price = 0.00;
        if (!empty($iceimportAttributes['price'])) {
            $price = $iceimportAttributes['price'];
        }

        // set status value
        $statusValue = (!empty($iceimportAttributes['status']) && $iceimportAttributes['status'] == 'Enabled') ? 1 : 0;
        $productData['int']['status'] = $statusValue;

        // set visibility value
        $visibilityValue = 1;
        if (!empty($iceimportAttributes['visibility'])) {
            switch ($iceimportAttributes['visibility']) {
                case 'Not Visible Individually':
                    $visibilityValue = 1;
                    break;
                case 'Catalog':
                    $visibilityValue = 2;
                    break;
                case 'Search':
                    $visibilityValue = 3;
                    break;
                case 'Catalog, Search':
                    $visibilityValue = 4;
                    break;
            }
        }
        $productData['int']['visibility'] = $visibilityValue;

        // set product image
        $productImage = '';
        if (!empty($iceimportAttributes['image'])) {
            $productImage = $iceimportAttributes['image'];
        }

        // init general attributes query
        $initAttributes = "
      SELECT @product_entity_type_id   := `entity_type_id` FROM `" . $this->_tablePrefix . "eav_entity_type` WHERE
        entity_type_code = 'catalog_product';
      SELECT @category_entity_type_id  := `entity_type_id` FROM `" . $this->_tablePrefix . "eav_entity_type` WHERE
        entity_type_code = 'catalog_category';
      SELECT @attribute_set_id         := `entity_type_id` FROM `" . $this->_tablePrefix . "eav_entity_type` WHERE
        entity_type_code = 'catalog_product';
      SELECT @stock_id                 := `stock_id` FROM `" . $this->_tablePrefix . "cataloginventory_stock` WHERE
        stock_name = 'Default';
      SELECT @attribute_set_id         := `attribute_set_id` FROM `" . $this->_tablePrefix . "eav_attribute_set`
                                          WHERE attribute_set_name = :attribute_set AND entity_type_id = 
                                          @product_entity_type_id;

      SELECT @price_id                 := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE
        `attribute_code` = 'price' AND entity_type_id = @product_entity_type_id;

      SELECT @unspcs_id                := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE
      `attribute_code` = 'unspsc' AND entity_type_id = @category_entity_type_id;
      SELECT @category_name_id         := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  
        `attribute_code` = 'name' AND entity_type_id = @category_entity_type_id;
      SELECT @category_active_id       := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE
        `attribute_code` = 'is_active' AND entity_type_id = @category_entity_type_id;
      SELECT @include_nav_bar_id       := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE
        `attribute_code` = 'include_in_menu'   AND entity_type_id = @category_entity_type_id;
      SELECT @category_is_anchor_id       := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE
        `attribute_code` = 'is_anchor'   AND entity_type_id = @category_entity_type_id;
    ";


        $this->_connRes->query($initAttributes, array(':attribute_set' => $iceimportAttributes['attribute_set']));

        $defaulttaxConf = (int)Mage::getStoreConfig('importprod_root/importprod/default_tax', $storeId);

        $productData['int']['tax_class_id'] = $defaulttaxConf;

        // get category id
        $categoriesToActiveConf = Mage::getStoreConfig('importprod_root/importprod/category_active', $storeId);
        $categoryIds = $this->_addCategories($category, $storeId, $unspsc, $unspscPath, $categoriesToActiveConf);

        // get url key
        $url = '';
        if (!empty($productData['varchar']['name'])) {
            $preUrl = explode(' ', strtolower($productData['varchar']['name']));
            $url = implode('-', $preUrl) . '-' . $iceimportAttributes['store'];
        }
        $productData['varchar']['url_key'] = $url;

        $prodIdFetch = $this->_connRes->fetchRow("SELECT entity_id FROM `" . $this->_tablePrefix . "catalog_product_entity` WHERE sku = :sku limit 1", array(
                ':sku' => $sku
            ));
        $productId = $prodIdFetch['entity_id'];
        if (!empty($productId)) {
            // check import type (Import only price & qty or all product info)
            $stockConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_stock', $storeId);
            $priceConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_prices', $storeId);
            $productId = $this->_coreSave($productData, $productId, $storeId, $sku, $categoryIds);
            $this->_corePriceStock($websiteId, $productId, $price, $qty, $sku, $isInStock, $stockConf, $priceConf);
            $this->_connRes->query('INSERT INTO iceshop_iceimport_imported_product_ids (product_id, product_sku) VALUES (:prod_id, :sku) ON DUPLICATE KEY UPDATE product_sku = :sku', array(':prod_id' => $productId, ':sku' => $sku));
        } else {
            $productId = $this->_coreSave($productData, $productId, $storeId, $sku, $categoryIds);
            // add price & stock
            $this->_corePriceStock($websiteId, $productId, $price, $qty, $sku, $isInStock);
            $this->_connRes->query('INSERT INTO iceshop_iceimport_imported_product_ids (product_id, product_sku) VALUES (:prod_id, :sku) ON DUPLICATE KEY UPDATE product_sku = :sku', array(':prod_id' => $productId, ':sku' => $sku));
        }

        // add product image to queue
        if (Mage::getStoreConfig('importprod_root/importprod/import_images')) {
            $this->_addImageToQueue($productId, $productImage);
        }

        // Check if this is last imported product
        //  Do category sort and set categories without products to inactive
        if (isset($counter) && isset($import_total) && ($counter == $import_total)) {
            $catCollection = Mage::getModel('catalog/category')
                ->getCollection()
                ->addAttributeToSort('name', 'ASC');
            $position = 1;

            foreach ($catCollection as $category) {

                $query = $this->_connRes->query("UPDATE `" . $this->_tablePrefix . "catalog_category_entity` SET position = :position WHERE entity_id = :cat_id ", array(
                        ':position' => $position,
                        ':cat_id' => $category->getId()
                    ));

                $query = "SELECT COUNT(*) FROM `" . $this->_tablePrefix . "catalog_category_product` WHERE category_id = :cat_id ";
                $cat_products = $this->_connRes->fetchRow($query, array(
                    ':cat_id' => $category->getId()
                ));

                if ($cat_products['COUNT(*)'] == 0) {
                    $query = "SELECT `entity_id` FROM `" . $this->_tablePrefix . "catalog_category_entity` WHERE parent_id = :cat_id";
                    $child_cat = $this->_connRes->fetchAll($query, array(
                        ':cat_id' => $category->getId()
                    ));

                    if (isset($child_cat) && count($child_cat) > 0) {
                        //Count child categories products and set them to inactive if they have no
                        $this->CountChildProd($child_cat);
                    } else {
                        $this->_connRes->query("UPDATE `" . $this->_tablePrefix . "catalog_category_entity_int`
                                    SET `value` = 0 WHERE `attribute_id` = @category_active_id AND entity_id = :cat_id", array(
                                ':cat_id' => $category->getId()
                            ));
                    }
                }
                $position++;
            }

            $session->unsetData('import_total');
            $session->unsetData('counter');
        }

        return true;
    }

    protected function _coreSave(array $entityData, $productId = null, $storeId = 0, $sku, $categoryIds)
    {
        if ($productId === null) {
            // add product to store
            $coreSaveProduct = "INSERT INTO `" . $this->_tablePrefix . "catalog_product_entity` (`entity_type_id`, `attribute_set_id`, `type_id`, `sku`, `created_at`) VALUES
          (@product_entity_type_id, @attribute_set_id, 'simple', :sku, NOW());
          SELECT @product_id := LAST_INSERT_ID();
      ";
            $this->_connRes->query($coreSaveProduct, array(':sku' => $sku));
            // get product ID
            $prodFetch = $this->_connRes->fetchRow('SELECT @product_id AS prod_id');
            $productId = $prodFetch['prod_id'];

        } else {
            $coreSaveSQL .= "SELECT @product_id := " . (int)$productId . "; ";
            $producteanConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_product_ean', $storeId);
            $productmpnConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_product_mpn', $storeId);
            $productnameConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_product_name', $storeId);
            $productshdescriptionConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_product_short_description', $storeId);
            $productdescriptionConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_product_description', $storeId);
            $productshsudescriptionConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_product_short_summary_description', $storeId);
            $productsudescriptionConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_product_summary_description', $storeId);
            $productbrandConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_product_brand', $storeId);
            $deliveryetaConf = (int)Mage::getStoreConfig('importprod_root/importprod/import_delivery_eta', $storeId);
            foreach ($entityData as $type => $typeAttributes) {
                foreach ($typeAttributes as $attribute => $value) {
                    if ((($attribute == 'mpn' && $productmpnConf == 0) || ($attribute == 'brand_name' && $productbrandConf == 0) || ($attribute == 'ean' && $producteanConf == 0) || ($attribute == 'name' && $productnameConf == 0) || ($attribute == 'short_description' && $productshdescriptionConf == 0) || ($attribute == 'description' && $productdescriptionConf == 0) || ($attribute == 'short_summary_description' && $productshsudescriptionConf == 0) || ($attribute == 'long_summary_description' && $productsudescriptionConf == 0) || ($attribute == 'delivery_eta' && $deliveryetaConf == 0))) {
                        unset($entityData[$type][$attribute]);
                    }
                }
            }
        }
        $bindArray[':store_id'] = $storeId;

        foreach ($entityData as $type => $typeAttributes) {

            if ($type != 'spec') {
                $tailCoreSaveSQL = '';
                $attributesInit = '';

                if (!empty($typeAttributes)) {
                    $tailCoreSaveSQL .= "
                        INSERT INTO `" . $this->_tablePrefix . "catalog_product_entity_" . $type . "` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) VALUES ";
                    foreach ($typeAttributes as $attribute => $value) {

                            $attributesInit .= "
                              SELECT @" . $attribute . "_id := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE
                                `attribute_code` = '" . $attribute . "' AND entity_type_id = @product_entity_type_id;
                            ";

                            $tailCoreSaveSQL .= "
                              (@product_entity_type_id, @" . $attribute . "_id, 0, @product_id, :" . $attribute . " ),
                              (@product_entity_type_id, @" . $attribute . "_id, :store_id, @product_id, :" . $attribute . " ), ";
                            $bindArray[':' . $attribute] = $value;

                    }
                    $tailCoreSaveSQL = substr($tailCoreSaveSQL, 0, -2);
                    $tailCoreSaveSQL .= "
            ON DUPLICATE KEY UPDATE
            `value` = VALUES (`value`);  
          ";
                }
                $coreSaveSQL .= $attributesInit . $tailCoreSaveSQL;
            } else {
                foreach ($typeAttributes as $attribute => $attributeData) {
                    $prod_id_field = $attributeData['prod_id_field'];
                    $table = $attributeData['table'];
                    $field = $attributeData['field'];
                    $value = $attributeData['value'];
                    if (!empty($table) && !empty($field)) {
                        $coreSaveSQL .= "
			      UPDATE `" . $this->_tablePrefix . $table . "` SET `" . $field . "` = :" . $attribute . " WHERE `" . $prod_id_field . "` = @product_id;
		        ";
                        $bindArray[':' . $attribute] = $value;
                    }
                }
            }
        }
        // categories
        $coreSaveSQL .= "INSERT INTO `" . $this->_tablePrefix . "catalog_category_product` (`category_id`, `product_id`, `position`) VALUES ";
        $counter = 1;
        $categoryIds[] = Mage::app()->getStore(1)->getRootCategoryId();
        foreach ($categoryIds as $categoryId) {
            if ($counter < count($categoryIds)) {
                $coreSaveSQL .= " (" . (int)$categoryId . ", @product_id, 1) , ";
            } else if ($counter == count($categoryIds)) {
                $coreSaveSQL .= " (" . (int)$categoryId . ", @product_id, 1) ON DUPLICATE KEY UPDATE `position` = 1 ";
            }
            $counter++;
        }
        try {
            $bindArray[':' . $attribute] = $value;

            $query = $this->_connRes->query($coreSaveSQL, $bindArray);

        } catch (Exception $e) {
            echo $e->getMessage();
        }
        return $productId;
    }

    protected function _corePriceStock($website = 0, $productId = false, $price = 0.00, $qty = 0.00, $sku = false, $isInStock = 0, $stockConf = 1, $priceConf = 1)
    {

        if (!$productId) {
            $message = Mage::helper('catalog')->__('Skip import row, product_id for product "%s" not defined ', $sku);
            Mage::throwException($message);
        }
        if ($stockConf == 1) {
            $stockSaveSQL = "
          INSERT INTO `" . $this->_tablePrefix . "cataloginventory_stock_item` (`product_id`, `stock_id`, `qty`, `is_in_stock`) VALUES
            (:product_id, @stock_id, :qty,1)
          ON DUPLICATE KEY UPDATE
            `product_id`  = :product_id,
            `stock_id`    = @stock_id,
            `qty`         = :qty,
            `is_in_stock` = :is_in_stock;

          INSERT INTO `" . $this->_tablePrefix . "cataloginventory_stock_status` (`product_id`, `website_id`, `stock_id`, `qty`, `stock_status`) VALUES
            (:product_id, :webisteId, @stock_id, :qty, 1)
          ON DUPLICATE KEY UPDATE
            `product_id`   = :product_id,
            `website_id`   = :webisteId,
            `stock_id`     = @stock_id,
            `qty`          = :qty,
            `stock_status` = :is_in_stock;";
        }
        if ($priceConf == 1) {
            $stockSaveSQL .= "
          INSERT INTO `" . $this->_tablePrefix . "catalog_product_website` (`product_id`, `website_id`) VALUES
            (:product_id, :webisteId)
          ON DUPLICATE KEY UPDATE
            `product_id` = :product_id,
            `website_id` = :webisteId;

          INSERT INTO `" . $this->_tablePrefix . "catalog_product_entity_decimal` (`entity_type_id`,`attribute_id`,`store_id`, `entity_id`, `value`) VALUES
            (@product_entity_type_id, @price_id,  0, :product_id, :price)
          ON DUPLICATE KEY UPDATE
            `entity_type_id` = @product_entity_type_id,
            `attribute_id`   = @price_id,
            `store_id`       = 0,
            `entity_id`      = :product_id,
            `value`          = :price;
          ";
        }
        if ($priceConf == 1 || $stockConf == 1) {
            $this->_connRes->query($stockSaveSQL, array(
                ':webisteId' => $website,
                ':product_id' => $productId,
                ':price' => $price,
                ':qty' => $qty,
                ':is_in_stock' => $isInStock
            ));
        }
    }

    protected function _addImageToQueue($productId = false, $productImageUrl)
    {
        $productImageUrl = trim($productImageUrl);
        if ($productId && !empty($productImageUrl)) {
            // add image if not exists to queue
            $this->_connRes->query(" INSERT IGNORE INTO `" . $this->_tablePrefix . "iceshop_iceimport_image_queue` (`entity_id`, `image_url` ) VALUES
	      (:product_id, :image_url)
      ", array(':product_id' => $productId,
                    ':image_url' => $productImageUrl));
        }
    }

    public function getImageQueue()
    {
        return $this->_connRes->fetchAll("SELECT `queue_id`, `entity_id`, `image_url` FROM `" . $this->_tablePrefix . "iceshop_iceimport_image_queue`
      WHERE `is_downloaded` = 0
    ");
    }

    public function setImageAsDownloaded($queueId = false)
    {
        if ($queueId) {
            $this->_connRes->query("UPDATE `" . $this->_tablePrefix . "iceshop_iceimport_image_queue` SET is_downloaded = 1
      WHERE queue_id = :queue_id", array(':queue_id' => $queueId));
        }
    }

    protected function _addCategories($categories, $storeId, $unspsc, $unspscPath, $categoryActive = 1)
    {

        // check if product exists
        $categoryId = $this->_getCategoryIdByUnspsc($unspsc);
        $categoryIds = array();
        if (!empty($categoryId)) {
            // merge categories by unspsc
            $categoryMergedArray = $this->_categoryMapper($categories, $unspscPath);
            foreach ($categoryMergedArray as $category) {
                $categoryName = $category['name'];
                $categoryUnspsc = $category['unspsc'];
                $categoryTreeId = $this->_getCategoryIdByUnspsc($categoryUnspsc);
                // check category name to current store
                $categoryBindArray = array(
                    ':store_id' => $storeId,
                    ':category_id' => $categoryTreeId
                );
                $nameCheckerFetch = $this->_connRes->fetchRow("SELECT value_id FROM `" . $this->_tablePrefix . "catalog_category_entity_varchar` WHERE
                  store_id = :store_id AND entity_id = :category_id AND attribute_id = @category_name_id
                ", $categoryBindArray);
                $nameChecker = $nameCheckerFetch['value_id'];
                if (!$nameChecker) {
                    // add category name to current store
                    $categoryBindArray[':category_name'] = $categoryName;
                    if (!empty($categoryBindArray[':category_id'])) {
                        $this->_connRes->query("
              INSERT INTO `" . $this->_tablePrefix . "catalog_category_entity_varchar` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) VALUES
              (@category_entity_type_id, @category_name_id, :store_id, :category_id, :category_name)
            ", $categoryBindArray);
                    }
                }
            }
            //

            // get current path of category
            $categoryPath = $this->_connRes->fetchRow("SELECT path FROM `" . $this->_tablePrefix . "catalog_category_entity` WHERE entity_id = :entity_id",
                array(':entity_id' => $categoryId));
            $categoryPathArray = explode('/', $categoryPath['path']);
            if ($categoryPathArray) {
                $activeSetter = "INSERT INTO `" . $this->_tablePrefix . "catalog_category_entity_int` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) VALUES ";
            }

            $falseCounter = 0;
            foreach ($categoryPathArray as $categoryId) {
                $category = Mage::getModel('catalog/category')->load($categoryId);
                $cid = $category->getId();
                if (!empty($cid)) {
                    if (!empty($categoryId)) {
                        $categoryIds[] = (int)$categoryId;
                        $activeSetter .= "(@category_entity_type_id, @category_active_id, :store_id, " . $categoryId . ", 1),
                                  (@category_entity_type_id, @category_active_id, 0, " . $categoryId . ", 1), ";
                    } else {
                        $falseCounter++;
                    }
                } else {
                    $falseCounter++;
                }
            }
            $activeSetter = substr($activeSetter, 0, -2);
            $activeSetter .= "
              ON DUPLICATE KEY UPDATE
              `value` = 1
            ";
            if (1 == $categoryActive) {
                if ($falseCounter < count($categoryPathArray)) {
                    $this->_connRes->query($activeSetter, array(':store_id' => $storeId));
                }
            }
            return $categoryIds;
        } else {

            // merge unspcs to current name in unspcs & name path's
            $categoryMergedArray = $this->_categoryMapper($categories, $unspscPath);
            // get max created parrent category
            $categoryCreateArray = array();
            for ($i = count($categoryMergedArray) - 1; $i >= 0; $i--) {
                $category = $categoryMergedArray[$i];
                $checkCategoryId = $this->_getCategoryIdByUnspsc($category['unspsc']);
                if ($checkCategoryId != null) {
                    $categoryId = $this->_buildCategoryTree($checkCategoryId, $storeId, $categoryCreateArray, $categoryActive);
                    $categoryIds[] = (int)$categoryId;
                    break;
                } else {
                    $categoryCreateArray[] = $category;
                }
            }
            return $categoryIds;
        }
    }

    protected function _categoryMapper($categoryPath, $unspscPath)
    {
        $nameArray = explode('/', $categoryPath);
        $unspscArray = explode('/', $unspscPath);

        if (count($nameArray) != count($unspscArray)) {
            $message = Mage::helper('catalog')->__('Skip import row, @categories data is invaled');
            Mage::throwException($message);
        }

        $categoryMergedArray = array(
            array(
                'unspsc' => 'default_root',
                'name' => 'Default category'
            )
        );

        for ($i = 0; $i < count($unspscArray); $i++) {
            $categoryMergedArray[] = array('name' => $nameArray[$i],
                'unspsc' => $unspscArray[$i]);
        }

        return $categoryMergedArray;
    }

    protected function _getCategoryIdByUnspsc($unspcs)
    {
        if ($unspcs == 'default_root') {
            return Mage::app()->getStore(1)->getRootCategoryId();
        } else {
            $categoryId = $this->_connRes->fetchRow("SELECT entity_id FROM `" . $this->_tablePrefix . "catalog_category_entity_varchar` WHERE
                                               `value` = :unspsc AND attribute_id = @unspcs_id", array(':unspsc' => $unspcs));
            return ($categoryId['entity_id']) ? $categoryId['entity_id'] : null;
        }
    }

    protected function _buildCategoryTree($parrentCategoryId, $storeId, $pathArray, $categoryActive = 0)
    {
        for ($i = count($pathArray) - 1; $i >= 0; $i--) {
            $category = $pathArray[$i];
            $parrentCategoryId = $this->_createCategory($parrentCategoryId, $category['unspsc'], $storeId, $category['name'], $categoryActive);
        }

        return $parrentCategoryId;
    }

    protected function _createCategory($parrentId, $unspsc, $storeId, $name, $categoryActive = 0)
    {

        $addCategory = "
      SELECT @tPath := `path`, @tLevel := `level` FROM `" . $this->_tablePrefix . "catalog_category_entity` WHERE `entity_id` = :parrent_id;
      SET @tLevel = @tLevel +1;

      SET @path := CONCAT(@tPath, '/',(SELECT MAX(entity_id) FROM `" . $this->_tablePrefix . "catalog_category_entity`) +1 );
    
      INSERT INTO `" . $this->_tablePrefix . "catalog_category_entity` (`entity_type_id`, `attribute_set_id`, 
                                                                        `parent_id`, `created_at`, 
                                                                        `path`, `position`, 
                                                                        `level`, `children_count`)
      VALUES
        (@category_entity_type_id, 0, :parrent_id, NOW(), @path, 1, @tLevel, 0);

      SELECT @catId := LAST_INSERT_ID();

      UPDATE `" . $this->_tablePrefix . "catalog_category_entity` SET `path` = CONCAT(@tPath, '/', @catId) WHERE entity_id =  LAST_INSERT_ID();

      UPDATE `" . $this->_tablePrefix . "catalog_category_entity` SET children_count = children_count +1 WHERE entity_id = :parrent_id;

      INSERT IGNORE INTO `" . $this->_tablePrefix . "catalog_category_entity_int` (`entity_type_id`, `attribute_id`,
                                                                            `store_id`, `entity_id`, `value`)
      VALUES
        (@category_entity_type_id, @category_active_id, 0,      @catId, :category_active),
        (@category_entity_type_id, @category_active_id, :store, @catId, :category_active),
        (@category_entity_type_id, @category_is_anchor_id, 0, @catId, 1),
        (@category_entity_type_id, @category_is_anchor_id, :store, @catId, 1),
        (@category_entity_type_id, @include_nav_bar_id, 0,      @catId, 1),
        (@category_entity_type_id, @include_nav_bar_id, :store, @catId, 1);

      INSERT IGNORE INTO `" . $this->_tablePrefix . "catalog_category_entity_varchar` (`entity_type_id`, `attribute_id`,
                                                                            `store_id`, `entity_id`, `value`)
      VALUES
        (@category_entity_type_id, @category_name_id, 0,      @catId, :category_name),
        (@category_entity_type_id, @category_name_id, :store, @catId, :category_name),
        (@category_entity_type_id, @unspcs_id,        0,      @catId, :unspsc_val),
        (@category_entity_type_id, @unspcs_id,        :store, @catId, :unspsc_val);
    ";

        $this->_connRes->query($addCategory, array(
            ':store' => $storeId,
            ':parrent_id' => $parrentId,
            ':category_name' => $name,
            ':unspsc_val' => $unspsc,
            ':category_active' => (int)$categoryActive
        ));

        $categoryIdFetch = $this->_connRes->fetchRow('SELECT @catId AS category_id');

        return $categoryIdFetch['category_id'];
    }

    protected function _mapAttributes(array $importData)
    {

        // map iceimport attributes, skip some attributes
        $iceAttributes = array();
        foreach ($importData as $attribute => $value) {
            // map iceimport attributes
            if ($attribute == 'type' ||
                $attribute == 'sku' ||
                $attribute == 'attribute_set' ||
                $attribute == 'categories' ||
                $attribute == 'unspsc' ||
                $attribute == 'price' ||
                $attribute == 'qty' ||
                $attribute == 'status' ||
                $attribute == 'visibility' ||
                $attribute == 'store' ||
                $attribute == 'websites' ||
                $attribute == 'is_in_stock' ||
                $attribute == 'image' ||
                $attribute == 'unspsc_path'
            ) {

                $iceAttributes[$attribute] = $value;
                unset($importData[$attribute]);

            }
            // skip some attributes
            if ($attribute == 'supplier_product_code' ||
                $attribute == 'supplier' ||
                $attribute == 'leader_categories' ||
                $attribute == 'leader_store' ||
                $attribute == 'sprice' ||
                $attribute == 'euprice' ||
                $attribute == 'icecat_product_id' ||
                $attribute == 'icecat_category_id' ||
                $attribute == 'icecat_vendor_id' ||
                $attribute == 'icecat_quality' ||
                $attribute == 'icecat_url' ||
                $attribute == 'icecat_thumbnail_img' ||
                $attribute == 'icecat_low_res_img' ||
                $attribute == 'icecat_high_res_img' ||
                $attribute == 'tax1' ||
                $attribute == 'tax2' ||
                $attribute == 'tax3' ||
                $attribute == 'tax4' ||
                $attribute == 'min_quantity' ||
                $attribute == 'loms' ||
                $attribute == 'image_label' ||
                $attribute == 'links_title' ||
                $attribute == 'small_image_label' ||
                $attribute == 'tax_rate' ||
                $attribute == 'gallery' ||
                $attribute == 'weight_type' ||
                $attribute == 'sku_type' ||
                $attribute == 'manage_stock' ||
                $attribute == 'minimal_price' ||
                $attribute == 'required_options' ||
                $attribute == 'samples_title' ||
                $attribute == 'shipment_type' ||
                $attribute == 'url_path' ||
                $attribute == 'recurring_profile' ||
                $attribute == 'product_keys'
            ) {

                unset($importData[$attribute]);

            }

        }

        // map default attributes
        $productData = array();
        foreach ($this->_getDefaultAttributesList() as $backendType => $attributesList) {
            if ($backendType != 'spec') {
                foreach ($attributesList as $attribute) {
                    if (isset($importData[$attribute]) && $importData[$attribute] != '') {
                        $productData[$backendType][$attribute] = $importData[$attribute];
                        unset($importData[$attribute]);
                    }
                }
            } else {
                foreach ($attributesList as $attributeCode => $attributeSpecs) {
                    if (isset($importData[$attributeCode]) && $importData[$attributeCode]  != false) {
                        $attributeSpecs['value'] = $importData[$attributeCode];
                        $productData[$backendType][$attributeCode] = $attributeSpecs;
                        unset($importData[$attributeCode]);
                    }
                }
            }
        }

        // map custom attributes
        if (!empty($importData)) {
            foreach ($importData as $attributeCode => $value) {
                $backendTypeFetch = $this->_connRes->fetchRow("SELECT backend_type FROM `" . $this->_tablePrefix . "eav_attribute` WHERE `attribute_code` = :code", array(':code' => $attributeCode));
                $backendType = $backendTypeFetch['backend_type'];
                if ($backendType != 'static' && !empty($backendType) && $value  != '') {
                    $productData[$backendType][$attributeCode] = $value;
                    unset($importData[$attributeCode]);
                }
            }
        }

        $failedAttributes = array();
        if (count($importData) > 0) {
            $failedAttributes = array_keys($importData);
        }

        return array(
            'iceimportAttributes' => $iceAttributes,
            'productData' => $productData,
            'failedAttributes' => $failedAttributes
        );

    }

    protected function _getDefaultAttributesList()
    {
        return array(
            'varchar' => array(
                'gift_message_available',
                'custom_design',
                'msrp_display_actual_price_type',
                'msrp_enabled',
                'options_container',
                'page_layout',
                'mpn',
                'brand_name',
                'name',
                'url_key',
                'meta_description',
                'meta_title'
            ),
            'int' => array(
                'enable_googlecheckout',
                'is_recurring',
                'links_purchased_separately',
                'links_exist',
                'status',
                'visibility',
                'tax_class_id',
                'color',
                'price_view',
                'manufacturer'
            ),
            'text' => array(
                'recurring_profile',
                'description',
                'custom_layout_update',
                'meta_keyword',
                'short_description'
            ),
            'decimal' => array(
                'cost',
                'group_price',
                'weight',
                'special_price',
                'msrp'
            ),
            'datetime' => array(
                'custom_design_from',
                'custom_design_to',
                'news_from_date',
                'news_to_date',
                'special_from_date',
                'special_to_date'
            ),
            'spec' => array(
                'is_qty_decimal' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'is_qty_decimal'
                ),
                'use_config_min_qty' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'use_config_min_qty'
                ),
                'use_config_min_sale_qty' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'use_config_min_sale_qty'
                ),
                'use_config_max_sale_qty' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'use_config_max_sale_qty'
                ),
                'use_config_manage_stock' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'use_config_manage_stock'
                ),
                'is_decimal_divided' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'is_decimal_divided'
                ),
                'use_config_backorders' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'use_config_backorders'
                ),
                'use_config_notify_stock_qty' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'use_config_notify_stock_qty'
                ),
                'max_sale_qty' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'max_sale_qty'
                ),
                'min_sale_qty' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'min_sale_qty'
                ),
                'notify_stock_qty' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'notify_stock_qty'
                ),
                'backorders' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'backorders'
                ),
                'created_at' => array(
                    'prod_id_field' => 'entity_id',
                    'table' => 'catalog_product_entity',
                    'field' => 'created_at'
                ),
                'min_qty' => array(
                    'prod_id_field' => 'product_id',
                    'table' => 'cataloginventory_stock_item',
                    'field' => 'min_qty'
                ),
                'updated_at' => array(
                    'prod_id_field' => 'entity_id',
                    'table' => 'catalog_product_entity',
                    'field' => 'updated_at'
                )
            )
        );
    }

    // Count child categories products and set them inactive if they have no products
    public function CountChildProd($child_cat)
    {
        foreach ($child_cat as $cat) {
            $query = "SELECT `entity_id` FROM `" . $this->_tablePrefix . "catalog_category_entity` WHERE parent_id = :cat_id";
            $child_cat = $this->_connRes->fetchAll($query, array(
                ':cat_id' => $cat['entity_id']
            ));

            $query = "SELECT COUNT(*) FROM `" . $this->_tablePrefix . "catalog_category_product` WHERE category_id = :cat_id ";
            $cat_products = $this->_connRes->fetchRow($query, array(
                ':cat_id' => $cat['entity_id']
            ));

            if ($cat_products['COUNT(*)'] == 0 && empty($child_cat)) {
                $this->_connRes->query("UPDATE `" . $this->_tablePrefix . "catalog_category_entity_int`
                               SET `value` = 0 WHERE `attribute_id` = @category_active_id AND entity_id = :cat_id", array(
                        ':cat_id' => $cat['entity_id']
                    ));
            } else if (!empty($child_cat)) {
                $this->CountChildProd($child_cat);
            }
        }
    }

}