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
        $DB_logger->deleteLogEntry('error_try_delete_product');
        $DB_logger->deleteLogEntry('error_try_delete_product_percentage');
        $this->setCroneStatus('running',$date_crone_start);

        //init DB data
        $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
        $tablePrefix = '';
        $tPrefix = (array)Mage::getConfig()->getTablePrefix();
        if (!empty($tPrefix)) {
            $tablePrefix = $tPrefix[0];
        }

        //service actions
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
            $this->updateCatalogCategoryChildren();
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


    /**
     * Method import imges for product
     */
    public function importImages(){

        $DB_logger = Mage::helper('iceimport/db');
        $import_info = array();
        if($_GET['import_run']==1){
            $iceimport_count_images = $this->getCountImagesNotImport($_GET['update_images']);
            $DB_logger->insertLogEntry('iceimport_count_images', $iceimport_count_images);
        } else {
          $iceimport_count_images = $DB_logger->getLogEntryByKey('iceimport_count_images');
          if(!empty($iceimport_count_images)){
              $iceimport_count_images = $iceimport_count_images ['log_value'];
          }
        }

        $iceimport_current_images_import = $DB_logger->getLogEntryByKey('iceimport_current_images_import');
        if(!empty($iceimport_current_images_import)){
            $iceimport_current_images_import = $iceimport_current_images_import['log_value'];
        }

        if(empty($iceimport_count_images)){
          $iceimport_count_images = $this->getCountImagesNotImport($_GET['update_images']);
          $DB_logger->insertLogEntry('iceimport_count_images', $iceimport_count_images);
        }
        $import_info['count_images'] = $iceimport_count_images;


        if(empty($iceimport_current_images_import)){
          $iceimport_current_images_import = 1;
          $DB_logger->insertLogEntry('iceimport_current_images_import', $iceimport_current_images_import);
        } else {
          $iceimport_current_images_import = $iceimport_current_images_import + 1;
          $DB_logger->insertLogEntry('iceimport_current_images_import', $iceimport_current_images_import);
        }
        if($_GET['import_run']==1){
          $iceimport_current_images_import = 1;
          $DB_logger->insertLogEntry('iceimport_current_images_import', $iceimport_current_images_import);
        }
        $import_info['current_images_import'] = $iceimport_current_images_import;

        // download & set product images
        $queueList = $this->getImageResourceOne($_GET['update_images']);
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
                    $import_info['images_error'] = 0;
                } else {
                  $this->setImageAsDownloadedError($queueId);

                  $iceimport_images_error_entity_id = $DB_logger->getLogEntryByKey('iceimport_images_error_entity_id');
                  if(empty($iceimport_images_error_entity_id)){
                    $DB_logger->insertLogEntry('iceimport_images_error_entity_id', $productId);
                    $DB_logger->insertLogEntry('iceimport_images_error_entity_id_log', $productId);
                  } else {
                    if(!empty($iceimport_images_error_entity_id)){
                        $iceimport_images_error_entity_id = $iceimport_images_error_entity_id['log_value'];
                        $DB_logger->insertLogEntry('iceimport_images_error_entity_id_log', $iceimport_images_error_entity_id . ', ' . $productId);
                        $DB_logger->insertLogEntry('iceimport_images_error_entity_id', $iceimport_images_error_entity_id . ', ' . $productId);

                    }
                  }
                  $import_info['images_error'] = 1;
                  $import_info['images_error_text'] = 'Requested file is not accessible. '.$imageUrl;
                }
            }
          $import_info['done'] = 0;
        } else {
          $import_info['done'] = 1;
          if($import_info['count_images'] == 0){
              $import_info['current_images_import'] = 0;
          }
          $DB_logger->deleteLogEntry('iceimport_current_images_import');
          $DB_logger->deleteLogEntry('iceimport_count_images');
        }

        if($import_info['current_images_import'] == $import_info['count_images'] || $import_info['done'] == 1){
            $import_info['done'] = 1;
            $DB_logger->deleteLogEntry('iceimport_images_error_entity_id');
            $DB_logger->deleteLogEntry('iceimport_current_images_import');
            $DB_logger->deleteLogEntry('iceimport_count_images');
        }elseif ($import_info['count_images'] == 0 || $import_info['current_images_import'] == 0) {
            $import_info['count_images'] = 0;
            $import_info['current_images_import'] = 0;

            $import_info['done'] = 1;
            $DB_logger->deleteLogEntry('iceimport_current_images_import');
            $DB_logger->deleteLogEntry('iceimport_count_images');
            echo json_encode($import_info);
            exit();
        } else {
              $import_info['done'] = 0;
        }
        echo json_encode($import_info);

    }

   /**
     * @return mixed
     */
    public function getImageResourceOne($update = 0)
    {
      try{
          $DB_logger = Mage::helper('iceimport/db');
          $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
          $tablePrefix = '';
          $tPrefix = (array)Mage::getConfig()->getTablePrefix();
          if (!empty($tPrefix)) {
              $tablePrefix = $tPrefix[0];
          }

          $iceimport_images_error_entity_id = $DB_logger->getLogEntryByKey('iceimport_images_error_entity_id');
          if(empty($iceimport_images_error_entity_id)){
            if($update){
              return $db_res->fetchAll("SELECT `queue_id`, `entity_id`, `image_url`
                                                    FROM `{$tablePrefix}iceshop_iceimport_image_queue`
                                                    WHERE `is_downloaded` = 2 LIMIT 1");
            }else{
              return $db_res->fetchAll("SELECT `queue_id`, `entity_id`, `image_url`
                                                    FROM `{$tablePrefix}iceshop_iceimport_image_queue`
                                                    WHERE `is_downloaded` = 0 LIMIT 1");
            }

          } else {
            $iceimport_images_error_entity_id = $iceimport_images_error_entity_id['log_value'];
            if($update){
              return $db_res->fetchAll("SELECT `queue_id`, `entity_id`, `image_url`
                                                    FROM `{$tablePrefix}iceshop_iceimport_image_queue`
                                            WHERE `is_downloaded` = 2 AND entity_id NOT IN({$iceimport_images_error_entity_id}) LIMIT 1");
            } else {
              return $db_res->fetchAll("SELECT `queue_id`, `entity_id`, `image_url`
                                                    FROM `{$tablePrefix}iceshop_iceimport_image_queue`
                                            WHERE `is_downloaded` = 0 AND entity_id NOT IN({$iceimport_images_error_entity_id}) LIMIT 1");
            }

          }

      } catch (Exception $e){
      }
    }

    /**
     * Method fetch count not import images for product
     * @return integer
     */
    public function getCountImagesNotImport($update = 0){
      try{
          $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
          $tablePrefix = '';
          $tPrefix = (array)Mage::getConfig()->getTablePrefix();
          if (!empty($tPrefix)) {
              $tablePrefix = $tPrefix[0];
          }
          if($update){
              return $return_resulr = $db_res->fetchOne("SELECT COUNT(*) FROM `{$tablePrefix}iceshop_iceimport_image_queue`
                                            WHERE `is_downloaded` = 2");
          } else {
              return $return_resulr = $db_res->fetchOne("SELECT COUNT(*) FROM `{$tablePrefix}iceshop_iceimport_image_queue`
                                            WHERE `is_downloaded` = 0");
          }
      } catch (Exception $e){
      }
    }



    /**
     * @param bool $queueId
     */
    private function setImageAsDownloaded($queueId = false)
    {
        if ($queueId) {
          $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
          $tablePrefix = '';
          $tPrefix = (array)Mage::getConfig()->getTablePrefix();
          if (!empty($tPrefix)) {
              $tablePrefix = $tPrefix[0];
          }
            $db_res->query("UPDATE `{$tablePrefix}iceshop_iceimport_image_queue`
                    SET is_downloaded = 1
                    WHERE queue_id = {$queueId}");
        }
    }


    /**
     * @param bool $queueId
     */
    private function setImageAsDownloadedError($queueId = false)
    {
        if ($queueId) {
            $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
            $tablePrefix = '';
            $tPrefix = (array)Mage::getConfig()->getTablePrefix();
            if (!empty($tPrefix)) {
                $tablePrefix = $tPrefix[0];
            }
            $db_res->query(
                "UPDATE `{$tablePrefix}iceshop_iceimport_image_queue`
                    SET is_downloaded = 2
                    WHERE queue_id = :queue_id",
                array(':queue_id' => $queueId)
            );
        }
    }
}

