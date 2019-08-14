<?php
/**
 * Import Multiple Images during Product Import
 * Copyright ? 2010 Web Design by Capacity Web Solutions Pvt. Ltd. All Rights Reserved.
 * http://www.capacitywebsolutions.com
 */


class Capacitywebsolutions_Importproduct_Model_Convert_Adapter_Product extends Mage_Catalog_Model_Convert_Adapter_Product
{
  protected $_categoryCache = array();
  protected $_connRes       = null;
  protected $_tablePrefix   = '';

  public function saveRow(array $importData) {

    if (!empty($importData['mpn']) && !empty($importData['brand_name']) && !empty($importData['categories'])) {
      // custom logic
      $this->_connRes = Mage::getSingleton('core/resource')->getConnection('core_write');

      $tablePrefix = (array)Mage::getConfig()->getTablePrefix();
      if (!empty($tablePrefix)) {
        $this->_tablePrefix = $tablePrefix[0];
      }

      $this->_coreSaveRow($importData);
	  //$this->_capacitySaveRow($importData);
    } else {
      // old logic
      $this->_capacitySaveRow($importData);
    }
  }

  protected function _coreSaveRow(array $importData){
    if (empty($importData['store'])) {
      if (!is_null($this->getBatchParams('store'))) {
        $store = $this->getStoreById($this->getBatchParams('store'));
      } else {
        $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'store');
        Mage::throwException($message);
      }
    }else {
      $store = $this->getStoreByCode($importData['store']);
    }

    if ($store === false) {
      $message = Mage::helper('catalog')->__('Skip import row, store "%s" not exists', $importData['store']);
      Mage::throwException($message);
    }

  	$storeId = $store->getId();

  	$websiteId = false;
  	if (!empty($importData['websites'])) {
  	  $website = Mage::app()->getWebsite(trim($importData['websites']));
  	  $websiteId = $website->getId();
  	} else {
      $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'website');
      Mage::throwException($message);  	  
  	}

    if (empty($importData['sku'])) {
      $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'sku');
      Mage::throwException($message);
    }

	// attributes set up
    $this->_connRes->query("
      SELECT @product_entity_type_id   := entity_type_id FROM " . $this->_tablePrefix . "eav_entity_type WHERE entity_type_code = 'catalog_product';
	  SELECT @category_entity_type_id  := entity_type_id FROM " . $this->_tablePrefix . "eav_entity_type WHERE entity_type_code = 'catalog_category';
      SELECT @attribute_set_id         := entity_type_id FROM " . $this->_tablePrefix . "eav_entity_type WHERE entity_type_code = 'catalog_product';
      SELECT @attribute_set_id         := `attribute_set_id` FROM eav_attribute_set
                                          WHERE attribute_set_name = 'Default' AND entity_type_id = 
                                          (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product');

      SELECT @name_id       := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'name'              AND entity_type_id = @product_entity_type_id;
      SELECT @mpn_id        := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'mpn'               AND entity_type_id = @product_entity_type_id;
      SELECT @brand_id      := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'brand_name'        AND entity_type_id = @product_entity_type_id;
      SELECT @desc_id       := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'description'       AND entity_type_id = @product_entity_type_id;
      SELECT @sh_desc_id    := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'short_description' AND entity_type_id = @product_entity_type_id;
      SELECT @sku_id        := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'sku'               AND entity_type_id = @product_entity_type_id;
      SELECT @weight_id     := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'weight'            AND entity_type_id = @product_entity_type_id;
      SELECT @status_id     := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'status'            AND entity_type_id = @product_entity_type_id;
      SELECT @url_key_id    := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'url_key'           AND entity_type_id = @product_entity_type_id;
      SELECT @visibility_id := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'visibility'        AND entity_type_id = @product_entity_type_id;
      SELECT @price_id      := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'price'             AND entity_type_id = @product_entity_type_id;
	  SELECT @stock_id      := `stock_id`     FROM `" . $this->_tablePrefix . "cataloginventory_stock` where stock_name = 'Default';
      SELECT @delivery_id   := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'delivery_eta'      AND entity_type_id = @product_entity_type_id;

	  SELECT @unspcs_id            := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'unspsc'            AND entity_type_id = @category_entity_type_id;
	  SELECT @category_name_id     := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'name'              AND entity_type_id = @category_entity_type_id;
	  SELECT @category_active_id   := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'is_active'         AND entity_type_id = @category_entity_type_id;
	  SELECT @include_nav_bar_id   := `attribute_id` FROM `" . $this->_tablePrefix . "eav_attribute` WHERE  `attribute_code` = 'include_in_menu'         AND entity_type_id = @category_entity_type_id;	  
	");

	$prodIdFetch = $this->_connRes->fetchRow('SELECT entity_id FROM catalog_product_entity WHERE sku = :sku limit 1' , array(':sku' => $importData['sku']));
	$productId   = $prodIdFetch['entity_id'];

  	if (!empty($productId)) {
        // check import type (Import only price & qty or all product info)
        if('import_price_stock' == Mage::getStoreConfig('importprod_root/importprod/import_only_prices_stock',
          $storeId)) {
      		$this->_corePriceStock($websiteId, $productId, $importData['price'], $importData['qty']);
          return true;
        }
  	} else {
  	  $productId = false;
  	}

    // get category id
    if (isset($importData['categories'])) {
      $categoriesToActiveConf = Mage::getStoreConfig('importprod_root/importprod/category_active', 
                                  $storeId);

      if (!empty($importData['leader_categories'])) {
        $leader_categories  = $importData['leader_categories'];
        $leader_store       = $this->getStoreByCode($importData['leader_store']);
      } else {
        $leader_categories = '';
        $leader_store      = '';
      }

      $unspsc = '';
      if (!empty($importData['unspsc'])) {
        $unspsc = $importData['unspsc'];
      }
      
      $unspscPath = '';
      if (!empty($importData['unspsc_path'])) {
        $unspscPath = $importData['unspsc_path'];
      } else {
        $message = Mage::helper('catalog')->__('Skip import. Category UNSPSC not defined in store');
        Mage::throwException($message);
      }
      $categoryId = $this->_addCategories($importData['categories'], $storeId, $importData['leader_store'], $unspsc, $unspscPath, $categoriesToActiveConf);
  	}

  	// agregate product data
  	$productData = array();
  	$productData['websiteId']           = $websiteId;
  	$productData['storeId']             = $storeId;
  	$productData['name']                = $importData['name'];
  	$productData['sku']                 = $importData['sku'];
  	$productData['mpn']                 = $importData['mpn'];
  	$productData['brand_name']          = $importData['brand_name'];
  	$productData['short_description']   = $importData['short_description'];
  	$productData['description']         = $importData['description'];
  	$productData['store']               = $importData['store'];
  	$productData['price']               = $importData['price'];
  	$productData['qty']                 = $importData['qty'];
  	$productData['weight']              = $importData['weight'];
  	$productData['status']              = $importData['status'];
  	$productData['visibility']          = $importData['visibility'];
  	$productData['is_in_stock']         = $importData['is_in_stock'];
  	$productData['delivery_eta']        = $importData['delivery_eta'];
  	$productData['productId']           = $productId;
  	$productData['categoryId']          = $categoryId;

  	return (bool)$this->_coreSave($productData);
  }

  protected function _capacitySaveRow(array $importData){
       $product = $this->getProductModel()
            ->reset();

        if (empty($importData['store'])) {
            if (!is_null($this->getBatchParams('store'))) {
                $store = $this->getStoreById($this->getBatchParams('store'));
            } else {
                $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'store');
                Mage::throwException($message);
            }
        }
        else {
             $store = $this->getStoreByCode($importData['store']);
        }
 
        if ($store === false) {
            $message = Mage::helper('catalog')->__('Skip import row, store "%s" not exists', $importData['store']);
            Mage::throwException($message);
        }
 
        if (empty($importData['sku'])) {
            $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'sku');
            Mage::throwException($message);
        }
        $product->setStoreId($store->getId());
        $productId = $product->getIdBySku($importData['sku']);
 
        if ($productId) {
            $product->load($productId);
            
            // check import type (Import only price & qty or all product info)
            if('import_price_stock' == Mage::getStoreConfig('importprod_root/importprod/import_only_prices_stock',
                                                  $storeId)) {
              $product->setPrice($importData['price']);
              $product->setStockData(array('qty' => $importData['qty']));
              $product->save();
              return true;
            }
            
        }
        else {
            $productTypes = $this->getProductTypes();
            $productAttributeSets = $this->getProductAttributeSets();
 
            /**
             * Check product define type
             */
            if (empty($importData['type']) || !isset($productTypes[strtolower($importData['type'])])) {
                $value = isset($importData['type']) ? $importData['type'] : '';
                $message = Mage::helper('catalog')->__('Skip import row, is not valid value "%s" for field "%s"', $value, 'type');
                Mage::throwException($message);
            }
            $product->setTypeId($productTypes[strtolower($importData['type'])]);
            /**
             * Check product define attribute set
             */
            if (empty($importData['attribute_set']) || !isset($productAttributeSets[$importData['attribute_set']])) {
                /*$value = isset($importData['attribute_set']) ? $importData['attribute_set'] : '';
                $message = Mage::helper('catalog')->__('Skip import row, is not valid value "%s" for field "%s"', $value, 'attribute_set');
                Mage::throwException($message);*/
            }
            $product->setAttributeSetId($productAttributeSets[$importData['attribute_set']]);
 
            foreach ($this->_requiredFields as $field) {
                $attribute = $this->getAttribute($field);
                if (!isset($importData[$field]) && $attribute && $attribute->getIsRequired()) {
                    $message = Mage::helper('catalog')->__('Skip import row, required field "%s" for new products not defined', $field);
                    Mage::throwException($message);
                }
            }
        }
 
        $this->setProductTypeInstance($product);

        if (isset($importData['category_ids'])) {
            $product->setCategoryIds($importData['category_ids']);
        }
 	/*	if category name is in csv file		*/
        if (isset($importData['categories'])) {
	
          
          //get IceImport configs
          $storeId = $store->getId();
          $categoriesToActiveConf = Mage::getStoreConfig('importprod_root/importprod/category_active', 
                                                             $storeId);
          $cronScheduleConf       = Mage::getStoreConfig('importprod_root/importprod/import_schedule',
                                                             $storeId);
          $importImagesConf       = Mage::getStoreConfig('importprod_root/importprod/import_images',
                                                             $storeId);
          
          
          
          
          if (!empty($importData['leader_categories'])) {
            $leader_categories  = $importData['leader_categories'];
            $leader_store       = $this->getStoreByCode($importData['leader_store']);
          } else {
            $leader_categories = '';
            $leader_store      = '';
          }
                        
          $unspsc = '';
          if (!empty($importData['unspsc'])) {
            $unspsc = $importData['unspsc'];
          }
          
          $unspscPath = '';
          if (!empty($importData['unspsc_path'])) {
            $unspscPath = $importData['unspsc_path'];
          } else {
            $message = Mage::helper('catalog')->__('Skip import. Category UNSPSC not defined in store');
            Mage::throwException($message);
          }

          $categoryIds = $this->_addCategories($importData['categories'], $store, $leader_store, $unspsc, $unspscPath, $categoriesToActiveConf);
          if ($categoryIds) {
              
            // check, that's product exist
            $oldProductId = $product->getIdBySku($importData['sku']);

            if ($oldProductId) {
              $oldCategoryIds = Mage::getModel('catalog/product')
                                ->load($oldProductId)
                                ->getCategoryIds();
              $categoryIds .= ','.implode(',', $oldCategoryIds);
            }
              
            $product->setCategoryIds($categoryIds);
          }
        }
        foreach ($this->_ignoreFields as $field) {
            if (isset($importData[$field])) {
                unset($importData[$field]);
            }
        }
 
        if ($store->getId() != 0) {
            $websiteIds = $product->getWebsiteIds();
            if (!is_array($websiteIds)) {
                $websiteIds = array();
            }
            if (!in_array($store->getWebsiteId(), $websiteIds)) {
                $websiteIds[] = $store->getWebsiteId();
            }
            $product->setWebsiteIds($websiteIds);
        }
 
        if (isset($importData['websites'])) {
            $websiteIds = $product->getWebsiteIds();
            if (!is_array($websiteIds)) {
                $websiteIds = array();
            }
            $websiteCodes = explode(',', $importData['websites']);
            foreach ($websiteCodes as $websiteCode) {
                try {
                    $website = Mage::app()->getWebsite(trim($websiteCode));
                    if (!in_array($website->getId(), $websiteIds)) {
                        $websiteIds[] = $website->getId();
                    }
                }
                catch (Exception $e) {}
            }
            $product->setWebsiteIds($websiteIds);
            unset($websiteIds);
        }
 
        foreach ($importData as $field => $value) {
            if (in_array($field, $this->_inventoryFields)) {
                continue;
            }
            if (in_array($field, $this->_imageFields)) {
                continue;
            }
            $attribute = $this->getAttribute($field);
          	if (!$attribute) {

				if(strpos($field,':')!==FALSE && strlen($value)) {
				   $values=explode('|',$value);
				   if(count($values)>0) {
					  @list($title,$type,$is_required,$sort_order) = explode(':',$field);
					  $title = ucfirst(str_replace('_',' ',$title));
					  $custom_options[] = array(
						 'is_delete'=>0,
						 'title'=>$title,
						 'previous_group'=>'',
						 'previous_type'=>'',
						 'type'=>$type,
						 'is_require'=>$is_required,
						 'sort_order'=>$sort_order,
						 'values'=>array()
					  );
					  foreach($values as $v) {
						 $parts = explode(':',$v);
						 $title = $parts[0];
						 if(count($parts)>1) {
							$price_type = $parts[1];
						 } else {
							$price_type = 'fixed';
						 }
						 if(count($parts)>2) {
							$price = $parts[2];
						 } else {
							$price =0;
						 }
						 if(count($parts)>3) {
							$sku = $parts[3];
						 } else {
							$sku='';
						 }
						 if(count($parts)>4) {
							$sort_order = $parts[4];
						 } else {
							$sort_order = 0;
						 }
						 switch($type) {
							case 'file':
							   /* TODO */
							   break;
							   
							case 'field':
							case 'area':
							   $custom_options[count($custom_options) - 1]['max_characters'] = $sort_order;
							   /* NO BREAK */
							   
							case 'date':
							case 'date_time':
							case 'time':
							   $custom_options[count($custom_options) - 1]['price_type'] = $price_type;
							   $custom_options[count($custom_options) - 1]['price'] = $price;
							   $custom_options[count($custom_options) - 1]['sku'] = $sku;
							   break;
														  
							case 'drop_down':
							case 'radio':
							case 'checkbox':
							case 'multiple':
							default:
							   $custom_options[count($custom_options) - 1]['values'][]=array(
								  'is_delete'=>0,
								  'title'=>$title,
								  'option_type_id'=>-1,
								  'price_type'=>$price_type,
								  'price'=>$price,
								  'sku'=>$sku,
								  'sort_order'=>$sort_order,
							   );
							   break;
						 }
					  }
				   }
				}

                continue;
            }
 
            $isArray = false;
            $setValue = $value;
 
            if ($attribute->getFrontendInput() == 'multiselect') {
                $value = explode(self::MULTI_DELIMITER, $value);
                $isArray = true;
                $setValue = array();
            }
 
            if ($value && $attribute->getBackendType() == 'decimal') {
                $setValue = $this->getNumber($value);
            }
 			
		
            if ($attribute->usesSource()) {
                $options = $attribute->getSource()->getAllOptions(false);
 
                if ($isArray) {
                    foreach ($options as $item) {
                        if (in_array($item['label'], $value)) {
                            $setValue[] = $item['value'];
                        }
                    }
                }
                else {
                    $setValue = null;
                    foreach ($options as $item) {
                        if ($item['label'] == $value) {
                            $setValue = $item['value'];
                        }
                    }
                }
            }
 
            $product->setData($field, $setValue);
        }
 
        if (!$product->getVisibility()) {
            $product->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE);
        }
 
        $stockData = array();
        $inventoryFields = isset($this->_inventoryFieldsProductTypes[$product->getTypeId()])
            ? $this->_inventoryFieldsProductTypes[$product->getTypeId()]
            : array();
        foreach ($inventoryFields as $field) {
            if (isset($importData[$field])) {
                if (in_array($field, $this->_toNumber)) {
                    $stockData[$field] = $this->getNumber($importData[$field]);
                }
                else {
                    $stockData[$field] = $importData[$field];
                }
            }
        }
        $product->setStockData($stockData);
 
        $imageData = array();
        foreach ($this->_imageFields as $field) {
            if (!empty($importData[$field]) && $importData[$field] != 'no_selection') {
                if (!isset($imageData[$importData[$field]])) {
                    $imageData[$importData[$field]] = array();
                }
                $imageData[$importData[$field]][] = $field;
            }
        }
 
        foreach ($imageData as $file => $fields) {
            try {
                $product->addImageToMediaGallery(Mage::getBaseDir('media') . DS . 'import' . $file, $fields);
            }
            catch (Exception $e) {}
        }
 
		/**
		 * Allows you to import multiple images for each product.
		 * Simply add a 'gallery' column to the import file, and separate
		 * each image with a semi-colon.
		 */
	        try {
	                $galleryData = explode(';',$importData["gallery"]);
	                foreach($galleryData as $gallery_img)
					/**
					 * @param directory where import image resides
					 * @param leave 'null' so that it isn't imported as thumbnail, base, or small
					 * @param false = the image is copied, not moved from the import directory to it's new location
					 * @param false = not excluded from the front end gallery
					 */
	                {
	                        $product->addImageToMediaGallery(Mage::getBaseDir('media') . DS . 'import' . $gallery_img, null, false, false);
	                }
	            }
	        catch (Exception $e) {}        
		/* End Modification */
 
        $product->setIsMassupdate(true);
        $product->setExcludeUrlRewrite(true);
 
        $product->save();
		 /* Add the custom options specified in the CSV import file 	*/
		
		if(isset($custom_options)){
		if(count($custom_options)) {
		   foreach($custom_options as $option) {
			  try {
				$opt = Mage::getModel('catalog/product_option');
				$opt->setProduct($product);
				$opt->addOption($option);
				$opt->saveOptions();
			  }
			  catch (Exception $e) {}
		   }
		}
		}
        return true;
  }

  protected function _coreSave(array $productData) {
    $preUrl = explode(' ', strtolower($productData['name']));
	$url    = implode('_', $preUrl) . '_' . $productData['store'];

	if ($productData['productId'] === false) {
	  // add product to store
	  $coreSaveProduct = "INSERT INTO `" . $this->_tablePrefix . "catalog_product_entity` (`entity_type_id`, `attribute_set_id`, `type_id`, `sku`) VALUES
	     (@product_entity_type_id, @attribute_set_id, 'simple', :sku)
	    ON DUPLICATE KEY UPDATE
	     `entity_type_id`   = @product_entity_type_id,
	     `attribute_set_id` = @attribute_set_id,
	     `type_id`          = 'simple',
	     `sku`              = :sku;

        SELECT @product_id := LAST_INSERT_ID();
	  ";

	  $this->_connRes->query($coreSaveProduct, array(':sku' => $productData['sku']));
	  // get product ID
      $prodFetch = $this->_connRes->fetchRow('SELECT @product_id AS prod_id');
      $productId = $prodFetch['prod_id'];
	} else {
	  $productId = (int)$productData['productId'];
	  $coreSaveSQL .= "SELECT @product_id := " . $productId . "; ";
	}
    // eav varchar
    $coreSaveSQL .= "
      INSERT INTO `" . $this->_tablePrefix . "catalog_product_entity_varchar` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) VALUES
        (@product_entity_type_id, @name_id,     0, @product_id, :name),
        (@product_entity_type_id, @mpn_id,      0, @product_id, :mpn),
        (@product_entity_type_id, @brand_id,    0, @product_id, :brand_name),
        (@product_entity_type_id, @url_key_id,  0, @product_id, :url),
		(@product_entity_type_id, @delivery_id, 0, @product_id, :delivery_eta),
        (@product_entity_type_id, @name_id,     :store_id, @product_id, :name),
        (@product_entity_type_id, @mpn_id,      :store_id, @product_id, :mpn),
        (@product_entity_type_id, @brand_id,    :store_id, @product_id, :brand_name),
        (@product_entity_type_id, @url_key_id,  :store_id, @product_id, :url),
		(@product_entity_type_id, @delivery_id, :store_id, @product_id, :delivery_eta)
      ON DUPLICATE KEY UPDATE
	    `entity_type_id` = VALUES (`entity_type_id`),
	    `attribute_id`   = VALUES (`attribute_id`),
	    `store_id`       = VALUES (`store_id`),
	    `entity_id`      = VALUES (`entity_id`),
	    `value`          = VALUES (`value`);
	";
	
	// eav text
	$coreSaveSQL .= "
      INSERT INTO `" . $this->_tablePrefix . "catalog_product_entity_text` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`)  VALUES 
        (@product_entity_type_id, @desc_id,    0, @product_id, :description),
        (@product_entity_type_id, @sh_desc_id, 0, @product_id, :short_description),
        (@product_entity_type_id, @desc_id,    :store_id, @product_id, :description),
        (@product_entity_type_id, @sh_desc_id, :store_id, @product_id, :short_description)
      ON DUPLICATE KEY UPDATE
	    `entity_type_id` = VALUES(`entity_type_id`),
	    `attribute_id`   = VALUES(`attribute_id`),
	    `store_id`       = VALUES(`store_id`),
	    `entity_id`      = VALUES(`entity_id`),
	    `value`          = VALUES(`value`);
	";

	// eav decimal
	$coreSaveSQL .= "
      INSERT INTO `" . $this->_tablePrefix . "catalog_product_entity_decimal` (`entity_type_id`,`attribute_id`,`store_id`, `entity_id`, `value`) VALUES
        (@product_entity_type_id, @weight_id, 0, @product_id, :weight)
      ON DUPLICATE KEY UPDATE
	    `entity_type_id` = @product_entity_type_id,
	    `attribute_id`   = @weight_id,
	    `store_id`       = 0,
	    `entity_id`      = @product_id, 
	    `value`          = :weight;
	";

	// eav int
	$coreSaveSQL .= "
      INSERT INTO `" . $this->_tablePrefix . "catalog_product_entity_int` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) VALUES
        (@product_entity_type_id, @status_id,     0, @product_id, :status),
        (@product_entity_type_id, @visibility_id, 0, @product_id, :visibility),
        (@product_entity_type_id, @status_id,     :store_id, @product_id, :status),
        (@product_entity_type_id, @visibility_id, :store_id, @product_id, :visibility)
       ON DUPLICATE KEY UPDATE
	      `entity_type_id` = VALUES(`entity_type_id`), 
		    `attribute_id`   = VALUES(`attribute_id`),
		    `store_id`       = VALUES(`store_id`),
		    `entity_id`      = VALUES(`entity_id`), 
		    `value`          = VALUES(`value`);
	";

	// categories
	$categoryId = $productData['categoryId'];
	$coreSaveSQL .= "
	  INSERT INTO `" . $this->_tablePrefix . "catalog_category_product` (`category_id`, `product_id`, `position`) VALUES 
	  (" . (int)$categoryId . ", @product_id, 1);";

    if ($productData['status'] == 'Enabled') {
      $productData['status'] = 1;
    } else {
      $productData['status'] = 0;
    }

    if ($productData['visibility'] == 'Catalog, Search') {
      $productData['visibility'] = 4;
    } else {
      $productData['visibility'] = 1;
    }

	try{
	  $this->_connRes->query($coreSaveSQL, array(
        ':store_id'          => $productData['storeId'],
        ':sku'               => $productData['sku'],
        ':name'              => $productData['name'],
        ':mpn'               => $productData['mpn'],
        ':brand_name'        => $productData['brand_name'],
        ':description'       => $productData['description'],
        ':short_description' => $productData['short_description'],
        ':weight'            => $productData['weight'],
        ':status'            => $productData['status'],
        ':visibility'        => $productData['visibility'],
        ':url'               => $url,
        ':delivery_eta'      => $productData['delivery_eta']
	  ));
	  
    } catch (Exception $e) {
      echo $e->getMessage();
    }

	  $this->_corePriceStock($productData['websiteId'], $productId, $productData['price'], $productData['qty']);

  }

  protected function _corePriceStock($website = false, $productId =false, $price =false, $qty =false) {

      $stockSaveSQL = "
        INSERT INTO `" . $this->_tablePrefix . "cataloginventory_stock_item` (`product_id`, `stock_id`, `qty`, `is_in_stock`) VALUES
          (:product_id, (SELECT stock_id FROM `cataloginventory_stock` where stock_name = 'Default'), :qty,1)
		ON DUPLICATE KEY UPDATE
		  `product_id`  = :product_id,
		  `stock_id`    = (SELECT stock_id FROM `cataloginventory_stock` where stock_name = 'Default'),
		  `qty`         = :qty,
		  `is_in_stock` = 1;
		
        INSERT INTO `" . $this->_tablePrefix . "cataloginventory_stock_status` (`product_id`, `website_id`, `stock_id`, `qty`, `stock_status`) VALUES
         (:product_id, :webisteId, @stock_id, :qty, 1)
		ON DUPLICATE KEY UPDATE
		  `product_id`   = :product_id, 
		  `website_id`   = :webisteId, 
		  `stock_id`     = @stock_id, 
		  `qty`          = :qty,
		  `stock_status` = 1;

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

      $this->_connRes->query($stockSaveSQL, array(
        ':webisteId'  => ($website) ? $website : 0,
        ':product_id' => $productId,
        ':price'      => $price,
        ':qty'        => $qty        
      )); 
  }
  
  protected function _addCategories($categories, $storeId, $leader_store, $unspsc, $unspscPath, $categoryActive = 1) {
    // check if product exists
    $categoryId = $this->_getCategoryIdByUnspsc($unspsc);
    if (!empty($categoryId)) {
      if ( 1 == $categoryActive) {
        $unspscArray = explode('/', $unspscPath);
        if ($unspscArray) {
          $activeSetter = "INSERT INTO `" . $this->_tablePrefix . "catalog_category_entity_int` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) VALUES ";
        }
        foreach($unspscArray as $cat_unspsc) {
          $categoryParrentId = $this->_getCategoryIdByUnspsc($cat_unspsc);
          $activeSetter .= "(@category_entity_type_id, @category_active_id, :store_id, " . $categoryParrentId . ", 1), 
                            (@category_entity_type_id, @category_active_id, 0, " . $categoryParrentId . ", 1), ";
        }
        $activeSetter = substr($activeSetter, 0, -2);
        $activeSetter .= "
          ON DUPLICATE KEY UPDATE
          `value` = 1
        ";
        $this->_connRes->query($activeSetter, array(':store_id' => $storeId));
      }
      return $categoryId;
    } else {

      // merge unspcs to current name in unspcs & name path's
      $nameArray   = explode('/', $categories);
      $unspscArray = explode('/', $unspscPath);

      if (count($nameArray) != count($unspscArray)) {
        $message = Mage::helper('catalog')->__('Skip import row, categories data is invaled');
        Mage::throwException($message);
      }

      $categoryMergedArray = array(
        array(
          'unspsc' => 'default_root',
          'name'   => 'Default category'
        )
      );

      for($i = 0; $i < count($unspscArray); $i++) {
        $categoryMergedArray[] = array('name'   =>$nameArray[$i],
                                       'unspsc' =>$unspscArray[$i]);
      }

      // get max created parrent category
      $categoryCreateArray = array();
      for ($i = count($categoryMergedArray) -1; $i >= 0; $i--) {
        $category        = $categoryMergedArray[$i];
        $checkCategoryId = $this->_getCategoryIdByUnspsc($category['unspsc']);
        if ($checkCategoryId != null) {
          $categoryId = $this->_buildCategoryTree($checkCategoryId, $storeId, $categoryCreateArray, $categoryActive);
          break;
        } else {
          $categoryCreateArray[] = $category;
        } 
      }
      return $categoryId;
    }
  }

  protected function _getCategoryIdByUnspsc($unspcs) {
    if ($unspcs == 'default_root') {
      return Mage::app()->getStore(1)->getRootCategoryId();
    } else {
      $categoryId = $this->_connRes->fetchRow("SELECT entity_id FROM `" . $this->_tablePrefix . "catalog_category_entity_varchar` WHERE 
  	                                           `value` = :unspsc AND attribute_id = @unspcs_id", array(':unspsc' => $unspcs));
      return ($categoryId['entity_id']) ? $categoryId['entity_id'] : null;
    }
  }
  
  protected function _buildCategoryTree($parrentCategoryId, $storeId, $pathArray, $categoryActive = 0) {
	for ($i = count($pathArray) -1; $i >= 0; $i--) {
	  $category = $pathArray[$i];
	  $parrentCategoryId = $this->_createCategory($parrentCategoryId, $category['unspsc'], $storeId, $category['name'], $categoryActive);
	}

	return $parrentCategoryId;
  }

  protected function _createCategory($parrentId, $unspsc, $storeId, $name, $categoryActive = 0) {

    $addCategory = "
	  SELECT @tPath := `path`, @tLevel := `level` FROM `" . $this->_tablePrefix . "catalog_category_entity` WHERE `entity_id` = :parrent_id;
	  SET @tLevel = @tLevel +1;

	  SET @path := CONCAT(@tPath, '/',(SELECT MAX(entity_id) FROM `catalog_category_entity`) +1 );
	  
	  INSERT INTO `" . $this->_tablePrefix . "catalog_category_entity` (`entity_type_id`, `attribute_set_id`, 
	                                                                    `parent_id`, `created_at`, 
																		`path`, `position`, 
																		`level`, `children_count`)
      VALUES
	  (@category_entity_type_id, 0, :parrent_id, NOW(), @path, 1, @tLevel, 0);
	  
	  SELECT @catId := LAST_INSERT_ID();
	  
	  UPDATE `" . $this->_tablePrefix . "catalog_category_entity` SET children_count = children_count +1 WHERE entity_id = :parrent_id;
	  
	  INSERT IGNORE INTO `" . $this->_tablePrefix . "catalog_category_entity_int` (`entity_type_id`, `attribute_id`,
                                                                            `store_id`, `entity_id`, `value`)
	  VALUES
	    (@category_entity_type_id, @category_active_id, 0,      @catId, :category_active),
	    (@category_entity_type_id, @category_active_id, :store, @catId, :category_active),
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
	  ':store'            => $storeId,
	  ':parrent_id'       => $parrentId,
	  ':category_name'    => $name,
	  ':unspsc_val'       => $unspsc,
	  ':category_active' => (int)$categoryActive
	));

	$categoryIdFetch = $this->_connRes->fetchRow('SELECT @catId AS category_id');
	return $categoryIdFetch['category_id'];
  }
}
