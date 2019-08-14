<?php

require_once Mage::getModuleDir('controllers', 'Mage_Adminhtml') . DS . 'System' . DS . 'Convert' . DS . 'GuiController.php';

include dirname(__DIR__).DIRECTORY_SEPARATOR.'../../../include.php';

class ICEshop_Iceimport_Adminhtml_System_Convert_GuiController extends Mage_Adminhtml_System_Convert_GuiController
{

    public function batchRunAction()
    {
        if ($this->getRequest()->isPost()) {

            $batchId = $this->getRequest()->getPost('batch_id', 0);
            $rowIds  = $this->getRequest()->getPost('rows');

            /* @var $batchModel Mage_Dataflow_Model_Batch */
            $batchModel = Mage::getModel('dataflow/batch')->load($batchId);

            if (!$batchModel->getId()) {
                return;
            }
            if (!is_array($rowIds) || count($rowIds) < 1) {
                return;
            }
            if (!$batchModel->getAdapter()) {
                return;
            }

            $batchImportModel = $batchModel->getBatchImportModel();
            $importIds = $batchImportModel->getIdCollection();

            $adapter = Mage::getModel($batchModel->getAdapter());
            $adapter->setBatchParams($batchModel->getParams());
            $importData = array();

            $errors = array();
            $saved  = 0;
            foreach ($rowIds as $importId) {
                $batchImportModel->load($importId);
                if (!$batchImportModel->getId()) {
                    $errors[] = Mage::helper('dataflow')->__('Skip undefined row.');
                    continue;
                }

                try {
                    $importData[] = $batchImportModel->getBatchData();
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                    continue;
                }
                $saved ++;
            }

            $iceimport = new Iceimport();

            $date = date('m/d/Y H:i:s');

            $DB_logger = Mage::helper('iceimport/db');
            $DB_logger->insertLogEntry('iceimport_import_started', $date);

            try {
                $iceimport->importProduct($importData);
            } catch (Exception $e) {
                $iceimport->deleteTemFileForAttributes();
                $iceimport->deleteTempFileForCategories();
                $iceimport->deleteTempFileForCatalogCategoryProductInsert();
                $iceimport->deleteTempTableCats();
                $iceimport->deleteTempTableProds();
                $errors[] = $e->getMessage();
                $date = date('m/d/Y H:i:s');
                $DB_logger->insertLogEntry('iceimport_import_ended', $date);
            }

            if (method_exists($adapter, 'getEventPrefix')) {
                /**
                 * Event for process rules relations after products import
                 */
                Mage::dispatchEvent($adapter->getEventPrefix() . '_finish_before', array(
                    'adapter' => $adapter
                ));

                /**
                 * Clear affected ids for adapter possible reuse
                 */
                $adapter->clearAffectedEntityIds();
            }

            $result = array(
                'savedRows' => $saved,
                'errors'    => $errors
            );
            $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
        }
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

    public function batchFinishAction()
    {

        $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
        $tablePrefix = '';
        $tPrefix = (array)Mage::getConfig()->getTablePrefix();
        if (!empty($tPrefix)) {
            $tablePrefix = $tPrefix[0];
        }

        $DB_logger = Mage::helper('iceimport/db');
        $delete_old_products = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/delete_old_products');
        $category_sort = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/category_sort');

        $batchId = $this->getRequest()->getParam('id');
        if ($batchId) {
            $batchModel = Mage::getModel('dataflow/batch')->load($batchId);
            /* @var $batchModel Mage_Dataflow_Model_Batch */

            if ($batchModel->getId()) {
                $result = array();
                try {
                    $batchModel->beforeFinish();
                } catch (Mage_Core_Exception $e) {
                    $result['error'] = $e->getMessage();
                } catch (Exception $e) {
                    $result['error'] = Mage::helper('adminhtml')->__('An error occurred while finishing process. Please refresh the cache');
                }
                $batchModel->delete();
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
            }
        }

        $select_count_imported_products = $db_res->query("SELECT COUNT(DISTINCT product_sku) as count FROM {$tablePrefix}iceshop_iceimport_imported_product_ids WHERE product_sku IS NOT NULL;");
        $count_imported_products = $select_count_imported_products->fetch()['count'];
        $DB_logger->insertLogEntry('iceimport_count_imported_products', $count_imported_products);

        if ($delete_old_products) {
            $iceimport = new Iceimport();
            $iceimport->deleteOldProducts($DB_logger);
        }

        if ($category_sort) {
            if (!isset($iceimport)) {
                $iceimport = new Iceimport();
            }
            $iceimport->runCategoriesSorting();
        }
        try {
            $this->updateCatalogCategoryChildren();
            $db_res->query("TRUNCATE {$tablePrefix}dataflow_batch_import");
        } catch (Exception $e) {
            $DB_logger->insertLogEntry('iceimport_import_status_cron', 'Failed');
            throw new Exception($e->getMessage());
        }
        $date = date('m/d/Y H:i:s');
        $DB_logger->insertLogEntry('iceimport_import_ended', $date);
    }
}