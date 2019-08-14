<?php

class Iceshop_Iceimport_Model_System_Config_Lockproddetails
{
    public function toOptionArray()
    {
        $paramsArray = array(
            'import_price_stock' => 'Yes',
            'import_info' => 'No'
        );
        return $paramsArray;
    }
}

?>
