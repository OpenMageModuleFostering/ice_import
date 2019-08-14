<?php
include_once 'uninstall-old-version.php';
$unistaller_old_version = new Uninstall_Capacitywebsolutions_Importproduct();
$unistaller_old_version->uninstall();
$installer = $this;
$installer->startSetup();

$entityTypeId = $installer->getEntityTypeId('catalog_category');
$attributeSetId = $installer->getDefaultAttributeSetId($entityTypeId);
$attributeGroupId = $installer->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);

$installer->addAttribute('catalog_category', 'unspsc', array(
    'type' => 'varchar',
    'label' => 'unspsc',
    'input' => 'text',
    'global' => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE,
    'visible' => true,
    'required' => false,
    'user_defined' => false,
    'default' => 0
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

        DROP TABLE IF EXISTS `{$installer->getTable('iceimport_imported_product_ids')}`;
	    DROP TABLE IF EXISTS `{$installer->getTable('capacity_product_image_queue')}`;

        INSERT IGNORE INTO `{$installer->getTable('catalog_category_entity_varchar')}`
        (`entity_type_id`, `attribute_id`, `entity_id`, `value`)
        SELECT '{$entityTypeId}', '{$attributeId}', `entity_id`, '1'
        FROM `{$installer->getTable('catalog_category_entity')}`;

        CREATE TABLE IF NOT EXISTS `{$installer->getTable('iceshop_iceimport_image_queue')}`
        (
          `queue_id`  INT(10) NOT NULL AUTO_INCREMENT,
          `entity_id` INT(10) UNSIGNED NOT NULL,
          `image_url` VARCHAR(255) NOT NULL,
          `is_downloaded` TINYINT NOT NULL DEFAULT 0,
          PRIMARY KEY(`queue_id`),
          UNIQUE KEY (`entity_id`, `image_url`),
          CONSTRAINT `FK_CAP_PRD_IMG_QUEUE_ENTT_ID_CAT_PRD_ENTT_ENTT_ID` FOREIGN KEY (`entity_id`) REFERENCES `{$installer->getTable('catalog_product_entity')}` (`entity_id`) ON DELETE CASCADE
        )ENGINE=InnoDB CHARSET=utf8 COMMENT='Table to manage product image import';

        CREATE TABLE IF NOT EXISTS `{$installer->getTable('iceshop_iceimport_imported_product_ids')}` (
          `product_id` int(11) NOT NULL,
          `product_sku` varchar(255) DEFAULT NULL,
          KEY `pi_idx` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;

        CREATE TABLE IF NOT EXISTS {$this->getTable('iceshop_extensions_logs')} (
		`log_key` VARCHAR(255) NOT NULL,
		`log_value` varchar(255) DEFAULT NULL,
		UNIQUE KEY (`log_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Iceshop Connector logs';
      ");

$installer->endSetup();
