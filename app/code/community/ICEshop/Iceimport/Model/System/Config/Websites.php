<?php

class ICEshop_Iceimport_Model_System_Config_Websites
{
    public function toOptionArray()
    {
        $collection = Mage::app()->getWebsites();
        $paramsArray = array('' => '--Choose the attribute--');
        if (!empty($collection)) {
            foreach ($collection as $key => $value) {
                $paramsArray[$value->getCode()] = $value->getCode();
            }
        }

        return $paramsArray;
    }
}

?>