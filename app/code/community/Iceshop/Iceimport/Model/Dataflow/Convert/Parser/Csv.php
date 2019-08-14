<?php
/**
 * Convert csv parser
 *
 * @category   Mage
 * @package    Mage_Dataflow
 * @author      Magento Core Team <core@magentocommerce.com>
 */
class Iceshop_Iceimport_Model_Dataflow_Convert_Parser_Csv extends Mage_Dataflow_Model_Convert_Parser_Csv
{
    protected $_fields;

    protected $_mapfields = array();

    public function parse()
    {
        $entityTypeId = Mage::getModel('eav/entity')
            ->setType('catalog_product')
            ->getTypeId();
        $attributeSetCollection = Mage::getModel('eav/entity_attribute_set')
            ->getCollection()
            ->setEntityTypeFilter($entityTypeId);
        $default_attr_set = $attributeSetCollection->getData();
        $attributes = Mage::getModel('catalog/product_attribute_api')->items($default_attr_set[0]['attribute_set_id'] );
        $req_attributes = array('brand_name', 'ean', 'mpn', 'delivery_eta', 'is_iceimport');
        $not_found_attr = array();
        foreach($req_attributes as $req_attribute){
            foreach($attributes as $_attribute){
                if($_attribute['code'] == $req_attribute){
                    unset($not_found_attr[$req_attribute]);
                    break;
                }else{
                    $not_found_attr[$req_attribute] = $req_attribute;
                }
            }
        }
        if(!empty($not_found_attr)){
            echo '
                <li style="background-color:#FDD" id="error-0">
	                <img id="error-0_img" src="' . Mage::getBaseUrl('web') . '/skin/adminhtml/default/default/images/error_msg_icon.gif" class="v-middle" style="margin-right:5px">
		            <span id="error-0_status" class="text">Not found attributes: ' . implode(", ", $not_found_attr) . ' please check you defaul attribute set.</span>
                </li>';
            exit;
        }

        // fixed for multibyte characters
        setlocale(LC_ALL, Mage::app()->getLocale()->getLocaleCode().'.UTF-8');

        $fDel = $this->getVar('delimiter', ',');
        $fEnc = $this->getVar('enclose', '"');
        if ($fDel == '\t') {
            $fDel = "\t";
        }

        $adapterName   = $this->getVar('adapter', null);
        $adapterMethod = $this->getVar('method', 'saveRow');

        if (!$adapterName || !$adapterMethod) {
            $message = Mage::helper('dataflow')->__('Please declare "adapter" and "method" nodes first.');
            $this->addException($message, Mage_Dataflow_Model_Convert_Exception::FATAL);
            return $this;
        }

        try {
            $adapter = Mage::getModel($adapterName);
        }
        catch (Exception $e) {
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
            $file = Mage::app()->getConfig()->getTempVarDir().'/import/'
                . urldecode(Mage::app()->getRequest()->getParam('files'));
            $this->_copy($file);
        }

        $batchIoAdapter->open(false);

        $isFieldNames = $this->getVar('fieldnames', '') == 'true' ? true : false;
        if (!$isFieldNames && is_array($this->getVar('map'))) {
            $fieldNames = $this->getVar('map');
        }
        else {
            $fieldNames = array();
            foreach ($batchIoAdapter->read(true, $fDel, $fEnc) as $v) {
                $fieldNames[$v] = $v;
            }
        }
        $countRows = 0;
        $currentRow = 0;
        $maxRows = 400;
        $itemData = array();
        while (($csvData = $batchIoAdapter->read(true, $fDel, $fEnc)) !== false) {
            if (count($csvData) == 1 && $csvData[0] === null) {
                continue;
            }
            $countRows ++; $i = 0;
            foreach ($fieldNames as $field) {
                $itemData[$currentRow][$field] = isset($csvData[$i]) ? $csvData[$i] : null;
                $i ++;
            }

            if($currentRow == $maxRows){
                $currentRow = 0;
                $batchImportModel = $this->getBatchImportModel()
                    ->setBatchId($this->getBatchModel()->getId())
                    ->setBatchData($itemData)->setStatus(1);
                $itemData = array();
            }
            $currentRow++;

        }
        if($currentRow < $maxRows && $currentRow != 0){
            $batchImportModel = $this->getBatchImportModel()
                ->setBatchId($this->getBatchModel()->getId())
                ->setBatchData($itemData)->setStatus(1);
        }

        $this->addException(Mage::helper('dataflow')->__('Found %d rows.', $countRows));
        $this->addException(Mage::helper('dataflow')->__('Starting %s :: %s', $adapterName, $adapterMethod));

        $batchModel->setParams($this->getVars())
            ->setAdapter($adapterName)
            ->save();


        return $this;
    }

}