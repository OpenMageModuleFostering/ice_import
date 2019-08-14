<?php
/*m@rk*/
class Capacitywebsolutions_Importproduct_Model_Observer
{
  
  protected function _construct() {
   $this->_init('importproduct/observer');
  }
  
  
  /**
   * load
   * @access publc
   */
  public function load() {
    
   /* 
    // test load
    $con = mysql_connect('localhost', 'test', 'test');
    $db = mysql_select_db('catch');
    $query = mysql_query("INSERT INTO detect (event, time) VALUES ('load called', NOW())");
    */
    
    
    $profileId = 3;
    $logFileName= 'test.log';
    $recordCount = 0;
    
    Mage::log("Import Started",null,$logFileName);
     
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
    if ($batchModel->getId()) {
      if ($batchModel->getAdapter()) {
        $batchId = $batchModel->getId(); 
        $batchImportModel = $batchModel->getBatchImportModel();
        $importIds = $batchImportModel->getIdCollection();
        
        $batchModel = Mage::getModel('dataflow/batch')->load($batchId);
        $adapter = Mage::getModel($batchModel->getAdapter());
        foreach ($importIds as $importId) {
          $recordCount++;
          try{
            $batchImportModel->load($importId);
            if (!$batchImportModel->getId()) {
              $errors[] = Mage::helper('dataflow')->__('Skip undefined row');
              continue;
            }

            $importData = $batchImportModel->getBatchData();

            try {
              $adapter->saveRow($importData);
            } catch (Exception $e) {
              Mage::log($e->getMessage(),null,$logFileName);          
              continue;
            }
             
          }catch (Exeption $ex) {
            Mage::log('Record# ' . $recordCount . ' - SKU = ' . $importData['sku']. ' - Error - ' . $ex->getMessage(),null,$logFileName);
          }
        
        }

        // download & set product images 
        $queueList = $adapter->getImageQueue();
        if (count($queueList) > 0) {
          $mediaDir  = Mage::getBaseDir('media');
          foreach ($queueList as $queue) {
            $queueId      = $queue['queue_id'];
            $productId    = $queue['entity_id'];
            $imageUrl     = $queue['image_url'];
            // TODO remove hardcode
            $imageUrl     = 'http://magento17.batavi.org/media/download.jpg';

            $preImageName = explode('/', $imageUrl);
            $imageName    = array_pop($preImageName);
            if (file_exists($mediaDir . DS . $imageName)) {
              // TODO remove rand()
              $imageName = rand() .'_'. time() . $imageName;
            }

            if(file_put_contents($mediaDir . DS . $imageName, file_get_contents($imageUrl))) {
              $product = Mage::getModel('catalog/product')->load($productId);
              $product->addImageToMediaGallery($mediaDir . DS . $imageName, 
                array('image', 'small_image', 'thumbnail'),
                true, true 
              );
              $product->save();
              $adapter->setImageAsDownloaded($queueId);
              echo $product->getCategory() . '<br>';
              unset($product);
            } else {
              Mage::log('Unable download file to ' . $productId, $logFileName);
              continue;
            }
          }
        }

        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
        $processes->walk('reindexAll');

        foreach ($profile->getExceptions() as $e) {
          Mage::log($e->getMessage(),null,$logFileName);          
        }        
        
      }
    }
    
    echo 'Import Completed';
    Mage::log("Import Completed",null,$logFileName);    
    
    try {
      $import = Mage::getModel('importexport/import');
    } catch (Exeptint $e) {
      Mage::log($e->getMessage(), null, $logFileName);
    }

    // get prouct download queue
  }
  
}
?>
