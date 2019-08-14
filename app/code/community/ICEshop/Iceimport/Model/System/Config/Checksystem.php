<?php

class ICEshop_Iceimport_Model_System_Config_Checksystem
{
    public function toOptionArray()
    {
        return array(
            0 => base64_encode(Mage::getSingleton('adminhtml/url')->getUrl("adminhtml/iceimport/system/"))
        );
    }
}