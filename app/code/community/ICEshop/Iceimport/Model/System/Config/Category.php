<?php

class ICEshop_Iceimport_Model_System_Config_Category
{

    public function toOptionArray()
    {

        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $prefix = Mage::getConfig()->getTablePrefix();

        $select_category_entity_type = $read->query("SELECT entity_type_id FROM `{$prefix}eav_entity_type` WHERE entity_type_code = 'catalog_category';");
        $category_entity_type = $select_category_entity_type->fetch()['entity_type_id'];

        $select_name_id = $read->query("SELECT attribute_id FROM `{$prefix}eav_attribute` WHERE attribute_code = 'name' AND entity_type_id = $category_entity_type;");
        $name_id = $select_name_id->fetch()['attribute_id'];

        $sql = "SELECT ccev.value, cce.entity_id FROM {$prefix}catalog_category_entity cce JOIN {$prefix}catalog_category_entity_varchar ccev ON cce.entity_id = ccev.entity_id WHERE cce.level = 1 AND attribute_id = $name_id;";
        $result = $read->fetchAll($sql);

        $paramsArray = [];

        if (!empty($result)) {
            foreach ($result as $key => $value) {
                $paramsArray[$value['entity_id']] = $value['value'];
            }
        }

        return $paramsArray;
    }
}

?>