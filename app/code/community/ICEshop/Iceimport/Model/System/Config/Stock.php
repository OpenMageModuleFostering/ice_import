<?php

class ICEshop_Iceimport_Model_System_Config_Stock
{
    public function toOptionArray()
    {
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');

        $prefix = Mage::getConfig()->getTablePrefix();
        $sql = "SELECT * FROM {$prefix}cataloginventory_stock WHERE 1";
        $result = $read->fetchAll($sql);
        $paramsArray = array('' => '--Choose the attribute--');
        if (!empty($result)) {
            foreach ($result as $key => $value) {
                $paramsArray[$value['stock_name']] = $value['stock_name'];
            }
        }
        return $paramsArray;
    }
}

?>