<?php

require_once Mage::getModuleDir('controllers', 'Mage_Adminhtml') . DS . 'System' . DS . 'Convert' . DS . 'GuiController.php';

class ICEshop_Iceimport_Adminhtml_System_Convert_GuiController extends Mage_Adminhtml_System_Convert_GuiController
{

    public function batchRunAction()
    {

        if ($this->getRequest()->isPost()) {

            $transactions_enabled = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/transactions_enabled');
            $batchId = $this->getRequest()->getPost('batch_id', 0);
            $rowIds  = $this->getRequest()->getPost('rows');
            $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
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

            if ($transactions_enabled != 0) {
                try {
                    $db_res->beginTransaction();
                    $adapter->saveRow($importData, true);
                    $db_res->commit();
                } catch (Exception $e) {
                    $db_res->rollBack();
                    throw $e;
                }
            }
            else {
                $adapter->saveRow($importData);
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
}