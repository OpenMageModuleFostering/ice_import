<?php

/**
 * Convert csv parser
 *
 * @category   Mage
 * @package    Mage_Dataflow
 * @author      Magento Core Team <core@magentocommerce.com>
 */
class ICEshop_Iceimport_Model_Dataflow_Convert_Parser_Csv extends Mage_Dataflow_Model_Convert_Parser_Csv
{
    protected $_fields;

    protected $_mapfields = array();

    public function parse()
    {
        $this->clearIceimportIds();
        $DB_logger = Mage::helper('iceimport/db');
        $date = date('m/d/Y H:i:s');
        $DB_logger->deleteLogEntry('error_try_delete_product');
        $DB_logger->deleteLogEntry('error_try_delete_product_percentage');
        $DB_logger->insertLogEntry('iceimport_import_started', $date);

        $default_attr_set_id = Mage::getModel('catalog/product')->getResource()->getEntityType()->getDefaultAttributeSetId();
        $attributes = Mage::getModel('catalog/product_attribute_api')->items($default_attr_set_id);

        $attribute_mapping_mpn        = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_mpn');
        $attribute_mapping_brand_name = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_brand_name');
        $attribute_mapping_ean        = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_ean');
        $entityAttributeModel         = Mage::getResourceModel('eav/entity_attribute');

        //stock_name and websites check
        $stock_name = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/stock_inventory');
        $websites = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/websites');

        $not_found_attr = array();

        if (!$entityAttributeModel->getIdByCode('catalog_product',$attribute_mapping_mpn)) {
            $not_found_attr['mapping']['mpn'] = 'mpn';
        }
        if (!$entityAttributeModel->getIdByCode('catalog_product',$attribute_mapping_brand_name)) {
            $not_found_attr['mapping']['brand_name'] = 'brand name';
        }
        if (!$entityAttributeModel->getIdByCode('catalog_product',$attribute_mapping_ean)) {
            $not_found_attr['mapping']['ean'] = 'ean';
        }

        if (empty($websites)) {
            $not_found_attr['not_set']['websites'] = 'Websites';
        }
        if (empty($stock_name)) {
            $not_found_attr['not_set']['stock_name'] = 'Stock name';
        }



        foreach ($attributes as $_attribute) {
            if ($_attribute['code'] == 'is_iceimport') {
                unset($not_found_attr['attribute']['is_iceimport']);
                break;
            } else {
                $not_found_attr['attribute']['is_iceimport'] = 'is iceimport';
            }
        }

        if (!empty($not_found_attr['attribute'])) {
            echo '
                <li style="background-color:#FDD" id="error-0">
	                <img id="error-0_img" src="' . Mage::getBaseUrl('web') . '/skin/adminhtml/default/default/images/error_msg_icon.gif" class="v-middle" style="margin-right:5px">
		            <span id="error-0_status" class="text">Not found attributes: ' . implode(", ", $not_found_attr['attribute']) . ' please check your default attribute set.</span>
                </li>';
            exit;
        } elseif (!empty($not_found_attr['mapping'])) {
            echo '
                <li style="background-color:#FDD" id="error-0">
	                <img id="error-0_img" src="' . Mage::getBaseUrl('web') . '/skin/adminhtml/default/default/images/error_msg_icon.gif" class="v-middle" style="margin-right:5px">
		            <span id="error-0_status" class="text">Attributes not set: ' . implode(', ', $not_found_attr['mapping']) . ' please check your attributes mapping.</span>
                </li>';
            exit;
        } elseif (!empty($not_found_attr['not_set'])) {
            echo '
                <li style="background-color:#FDD" id="error-0">
	                <img id="error-0_img" src="' . Mage::getBaseUrl('web') . '/skin/adminhtml/default/default/images/error_msg_icon.gif" class="v-middle" style="margin-right:5px">
		            <span id="error-0_status" class="text">Attributes not set: ' . implode(', ', $not_found_attr['not_set']) . ' please check your attributes values in ICEImport settings.</span>
                </li>';
            exit;
        }
        // fixed for multibyte characters
        setlocale(LC_ALL, Mage::app()->getLocale()->getLocaleCode() . '.UTF-8');

        $fDel = $this->getVar('delimiter', ',');
        $fEnc = $this->getVar('enclose', '"');
        if ($fDel == '\t') {
            $fDel = "\t";
        }

        $adapterName = $this->getVar('adapter', null);
        $adapterMethod = $this->getVar('method', 'saveRow');

        if (!$adapterName || !$adapterMethod) {
            $message = Mage::helper('dataflow')->__('Please declare "adapter" and "method" nodes first.');
            $this->addException($message, Mage_Dataflow_Model_Convert_Exception::FATAL);
            return $this;
        }

        try {
            $adapter = Mage::getModel($adapterName);
        } catch (Exception $e) {
            $message = Mage::helper('dataflow')->__('Declared adapter %s was not found.', $adapterName);
            $this->addException($message, Mage_Dataflow_Model_Convert_Exception::FATAL);
            return $this;
        }

        if (!is_callable(array($adapter, $adapterMethod))) {
            $message = Mage::helper('dataflow')->__('Method "%s" not defined in adapter %s.', $adapterMethod, $adapterName);
            $this->addException($message, Mage_Dataflow_Model_Convert_Exception::FATAL);
            return $this;
        }

        $batchModel = $this->getBatchModel();
        $batchIoAdapter = $this->getBatchModel()->getIoAdapter();

        if (Mage::app()->getRequest()->getParam('files')) {
            $file = Mage::app()->getConfig()->getTempVarDir() . '/import/'
                . urldecode(Mage::app()->getRequest()->getParam('files'));
            $this->_copy($file);
        }

        $batchIoAdapter->open(false);

        $isFieldNames = $this->getVar('fieldnames', '') == 'true' ? true : false;
        if (!$isFieldNames && is_array($this->getVar('map'))) {
            $fieldNames = $this->getVar('map');
        } else {
            $fieldNames = array();
            foreach ($batchIoAdapter->read(true, $fDel, $fEnc) as $v) {
                $fieldNames[$v] = $v;
            }
        }
        $countRows = 0;
        $currentRow = 0;
        $maxRows = (int)Mage::getStoreConfig(
            'iceshop_iceimport_importprod_root/importprod/iceimport_batch_size',
            Mage::app()
                ->getWebsite()
                ->getDefaultGroup()
                ->getDefaultStoreId()
        );
        $itemData = array();
        while (($csvData = $batchIoAdapter->read(true, $fDel, $fEnc)) !== false) {
            if (count($csvData) == 1 && $csvData[0] === null) {
                continue;
            }
            $countRows++;
            $i = 0;
            foreach ($fieldNames as $field) {

                $field == 'mpn' ? $field = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_mpn') : '';
                $field == 'brand_name' ? $field = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_brand_name') : '';
                $field == 'ean' ? $field = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_ean') : '';
                $field == 'delivery_eta' ? $field = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_delivery_eta') : '';

                $itemData[$currentRow][$field] = isset($csvData[$i]) ? $csvData[$i] : null;
                $i++;
            }

            if ($currentRow == $maxRows) {
                $currentRow = 0;
                $batchImportModel = $this->getBatchImportModel()
                    ->setBatchId($this->getBatchModel()->getId())
                    ->setBatchData($itemData)->setStatus(1);
                $itemData = array();
            }
            $currentRow++;

        }
        if ($currentRow <= $maxRows && $currentRow != 0 && !empty($itemData)) {
            $batchImportModel = $this->getBatchImportModel()
                ->setBatchId($this->getBatchModel()->getId())
                ->setBatchData($itemData)->setStatus(1);
        }

        $this->addException(Mage::helper('dataflow')->__('Found %d rows.', $countRows));
        $this->addException(Mage::helper('dataflow')->__('Starting %s :: %s', $adapterName, $adapterMethod));

        $batchModel->setParams($this->getVars())
            ->setAdapter($adapterName)
            ->save();

        //latest session cleaning
        //Init session values to count total products and determine the last call of saveRow method
        $session            = Mage::getSingleton("core/session");
        $import_total       = $session->getData("import_total");
        $counter            = $session->getData("counter");
        $skipped_counter    = $session->getData("skipped_counter");
        if (isset($import_total)) {
            $session->unsetData("import_total");
        }
        if (isset($counter)) {
            $session->unsetData("counter");
        }
        if (isset($skipped_counter)) {
            $session->unsetData("skipped_counter");
        }
        $this->updateCatalogCategoryChildren();

        return $this;
    }


    public function clearIceimportIds(){
        $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
        $tablePrefix = '';
        $tPrefix = (array)Mage::getConfig()->getTablePrefix();
        if (!empty($tPrefix)) {
            $tablePrefix = $tPrefix[0];
        }
        $db_res->query("DELETE FROM {$tablePrefix}iceshop_iceimport_imported_product_ids");
    }


    /**
     * Update path to children category/root
     */
    public function updateCatalogCategoryChildren(){
        try{
            $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
            $tablePrefix = '';
            $tPrefix = (array)Mage::getConfig()->getTablePrefix();
            if (!empty($tPrefix)) {
                $tablePrefix = $tPrefix[0];
            }
            $db_res->query('UPDATE `'.$tablePrefix.'catalog_category_entity` SET children_count = (SELECT COUNT(*) FROM (SELECT * FROM `'.$tablePrefix.'catalog_category_entity`) AS table2 WHERE path LIKE CONCAT(`'.$tablePrefix.'catalog_category_entity`.path,"/%"));');
        } catch (Exception $e){
        }
    }

}