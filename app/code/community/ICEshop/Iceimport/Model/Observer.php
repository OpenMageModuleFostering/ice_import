<?php

class ICEshop_Iceimport_Model_Observer
{
    /**
     * Our process ID.
     * @var int
     */
    private $process_id = 'iceshop_iceimport';

    /**
     * @var array
     */
    private $indexProcess;

    protected function _construct()
    {
        $this->_init('iceimport/observer');
    }


    /**
     * load
     * @access public
     * @throws Exception
     */
    public function load()
    {
        //init logger
        $DB_logger = Mage::helper('iceimport/db');
        $date_crone_start = date('Y-m-d H:i:s');
        $date = date('m/d/Y H:i:s');
        $DB_logger->insertLogEntry('iceimport_import_status_cron', 'Started');
        $DB_logger->insertLogEntry('iceimport_import_started', $date);
        $DB_logger->insertLogEntry('iceimport_import_ended', '');
        $this->setCroneStatus('running',$date_crone_start);



        //init DB data
        $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
        $tablePrefix = '';
        $tPrefix = (array)Mage::getConfig()->getTablePrefix();
        if (!empty($tPrefix)) {
            $tablePrefix = $tPrefix[0];
        }

        //service actions
//        ini_set('max_execution_time', 360 );
//        ini_set("memory_limit", "512M");
        ini_set('max_execution_time', 0);
        ini_set("memory_limit","-1");
        $profileId = 3;
        $logFileName = 'test.log';
        $recordCount = 0;

        //checking locks
        $this->indexProcess = new Mage_Index_Model_Process();
        $this->indexProcess->setId($this->process_id);
        if ($this->indexProcess->isLocked()) {
            print 'Error! Another iceimport module cron process is running!';
            die();
        }
        $this->indexProcess->lockAndBlock();

        Mage::log("Import Started", null, $logFileName);

        //basic action fired
        $profile = Mage::getModel('dataflow/profile');
        $userModel = Mage::getModel('admin/user');
        $userModel->setUserId(0);
        Mage::getSingleton('admin/session')->setUser($userModel);
        if ($profileId) {
            $profile->load($profileId);
            if (!$profile->getId()) {
                Mage::getSingleton('adminhtml/session')->addError('The profile that you are trying to save no longer exists');
            }
        }
        $profile->run();
        $batchModel = Mage::getSingleton('dataflow/batch');

        //batch processing
        if ($batchModel->getId()) {
            if ($batchModel->getAdapter()) {

                $batchId = $batchModel->getId();
                $batchImportModel = $batchModel->getBatchImportModel();
                $importIds = $batchImportModel->getIdCollection();

                $batchModel = Mage::getModel('dataflow/batch')->load($batchId);
                $adapter = Mage::getModel($batchModel->getAdapter());
                $run_only_images = 0;
                try {
                    if (method_exists($adapter, 'getAdapterSetting')) {
                        $run_only_images = $adapter->getAdapterSetting('iceshop_iceimport_importprod_root/importprod/images_queue_processing_only');
                    }
                } catch(Exception $e) {}

                // delete previous products id
                $DB_logger->insertLogEntry('iceimport_import_status_cron', 'Running');
                try {
                    $db_res->query("DELETE FROM {$tablePrefix}iceshop_iceimport_imported_product_ids");
                } catch (Exception $e) {
                    $DB_logger->insertLogEntry('iceimport_import_status_cron', 'Failed');
                    throw new Exception($e->getMessage());
                }
                if ($run_only_images == 0) {
                    foreach ($importIds as $importId) {

                        $recordCount++;
                        try {
                            $batchImportModel->load($importId);
                            if (!$batchImportModel->getId()) {
                                $errors[] = Mage::helper('dataflow')->__('Skip undefined row');
                                continue;
                            }

                            $importData = $batchImportModel->getBatchData();
                            $importData['batchId'] = $batchId;

                            try {
                                $adapter->saveRow($importData);
                            } catch (Exception $e) {
                                $DB_logger->insertLogEntry('iceimport_import_status_cron', 'Failed');
                                Mage::log($e->getMessage(), null, $logFileName);
                                continue;
                            }

                        } catch (Exception $ex) {
                            if (!empty($importData['sku'])) {
                                Mage::log('Record# ' . $recordCount . ' - SKU = ' . $importData['sku'] . ' - Error - ' . $ex->getMessage(), null, $logFileName);
                            } else {
                                Mage::log('Record# ' . $recordCount . ' - SKU = undefined - Error - ' . $ex->getMessage(), null, $logFileName);
                            }
                        }
                    }
                }

                //run image queue processing
                $adapter->processImageQueue($logFileName);

                $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
                $processes->walk('reindexAll');

                foreach ($profile->getExceptions() as $e) {
                    Mage::log($e->getMessage(), null, $logFileName);
                }
            }
        }
        print 'Import Completed';

        //drop locks
        $this->indexProcess->unlock();

        //extra logging
        Mage::log("Import Completed", null, $logFileName);
        $count_imported_products = $DB_logger->getRowsCount($tablePrefix . "iceshop_iceimport_imported_product_ids");
        $DB_logger->insertLogEntry('iceimport_count_imported_products', $count_imported_products);

        // clear dataflow_batch_import table
        try {
            $db_res->query("DELETE FROM {$tablePrefix}iceshop_iceimport_imported_product_ids");
            $db_res->query("TRUNCATE {$tablePrefix}dataflow_batch_import");
        } catch (Exception $e) {
            $DB_logger->insertLogEntry('iceimport_import_status_cron', 'Failed');
            throw new Exception($e->getMessage());
        }
        //extra logging
        $DB_logger->insertLogEntry('iceimport_import_status_cron', 'Finished');
        $date = date('m/d/Y H:i:s');
        $DB_logger->insertLogEntry('iceimport_import_ended', $date);
        $this->setCroneStatus('finished', $date);



        unset($db_res, $DB_logger, $date, $profile, $profileId, $logFileName);
    }

    /**
     * Change crone status in table `cron_schedule`
     * @param string $status
     * @param string $date_crone_start
     */
    public function setCroneStatus($status = 'pending',$date_crone_start){
      try{
          $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
          $tablePrefix = '';
          $tPrefix = (array)Mage::getConfig()->getTablePrefix();
          if (!empty($tPrefix)) {
              $tablePrefix = $tPrefix[0];
          }
          $db_res->query("UPDATE `{$tablePrefix}cron_schedule` SET status='$status' WHERE job_code = 'iceshop_iceimport' AND executed_at='$date_crone_start'");
      } catch (Exception $e){

      }
    }

}

