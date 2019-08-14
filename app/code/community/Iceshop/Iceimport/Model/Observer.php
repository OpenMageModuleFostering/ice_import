<?php
class Iceshop_Iceimport_Model_Observer
{
    /**
     * Our process ID.
     */
    private $process_id = 'iceshop_iceimport';
    private $indexProcess;

    protected function _construct()
    {
        $this->_init('iceimport/observer');
    }


    /**
     * load
     * @access publc
     */
    public function load()
    {

        $profileId = 3;
        $logFileName = 'test.log';
        $recordCount = 0;
        $this->indexProcess = new Mage_Index_Model_Process();
        $this->indexProcess->setId($this->process_id);

        if ($this->indexProcess->isLocked()) {
            echo 'Error! Another iceimport module cron process is running!';
            die();
        }

        $this->indexProcess->lockAndBlock();

        Mage::log("Import Started", null, $logFileName);

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
                // delete previous products id
                $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
                $tablePrefix = '';
                $tPrefix = (array)Mage::getConfig()->getTablePrefix();
                if (!empty($tPrefix)) {
                    $tablePrefix = $tPrefix[0];
                }

                try {
                    $db_res->query('DELETE FROM ' . $tablePrefix . 'iceshop_iceimport_imported_product_ids');
                } catch (Exception $e) {
                    throw new Exception($e->getMessage());
                }
                foreach ($importIds as $importId) {

                    $recordCount++;
                    try {
                        $batchImportModel->load($importId);
                        if (!$batchImportModel->getId()) {
                            $errors[] = Mage::helper('dataflow')->__('Skip undefined row');
                            continue;
                        }

                        $importData = $batchImportModel->getBatchData();

                        try {
                            $adapter->saveRow($importData);
                        } catch (Exception $e) {
                            Mage::log($e->getMessage(), null, $logFileName);
                            continue;
                        }

                    } catch (Exeption $ex) {
                        Mage::log('Record# ' . $recordCount . ' - SKU = ' . $importData['sku'] . ' - Error - ' . $ex->getMessage(), null, $logFileName);
                    }

                }

                // delete old products
                try {
                    $db_res->query("SELECT @is_iceimport_id := `attribute_id` FROM " . $tablePrefix . "eav_attribute WHERE attribute_code = 'is_iceimport'");
                    $db_res->query("DELETE cpe FROM " . $tablePrefix . "catalog_product_entity AS cpe
              JOIN " . $tablePrefix . "catalog_product_entity_varchar AS cpev ON cpe.entity_id = cpev.entity_id AND cpev.value = 1 AND cpev.attribute_id = @is_iceimport_id
              LEFT JOIN " . $tablePrefix . "iceshop_iceimport_imported_product_ids AS iip ON cpe.entity_id = iip.product_id
              WHERE iip.product_id IS NULL");

                } catch (Exception $e) {
                    throw new Exception($e->getMessage());
                }


                // download & set product images
                $queueList = $adapter->getImageQueue();
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
                            $adapter->setImageAsDownloaded($queueId);
                            echo $product->getCategory() . '<br>';
                            unset($product);
                        } else {
                            Mage::log('Unable download file to ' . $productId, $logFileName);
                            continue;
                        }
                    }
                }
                // sort  category in abc

                $catCollection = Mage::getModel('catalog/category')
                    ->getCollection()
                    ->addAttributeToSort('name', 'ASC');
                $position = 1;
                foreach ($catCollection as $category) {
                    $catSource = Mage::getModel('catalog/category')->load($category->getId());
                    $catSource->setData('position', $position);
                    $catSource->save();
                    $query = "SELECT COUNT(*) FROM `" . $tablePrefix . "catalog_category_product` WHERE category_id = :cat_id ";
                    $cat_products = $db_res->fetchRow($query, array(
                        ':cat_id' => $category->getId()
                    ));

                    if ($cat_products['COUNT(*)'] == 0) {
                        $query = "SELECT `entity_id` FROM `" . $tablePrefix . "catalog_category_entity` WHERE parent_id = :cat_id";
                        $child_cat = $db_res->fetchAll($query, array(
                            ':cat_id' => $category->getId()
                        ));
                        $cat_prod = 0;

                        if (isset($child_cat) && count($child_cat) > 0) {

                            foreach ($child_cat as $cat) {
                                $query = "SELECT COUNT(*) FROM `" . $tablePrefix . "catalog_category_product` WHERE category_id = :cat_id ";
                                $cat_products = $db_res->fetchRow($query, array(
                                    ':cat_id' => $cat['entity_id']
                                ));

                                if ($cat_products['COUNT(*)'] != 0) {
                                    $cat_prod = 1;
                                }

                            }

                            if ($cat_prod == 0) {
                                $db_res->query("UPDATE `" . $tablePrefix . "catalog_category_entity_int`
                                            SET `value` = 0 WHERE `attribute_id` = @category_active_id AND entity_id = :cat_id", array(
                                        ':cat_id' => $category->getId()
                                    ));
                            }
                        } else {
                            $db_res->query("UPDATE `" . $tablePrefix . "catalog_category_entity_int`
                                           SET `value` = 0 WHERE `attribute_id` = @category_active_id AND entity_id = :cat_id", array(
                                    ':cat_id' => $category->getId()
                                ));
                        }
                    }
                    $position++;
                }


                $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
                $processes->walk('reindexAll');

                foreach ($profile->getExceptions() as $e) {
                    Mage::log($e->getMessage(), null, $logFileName);
                }

            }
        }

        unset($db_res);
        echo 'Import Completed';
        $this->indexProcess->unlock();
        Mage::log("Import Completed", null, $logFileName);

        try {
            $import = Mage::getModel('importexport/import');
        } catch (Exeptint $e) {
            Mage::log($e->getMessage(), null, $logFileName);
        }

        // get prouct download queue
    }

}

?>
