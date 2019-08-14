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

  }
  
}
?>
