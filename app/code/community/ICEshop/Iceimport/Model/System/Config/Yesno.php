<?php

class Iceshop_Iceimport_Model_System_Config_Yesno
{
    public function toOptionArray()
    {
        $paramsArray = array(
            '1' => 'Yes',
            '0' => 'No'
        );
        return $paramsArray;
    }
}

?>
