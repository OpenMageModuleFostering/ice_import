<?php

class ICEshop_Iceimport_Block_Adminhtml_Images_List_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('iceimportGrid');
        $this->setUseAjax(true);
//        $this->setVarNameFilter('iceimport_filter');
//        $this->setDefaultLimit($this->getCountImagesNotImport());
//        $this->setPagerVisibility(false);
//        $this->setFilterVisibility(false);
//        $this->setSaveParametersInSession(true);
        $this->_prepareCollection;
    }

    protected function _getStore()
    {
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        return Mage::app()->getStore($storeId);
    }

    protected function _prepareCollection()
    {

        $store = $this->_getStore();
        $collection = Mage::getModel('catalog/product')->getCollection();
        $collection->addAttributeToSort('entity_id', 'DESC');

        $collection->getSelect()->joinLeft( array('pt'=> 'iceshop_iceimport_image_queue'),
                'e.`entity_id` = pt.`entity_id`', array('*'))->where("pt.`is_downloaded`=2")->limit(20, 10);

        if($this->checkExistingAttribute('catalog_product', 'sku')){
            $collection->addAttributeToSelect('sku');
        }

        if ($store->getId()) {
            $adminStore = Mage_Core_Model_App::ADMIN_STORE_ID;

            if($this->checkExistingAttribute('catalog_product', 'mpn')){
                $collection->joinAttribute(
                    'mpn',
                    'catalog_product/mpn',
                    'entity_id',
                    null,
                    'inner',
                    $adminStore
                );
            }
            $collection->joinAttribute(
                'image_url',
                'iceshop_iceimport_image_queue/image_url',
                'entity_id',
                null,
                'inner',
                $store->getId()
            );
        }
        else {
          if($this->checkExistingAttribute('catalog_product', 'mpn')){
              $collection->joinAttribute('mpn', 'catalog_product/mpn', 'entity_id', null, 'inner');
          }
        }

        $this->setCollection($collection);

        parent::_prepareCollection();
        $this->getCollection()->addWebsiteNamesToResult();
        return $this;

    }

    public function checkExistingAttribute($group, $attribut){
        $eav       = Mage::getModel('eav/config');
        $attribute = $eav->getAttribute($group, $attribut);
        return $attribute->getId();
    }


    protected function _prepareColumns()
    {
      if ($this->_isExport) {
          $this->setDefaultLimit($this->getCountImagesNotImport());
        }
       $this->addColumn('entity_id',
            array(
                'header'=> Mage::helper('catalog')->__('ID'),
                'width' => '50px',
                'type'  => 'number',
                'index' => 'entity_id',
        ));
           $this->addColumn('image_url',
                array(
                    'header'=> Mage::helper('catalog')->__('Url'),
                    'index' => 'image_url',
                    'filter' => FALSE
            ));

        if($this->checkExistingAttribute('catalog_product', 'sku')){
            $this->addColumn('sku',
                array(
                    'header'=> Mage::helper('catalog')->__('SKU'),
                    'width' => '80px',
                    'index' => 'sku',
            ));
        }

        if($this->checkExistingAttribute('catalog_product', 'mpn')){
            $this->addColumn('mpn',
              array(
                  'header'=> Mage::helper('catalog')->__('Manufacturer product number'),
                  'width' => '80px',
                  'index' => 'mpn',
          ));
          }
        $this->addExportType('*/iceimport/exportIceimportimagesCsv', Mage::helper('iceimport')->__('CSV'));
        $this->addExportType('*/iceimport/exportIceimportimagesExcel', Mage::helper('iceimport')->__('Excel XML'));

        return parent::_prepareColumns();
    }

    public function getGridUrl()
    {
        return $this->getUrl('*/iceimport/grid', array('_current'=>true));
    }

    public function getRowUrl($row)
    {
        return $this->getUrl('*/catalog_product/edit', array(
            'store'=>$this->getRequest()->getParam('store'),
            'id'=>$row->getId())
        );
    }

    public function getCountImagesNotImport(){
      try{
          $db_res = Mage::getSingleton('core/resource')->getConnection('core_write');
          $tablePrefix = '';
          $tPrefix = (array)Mage::getConfig()->getTablePrefix();
          if (!empty($tPrefix)) {
              $tablePrefix = $tPrefix[0];
          }
              return $return_resulr = $db_res->fetchOne("SELECT COUNT(*) FROM `{$tablePrefix}iceshop_iceimport_image_queue`
                                            WHERE `is_downloaded` = 2");

      } catch (Exception $e){
      }
    }

}