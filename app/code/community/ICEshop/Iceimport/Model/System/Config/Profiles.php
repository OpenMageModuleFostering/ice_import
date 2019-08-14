<?php

class ICEshop_Iceimport_Model_System_Config_Profiles
{
    public function toOptionArray()
    {

        $profileCollection = Mage::getModel('dataflow/profile')->getCollection();
        $paramsArray = [];

        foreach ($profileCollection as $profile) {
            $paramsArray[$profile->getId()] = $profile->getName();
        }

        return $paramsArray;
    }
}

?>