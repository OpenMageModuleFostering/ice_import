<?php

/**
 * Import Product with additional attributes
 *
 *
 */
class ICEshop_Iceimport_Model_Convert_Adapter_Product extends Mage_Catalog_Model_Convert_Adapter_Product
{
    /**
     * @var array
     */
    protected $_categoryCache = array();

    /**
     * @var null
     */
    protected $_connRes = null;

    /**
     * @var string
     */
    protected $_tablePrefix = '';
    protected $_counter = 0;

    /**
     * @var
     */
    protected $_refreshSettings;

    /**
     * Import process ID
     *
     * @var int
     */
    protected $process_id = 'iceshop_iceimport';

    /**
     * Init class
     */
    public function __construct()
    {

        $this->_connRes = Mage::getSingleton('core/resource')->getConnection('core_write');
        $tablePrefix = (array)Mage::getConfig()->getTablePrefix();
        if (!empty($tablePrefix)) {
            $this->_tablePrefix = $tablePrefix[0];
        }

    }

    /**
     * @param $storeId
     */

    private function _initRefreshSettings($storeId)
    {
        $this->_refreshSettings = new Varien_Object();
        $this->_refreshSettings->setData('categories', (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_categories_from_csv', $storeId));
        $this->_refreshSettings->setData('status', (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_status_from_csv', $storeId));
        $this->_refreshSettings->setData('visibility', (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_visibility_from_csv', $storeId));
        $this->_refreshSettings->setData('is_in_stock', (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_is_in_stock_from_csv', $storeId));
        $this->_refreshSettings->setData('update_hide_category', (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_hide_category', $storeId));
        $this->_refreshSettings->setData('url_key', (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_url_key_from_csv', $storeId));
        $this->_refreshSettings->setData('import_new_products', (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_new_products', $storeId));
    }

    /**
     * @param $key
     * @return mixed
     */
    private function _getRefreshSetting($key)
    {
        $key = Mage::helper('iceimport')->toCamelCase($key, true);
        return $this->_refreshSettings->{'get' . $key}();
    }

    /**
     * @param array $importData
     * @return bool
     * @throws Mage_Core_Exception
     */
    public function saveRow(array $importData, $manually = false)
    {

        $DB_helper = Mage::helper('iceimport/db');

        $DB_logger = Mage::helper('iceimport/db');

        $transactions_enabled = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/transactions_enabled');

        if (empty($importData[0]['batchId'])) {
            $batchId = Mage::getSingleton('core/app')->getRequest()->getPost('batch_id', 0);
        } else {
            $batchId = (int)$importData[0]['batchId'];
        }

        // set store id

        if (empty($importData[0]['store'])) {
            if (!is_null($this->getBatchParams('store'))) {
                $store = $this->getStoreById($this->getBatchParams('store'));
            } else {
                $message = Mage::helper('catalog')->__('Skip import, required field "%s" not defined', 'store');
                Mage::throwException($message);
            }
        }

        if (empty($importData[0]['attribute_set'])) {
            $message = Mage::helper('catalog')->__('Skip import, required field "%s" not defined', 'attribute_set');
            Mage::throwException($message);
        }

        if (empty($store)) {
            $store = Mage::app()->getStore(trim($importData[0]['store']));
        }

        if ($store === false) {
            $message = Mage::helper('catalog')->__('Skip import, store "%s" not exists', $importData[0]['store']);
            Mage::throwException($message);
        }

        $storeId = $store->getId();

        $stock_name = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/stock_inventory');

        $defaulttaxConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/default_tax', $storeId);

        $stockConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_stock', $storeId);
        $priceConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_prices', $storeId);

        $categoriesToActiveConf = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/category_active', $storeId);

        $batch_size = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/iceimport_batch_size', $storeId);

        $addImages = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_images');

        //init refresh settings values
        $this->_initRefreshSettings($storeId);

        if ($transactions_enabled != 0 && !$manually) {
            $this->_connRes->beginTransaction();
        }

        foreach($importData as $key => $importDatum) {

            $sortedProductData = $this->_mapAttributes($importDatum);

            $productData = $sortedProductData['productData'];
            $iceimportAttributes = $sortedProductData['iceimportAttributes'];


            $DB_helper->deleteLogEntry('try_delete_product_percentage_warning_flag');
            if ($batchId != $DB_helper->getConfigData($this->process_id . '_flag')) {
                //set flag, first iteration
                $DB_helper->unsetConfigData("import_total");
                $DB_helper->unsetConfigData("counter");
                $DB_helper->unsetConfigData("skipped_counter");
                $DB_helper->setConfigData($this->process_id . '_flag', $batchId);
            }

            $import_total = $DB_helper->getConfigData("import_total");
            $counter = $DB_helper->getConfigData("counter");
            $skipped_counter = $DB_helper->getConfigData("skipped_counter");

            if (empty($import_total)) {
                $batchModel = Mage::getModel('dataflow/batch')->load($batchId);
                $batchImportModel = $batchModel->getBatchImportModel();
                $importIds = $batchImportModel->getIdCollection();
                $import_total = count($importIds);
                $DB_helper->setConfigData("import_total", (int)$import_total);
            }
            if (empty($counter)) {
                $DB_helper->setConfigData("counter", 1);
                $counter = $DB_helper->getConfigData("counter");
            }
            if (empty($skipped_counter)) {
                $DB_helper->setConfigData("skipped_counter", 0);
                $skipped_counter = $DB_helper->getConfigData("skipped_counter");
            }

            // mark product ice_import generic
            $productData['varchar']['is_iceimport'] = 1;

            // set sku
            if (empty($iceimportAttributes['sku'])) {
                $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'sku');
                Mage::throwException($message);
            }
            $sku = $iceimportAttributes['sku'];

            if (!empty($iceimportAttributes['categories'])) {
                if (!empty($iceimportAttributes['categories'])) {
                    $cat_names = explode('/', $iceimportAttributes['categories']);
                    foreach ($cat_names as $cat_name) {
                        if (empty($cat_name)) {
                            $message = Mage::helper('catalog')->__('Skip import row, some of categories does not have name');
                            Mage::throwException($message);
                        }
                    }
                }
            }
            if (!empty($iceimportAttributes['categories'])) {
                $category = $iceimportAttributes['categories'];
                if (empty($iceimportAttributes['unspsc'])) {
                    $message = Mage::helper('catalog')->__('Skip import. Category UNSPSC not defined in store');
                    Mage::throwException($message);
                }
                $unspsc = $iceimportAttributes['unspsc'];
                $unspsc = preg_replace("/[^a-zA-Z0-9]/", "", $unspsc);
                if (empty($iceimportAttributes['unspsc_path'])) {
                    $message = Mage::helper('catalog')->__('Skip import. Category UNSPSC path not defined in store');
                    Mage::throwException($message);
                }
                if (!empty($iceimportAttributes['unspsc_path'])) {
                    $cat_unspscs = explode('/', $iceimportAttributes['unspsc_path']);
                    foreach ($cat_unspscs as $cat_unspsc) {
                        if (empty($cat_unspsc)) {
                            $message = Mage::helper('catalog')->__('Skip import row, some of categories does not have UNSPSC');
                            Mage::throwException($message);
                        }
                    }
                }
                $unspscPath = $iceimportAttributes['unspsc_path'];
                $unspscPath = preg_replace("/[^a-zA-Z0-9\/]/", "", $unspscPath);
                if (!empty($cat_unspscs) && !empty($cat_names) && count($cat_names) != count($cat_unspscs)) {
                    $message = Mage::helper('catalog')->__('Skip import row, categories names does not match categories UNSPSC');
                    Mage::throwException($message);
                }
            }

            // set website id;
            $websites = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/websites');
            if (empty($websites)) {
                $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'websites');
                Mage::throwException($message);
            }

            $website = Mage::app()->getWebsite(trim($websites));
            $websiteId = $website->getId();

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

                // set tax_auvibel
            $tax_auvibel = 0.00;
            if (!empty($iceimportAttributes['tax_auvibel'])) {
                $tax_auvibel = $iceimportAttributes['tax_auvibel'];
            }

            // set tax_bebat
            $tax_bebat = 0.00;
            if (!empty($iceimportAttributes['tax_bebat'])) {
                $tax_bebat = $iceimportAttributes['tax_bebat'];
            }

            // set tax_recupel
            $tax_recupel = 0.00;
            if (!empty($iceimportAttributes['tax_recupel'])) {
                $tax_recupel = $iceimportAttributes['tax_recupel'];
            }

            // set tax_reprobel
            $tax_reprobel = 0.00;
            if (!empty($iceimportAttributes['tax_reprobel'])) {
                $tax_reprobel = $iceimportAttributes['tax_reprobel'];
            }

            // set status value
            $statusValue = (!empty($iceimportAttributes['status']) && $iceimportAttributes['status'] == 'Enabled') ? 1 : 2;
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

            $initAttributes = '';
            // init general attributes query
            $initAttributes .= "SELECT @product_entity_type_id := `entity_type_id`
                FROM `{$this->_tablePrefix}eav_entity_type`
                WHERE entity_type_code = 'catalog_product';";

            $initAttributes .= "SELECT @price_id := `attribute_id`
                FROM `{$this->_tablePrefix}eav_attribute`
                WHERE `attribute_code` = 'price'
                    AND entity_type_id = @product_entity_type_id;";

            $initAttributes .= "SELECT @attribute_set_id := `attribute_set_id`
                      FROM `{$this->_tablePrefix}eav_attribute_set`
                      WHERE attribute_set_name = :attribute_set
                          AND entity_type_id = @product_entity_type_id;";

            if (!empty($iceimportAttributes['categories'])) {
                $initAttributes .= "SELECT @category_entity_type_id := `entity_type_id`
                      FROM `{$this->_tablePrefix}eav_entity_type`
                      WHERE entity_type_code = 'catalog_category';";
            }

            if (!empty($iceimportAttributes['categories'])) {
                $initAttributes .= "SELECT @unspsc_id := `attribute_id`
                      FROM `{$this->_tablePrefix}eav_attribute`
                      WHERE `attribute_code` = 'unspsc'
                          AND entity_type_id = @category_entity_type_id;";

                $initAttributes .= "SELECT @category_name_id := `attribute_id`
                      FROM `{$this->_tablePrefix}eav_attribute`
                      WHERE `attribute_code` = 'name'
                          AND entity_type_id = @category_entity_type_id;";

                $initAttributes .= "SELECT @category_active_id := `attribute_id`
                      FROM `{$this->_tablePrefix}eav_attribute`
                      WHERE `attribute_code` = 'is_active'
                          AND entity_type_id = @category_entity_type_id;";

                $initAttributes .= "SELECT @include_nav_bar_id := `attribute_id`
                      FROM `{$this->_tablePrefix}eav_attribute`
                      WHERE `attribute_code` = 'include_in_menu'
                          AND entity_type_id = @category_entity_type_id;";

                $initAttributes .= "SELECT @category_is_anchor_id := `attribute_id`
                      FROM `{$this->_tablePrefix}eav_attribute`
                      WHERE `attribute_code` = 'is_anchor'
                          AND entity_type_id = @category_entity_type_id;";
            }

            $initAttributes .= "SELECT @tax_auvibel_id := `attribute_id`
                      FROM `{$this->_tablePrefix}eav_attribute`
                      WHERE `attribute_code` = 'tax_auvibel'
                          AND entity_type_id = @product_entity_type_id;";

            $initAttributes .= "SELECT @tax_bebat_id := `attribute_id`
                        FROM `{$this->_tablePrefix}eav_attribute`
                        WHERE `attribute_code` = 'tax_bebat'
                            AND entity_type_id = @product_entity_type_id;";

            $initAttributes .= "SELECT @tax_recupel_id := `attribute_id`
                        FROM `{$this->_tablePrefix}eav_attribute`
                        WHERE `attribute_code` = 'tax_recupel'
                            AND entity_type_id = @product_entity_type_id;";

            $initAttributes .= "SELECT @tax_reprobel_id := `attribute_id`
                        FROM `{$this->_tablePrefix}eav_attribute`
                        WHERE `attribute_code` = 'tax_reprobel'
                            AND entity_type_id = @product_entity_type_id;";

            if (!empty($stock_name)) {
                $stock_id = $this->_connRes->fetchRow("SELECT stock_id FROM `{$this->_tablePrefix}cataloginventory_stock` WHERE stock_name = :stock_name LIMIT 1", array(
                    ':stock_name' => $stock_name
                ));
                if (!empty($stock_id)) {
                    $initAttributes .= "SELECT @stock_id := `stock_id` FROM `{$this->_tablePrefix}cataloginventory_stock` WHERE stock_name = '" . $stock_name . "';";
                } else {
                    $message = Mage::helper('catalog')->__('Skip import row, stock name "' . $stock_name . '" does not exists in the shop');
                    Mage::throwException($message);
                }
            } else {
                $initAttributes .= "SELECT @stock_id := `stock_id` FROM `{$this->_tablePrefix}cataloginventory_stock` WHERE stock_name = 'Default';";
            }
            $this->_connRes->query($initAttributes, array(':attribute_set' => $iceimportAttributes['attribute_set']));

            // get tax class id

            $productData['int']['tax_class_id'] = $defaulttaxConf;

            if (!empty($iceimportAttributes['categories'])) {
                // get category id
                $categoryIds = $this->_addCategories($category, $storeId, $unspsc, $unspscPath, $categoriesToActiveConf);
            }

            // get url key
            $url = '';
            if (!empty($productData['varchar']['name'])) {
                $preUrl = explode(' ', strtolower($productData['varchar']['name']));
                $url = implode('-', $preUrl);
            }
            $productData['varchar']['url_key'] = $url;

            $prodIdFetch = $this->_connRes->fetchRow("SELECT entity_id FROM `{$this->_tablePrefix}catalog_product_entity` WHERE sku = :sku LIMIT 1", array(
                ':sku' => $sku
            ));
            $productId = $prodIdFetch['entity_id'];

            if (!empty($productId)) {
                // check import type (Import only price & qty or all product info)

                if (!empty($iceimportAttributes['categories'])) {
                    $productId = $this->_coreSave($productData, $productId, $storeId, $sku, $categoryIds);
                } else {
                    $productId = $this->_coreSave($productData, $productId, $storeId, $sku);
                }

                $this->_corePriceStock($websiteId, $productId, $price, $qty, $sku, $isInStock, $stockConf, $priceConf, 0);

                $this->_connRes->query("INSERT INTO {$this->_tablePrefix}iceshop_iceimport_imported_product_ids (product_id, product_sku) VALUES (:prod_id, :sku) ON DUPLICATE KEY UPDATE product_sku = :sku", array(':prod_id' => $productId, ':sku' => $sku));
            } else {
                if ($this->_getRefreshSetting('import_new_products') == 0) {
                    $DB_helper->setConfigData("skipped_counter", (int)++$skipped_counter);
                    return true;
                }

                if (!empty($iceimportAttributes['categories'])) {
                    $productId = $this->_coreSave($productData, $productId, $storeId, $sku, $categoryIds);
                } else {
                    $productId = $this->_coreSave($productData, $productId, $storeId, $sku);
                }
                // add price & stock
                if ($productId) {
                    $this->_corePriceStock($websiteId, $productId, $price, $qty, $sku, $isInStock);
                    $this->_connRes->query("INSERT INTO {$this->_tablePrefix}iceshop_iceimport_imported_product_ids (product_id, product_sku) VALUES (:prod_id, :sku) ON DUPLICATE KEY UPDATE product_sku = :sku", array(':prod_id' => $productId, ':sku' => $sku));
                }
            }

            $this->_connRes->query("DELETE FROM {$this->_tablePrefix}weee_tax WHERE entity_id = :prod_id AND attribute_id = @tax_auvibel_id", array(':prod_id' => $productId));
            $this->_connRes->query("DELETE FROM {$this->_tablePrefix}weee_tax WHERE entity_id = :prod_id AND attribute_id = @tax_bebat_id", array(':prod_id' => $productId));
            $this->_connRes->query("DELETE FROM {$this->_tablePrefix}weee_tax WHERE entity_id = :prod_id AND attribute_id = @tax_recupel_id", array(':prod_id' => $productId));
            $this->_connRes->query("DELETE FROM {$this->_tablePrefix}weee_tax WHERE entity_id = :prod_id AND attribute_id = @tax_reprobel_id", array(':prod_id' => $productId));

            $countryCode = Mage::getStoreConfig('general/country/default');
            if ($tax_auvibel > 0) {
                $query = "INSERT INTO {$this->_tablePrefix}weee_tax (website_id, entity_id, country, value, state, attribute_id, entity_type_id)"
                    . "VALUES (0, :prod_id, :country, :value, '*', @tax_auvibel_id, @product_entity_type_id)";
                $this->_connRes->query($query, [':prod_id' => $productId, ':country' => $countryCode, ':value' => $tax_auvibel]);
            }

            if ($tax_bebat > 0) {
                $query = "INSERT INTO {$this->_tablePrefix}weee_tax (website_id, entity_id, country, value, state, attribute_id, entity_type_id)"
                    . "VALUES (0, :prod_id, :country, :value, '*', @tax_bebat_id, @product_entity_type_id)";
                $this->_connRes->query($query, [':prod_id' => $productId, ':country' => $countryCode, ':value' => $tax_bebat]);
            }

            if ($tax_recupel > 0) {
                $query = "INSERT INTO {$this->_tablePrefix}weee_tax (website_id, entity_id, country, value, state, attribute_id, entity_type_id)"
                    . "VALUES (0, :prod_id, :country, :value, '*', @tax_recupel_id, @product_entity_type_id)";
                $this->_connRes->query($query, [':prod_id' => $productId, ':country' => $countryCode, ':value' => $tax_recupel]);
            }

            if ($tax_reprobel > 0) {
                $query = "INSERT INTO {$this->_tablePrefix}weee_tax (website_id, entity_id, country, value, state, attribute_id, entity_type_id)"
                    . "VALUES (0, :prod_id, :country, :value, '*', @tax_reprobel_id, @product_entity_type_id)";
                $this->_connRes->query($query, [':prod_id' => $productId, ':country' => $countryCode, ':value' => $tax_reprobel]);
            }

            // add product image to queue
            if ($addImages) {
                $this->_addImageToQueue($productId, $productImage);
            }


            $counter_sum = $counter + $skipped_counter;

            if (isset($counter) && isset($skipped_counter) && isset($import_total) && $counter_sum == $import_total) {

                $this->_runCategoriesSorting();

                $this->deleteOldProducts($DB_logger);

                $DB_logger->insertLogEntry('error' . md5(microtime(true)), 'New products skipped while export according to Iceimport settings: ' . $skipped_counter, 'stat');

                $date = date('m/d/Y H:i:s');
                $DB_logger->insertLogEntry('iceimport_import_ended', $date);
            }
            if ($counter < $import_total) {
                $DB_helper->setConfigData("counter", (int)++$counter);
            }

            if ($transactions_enabled != 0 && !$manually) {
                $this->_counter++;

                if ($this->_counter % $batch_size == 0) {
                    try {
                        $this->_connRes->commit();
                    } catch (Exception $e) {
                        $this->_connRes->rollBack();
                        throw $e;
                    }
                    $this->_connRes->beginTransaction();
                }
                if ($this->_counter == count($importData)) {
                    try {
                        $this->_connRes->commit();
                    } catch (Exception $e) {
                        $this->_connRes->rollBack();
                        throw $e;
                    }
                }
            }
        }
        return true;
    }

    /**
     * @param $arg_attribute
     * @param $arg_value
     * @return bool
     */
    public function getAttributeOptionValue($arg_attribute, $arg_value)
    {
        $attribute_model = Mage::getModel('eav/entity_attribute');
        $attribute_options_model = Mage::getModel('eav/entity_attribute_source_table');

        $attribute_code = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute = $attribute_model->load($attribute_code);

        $attribute_options_model->setAttribute($attribute);
        $options = $attribute_options_model->getAllOptions(false);

        foreach ($options as $option) {
            if ($option['label'] == $arg_value) {
                return $option['value'];
            }
        }
        return false;
    }


    /**
     * @param $arg_attribute
     * @param $arg_value
     * @return bool
     * @throws Exception
     */
    public function addAttributeOption($arg_attribute, $arg_value)
    {
        $attribute_model = Mage::getModel('eav/entity_attribute');
        $attribute_options_model = Mage::getModel('eav/entity_attribute_source_table');

        $attribute_code = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute = $attribute_model->load($attribute_code);

        $attribute_options_model->setAttribute($attribute);
        $attribute_options_model->getAllOptions(false);

        $value = array();
        $value['option'] = array($arg_value, $arg_value);
        $result = array('value' => $value);

        $attribute->setData('option', $result);
        $attribute->save();

        return $this->getAttributeOptionValue($arg_attribute, $arg_value);
    }


    /**
     * @param array $entityData
     * @param null $productId
     * @param int $storeId
     * @param $sku
     * @param $categoryIds
     * @return null
     */
    protected function _coreSave(array $entityData, $productId = null, $storeId = 0, $sku, $categoryIds =NULL)
    {

        $currentDate = Mage::app()->getLocale()->date(null, null, null, false)->toString(Varien_Date::DATETIME_INTERNAL_FORMAT);

        $coreSaveSQL = '';
        $newProduct = false;
        if ($productId === null) {
            // add product to store
            $coreSaveProduct = "INSERT INTO `{$this->_tablePrefix}catalog_product_entity` (`entity_type_id`, `attribute_set_id`, `type_id`, `sku`, `created_at`, `updated_at`)
                                    VALUES (@product_entity_type_id, @attribute_set_id, 'simple', :sku, :current_date, :current_date);
                                    SELECT @product_id := LAST_INSERT_ID();";

            $this->_connRes->query($coreSaveProduct, array(':sku' => $sku, ':current_date' => $currentDate));
            $prodFetch = $this->_connRes->fetchRow("SELECT @product_id AS prod_id");
            $productId = $prodFetch['prod_id'];
            $newProduct = TRUE;

        } else {
            $productId = (int)$productId;
            $coreSaveSQL .= "SELECT @product_id := {$productId}; ";
            $producteanConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_ean', $storeId);
            $productmpnConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_mpn', $storeId);
            $productnameConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_name', $storeId);
            $productshdescriptionConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_short_description', $storeId);
            $productdescriptionConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_description', $storeId);
            $productbrandConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_brand_name', $storeId);
            $deliveryetaConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_delivery_eta', $storeId);
            $attribute_mapping_mpn = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_mpn');
            $attribute_mapping_brand_name = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_brand_name');
            $attribute_mapping_ean = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_ean');
            $attribute_mapping_delivery_eta = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_delivery_eta');

            foreach ($entityData as $type => $typeAttributes) {
                foreach ($typeAttributes as $attribute => $value) {
                    if (
                        (
                            ($attribute == $attribute_mapping_mpn && $productmpnConf == 0) ||
                            ($attribute == $attribute_mapping_brand_name && $productbrandConf == 0) ||
                            ($attribute == $attribute_mapping_ean && $producteanConf == 0) ||
                            ($attribute == 'name' && $productnameConf == 0) ||
                            ($attribute == 'short_description' && $productshdescriptionConf == 0) ||
                            ($attribute == 'description' && $productdescriptionConf == 0) ||
                            ($attribute == $attribute_mapping_delivery_eta && $deliveryetaConf == 0)
                        ) || (
                            $attribute == 'sku' ||
                            ($attribute == 'attribute_set') ||
                            ($attribute == 'categories' && $this->_getRefreshSetting('categories') == 0) ||
                            ($attribute == 'unspsc') ||
                            ($attribute == 'price') ||
                            ($attribute == 'qty') ||
                            ($attribute == 'status' && $this->_getRefreshSetting('status') == 0) ||
                            ($attribute == 'visibility' && $this->_getRefreshSetting('visibility') == 0) ||
                            ($attribute == 'store') ||
                            ($attribute == 'websites') ||
                            ($attribute == 'is_in_stock' && $this->_getRefreshSetting('is_in_stock') == 0) ||
                            ($attribute == 'url_key' && $this->_getRefreshSetting('url_key') == 0) ||
                            ($attribute == 'image') ||
                            ($attribute == 'unspsc_path') ||
                            ($attribute == 'stock_name') ||
                            ($attribute == 'tax_auvibel') ||
                            ($attribute == 'tax_bebat') ||
                            ($attribute == 'tax_recupel') ||
                            ($attribute == 'tax_reprobel')
                        )
                    ) {
                        unset($entityData[$type][$attribute]);
                    }
                }
            }

            $updateDate = "UPDATE `{$this->_tablePrefix}catalog_product_entity` SET `updated_at` = :current_date where entity_id = {$productId}";
            $this->_connRes->query($updateDate, array(':current_date' => $currentDate));
        }

        $bindArray[':store_id'] = $storeId;

        foreach ($entityData as $type => $typeAttributes) {

            if ($type != 'spec') {
                $tailCoreSaveSQL = '';
                $attributesInit = '';
                if ($type == 'select') {
                    $type = 'int';
                    $is_select = 1;
                } else {
                    $is_select = 0;
                }
                if (!empty($typeAttributes)) {
                    $tailCoreSaveSQL .= "INSERT INTO `{$this->_tablePrefix}catalog_product_entity_{$type}` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) VALUES ";
                    foreach ($typeAttributes as $attribute => $value) {
                        if($this->_checkAttributeExist($attribute)){
                            if ($is_select == 1) {
                                $option_id = $this->getAttributeOptionValue($attribute, $value);
                                if (empty($option_id)) {
                                    $option_id = $this->addAttributeOption($attribute, $value);
                                }
                                $value = $option_id;
                            }
                            $attributesInit .= "SELECT @product_entity_type_id := `entity_type_id`
                                            FROM `{$this->_tablePrefix}eav_entity_type`
                                            WHERE entity_type_code = 'catalog_product';";
                            $attributesInit .= "SELECT @{$attribute}_id := `attribute_id`
                                                    FROM `{$this->_tablePrefix}eav_attribute`
                                                    WHERE `attribute_code` = '{$attribute}'
                                                        AND entity_type_id = @product_entity_type_id;";

                            $tailCoreSaveSQL .= "
                                  (@product_entity_type_id, @{$attribute}_id, 0, @product_id, :{$attribute} ),
                                  (@product_entity_type_id, @{$attribute}_id, :store_id, @product_id, :{$attribute} ), ";
                            $bindArray[':' . $attribute] = $value;
                        }
                    }
                    $tailCoreSaveSQL = substr($tailCoreSaveSQL, 0, -2);
                    $tailCoreSaveSQL .= "
            ON DUPLICATE KEY UPDATE
            `value` = VALUES (`value`);";
                }
                $coreSaveSQL .= $attributesInit . $tailCoreSaveSQL;
            } else {
                foreach ($typeAttributes as $attribute => $attributeData) {
                    if($this->_checkAttributeExist($attribute)){
                        $prod_id_field = $attributeData['prod_id_field'];
                        $table = $attributeData['table'];
                        $field = $attributeData['field'];
                        $value = $attributeData['value'];
                        if (!empty($table) && !empty($field)) {
                            $coreSaveSQL .= "UPDATE `{$this->_tablePrefix}{$table}`
                                                SET `{$field}` = :{$attribute}
                                                WHERE `{$prod_id_field}` = @product_id;";
                            $bindArray[':' . $attribute] = $value;
                        }
                    }
                }
            }
        }
        // categories
        if (($newProduct || ($productId === null) || ($productId !== null && $this->_getRefreshSetting('categories') == 1)) && !empty($categoryIds)) {
            $coreSaveSQL .= "INSERT INTO `{$this->_tablePrefix}catalog_category_product` (`category_id`, `product_id`, `position`) VALUES ";
            $counter = 1;

            $mapCategoryIds = array();
            $mapCategoryIds[] = array_pop($categoryIds);
            $delCategoryIds = array_diff($categoryIds, $mapCategoryIds);

            foreach ($mapCategoryIds as $categoryId) {
                if ($counter < count($mapCategoryIds)) {
                    $coreSaveSQL .= " (" . (int)$categoryId . ", @product_id, 1) , ";
                } else if ($counter == count($mapCategoryIds)) {
                    $coreSaveSQL .= " (" . (int)$categoryId . ", @product_id, 1) ON DUPLICATE KEY UPDATE `position` = 1; ";
                }
                $counter++;
            }

            $not_delete_category = $this->getCategoryIdEmtyUnspsc($storeId);
            $noDelCategory = '';
            if(!empty($not_delete_category)){
                foreach ($not_delete_category as $category_ID ){
                    $noDelCategory .=  ''.$category_ID['entity_id'] . ',';
                }
            }
            if(!empty($noDelCategory)){
                $noDelCategory = substr($noDelCategory, 0, -1);
            }

            if (!empty($mapCategoryIds)) {
                foreach ($mapCategoryIds as $delCategoryId) {
                    $delCategoryId = (int)$delCategoryId;
                    $coreSaveSQL .= "DELETE FROM `{$this->_tablePrefix}catalog_category_product`";
                    if(!empty($noDelCategory)){
                        $coreSaveSQL .= "WHERE `category_id` NOT IN({$noDelCategory},{$delCategoryId}) AND `product_id` = @product_id;";
                    } else {
                        $coreSaveSQL .= "WHERE `category_id`!={$delCategoryId} AND `product_id` = @product_id;";
                    }
                }
            }
            try {
                $this->_connRes->query($coreSaveSQL, $bindArray);
                unset($coreSaveSQL, $bindArray);
            } catch (Exception $e) {
                echo $e->getMessage();
            }
        } else {
            try {
                $this->_connRes->query($coreSaveSQL, $bindArray);
                unset($coreSaveSQL, $bindArray);
            } catch (Exception $e) {
                echo $e->getMessage();
            }
        }
        return $productId;
    }

    /**
     * Verify the existence of an attribute
     * @param string $attributeCode
     * @return boolean
     */
    protected function _checkAttributeExist($attributeCode){
        $query = "SELECT `attribute_id` FROM `{$this->_tablePrefix}eav_attribute` WHERE attribute_code='{$attributeCode}';";
        $attributeCheck = $this->_connRes->fetchRow($query);
        $attributeId = $attributeCheck['attribute_id'];
        if(empty($attributeId)){
            return false;
        }
        return true;
    }

    /**
     * @param int $website
     * @param bool $productId
     * @param float $price
     * @param float $qty
     * @param bool $sku
     * @param int $isInStock
     * @param int $stockConf
     * @param int $priceConf
     * @param int $new_product
     * @throws Mage_Core_Exception
     */
    protected function _corePriceStock($website = 0, $productId = false, $price = 0.00, $qty = 0.00, $sku = false, $isInStock = 0, $stockConf = 1, $priceConf = 1, $new_product = 1)
    {

        if (!$productId) {
            $message = Mage::helper('catalog')->__('Skip import row, product_id for product "%s" not defined ', $sku);
            Mage::throwException($message);
        }
        $stockSaveSQL = '';
        if ($stockConf == 1) {
            $stockSaveSQL .= "INSERT INTO `{$this->_tablePrefix}cataloginventory_stock_item` (`product_id`, `stock_id`, `qty`, `is_in_stock`)
                                VALUES (:product_id, @stock_id, :qty,1)
                                ON DUPLICATE KEY UPDATE
                                    `product_id`  = :product_id,
                                    `stock_id`    = @stock_id,
                                    `qty`         = :qty";

            if ($new_product == 1 || $this->_getRefreshSetting('is_in_stock') == 1) {
                $stockSaveSQL .= ",
                                    `is_in_stock` = :is_in_stock";
            }
            $stockSaveSQL .= ";";
            $fields_values = array(
                ':product_id' => $productId,
                ':websiteId' => $website,
                ':qty' => $qty
            );
            if ($new_product == 1 || $this->_getRefreshSetting('is_in_stock') == 1) {
                $fields_values[':is_in_stock'] = $isInStock;
            }
            $this->_connRes->query($stockSaveSQL, $fields_values);
            $stockSaveSQL = "";

            $stockSaveSQL .= "INSERT INTO `{$this->_tablePrefix}cataloginventory_stock_status` (`product_id`, `website_id`, `stock_id`, `qty`, `stock_status`)
                                VALUES (:product_id, :websiteId, @stock_id, :qty, 1)
                                ON DUPLICATE KEY UPDATE
                                    `product_id`   = :product_id,
                                    `website_id`   = :websiteId,
                                    `stock_id`     = @stock_id,
                                    `qty`          = :qty";
            if ($new_product == 1 || $this->_getRefreshSetting('is_in_stock') == 1) {
                $stockSaveSQL .= ",
                                    `stock_status` = :is_in_stock";
            }
            $stockSaveSQL .= ";";
            $fields_values = array(
                ':product_id' => $productId,
                ':websiteId' => $website,
                ':qty' => $qty
            );
            if ($new_product == 1 || $this->_getRefreshSetting('is_in_stock') == 1) {
                $fields_values[':is_in_stock'] = $isInStock;
            }
            $this->_connRes->query($stockSaveSQL, $fields_values);
            $stockSaveSQL = "";

        } elseif ($new_product == 0) {
            //existent product
            if ($this->_getRefreshSetting('is_in_stock') == 1) {
                $stockSaveSQL .= "UPDATE `{$this->_tablePrefix}cataloginventory_stock_item` SET `is_in_stock` = :is_in_stock WHERE `product_id` = :product_id AND `stock_id` = @stock_id;";
                $stockSaveSQL .= "UPDATE `{$this->_tablePrefix}cataloginventory_stock_status` SET `stock_status` = :is_in_stock WHERE `product_id` = :product_id AND `website_id` = :websiteId AND `stock_id` = @stock_id;";
                $fields_values = array(
                    ':websiteId' => $website,
                    ':product_id' => $productId,
                    ':is_in_stock' => $isInStock
                );
                $this->_connRes->query($stockSaveSQL, $fields_values);
                $stockSaveSQL = '';
            }
        }

        if ($priceConf == 1) {
            $stockSaveSQL .= "INSERT INTO `{$this->_tablePrefix}catalog_product_website` (`product_id`, `website_id`)
                                VALUES (:product_id, :websiteId)
                                ON DUPLICATE KEY UPDATE
                                    `product_id` = :product_id,
                                    `website_id` = :websiteId;";

            $fields_values = array(
                ':websiteId' => $website,
                ':product_id' => $productId
            );
            $this->_connRes->query($stockSaveSQL, $fields_values);
            $stockSaveSQL = "";
            $stockSaveSQL .= "INSERT INTO `{$this->_tablePrefix}catalog_product_entity_decimal` (`entity_type_id`,`attribute_id`,`store_id`, `entity_id`, `value`)
                                VALUES (@product_entity_type_id, @price_id,  0, :product_id, :price)
                                ON DUPLICATE KEY UPDATE
                                `entity_type_id` = @product_entity_type_id,
                                `attribute_id`   = @price_id,
                                `store_id`       = 0,
                                `entity_id`      = :product_id,
                                `value`          = :price;";
            $stockSaveSQL .= "INSERT INTO `{$this->_tablePrefix}catalog_product_entity_decimal` (`entity_type_id`,`attribute_id`,`store_id`, `entity_id`, `value`)
                                VALUES (@product_entity_type_id, @price_id,  {$website}, :product_id, :price)
                                ON DUPLICATE KEY UPDATE
                                `entity_type_id` = @product_entity_type_id,
                                `attribute_id`   = @price_id,
                                `store_id`       = {$website},
                                `entity_id`      = :product_id,
                                `value`          = :price;";
            $fields_values = array(
                ':product_id' => $productId,
                ':price' => $price,
                ':qty' => $qty
            );
            if ($new_product == 1 || $this->_getRefreshSetting('is_in_stock') == 1) {
                $fields_values[':is_in_stock'] = $isInStock;
            }
            $this->_connRes->query($stockSaveSQL, $fields_values);
            $stockSaveSQL = "";
        }
        if (($priceConf == 1 || $stockConf == 1) && !empty($stockSaveSQL)) {
            $fields_values = array(
                ':websiteId' => $website,
                ':product_id' => $productId,
                ':price' => $price,
                ':qty' => $qty
            );
            if ($this->_getRefreshSetting('is_in_stock') == 1) {
                $fields_values[':is_in_stock'] = $isInStock;
            }
            $this->_connRes->query($stockSaveSQL, $fields_values);
            unset($stockSaveSQL);
        }
        unset($fields_values);
    }

    /**
     * @param bool $productId
     * @param $productImageUrl
     */
    protected function _addImageToQueue($productId = false, $productImageUrl)
    {
        $productImageUrl = trim($productImageUrl);
        if ($productId && !empty($productImageUrl)) {
            // add image if not exists to queue
            $this->_connRes->query(
                "INSERT IGNORE INTO `{$this->_tablePrefix}iceshop_iceimport_image_queue` (`entity_id`, `image_url` )
                    VALUES (:product_id, :image_url)",
                array(
                    ':product_id' => $productId,
                    ':image_url' => $productImageUrl
                )
            );
        }
    }

    /**
     * @return mixed
     */
    private function getImageQueue()
    {
        return $this->_connRes->fetchAll("SELECT `queue_id`, `entity_id`, `image_url`
                                            FROM `{$this->_tablePrefix}iceshop_iceimport_image_queue`
                                            WHERE `is_downloaded` = 0");
    }

    /**
     * @param $logFileName
     * @throws Exception
     */
    public function processImageQueue($logFileName)
    {
        // download & set product images
        $queueList = $this->getImageQueue();
        if (count($queueList) > 0) {
            $mediaDir = Mage::getBaseDir('media');
            foreach ($queueList as $queue) {
                $queueId = $queue['queue_id'];
                $productId = $queue['entity_id'];
                $imageUrl = $queue['image_url'];

                $preImageName = explode('/', $imageUrl);
                $imageName = array_pop($preImageName);
                if (file_exists($mediaDir . DS . $imageName)) {
                    $imageName = rand() . '_' . time() . $imageName;
                }

                if (file_put_contents($mediaDir . DS . $imageName, file_get_contents($imageUrl))) {
                    $product = Mage::getModel('catalog/product')->load($productId);
                    $product->addImageToMediaGallery($mediaDir . DS . $imageName,
                        array('image', 'small_image', 'thumbnail'),
                        true, true
                    );
                    $product->save();
                    $this->setImageAsDownloaded($queueId);
                    unset($product);
                } else {
                    $this->setImageAsDownloadedError($queueId);
                    Mage::log('Unable download file to ' . $productId, $logFileName);
                    continue;
                }
            }
        }
    }

    /**
     * @param bool $queueId
     */
    private function setImageAsDownloaded($queueId = false)
    {
        if ($queueId) {
            $this->_connRes->query(
                "UPDATE `{$this->_tablePrefix}iceshop_iceimport_image_queue`
                    SET is_downloaded = 1
                    WHERE queue_id = :queue_id",
                array(':queue_id' => $queueId)
            );
        }
    }

    /**
     * @param bool $queueId
     */
    private function setImageAsDownloadedError($queueId = false)
    {
        if ($queueId) {
            $this->_connRes->query(
                "UPDATE `{$this->_tablePrefix}iceshop_iceimport_image_queue`
                    SET is_downloaded = 2
                    WHERE queue_id = :queue_id",
                array(':queue_id' => $queueId)
            );
        }
    }

    /**
     * @param $categories
     * @param $storeId
     * @param $unspsc
     * @param $unspscPath
     * @param int $categoryActive
     * @return array
     */
    protected function _addCategories($categories, $storeId, $unspsc, $unspscPath, $categoryActive = 1)
    {

        // check if product exists
        $categoryId = $this->_getCategoryIdByUnspsc($unspsc,$storeId);

        $categoryIds = array();
        if (!empty($categoryId)) {

            // merge categories by unspsc
            $categoryMergedArray = $this->_categoryMapper($categories, $unspscPath);
            foreach ($categoryMergedArray as $category) {

                $categoryName = $category['name'];
                $categoryUnspsc = $category['unspsc'];
                $categoryTreeId = $this->_getCategoryIdByUnspsc($categoryUnspsc,$storeId);
                // check category name to current store
                $categoryBindArray = array(
                    ':store_id' => 0,
                    ':category_id' => $categoryTreeId
                );
                $nameCheckerFetch = $this->_connRes->fetchRow(
                    "SELECT value_id
                        FROM `{$this->_tablePrefix}catalog_category_entity_varchar`
                        WHERE store_id = :store_id
                            AND entity_id = :category_id
                            AND attribute_id = @category_name_id",
                    $categoryBindArray
                );
                $nameChecker = $nameCheckerFetch['value_id'];
                if (!$nameChecker) {

                    // add category name to current store
                    $categoryBindArray[':category_name'] = $categoryName;
                    if (!empty($categoryBindArray[':category_id'])) {
                        $this->_connRes->query(
                            "INSERT INTO `{$this->_tablePrefix}catalog_category_entity_varchar` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`)
                            VALUES (@category_entity_type_id, @category_name_id, :store_id, :category_id, :category_name)",
                            $categoryBindArray
                        );
                    }
                }
            }
            //

            $activeSetter = '';
            // get current path of category
            $categoryPath = $this->_connRes->fetchRow(
                "SELECT path
                    FROM `{$this->_tablePrefix}catalog_category_entity`
                    WHERE entity_id = :entity_id",
                array(':entity_id' => $categoryId)
            );
            $categoryPathArray = explode('/', $categoryPath['path']);
            if ($categoryPathArray) {
                $activeSetter = "INSERT INTO `{$this->_tablePrefix}catalog_category_entity_int` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) VALUES ";
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

            // merge unspsc to current name in unspsc & name path's
            $categoryMergedArray = $this->_categoryMapper($categories, $unspscPath);
            // get max created parent category
            $categoryCreateArray = array();
            for ($i = count($categoryMergedArray) - 1; $i >= 0; $i--) {
                $category = $categoryMergedArray[$i];
                $checkCategoryId = $this->_getCategoryIdByUnspsc($category['unspsc'],$storeId);
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

    /**
     * @param $categoryPath
     * @param $unspscPath
     * @return array
     * @throws Mage_Core_Exception
     */
    protected function _categoryMapper($categoryPath, $unspscPath)
    {
        $nameArray = explode('/', $categoryPath);
        $unspscArray = explode('/', $unspscPath);

        if (count($nameArray) != count($unspscArray)) {
            $message = Mage::helper('catalog')->__('Skip import row, @categories data is invalid');
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

    /**
     * @param $unspsc
     * @return int|null
     */
    protected function _getCategoryIdByUnspsc($unspsc,$storeId = 0)
    {
        if ($unspsc == 'default_root') {
            return Mage::app()->getStore($storeId)->getRootCategoryId();
        } else {
//            $replace = array(']','[', '{','}','^','-','<','>','(',')');
//            $unspsc = str_replace($replace, '', $unspsc);
            $categoryId = $this->_connRes->fetchRow(
                "SELECT entity_id
                    FROM `{$this->_tablePrefix}catalog_category_entity_varchar`
                    WHERE `value` REGEXP '[[:<:]]".addslashes($unspsc)."[[:>:]]'
                        AND attribute_id = @unspsc_id"
            );
            return ($categoryId['entity_id']) ? $categoryId['entity_id'] : null;
        }
    }

    /**
     * @param $parentCategoryId
     * @param $storeId
     * @param $pathArray
     * @param int $categoryActive
     * @return mixed
     */
    protected function _buildCategoryTree($parentCategoryId, $storeId, $pathArray, $categoryActive = 0)
    {
        for ($i = count($pathArray) - 1; $i >= 0; $i--) {
            $category = $pathArray[$i];
            $parentCategoryId = $this->_createCategory($parentCategoryId, $category['unspsc'], $storeId, $category['name'], $categoryActive);
        }

        return $parentCategoryId;
    }

    /**
     * @param $parentId
     * @param $unspsc
     * @param $storeId
     * @param $name
     * @param int $categoryActive
     * @return mixed
     */
    protected function _createCategory($parentId, $unspsc, $storeId, $name, $categoryActive = 0)
    {

        $addCategory = "SELECT @tPath := `path`, @tLevel := `level`
                            FROM `{$this->_tablePrefix}catalog_category_entity`
                            WHERE `entity_id` = :parent_id;";
        $addCategory .= "SET @tLevel = @tLevel +1;";

        $addCategory .= "SET @path := CONCAT(@tPath, '/',(SELECT MAX(entity_id) FROM `{$this->_tablePrefix}catalog_category_entity`) +1 );";

        $addCategory .= "INSERT INTO `{$this->_tablePrefix}catalog_category_entity` (`entity_type_id`, `attribute_set_id`, `parent_id`, `created_at`, `path`, `position`, `level`, `children_count`)
                            VALUES (@category_entity_type_id, 0, :parent_id, NOW(), @path, 1, @tLevel, 0);";

        $addCategory .= "SELECT @catId := LAST_INSERT_ID();";

        $addCategory .= "UPDATE `{$this->_tablePrefix}catalog_category_entity`
                            SET `path` = CONCAT(@tPath, '/', @catId)
                            WHERE entity_id =  LAST_INSERT_ID();";

        $addCategory .= "UPDATE `{$this->_tablePrefix}catalog_category_entity`
                            SET children_count = children_count +1
                            WHERE entity_id = :parent_id;";

        $addCategory .= "INSERT IGNORE INTO `{$this->_tablePrefix}catalog_category_entity_int` (`entity_type_id`, `attribute_id`,`store_id`, `entity_id`, `value`)
                            VALUES (@category_entity_type_id, @category_active_id, 0,      @catId, :category_active),
                                (@category_entity_type_id, @category_active_id, :store, @catId, :category_active),
                                (@category_entity_type_id, @category_is_anchor_id, 0, @catId, 1),
                                (@category_entity_type_id, @category_is_anchor_id, :store, @catId, 1),
                                (@category_entity_type_id, @include_nav_bar_id, 0,      @catId, 1),
                                (@category_entity_type_id, @include_nav_bar_id, :store, @catId, 1);";

        $addCategory .= "INSERT IGNORE INTO `{$this->_tablePrefix}catalog_category_entity_varchar` (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`)
                            VALUES (@category_entity_type_id, @category_name_id, 0,      @catId, :category_name),
                                (@category_entity_type_id, @category_name_id, :store, @catId, :category_name),
                                (@category_entity_type_id, @unspsc_id,        0,      @catId, :unspsc_val),
                                (@category_entity_type_id, @unspsc_id,        :store, @catId, :unspsc_val);
    ";

        $this->_connRes->query($addCategory, array(
            ':store' => $storeId,
            ':parent_id' => $parentId,
            ':category_name' => $name,
            ':unspsc_val' => $unspsc,
            ':category_active' => (int)$categoryActive
        ));

        $categoryIdFetch = $this->_connRes->fetchRow('SELECT @catId AS category_id');

        return $categoryIdFetch['category_id'];
    }

    /**
     * @param array $importData
     * @return array
     */
    protected function _mapAttributes(array $importData)
    {

        // map iceimport attributes, skip some attributes
        $iceAttributes = array();
        foreach ($importData as $attribute => $value) {
            // map iceimport attributes

            if ($attribute == 'sku' ||
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
                $attribute == 'unspsc_path' ||
                $attribute == 'stock_name' ||
                $attribute == 'tax_auvibel' ||
                $attribute == 'tax_bebat' ||
                $attribute == 'tax_recupel' ||
                $attribute == 'tax_reprobel'
            ) {

                $iceAttributes[$attribute] = $value;
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
                    if (isset($importData[$attributeCode]) && $importData[$attributeCode] != false) {
                        $attributeSpecs['value'] = $importData[$attributeCode];
                        $productData[$backendType][$attributeCode] = $attributeSpecs;
                        unset($importData[$attributeCode]);
                    }
                }
            }
        }

        if (!empty($importData)) {
            foreach ($importData as $attributeCode => $value) {
                $frontendTypeFetch = $this->_connRes->fetchRow(
                    "SELECT frontend_input FROM `{$this->_tablePrefix}eav_attribute` WHERE `attribute_code` = :code",
                    array(':code' => $attributeCode)
                );
                if ($frontendTypeFetch['frontend_input'] == 'select') {
                    $frontendType = $frontendTypeFetch['frontend_input'];
                    if ($frontendType != 'static' && !empty($frontendType) && $value != '') {
                        $productData[$frontendType][$attributeCode] = $value;
                        unset($importData[$attributeCode]);
                    }
                }
            }
        }
        // map custom attributes
        if (!empty($importData)) {
            foreach ($importData as $attributeCode => $value) {
                $backendTypeFetch = $this->_connRes->fetchRow("SELECT backend_type FROM `{$this->_tablePrefix}eav_attribute` WHERE `attribute_code` = :code", array(':code' => $attributeCode));
                $backendType = $backendTypeFetch['backend_type'];
                if (($backendType != 'static' && !empty($backendType) && $value != '')) {
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


    /**
     * @return array
     */
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
                'price_view'
            ),
            'text' => array(
                'recurring_profile',
                'description',
                'custom_layout_update',
                'meta_keyword',
                'short_description',
                'total_supplier_stock'
            ),
            'decimal' => array(
                'group_price',
                'weight',
                'special_price',
                'msrp',
                'tax_auvibel',
                'tax_bebat',
                'tax_recupel',
                'tax_reprobel'
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

    /**
     * Count child categories products and set them inactive if they have no products
     *
     * @param $child_cat
     */
    public function CountChildProd($child_cat)
    {
        foreach ($child_cat as $cat) {
            $query = "SELECT `entity_id`
                        FROM `{$this->_tablePrefix}catalog_category_entity`
                        WHERE parent_id = :cat_id";
            $child_cat = $this->_connRes->fetchAll(
                $query,
                array(
                    ':cat_id' => $cat['entity_id']
                )
            );

            $query = "SELECT COUNT(*)
                        FROM `{$this->_tablePrefix}catalog_category_product`
                        WHERE category_id = :cat_id ";
            $cat_products = $this->_connRes->fetchRow(
                $query,
                array(
                    ':cat_id' => $cat['entity_id']
                )
            );

            if ($cat_products['COUNT(*)'] == 0 && empty($child_cat) && $this->_getRefreshSetting('update_hide_category') == 1) {
                $this->_connRes->query(
                    "UPDATE `{$this->_tablePrefix}catalog_category_entity_int`
                        SET `value` = 0
                        WHERE `attribute_id` = @category_active_id
                            AND entity_id = :cat_id",
                    array(
                        ':cat_id' => $cat['entity_id']
                    )
                );
            } else if (!empty($child_cat)) {
                $this->CountChildProd($child_cat);
            }
        }
    }

    /**
     * Run categories resorting procedure
     */
    private function _runCategoriesSorting()
    {
        // Check if this is last imported product
        //  Do category sort and set categories without products to inactive
        $catCollection = Mage::getModel('catalog/category')
            ->getCollection()
            ->addAttributeToSort('name', 'ASC');

        $category_sort = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/category_sort');
        if ($category_sort == 1) {
            $position = 1;
        }
        foreach ($catCollection as $category) {

            if ($category_sort) {
                $query = "UPDATE `{$this->_tablePrefix}catalog_category_entity` SET position = :position WHERE entity_id = :cat_id ";
                $this->_connRes->query($query, array(
                    ':position' => $position++,
                    ':cat_id' => $category->getId()
                ));
            }

            $query = "SELECT COUNT(*) FROM `{$this->_tablePrefix}catalog_category_product` WHERE category_id = :cat_id ";
            //$query = "SELECT COUNT(category_id) FROM `{$this->_tablePrefix}catalog_category_product` WHERE category_id = :cat_id ";
            $cat_products = $this->_connRes->fetchRow($query, array(
                ':cat_id' => $category->getId()
            ));

            //if ($cat_products['COUNT(category_id)'] == 0) {
            if ($cat_products['COUNT(*)'] == 0) {
                $query = "SELECT `entity_id` FROM `{$this->_tablePrefix}catalog_category_entity` WHERE parent_id = :cat_id";
                $child_cat = $this->_connRes->fetchAll($query, array(
                    ':cat_id' => $category->getId()
                ));

                if (isset($child_cat) && count($child_cat) > 0) {
                    //Count child categories products and set them to inactive if they have no
                    $this->CountChildProd($child_cat);
                } elseif($this->_getRefreshSetting('update_hide_category') == 1) {

                    $this->_connRes->query("UPDATE `{$this->_tablePrefix}catalog_category_entity_int`
                                SET `value` = 0 WHERE `attribute_id` = @category_active_id AND entity_id = :cat_id", array(
                        ':cat_id' => $category->getId()
                    ));
                    $query = "SELECT path
                        FROM `{$this->_tablePrefix}catalog_category_entity`
                        WHERE entity_id = :cat_id ";
                    $cat_products = $this->_connRes->fetchRow(
                        $query,
                        array(
                            ':cat_id' => $category->getId()
                        )
                    );
                    $ar_cat_products = explode("/", $cat_products['path']);

                    $count_of_all_products = 0;

                    foreach($ar_cat_products as $key => $value) {
                        $query = "SELECT COUNT(*) FROM `{$this->_tablePrefix}catalog_category_product` WHERE category_id = :cat_id ";
                        //$query = "SELECT COUNT(category_id) FROM `{$this->_tablePrefix}catalog_category_product` WHERE category_id = :cat_id ";
                        $products_of_category = $this->_connRes->fetchRow($query, array(
                            ':cat_id' => $value
                        ));

                        $cat = Mage::getModel('catalog/category')->load($value);

                        $subcats = $cat->getChildren();
                        $ar_subcatgs = explode(',',$subcats);
                        foreach($ar_subcatgs as $item) {
                            $query = "SELECT COUNT(*) FROM `{$this->_tablePrefix}catalog_category_product` WHERE category_id = :cat_id ";
                            //$query = "SELECT COUNT(category_id) FROM `{$this->_tablePrefix}catalog_category_product` WHERE category_id = :cat_id ";
                            $products_of_category_children = $this->_connRes->fetchRow($query, array(
                                ':cat_id' => $item
                            ));
                            //$count_of_all_products += $products_of_category_children['COUNT(*)'];
                            if($products_of_category_children > 0) {
                                $count_of_all_products += $products_of_category_children['COUNT(*)'];
                            }
                        }

                        //p($count_of_all_products);
                        if($products_of_category['COUNT(*)'] == 0 && $count_of_all_products == 0) {
                        //if($products_of_category['COUNT(category_id)'] == 0 && $count_of_all_products == 0) {
                            $this->_connRes->query("UPDATE `{$this->_tablePrefix}catalog_category_entity_int`
                                SET `value` = 0 WHERE `attribute_id` = @category_active_id AND entity_id = :cat_id", array(
                                ':cat_id' => $value
                            ));
                        }
                        else {
                            $this->_connRes->query("UPDATE `{$this->_tablePrefix}catalog_category_entity_int`
                                SET `value` = 1 WHERE `attribute_id` = @category_active_id AND entity_id = :cat_id", array(
                                ':cat_id' => $value
                            ));
                        }
                    }
                }
                else {
                    $this->_connRes->query("UPDATE `{$this->_tablePrefix}catalog_category_entity_int`
                                SET `value` = 1 WHERE `attribute_id` = @category_active_id AND entity_id = :cat_id", array(
                        ':cat_id' => $category->getId()
                    ));
                }
            }
        }
    }

    /**
     * @param object $DB_logger
     * @throws Exception
     */
    public function deleteOldProducts($DB_logger)
    {
        $delete_old_products = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/delete_old_products');
        if ($delete_old_products) {

            try {
                $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
                $db_res->query("SELECT @is_iceimport_id := `attribute_id`
                                    FROM {$this->_tablePrefix}eav_attribute
                                    WHERE attribute_code = 'is_iceimport'");

                //$count_prod = $db_res->fetchRow("SELECT COUNT(*) AS count_prod
                $count_prod = $db_res->fetchRow("SELECT COUNT(cpe.entity_id) AS count_prod
                                                        FROM {$this->_tablePrefix}catalog_product_entity AS cpe
                                                        JOIN {$this->_tablePrefix}catalog_product_entity_varchar AS cpev
                                                            ON cpe.entity_id = cpev.entity_id
                                                            AND cpev.value = 1
                                                            AND cpev.attribute_id = @is_iceimport_id");

                $count_prod = $count_prod['count_prod'];

                if ($count_prod > 0) {
                    //iceimport products exists, amount > 0
                    //$count_del_prod = $db_res->fetchRow("SELECT COUNT(*) AS count__del_prod
                    $count_del_prod = $db_res->fetchRow("SELECT COUNT(cpe.entity_id) AS count__del_prod
                                                        FROM {$this->_tablePrefix}catalog_product_entity AS cpe
                                                        JOIN {$this->_tablePrefix}catalog_product_entity_varchar AS cpev
                                                            ON cpe.entity_id = cpev.entity_id
                                                            AND cpev.value = 1
                                                            AND cpev.attribute_id = @is_iceimport_id
                                                        LEFT JOIN {$this->_tablePrefix}iceshop_iceimport_imported_product_ids AS iip
                                                            ON cpe.entity_id = iip.product_id
                                                        WHERE iip.product_id IS NULL");

                    if(!empty($count_del_prod['count__del_prod'])){
                        $count_del_prod = $count_del_prod['count__del_prod'];
                    } else {
                        $count_del_prod = 0;
                    }

                    $delete_old_products_tolerance = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/delete_old_products_tolerance');

                    if ($count_del_prod > 0) {
                        //iceimport products to delete exists, amount > 0
                        $delete_old_products_tolerance = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/delete_old_products_tolerance');

                        if (round(($count_del_prod / $count_prod * 100), 0) < $delete_old_products_tolerance) {

                            //iceimport products to delete franction is less than allowed tolerance, deletion approved
                            $DB_logger->insertLogEntry('iceimport_count_delete_product', $count_del_prod);
                            $db_res->query("DELETE cpe
                                    FROM {$this->_tablePrefix}catalog_product_entity AS cpe
                                    JOIN {$this->_tablePrefix}catalog_product_entity_varchar AS cpev
                                        ON cpe.entity_id = cpev.entity_id
                                        AND cpev.value = 1
                                        AND cpev.attribute_id = @is_iceimport_id
                                    LEFT JOIN {$this->_tablePrefix}iceshop_iceimport_imported_product_ids AS iip
                                        ON cpe.entity_id = iip.product_id
                                    WHERE iip.product_id IS NULL");

                            $db_res->query("DELETE FROM {$this->_tablePrefix}iceshop_iceimport_imported_product_ids");
                        } else {
                            $error_message = 'Attempt to delete more old products than allowed in Iceimport configuration. Interruption of the process.';
                            $DB_logger->insertLogEntry('error_try_delete_product', $error_message);
                            $error_message2 = 'Old product percentage: ' . round(($count_del_prod / $count_prod * 100), 2) . '%';
                            $DB_logger->insertLogEntry('error_try_delete_product_percentage', $error_message2);

                            //flag to warning notice
                            $DB_logger->insertLogEntry('try_delete_product_percentage_warning_flag', 'SHOW');
                            print $error_message;
                            print $error_message2;
                            exit;
                        }
                    }
                }
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        }
    }

    /**
     * @throws Exception
     */
    public function finish()
    {
        /**
         * Back compatibility event
         */
        $this->_checkAndSetIceField();
        $DB_logger = Mage::helper('iceimport/db');
        $tablePrefix = '';
        $tPrefix = (array)Mage::getConfig()->getTablePrefix();
        if (!empty($tPrefix)) {
            $tablePrefix = $tPrefix[0];
        }
        $count_imported_products = $DB_logger->getRowsCount($tablePrefix . "iceshop_iceimport_imported_product_ids");
        $DB_logger->insertLogEntry('iceimport_count_imported_products', $count_imported_products);

        Mage::dispatchEvent($this->_eventPrefix . '_after', array());

        $re_index_required = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/is_reindex_required_import');
        if ((isset($re_index_required)) && ($re_index_required == 1)) {
            $entity = new Varien_Object();
            Mage::getSingleton('index/indexer')->processEntityAction(
                $entity,
                self::ENTITY,
                Mage_Index_Model_Event::TYPE_SAVE
            );
        }
    }


    /**
     * Method return id categoryees where 'unspsc' empty.
     * @param intneger $store_id
     * @return array
     */
    public function getCategoryIdEmtyUnspsc($store_id=0){

        $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
        $query = "SELECT ccev.`entity_id` FROM `{$this->_tablePrefix}catalog_category_entity_varchar`  AS ccev
                                     LEFT JOIN `{$this->_tablePrefix}eav_attribute` AS ea
                                            ON ea.`attribute_id` = ccev.`attribute_id`
                  WHERE ea.`attribute_code`='unspsc' AND (ccev.`store_id` = 0 OR ccev.`store_id`=:store_id) AND ccev.`value` IS NULL;";

        return $this->_connRes->fetchAll($query, array( ':store_id' => $store_id ));
    }

    /**
     * Check active_ice field and update if need
     * @throws Mage_Core_Exception
     */
    private function _checkAndSetIceField()
    {
        $attribute = Mage::getModel('eav/config')->getAttribute('catalog_product', 'active_ice')->getData();
        if (isset($attribute['attribute_id'])) {
            $write = Mage::getSingleton('core/resource')->getConnection('core_write');
            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $prefix = Mage::getConfig()->getTablePrefix();
            if (!empty($prefix[0])) {
                $prefix = $prefix[0];
            } else {
                $prefix = '';
            }

            $sql = "DROP PROCEDURE IF EXISTS FIELD_EXISTS;";
            $write->query($sql);

            $sql = "CREATE PROCEDURE FIELD_EXISTS(
                    OUT _exists    BOOLEAN, -- return value
                    IN  tableName  CHAR(255) CHARACTER SET 'utf8', -- name of table to look for
                    IN  columnName CHAR(255) CHARACTER SET 'utf8', -- name of column to look for
                    IN  dbName     CHAR(255) CHARACTER SET 'utf8'       -- optional specific db
                ) BEGIN
                -- try to lookup db if none provided
                    SET @_dbName := IF(dbName IS NULL, database(), dbName);

                    IF CHAR_LENGTH(@_dbName) = 0
                    THEN -- no specific or current db to check against
                        SELECT
                            FALSE
                        INTO _exists;
                    ELSE -- we have a db to work with
                        SELECT
                            IF(count(*) > 0, TRUE, FALSE)
                        INTO _exists
                        FROM information_schema.COLUMNS c
                        WHERE
                            c.TABLE_SCHEMA = @_dbName
                            AND c.TABLE_NAME = tableName
                            AND c.COLUMN_NAME = columnName;
                    END IF;
                END;";
            $write->query($sql);

            $sql = "CALL FIELD_EXISTS(@_exists, '{$prefix}catalog_product_entity', 'active_ice', NULL);";
            $read->query($sql);

            $sql = "SELECT @_exists;";
            $res = $read->fetchCol($sql);
            if (array_shift($res)) {
                $options = Mage::getModel('eav/config')->getAttribute('catalog_product', 'active_ice')->getSource()->getAllOptions();
                $optionId = false;
                foreach ($options as $option) {
                    if ($option['label'] == 'Yes') {
                        $optionId = $option['value'];
                        break;
                    }
                }
                if ($optionId) {
                    $sql = "UPDATE `{$prefix}catalog_product_entity` SET `active_ice` = '{$optionId}' WHERE `active_ice` IS NULL;";
                    $write->query($sql);
                }
            }
        }
    }
}


