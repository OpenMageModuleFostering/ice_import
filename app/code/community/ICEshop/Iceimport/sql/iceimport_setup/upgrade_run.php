<?php

$installer = $this;
$installer->startSetup();

$installer->run("
        INSERT IGNORE INTO `{$installer->getTable('iceshop_extensions_logs')}`
        SET `log_key` = 'first_start', `log_value` = 'yes', `log_type` = 'info';
");

$installer->endSetup();