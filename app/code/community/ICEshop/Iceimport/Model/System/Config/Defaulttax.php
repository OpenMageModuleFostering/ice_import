<?php

class ICEshop_Iceimport_Model_System_Config_Defaulttax
{
    public function toOptionArray()
    {
        $collection = Mage::getModel('tax/class')->getCollection();
        $paramsArray = array();
        $paramsArray['0'] = 'None';

        foreach ($collection as $product) {
            $tax = $product->getData();
            $paramsArray[$tax['class_id']] = $tax['class_name'];
        }

        return $paramsArray;
    }
}

?>