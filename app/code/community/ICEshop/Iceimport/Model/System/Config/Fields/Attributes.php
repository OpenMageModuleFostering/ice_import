<?php

/**
 * Class ICEshop_Iceimport_Model_System_Config_Fields_Attributes
 */
class ICEshop_Iceimport_Model_System_Config_Fields_Attributes
{
    /**
     * Magento method
     * @access public
     * @return array
     */
    public function toOptionArray()
    {
        $paramsArray = array('' => '--Choose the attribute--');
        $attribute_set_id = Mage::getModel('catalog/product')->getResource()->getEntityType()->getDefaultAttributeSetId();
        if (!empty($attribute_set_id)) {
            return array_merge($paramsArray, $this->getAttributesArrayBySetName($attribute_set_id));
        }
        return $paramsArray;
    }

    /**
     * Fetch attributes in desired attribute set
     * @access protected
     * @param $attribute_set_id
     * @return array
     */
    protected function getAttributesArrayBySetName($attribute_set_id)
    {
        if (!empty($attribute_set_id)) {
            $params_array = array();
            $attributes = Mage::getResourceModel('eav/entity_attribute_collection');
            $attributes = $attributes->setAttributeSetFilter($attribute_set_id)
                ->getItems();

            foreach ($attributes as $attribute) {
                $params_array[$attribute->getAttributeCode()] = $attribute->getAttributeCode();
            }
            ksort($params_array);
            return $params_array;
        }
        return array();
    }

    /**
     * Get attribute set ID by name and entity type ID
     * @access protected
     * @param $attribute_set_name
     * @param $entity_type_id
     * @return bool
     */
    protected function getAttributeSetId($attribute_set_name, $entity_type_id)
    {
        if (!empty($attribute_set_name) && !empty($entity_type_id)) {
            $attribute_set_collection = Mage::getResourceModel('eav/entity_attribute_set_collection')
                ->setEntityTypeFilter($entity_type_id)
                ->getItemsByColumnValue('attribute_set_name', $attribute_set_name);
            foreach ($attribute_set_collection as $attr) {
                $attribute_set_id = $attr->getId();
                break;
            }
            return (!empty($attribute_set_id) ? $attribute_set_id : false);
        }
        return false;
    }

    /**
     * Get entity type ID by name
     * @access protected
     * @param $entity_type_name
     * @return bool
     */
    protected function getEntityTypeId($entity_type_name)
    {
        if (!empty($entity_type_name)) {
            $entity_type_id = Mage::getModel($entity_type_name)
                ->getResource()
                ->getTypeId();
            return (!empty($entity_type_id) ? $entity_type_id : false);
        }
        return false;
    }
}