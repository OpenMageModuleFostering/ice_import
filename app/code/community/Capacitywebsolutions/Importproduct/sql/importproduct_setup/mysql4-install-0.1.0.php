<?php

  $installer = $this;
  $installer->startSetup();

  $entityTypeId     = $installer->getEntityTypeId('catalog_category');
  $attributeSetId   = $installer->getDefaultAttributeSetId($entityTypeId);
  $attributeGroupId = $installer->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
  
  $installer->addAttribute('catalog_category', 'unspsc',  array(
    'type'     => 'varchar',
    'label'    => 'unspsc',
    'input'    => 'text',
    'global'   => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE,
    'visible'           => true,
    'required'          => false,
    'user_defined'      => false,
    'default'           => 0
  ));
  
  $installer->addAttributeToGroup(
    $entityTypeId,
    $attributeSetId,
    $attributeGroupId,
    'unspsc',
    '11'
  );  
  
  $attributeId = $installer->getAttributeId($entityTypeId, 'unspsc');
  
  $installer->run("
    INSERT INTO `{$installer->getTable('catalog_category_entity_varchar')}`
    (`entity_type_id`, `attribute_id`, `entity_id`, `value`)
    SELECT '{$entityTypeId}', '{$attributeId}', `entity_id`, '1'
    FROM `{$installer->getTable('catalog_category_entity')}`;
  ");

  /*  
  // set attribute to root category  
  Mage::getModel('catalog/category')
      ->load(1)
      ->setImportedCatId(0)
      ->setInitialSetupFlag(true)
      ->save(); 

  Mage::getModel('catalog/category')
    ->load(2)
    ->setImportedCatId(0)
    ->setInitialSetupFlag(true)
    ->save();
  */
  $installer->endSetup();
 
?>
