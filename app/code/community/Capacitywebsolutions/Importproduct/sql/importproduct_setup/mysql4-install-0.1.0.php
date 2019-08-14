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

    CREATE TABLE IF NOT EXISTS `{$installer->getTable('capacity_product_image_queue')}` 
    (
      `queue_id`  INT(10) NOT NULL AUTO_INCREMENT,
      `entity_id` INT(10) UNSIGNED NOT NULL,
      `image_url` VARCHAR(255) NOT NULL,
      `is_downloaded` TINYINT NOT NULL DEFAULT 0,
      PRIMARY KEY(`queue_id`),
      UNIQUE KEY (`entity_id`, `image_url`),
      CONSTRAINT `FK_CAP_PRD_IMG_QUEUE_ENTT_ID_CAT_PRD_ENTT_ENTT_ID` FOREIGN KEY (`entity_id`) REFERENCES `{$installer->getTable('catalog_product_entity')}` (`entity_id`) ON DELETE CASCADE
    )ENGINE=InnoDB CHARSET=utf8 COMMENT='Table to manage product image import';
  ");

  $installer->endSetup();
 
?>
