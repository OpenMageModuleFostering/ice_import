<?php

class Iceimport
{

    public $arFields = false;

    public $db_magento = null;

    public $db_config = [];

    public $tablePrefix = false;

    public $arrayForPostfixes = [];

    public $stores = [];

    public $count_all_products = 0;

    public $batch = 100000;

    public $end = 0;

    public $filenameCats = 'file.csv';

    public $filenameValues = 'category_to_product.csv';

    public $tempFeed = 'tempFeed.csv';

    public $tempAttr = 'attr.csv';

    public function __construct ()
    {

        $config = Mage::getConfig()->getResourceConnectionConfig("default_setup");

        $dbinfo = ["host" => $config->host,
            "user" => $config->username,
            "pass" => $config->password,
            "dbname" => $config->dbname
        ];

        $hostname = $dbinfo["host"];
        $user = $dbinfo["user"];
        $password = $dbinfo["pass"];
        $dbname = $dbinfo["dbname"];

        $this->db_config = [
            'host' => $hostname,
            'username' => $user,
            'password' => $password,
            'dbname' => $dbname,
            'driver_options' => [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES UTF8', PDO::MYSQL_ATTR_LOCAL_INFILE => true]
        ];

        $this->db_magento = Zend_Db::factory('Pdo_Mysql', $this->db_config);

        $tablePrefix = (array)Mage::getConfig()->getTablePrefix();

        if (!empty($tablePrefix)) {
            $this->tablePrefix = $tablePrefix[0];
        }

        if (!empty(Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/batch_size'))) {
            $this->batch = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/batch_size');
        }
    }

    public function makeFileFromArrayForManualLaunching ($importProduct)
    {

        $fp = fopen($this->tempFeed, 'w+');

        fputcsv($fp, array_keys($importProduct[0]), "\t");

        foreach ($importProduct as $fields) {
            fputcsv($fp, $fields, "\t");
        }

        fclose($fp);
    }

    public function getGuiData ()
    {

        $profileId = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/iceimport_profile');

        if (!$profileId) {
            $profileId = 3;
        }

        $select_gui_data = $this->db_magento->query("SELECT gui_data FROM `{$this->tablePrefix}dataflow_profile` WHERE profile_id = $profileId;");
        $gui_data = $select_gui_data->fetch()['gui_data'];
        $gui_data = unserialize($gui_data);
        return $gui_data;
    }

    public function getFileByFTP ()
    {

        $gui_data = $this->getGuiData();
        $file_name = $gui_data['file']['filename'];
        $url = $gui_data['file']['host'];
        $login = $gui_data['file']['user'];
        $password = $gui_data['file']['password'];
        $file_mode = $gui_data['file']['file_mode'];
        $passive_mode = $gui_data['file']['passive'];
        $path = $gui_data['file']['path'];
        $tmp_file = sys_get_temp_dir() . DS . time() . '_' . $file_name;

        if (file_exists($tmp_file)) {
            unlink($tmp_file);
        }

        $conn_id = ftp_connect($url);

        if (empty($conn_id)) {
            throw new \Exception('Cannot connect by ftp for shop');
        }

        $login_result = ftp_login($conn_id, $login, $password);

        if (empty($login_result)) {
            ftp_close($conn_id);
            throw new \Exception('Cannot login on ftp server for shop');
        }
        //set passive mode
        if ($passive_mode == 1) {
            ftp_pasv($conn_id, true);
        }

        $size = ftp_size($conn_id, $path . $file_name);
        if ($size > 0) {

            $res = ftp_get($conn_id, $tmp_file, $path . $file_name, $file_mode);

            if (empty($res)) {
                ftp_close($conn_id);
                throw new \Exception('Cannot get file from ftp server for shop');
            }
        } else {
            throw new \Exception('File on FTP not exists or empty');
        }

        ftp_close($conn_id);

        return $tmp_file;
    }

    public function getDelimiterFromProfile ()
    {

        $gui_data = $this->getGuiData();
        $delimiter = $gui_data['parse']['delimiter'];

        if ($delimiter == '\t') {
            $delimiter = "\t";
        }

        return $delimiter;

    }

    public function sendToDb ($importProduct = [])
    {

        $db_helper = Mage::helper('iceimport/db');

        $gui_data = $this->getGuiData();
        $if_ftp = $gui_data['file']['type'];
        $delimiter = $this->getDelimiterFromProfile();
        $file_name = $gui_data['file']['filename'];

        $db_helper->insertLogEntry('import_filename', $file_name, 'info');

        if (!defined('MAGENTO_ROOT')) {
            define('MAGENTO_ROOT', getcwd());
        }

        $filepath = MAGENTO_ROOT.DS.$gui_data['file']['path'].DS.$file_name;

        if (!empty($importProduct)) {

            $this->makeFileFromArrayForManualLaunching($importProduct);
            $filepath = $this->tempFeed;
        }

        if ($if_ftp == 'ftp') {
            $filepath = $this->getFileByFTP();
        }

        $fieldsForCreatingTable = '`id` INT(10) NOT NULL PRIMARY KEY AUTO_INCREMENT, ';

        $fieldsAr = fgetcsv(fopen($filepath, "r"));

        if (!$fieldsAr) {
            $this->db_magento->query("INSERT INTO `{$this->tablePrefix}iceshop_extensions_logs` (`log_key`, `log_value`, `log_type`, `timecol`) VALUES ('errorFile', 'Can not find file', 'stat', NOW());");
            echo "Can not find file. \n";
            exit;
        }

        $fields = str_replace($delimiter, ',', $fieldsAr[0]);

        $arrayForTable = explode(',', $fields);

        foreach ($arrayForTable as $key => $field) {

            if ($field == "") {
                $this->db_magento->query("INSERT INTO `{$this->tablePrefix}iceshop_extensions_logs` (`log_key`, `log_value`, `log_type`, `timecol`) VALUES ('errorColumn', 'Empty column name', 'stat', NOW());");
                echo "Empty column name. \n";
                exit;
            }
            if (stristr($field, '_id') || stristr($field, 'quantity') || stristr($field, 'is_in_stock') || stristr($field, 'central_stock')) {
                $fieldsForCreatingTable .= "`$field` INT(10) DEFAULT NULL, ";
                $this->arrayForPostfixes['int'][] = $field;
            }

            elseif ($field == 'sku') {
                $fieldsForCreatingTable .= "`$field` VARCHAR(64) NOT NULL, ";
            }

            elseif (stristr($field, 'price') || stristr($field, 'weight') || stristr($field, 'cost') || $field == 'qty') {
                $fieldsForCreatingTable .= "`$field` DECIMAL(12,4) DEFAULT NULL, ";
                $this->arrayForPostfixes['decimal'][] = $field;
            }

            elseif ((stristr($field, 'description') && !stristr($field, 'short_') && !stristr($field, 'long_')) || stristr($field, 'categories') || stristr($field, '_tree')) {
                $fieldsForCreatingTable .= "`$field` TEXT DEFAULT NULL, ";
                $this->arrayForPostfixes['text'][] = $field;
            }

            elseif (stristr($field, 'tax')) {
                $fieldsForCreatingTable .= "`$field` DECIMAL(12,4) DEFAULT NULL, ";
                $this->arrayForPostfixes['taxes'][] = $field;
            }

            else {
                $fieldsForCreatingTable .= "`$field` VARCHAR(128) DEFAULT NULL, ";
                $this->arrayForPostfixes['varchar'][] = $field;
            }

            if ($field == 'visibility' || $field == 'status') {
                $this->arrayForPostfixes['int'][] = $field;
            }
        }

        $fieldsForCreatingTable .= '`entity_id` int(10) DEFAULT NULL,`attribute_set_id` smallint(5) DEFAULT NULL, `entity_type_id` smallint(5) DEFAULT NULL, `tax_class_id` int(10) DEFAULT NULL, `is_iceimport` VARCHAR(64) DEFAULT 1, `url_key` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';

        $this->arrayForPostfixes['int'][] = 'tax_class_id';
        $this->arrayForPostfixes['varchar'][] = 'is_iceimport';
        $this->arrayForPostfixes['varchar'][] = 'url_key';

        $this->db_magento->query("DROP TABLE IF EXISTS {$this->tablePrefix}import_feed;");
        $this->db_magento->query("CREATE TABLE IF NOT EXISTS {$this->tablePrefix}import_feed($fieldsForCreatingTable) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8");
        $this->db_magento->query("LOAD DATA LOCAL INFILE '{$filepath}' IGNORE INTO TABLE {$this->tablePrefix}import_feed FIELDS TERMINATED BY '$delimiter' ENCLOSED BY '' LINES TERMINATED BY '\n' IGNORE 1 LINES ($fields);");
    }

    public function getDefaultStoreId ()
    {

        $website_code = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/websites');

        $select_website_id = $this->db_magento->query("SELECT website_id FROM `{$this->tablePrefix}core_website` WHERE `code` = '$website_code';");
        $website_id = $select_website_id->fetch()['website_id'];

        $select_default_store_id = $this->db_magento->query("SELECT default_store_id FROM `{$this->tablePrefix}core_store_group` WHERE website_id = $website_id;");
        $default_store_id = $select_default_store_id->fetch()['default_store_id'];

        return $default_store_id;
    }

    public function updateFeedTable ($importProduct = [])
    {

        $this->sendToDb($importProduct);

        $defaulttaxConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/default_tax');

        $select_product_entity_type_id = $this->db_magento->query("SELECT entity_type_id FROM `{$this->tablePrefix}eav_entity_type` WHERE entity_type_code = 'catalog_product';");
        $product_entity_type_id = $select_product_entity_type_id->fetch()['entity_type_id'];
        $select_attribute_set_id = $this->db_magento->query("SELECT `default_attribute_set_id` FROM `{$this->tablePrefix}eav_entity_type` WHERE entity_type_code = 'catalog_product';");
        $attribute_set_id = $select_attribute_set_id->fetch()['default_attribute_set_id'];
        $this->db_magento->query("UPDATE `{$this->tablePrefix}import_feed` SET `entity_type_id` = $product_entity_type_id;");
        $this->db_magento->query("UPDATE `{$this->tablePrefix}import_feed` SET `attribute_set_id` = $attribute_set_id;");
        $this->db_magento->query("DELETE FROM `{$this->tablePrefix}import_feed` WHERE sku = '';");

        foreach ($this->arrayForPostfixes['varchar'] as $i) {

            $this->db_magento->query("UPDATE `{$this->tablePrefix}import_feed` SET $i = TRIM(BOTH '\"' FROM $i);");
        }

        $update_visibility = "UPDATE `{$this->tablePrefix}import_feed` SET `visibility` = CASE WHEN visibility = 'Not Visible Individually' THEN ". Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE. " WHEN visibility = 'Catalog' THEN ". Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG. " WHEN visibility = 'Search' THEN ". Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH. " WHEN visibility = 'Catalog, Search' THEN ". Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH. " ELSE visibility END;";
        $this->db_magento->query($update_visibility);

        $update_status = "UPDATE `{$this->tablePrefix}import_feed` SET `status` = CASE WHEN status = 'Enabled' THEN ". Mage_Catalog_Model_Product_Status::STATUS_ENABLED ." ELSE ". Mage_Catalog_Model_Product_Status::STATUS_DISABLED ." END;";
        $this->db_magento->query($update_status);

        $this->db_magento->query("UPDATE `{$this->tablePrefix}import_feed` SET `tax_class_id` = $defaulttaxConf;");
        $this->db_magento->query("UPDATE `{$this->tablePrefix}import_feed` SET `url_key` = LOWER(REPLACE(REPLACE(REPLACE(REPLACE(name, ' ', '-'), '/', '-'), '*', ''), ',', ''));");

        // Update stores

        $default_store_id = $this->getDefaultStoreId();
        $stores_array = $this->db_magento->fetchAll("SELECT `store_id`, `code` FROM {$this->tablePrefix}core_store WHERE store_id <> 0;");
        $this->stores = $stores_array;
        $query_for_store_update = "UPDATE `{$this->tablePrefix}import_feed` SET `store` = CASE WHEN store = 'default' THEN $default_store_id ";

        foreach ($stores_array as $store) {

            $store_code = $store['code'];
            $store_id = $store['store_id'];
            $query_for_store_update .= "WHEN store = '$store_code' THEN $store_id ";
        }

        $query_for_store_update .= 'ELSE store END;';

        $this->db_magento->query($query_for_store_update);
    }

    public function getUnspscToCatId ()
    {

        $select_category_entity_type = $this->db_magento->query("SELECT entity_type_id FROM `{$this->tablePrefix}eav_entity_type` WHERE entity_type_code = 'catalog_category';");
        $category_entity_type = $select_category_entity_type->fetch()['entity_type_id'];
        $select_unspsc_id = $this->db_magento->query("SELECT attribute_id FROM `{$this->tablePrefix}eav_attribute` WHERE attribute_code = 'unspsc' AND entity_type_id = $category_entity_type;");
        $unspsc_id = $select_unspsc_id->fetch()['attribute_id'];
        $select_unspsc_to_id = $this->db_magento->query("SELECT `entity_id`, `value` FROM  `{$this->tablePrefix}catalog_category_entity_varchar` WHERE attribute_id = $unspsc_id AND store_id = 0;");

        return $select_unspsc_to_id->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function addCatIdToArray ($offset)
    {

        $unspscToCatId = $this->getUnspscToCatId();
        $unspscToProdId = $this->db_magento->fetchAll("SELECT `unspsc`, `entity_id` FROM  `{$this->tablePrefix}import_feed` GROUP BY `entity_id` LIMIT $offset,  $this->batch;");

        $resultArray = [];
        foreach ($unspscToProdId as $key => &$value) {

            foreach ($unspscToCatId as $k => $item) {

                if ($value['unspsc'] == $item) {
                    $resultArray[$key]['unspsc'] = $k;
                    $resultArray[$key]['entity_id'] = $value['entity_id'];
                }
            }
        }

        return $resultArray;
    }

    public function makeTempFileForCatalogCategoryProductInsert ($offset)
    {

        $this->deleteTempFileForCatalogCategoryProductInsert();

        $categoriesToProductsConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_categories_to_product');

        $arValues = $this->addCatIdToArray($offset);
        $columns = ['category_id', 'product_id'];
        $fp = fopen($this->filenameValues, 'w+');

        fputcsv($fp, array_unique($columns), "\t");

        foreach ($arValues as $fields) {
            fputcsv($fp, $fields, "\t");
        }

        fclose($fp);

        // delete categories for set new

        $final = array_map(function ($elements) {
            return $elements['entity_id'];
        }, $arValues);

        if ($categoriesToProductsConf) {
            if (!empty($final)) {
                $this->db_magento->query("DELETE FROM {$this->tablePrefix}catalog_category_product WHERE product_id IN (" . implode(',', $final) . ");");
            }
        }
    }

    public function loadDataToCatalogCategoryProduct()
    {

        $continue = true;
        $counter = 0;

        while ($continue) {

            $offset = $counter * $this->batch;

            $this->makeTempFileForCatalogCategoryProductInsert($offset);

            $this->db_magento->query("LOAD DATA LOCAL INFILE '{$this->filenameValues}' IGNORE INTO TABLE {$this->tablePrefix}catalog_category_product FIELDS TERMINATED BY '\t' ENCLOSED BY '' LINES TERMINATED BY '\n' IGNORE 1 LINES (category_id, product_id);");
            $this->db_magento->query("UPDATE {$this->tablePrefix}catalog_category_product SET position = 1;");
            
            if ($counter == $this->end) {

                $continue = false;
            }

            $counter++;
        }
    }

    public function getCategoriesEntityId ()
    {

        $select_data = $this->db_magento->query("SELECT `entity_id`, `path` FROM `{$this->tablePrefix}catalog_category_entity` WHERE entity_id <> 1 AND entity_id <> 2 ORDER BY entity_id;");
        return $select_data->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function prepareArrayForInsertCategories ()
    {

        $select_max_qty_of_slashes = $this->db_magento->query("select MAX(LENGTH(`categories`) - LENGTH(REPLACE(`categories`, '/', ''))) as `max_qty_of_slashes` from `{$this->tablePrefix}import_feed`;");
        $max_qty_of_slashes = $select_max_qty_of_slashes->fetch()['max_qty_of_slashes'] + 1;

        $selectCategories = "";

        for ($i = 1; $i <= $max_qty_of_slashes; $i++) {
            if ($i == 1) {
                $selectCategories .= "select SUBSTRING_INDEX(categories,'/', $i) category, SUBSTRING_INDEX(unspsc_path,'/', $i) as unspsc_c, unspsc_path, categories path, store
            from {$this->tablePrefix}import_feed
            group by store, unspsc_c union ";
            } else {
                $selectCategories .= "select (SUBSTRING_INDEX(SUBSTRING_INDEX(categories,'/', $i),'/',-1)) category, (SUBSTRING_INDEX(SUBSTRING_INDEX(unspsc_path,'/', $i),'/',-1)) as unspsc_c, unspsc_path, categories path, store
            from {$this->tablePrefix}import_feed
            group by store, unspsc_c union ";
            }
        }

        $selectCategories = rtrim($selectCategories, 'union ');
        $selectCategories .= " order by unspsc_c;";

        $categories = $this->db_magento->fetchAll($selectCategories);

        $unique_categories = [];

        foreach ($categories as $key => $category) {

            $path = explode('/', $category['unspsc_path']);
            $category['unspsc_path_c'] = '';

            foreach ($path as $k => $cat) {
                if ($cat == $category['unspsc_c']) {
                    $category['level'] = $k + 2;
                    break;
                }
            }

            $unsps_s = $category['store'].$category['unspsc_c'];

            if (!array_key_exists($unsps_s, $unique_categories)) {
                $unique_categories[$unsps_s] = $category;
            }
        }

        foreach ($unique_categories as $key => &$category) {

            $path = explode('/', $unique_categories[$key]['unspsc_path']);

            foreach ($path as $cat) {
                foreach ($unique_categories as $k => $item) {
                    if ($cat == $item['unspsc_c']) {
                        $category['unspsc_path_c'] .=  $item['unspsc_c'].'/';
                        break;
                    }
                }
                if ($cat == $category['unspsc_c']) {
                    break;
                }
            }

            $category['unspsc_path_c'] = rtrim($category['unspsc_path_c'], '/');
        }

        return $unique_categories;
    }

    public function makeTempFileForCategories ()
    {

        $this->deleteTempFileForCategories();
        $arCats = $this->prepareArrayForInsertCategories();
        $columns = [];

        foreach ($arCats as &$value) {
            foreach ($value as $key => $item) {
                if ($key == 'path') {
                    unset($value[$key]);
                }
                else {
                    $columns[] = $key;
                }
            }
        }

        $fp = fopen($this->filenameCats, 'w+');

        fputcsv($fp, array_unique($columns), "\t");

        foreach ($arCats as $fields) {
            fputcsv($fp, $fields, "\t");
        }

        fclose($fp);

    }

    public function processTemFileForAttributes ($attributes)
    {

        $delimiter = $this->getDelimiterFromProfile();

        $this->deleteTemFileForAttributes();

        $fp = fopen($this->tempAttr, 'w+');

        foreach ($attributes as $fields) {
            fputcsv($fp, $fields, $delimiter);
            $fields['store'] = 0;
            fputcsv($fp, $fields, $delimiter);
        }

        fclose($fp);
    }

    public function insertIdsInCatalogCategoryEntity ()
    {

        $now = date("Y-m-d H:i:s");

        $select_attribute_set_id = $this->db_magento->query("SELECT `default_attribute_set_id` FROM `{$this->tablePrefix}eav_entity_type` WHERE entity_type_code = 'catalog_category';");
        $attribute_set_id = $select_attribute_set_id->fetch()['default_attribute_set_id'];

        $select_category_entity_type = $this->db_magento->query("SELECT entity_type_id FROM `{$this->tablePrefix}eav_entity_type` WHERE entity_type_code = 'catalog_category';");
        $category_entity_type = $select_category_entity_type->fetch()['entity_type_id'];

        $select_unspsc_id = $this->db_magento->query("SELECT attribute_id FROM `{$this->tablePrefix}eav_attribute` WHERE attribute_code = 'unspsc' AND entity_type_id = $category_entity_type;");
        $unspsc_id = $select_unspsc_id->fetch()['attribute_id'];
        $this->db_magento->query("INSERT INTO {$this->tablePrefix}catalog_category_entity (`entity_type_id`, `attribute_set_id`, `path`, `level`, `children_count`, `position`, `created_at`, `updated_at`) SELECT $category_entity_type, $attribute_set_id, tc.unspsc_path_c, tc.level, 0, 1, '$now', '$now' FROM {$this->tablePrefix}temp_cats tc WHERE unspsc_c NOT IN (SELECT value FROM {$this->tablePrefix}catalog_category_entity_varchar WHERE attribute_id = $unspsc_id) GROUP BY tc.unspsc_path_c;");
    }

    public function updateCategoryPath ()
    {

        $unspsc_ids = $this->db_magento->fetchAll("SELECT id, unspsc_c, unspsc_path_c FROM `{$this->tablePrefix}temp_cats`;");

        $select_root_category_id = $this->db_magento->query("SELECT entity_id FROM `{$this->tablePrefix}catalog_category_entity` WHERE level = 0;");
        $root_category_id = $select_root_category_id->fetch()['entity_id'];

        if (!$root_category_id) {
            $this->db_magento->query("INSERT INTO `{$this->tablePrefix}iceshop_extensions_logs` (`log_key`, `log_value`, `log_type`, `timecol`) VALUES ('errorRootCategory', 'There is no root category in shop.', 'stat', NOW());");
            echo "There is no root category in your shop. \n";
            exit;
        }

        $default_category_from_config = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/default_category');

        if (!$default_category_from_config) {
            $this->db_magento->query("INSERT INTO `{$this->tablePrefix}iceshop_extensions_logs` (`log_key`, `log_value`, `log_type`, `timecol`) VALUES ('errorDefaultCategory', 'There is no default category in shop.', 'stat', NOW());");
            echo "There is no default category in your shop. \n";
            exit;
        }

        foreach ($unspsc_ids as $k => &$unspsc_id) {

            $unspsc_id['cat_path'] = $root_category_id.'/'.$default_category_from_config.'/';
            $paths = explode('/', $unspsc_id['unspsc_path_c']);

            foreach ($paths as $path) {
                foreach ($unspsc_ids as $key => $item) {
                    if ($path == $item['unspsc_c']) {
                        $unspsc_id['cat_path'] .= $item['id'].'/';
                        break;
                    }
                }

                if ($path == $unspsc_ids[$k]['unspsc_c']) {
                    break;
                }
            }

            $unspsc_id['cat_path'] = rtrim($unspsc_id['cat_path'], '/');
            $parent = explode('/', $unspsc_id['cat_path']);
            $unspsc_id['parent_id'] = $parent[count($parent) - 2];
        }

        $query_for_update_path = "UPDATE `{$this->tablePrefix}catalog_category_entity` SET `path` = CASE ";

        foreach ($unspsc_ids as $k => $v) {

            $id = $v['id'];
            $path = $v['cat_path'];
            $query_for_update_path .= "WHEN entity_id = '$id' THEN '$path' ";
        }

        $query_for_update_path .= 'ELSE path END;';

        $this->db_magento->query($query_for_update_path);

        $query_for_update_parent_id = "UPDATE `{$this->tablePrefix}catalog_category_entity` SET `parent_id` = CASE ";

        foreach ($unspsc_ids as $key => $unspsc_id) {
            $id = $unspsc_id['id'];
            $parent_id = $unspsc_id['parent_id'];
            $query_for_update_parent_id .= "WHEN entity_id = '$id' THEN '$parent_id' ";
        }

        $query_for_update_parent_id .= 'ELSE parent_id END;';
        $this->db_magento->query($query_for_update_parent_id);
    }

    public function makeTempTableForCategories ()
    {

        $fieldsForCreatingTable = '`row_id` INT(10) NOT NULL PRIMARY KEY AUTO_INCREMENT, ';

        $fieldsAr = fgetcsv(fopen($this->filenameCats, "r"));
        $fields = str_replace("\t", ',', $fieldsAr[0]);
        $arrayForTable = explode(',', $fields);

        foreach ($arrayForTable as $key => $field) {

            if ($field == 'level' || $field == 'parent_id') {
                $fieldsForCreatingTable .= "`$field` INT(10) DEFAULT NULL, ";
            }
            else {
                $fieldsForCreatingTable .= "`$field` VARCHAR(128) DEFAULT NULL, ";
            }
        }

        $fieldsForCreatingTable = rtrim($fieldsForCreatingTable, ', ');

        $this->db_magento->query("DROP TABLE IF EXISTS {$this->tablePrefix}temp_cats;");
        $this->db_magento->query("CREATE TABLE IF NOT EXISTS {$this->tablePrefix}temp_cats($fieldsForCreatingTable, id INT(10) DEFAULT NULL, `url_key` VARCHAR(255) DEFAULT NULL) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8");

        $this->db_magento->query("LOAD DATA LOCAL INFILE '{$this->filenameCats}' IGNORE INTO TABLE {$this->tablePrefix}temp_cats FIELDS TERMINATED BY '\t' ENCLOSED BY '' LINES TERMINATED BY '\n' IGNORE 1 LINES ($fields);");

    }

    public function countAllProducts ()
    {

        $select_count_all_products = $this->db_magento->query("SELECT COUNT(DISTINCT sku) as count_all_prods FROM {$this->tablePrefix}import_feed;");
        $this->count_all_products = $select_count_all_products->fetch()['count_all_prods'];
    }

    public function importProduct ($importProduct = [])
    {
        
        $this->updateFeedTable($importProduct);

        $this->countAllProducts();
        $this->end = ceil($this->count_all_products / $this->batch);
        
        $websites = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/websites');
        $website = Mage::app()->getWebsite(trim($websites));
        $websiteId = $website->getId();

        $countryCode = Mage::getStoreConfig('general/country/default');

        // new products
        $newProducts = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_new_products');

        // product attributes
        $producteanConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_ean');
        $productmpnConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_mpn');
        $productbrandConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_brand_name');
        $productnameConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_name');
        $productshdescriptionConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_short_description');
        $productdescriptionConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_description');
        $deliveryetaConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_product_delivery_eta');
        $updateStatusFromCsvConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_status_from_csv');
        $updateVisibilityFromCsvConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_visibility_from_csv');
        $updateUrlKeyFromCsvConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_url_key_from_csv');

        // Images
        $addImages = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_images');

        // p&a
        $productpricesConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_prices');
        $productstockConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/import_stock');
        $updateIsInStockFromCsvConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_is_in_stock_from_csv');

        // categories
        $categoriesConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_categories_from_csv');
        $categoriesToActiveConf = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/category_active');

        // mapping
        $config_mpn = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_mpn');
        $config_brand = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_brand_name');
        $config_gtin = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_ean');
        $config_delivery_eta = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/attribute_mapping_delivery_eta');

        // sorting
        $default_store_id = $this->getDefaultStoreId();

        // importing products
        if (!$newProducts) {
            $this->db_magento->query("DELETE FROM `{$this->tablePrefix}import_feed` WHERE sku NOT IN (SELECT sku FROM `{$this->tablePrefix}catalog_product_entity`);");
        }

        //check for empty main table
        $main_table_query = $this->db_magento->query("SELECT COUNT(*) as cnt_rows FROM `{$this->tablePrefix}import_feed` WHERE 1;");
        $main_table_check = $main_table_query->fetch()['cnt_rows'];

        if (!empty($main_table_check)) {

            $this->db_magento->query("UPDATE `{$this->tablePrefix}catalog_product_entity` cpe JOIN {$this->tablePrefix}import_feed impf ON cpe.sku = impf.sku SET cpe.`sku` = impf.sku, cpe.`entity_type_id` = impf.entity_type_id, cpe.`attribute_set_id` = impf.attribute_set_id, cpe.`type_id` = 'simple', cpe.`updated_at` = impf.updated_at;");

            $this->db_magento->query("INSERT INTO `{$this->tablePrefix}catalog_product_entity` (`sku`, `entity_type_id`, `attribute_set_id`, `type_id`, `created_at`, `updated_at`) SELECT impf.sku, impf.entity_type_id, impf.attribute_set_id, 'simple', impf.created_at, impf.updated_at  
FROM {$this->tablePrefix}import_feed impf WHERE sku NOT IN (SELECT sku FROM {$this->tablePrefix}catalog_product_entity WHERE sku IS NOT NULL) GROUP BY impf.sku;");

            $this->db_magento->query("UPDATE `{$this->tablePrefix}import_feed` impf JOIN `{$this->tablePrefix}catalog_product_entity` cpe ON impf.sku = cpe.sku SET impf.entity_id = cpe.entity_id;");
            $this->db_magento->query("INSERT INTO `{$this->tablePrefix}iceshop_iceimport_imported_product_ids` (`product_id`, `product_sku`) SELECT impf.entity_id, impf.sku FROM `{$this->tablePrefix}import_feed` impf ON DUPLICATE KEY UPDATE product_sku = impf.sku;");

            $this->db_magento->query("INSERT INTO `{$this->tablePrefix}catalog_product_website` (`product_id`, `website_id`) SELECT impf.entity_id, $websiteId FROM {$this->tablePrefix}import_feed impf ON DUPLICATE KEY UPDATE product_id = impf.entity_id, website_id = $websiteId;");

            $select_product_entity_type_id = $this->db_magento->query("SELECT entity_type_id FROM `{$this->tablePrefix}eav_entity_type` WHERE entity_type_code = 'catalog_product';");
            $product_entity_type_id = $select_product_entity_type_id->fetch()['entity_type_id'];

            if (!$config_mpn || !$config_brand || !$config_gtin || !$config_delivery_eta) {
                $this->db_magento->query("INSERT INTO `{$this->tablePrefix}iceshop_extensions_logs` (`log_key`, `log_value`, `log_type`, `timecol`) VALUES ('errorMapping', 'Mapings are incorrect.', 'stat', NOW());");
                echo "Mapings are incorrect. \n";
                exit;
            }

            if ($addImages) {
                $this->db_magento->query("INSERT IGNORE INTO `{$this->tablePrefix}iceshop_iceimport_image_queue` (`entity_id`, `image_url`) SELECT impf.entity_id, impf.image FROM `{$this->tablePrefix}import_feed` impf ;");
            }
            
            foreach ($this->arrayForPostfixes as $key => $value) {
                if ($key != 'taxes') {

                    foreach ($value as $k => $attr) {
                        $front_input = false;

                        $attr_for_select = $attr;

                        switch ($attr_for_select) {
                            case 'mpn':
                                $attr_for_select = $config_mpn;
                                break;
                            case 'delivery_eta':
                                $attr_for_select = $config_delivery_eta;
                                break;
                            case 'brand_name':
                                $attr_for_select = $config_brand;
                                break;
                            case 'ean':
                                $attr_for_select = $config_gtin;
                                break;
                        }

                        $select_attribute_id = $this->db_magento->query("SELECT `attribute_id`, `frontend_input`, `source_model` FROM `{$this->tablePrefix}eav_attribute` WHERE attribute_code = '$attr_for_select' AND entity_type_id = $product_entity_type_id;");
                        $tmp_attribute = $select_attribute_id->fetch();
                        $attribute_id = (isset($tmp_attribute['attribute_id'])) ? $tmp_attribute['attribute_id'] : null;
                        $frontend_input = (isset($tmp_attribute['frontend_input'])) ? $tmp_attribute['frontend_input'] : null;
                        $source_model = (isset($tmp_attribute['source_model'])) ? $tmp_attribute['source_model'] : null;

                        if ($attribute_id) {

                            if ($frontend_input == 'select' && ($source_model == 'eav/entity_attribute_source_table' || empty($source_model))) {
                                $front_input = true;

                                $brands_in_store = [];
                                $elements = [];
                                $brands_in_store = $this->db_magento->fetchAll("SELECT DISTINCT `{$attr}` FROM  {$this->tablePrefix}import_feed WHERE `{$attr}` NOT IN (SELECT `value` FROM {$this->tablePrefix}eav_attribute_option_value eavov LEFT JOIN {$this->tablePrefix}eav_attribute_option eavo ON eavov.option_id = eavo.option_id WHERE eavo.attribute_id = '{$attribute_id}') AND `{$attr}` <> '';");

                                if (!empty($brands_in_store)) {
                                    $final = array_map(function ($elements) use ($attr) {
                                        return $elements[$attr];
                                    }, $brands_in_store);

                                    $attribute_model = Mage::getModel('eav/entity_attribute');
                                    $attribute_options_model = Mage::getModel('eav/entity_attribute_source_table');

                                    $attribute_code = $attribute_model->getIdByCode('catalog_product', $attr_for_select);
                                    $attribute = $attribute_model->load($attribute_code);

                                    $attribute_options_model->setAttribute($attribute);
                                    $attribute_options_model->getAllOptions(false);

                                    foreach ($final as $brand_item) {
                                        $value = [];
                                        $value['option'] = [$brand_item];
                                        $result = ['value' => $value];
                                        $attribute->setData('option', $result);
                                        $attribute->save();
                                    }
                                }
                            }

                            $post_fix = $key;

                            $continue = true;
                            $counter = 0;

                            if ($front_input) {
                                $this->db_magento->query("UPDATE {$this->tablePrefix}import_feed impf LEFT JOIN (SELECT eavov.`option_id` as option_id, eavov.`value` FROM {$this->tablePrefix}eav_attribute_option_value eavov LEFT JOIN {$this->tablePrefix}eav_attribute_option eavo ON eavov.option_id = eavo.option_id WHERE eavo.attribute_id = '{$attribute_id}') tmptable ON tmptable.value = impf.{$attr} SET impf.{$attr} = tmptable.option_id WHERE tmptable.option_id IS NOT NULL;");
                            }

                            while ($continue) {

                                $offset = $counter * $this->batch;

                                if ($front_input) {
                                    $post_fix = 'int';
                                    $select_attributes = $this->db_magento->query("SELECT impf.entity_type_id, $attribute_id, impf.store, impf.entity_id, $attr FROM {$this->tablePrefix}import_feed impf LIMIT $offset, $this->batch;");

                                } else {
                                    $select_attributes = $this->db_magento->query("SELECT impf.entity_type_id, $attribute_id, impf.store, impf.entity_id, $attr FROM {$this->tablePrefix}import_feed impf LIMIT $offset, $this->batch;");
                                }

                                $attributes = $select_attributes->fetchAll();
                                $this->processTemFileForAttributes($attributes);

                                $delimiter = $this->getDelimiterFromProfile();
                                $filepath = $this->tempAttr;

                                $this->db_magento->query("LOAD DATA LOCAL INFILE '{$filepath}' IGNORE INTO TABLE {$this->tablePrefix}catalog_product_entity_{$post_fix} FIELDS TERMINATED BY '$delimiter' ENCLOSED BY '' LINES TERMINATED BY '\n' (entity_type_id, attribute_id, store_id, entity_id, value);");

                                if ($counter == $this->end) {

                                    $continue = false;
                                }

                                $counter++;
                            }

                            if ($attr == 'mpn' && $productmpnConf ||
                                $attr == 'ean' && $producteanConf ||
                                $attr == 'brand_name' && $productbrandConf ||
                                $attr == 'delivery_eta' && $deliveryetaConf ||
                                $attr == 'description' && $productdescriptionConf ||
                                $attr == 'short_description' && $productshdescriptionConf ||
                                $attr == 'visibility' && $updateVisibilityFromCsvConf ||
                                $attr == 'url_key' && $updateUrlKeyFromCsvConf ||
                                $attr == 'name' && $productnameConf ||
                                $attr == 'price' && $productpricesConf ||
                                $attr == 'status' && $updateStatusFromCsvConf
                            ) {
                                $this->db_magento->query("UPDATE {$this->tablePrefix}catalog_product_entity_{$post_fix} cpek JOIN {$this->tablePrefix}import_feed impf ON impf.entity_id = cpek.entity_id SET cpek.value = impf.$attr WHERE cpek.attribute_id = $attribute_id AND cpek.value <> impf.$attr AND cpek.store_id = impf.store AND cpek.entity_type_id = impf.entity_type_id;");
                                $this->db_magento->query("UPDATE {$this->tablePrefix}catalog_product_entity_{$post_fix} cpek JOIN {$this->tablePrefix}import_feed impf ON impf.entity_id = cpek.entity_id SET cpek.value = impf.$attr WHERE cpek.attribute_id = $attribute_id AND cpek.value <> impf.$attr AND cpek.store_id = 0 AND cpek.entity_type_id = impf.entity_type_id;");
                            }
                        }
                    }
                }
            }

            $this->deleteTemFileForAttributes();

            if (array_key_exists('taxes', $this->arrayForPostfixes)) {

                foreach ($this->arrayForPostfixes['taxes'] as $tax) {

                    $select_tax_id = $this->db_magento->query("SELECT `attribute_id` FROM `{$this->tablePrefix}eav_attribute` WHERE attribute_code = '$tax' AND entity_type_id = $product_entity_type_id;");
                    $tax_id = $select_tax_id->fetch()['attribute_id'];

                    if ($tax_id) {
                        $this->db_magento->query("DELETE FROM `{$this->tablePrefix}weee_tax` WHERE attribute_id = '$tax_id';");
                        $this->db_magento->query("INSERT INTO {$this->tablePrefix}weee_tax (website_id, entity_id, country, value, state, attribute_id, entity_type_id) SELECT 0, entity_id, '$countryCode', $tax, '*', $tax_id, $product_entity_type_id FROM {$this->tablePrefix}import_feed;");
                    }
                }
            }

            $stock_name = Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/stock_inventory');
            $select_stock_id = $this->db_magento->query("SELECT stock_id FROM `{$this->tablePrefix}cataloginventory_stock` WHERE stock_name = '$stock_name';");
            $stock_id = $select_stock_id->fetch()['stock_id'];

            if ($productstockConf) {
                $this->db_magento->query("UPDATE {$this->tablePrefix}import_feed SET qty = 0 WHERE qty IS NULL;");
                $this->db_magento->query("INSERT INTO {$this->tablePrefix}cataloginventory_stock_item (`product_id`, `stock_id`, `qty`, `is_in_stock`) SELECT impf.entity_id, $stock_id, impf.qty, impf.is_in_stock FROM {$this->tablePrefix}import_feed impf 
ON DUPLICATE KEY UPDATE product_id = impf.entity_id, stock_id = $stock_id, qty = impf.qty, is_in_stock = impf.is_in_stock;");
            } else {
                $this->db_magento->query("INSERT INTO {$this->tablePrefix}cataloginventory_stock_item (`product_id`, `stock_id`, `qty`, `is_in_stock`) SELECT impf.entity_id, $stock_id, impf.qty, impf.is_in_stock FROM {$this->tablePrefix}import_feed impf 
ON DUPLICATE KEY UPDATE product_id = impf.entity_id;");
            }

            if ($updateIsInStockFromCsvConf) {
                $this->db_magento->query("INSERT INTO {$this->tablePrefix}cataloginventory_stock_status (`product_id`, `website_id`, `stock_id`, `qty`, `stock_status`) SELECT impf.entity_id, $websiteId, $stock_id, impf.qty, impf.is_in_stock FROM {$this->tablePrefix}import_feed impf
ON DUPLICATE KEY UPDATE product_id = impf.entity_id, stock_id = $stock_id, website_id = $websiteId, qty = impf.qty;");
            } else {
                $this->db_magento->query("INSERT INTO {$this->tablePrefix}cataloginventory_stock_status (`product_id`, `website_id`, `stock_id`, `qty`, `stock_status`) SELECT impf.entity_id, $websiteId, $stock_id, impf.qty, impf.is_in_stock FROM {$this->tablePrefix}import_feed impf
ON DUPLICATE KEY UPDATE product_id = impf.entity_id;");
            }

            // Creating temp file for categories
            $this->makeTempFileForCategories();

            // Creating temp table
            $this->makeTempTableForCategories();

            // catalog_category_entity
            $select_category_entity_type = $this->db_magento->query("SELECT entity_type_id FROM `{$this->tablePrefix}eav_entity_type` WHERE entity_type_code = 'catalog_category';");
            $category_entity_type = $select_category_entity_type->fetch()['entity_type_id'];

            $select_unspsc_id = $this->db_magento->query("SELECT attribute_id FROM `{$this->tablePrefix}eav_attribute` WHERE attribute_code = 'unspsc' AND entity_type_id = $category_entity_type;");
            $unspsc_id = $select_unspsc_id->fetch()['attribute_id'];

            $select_name_id = $this->db_magento->query("SELECT attribute_id FROM `{$this->tablePrefix}eav_attribute` WHERE attribute_code = 'name' AND entity_type_id = $category_entity_type;");
            $name_id = $select_name_id->fetch()['attribute_id'];

            $select_url_key_id = $this->db_magento->query("SELECT attribute_id FROM `{$this->tablePrefix}eav_attribute` WHERE attribute_code = 'url_key' AND entity_type_id = $category_entity_type;");
            $url_key_id = $select_url_key_id->fetch()['attribute_id'];

            $this->db_magento->query("UPDATE `{$this->tablePrefix}temp_cats` tc JOIN `{$this->tablePrefix}catalog_category_entity_varchar` ccev ON ccev.value = tc.unspsc_c SET tc.id = ccev.entity_id WHERE attribute_id = $unspsc_id AND tc.id IS NULL;");
            $this->db_magento->query("UPDATE `{$this->tablePrefix}temp_cats` SET `category` = TRIM(BOTH '\"' FROM `category`);");
            $this->db_magento->query("UPDATE `{$this->tablePrefix}temp_cats` SET `url_key` = LOWER(REPLACE(REPLACE(REPLACE(category, '&', '-'), '/', '-'), ' ', ''));");

            $this->insertIdsInCatalogCategoryEntity();
            $this->db_magento->query("UPDATE `{$this->tablePrefix}temp_cats` tc JOIN `{$this->tablePrefix}catalog_category_entity` cce ON cce.path = tc.unspsc_path_c SET tc.id = cce.entity_id;");

            $this->updateCategoryPath();

                // catalog_category_entity_varchar
            $array_for_varchar = ['unspsc_c' => $unspsc_id, 'category' => $name_id, 'url_key' => $url_key_id];
            foreach ($array_for_varchar as $attr_varchar => $attr_varchar_id) {

                $store_def_query_varchar = "INSERT INTO {$this->tablePrefix}catalog_category_entity_varchar (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) SELECT $category_entity_type, $attr_varchar_id , tc.store, tc.id, tc.$attr_varchar FROM {$this->tablePrefix}temp_cats tc WHERE tc.id <> 0 ";
                $store_null_query_varchar = "INSERT INTO {$this->tablePrefix}catalog_category_entity_varchar (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) SELECT $category_entity_type, $attr_varchar_id , 0, tc.id, tc.$attr_varchar FROM {$this->tablePrefix}temp_cats tc WHERE store = '$default_store_id' AND tc.id <> 0 ";

                if ($categoriesConf) {
                    $store_def_query_varchar .= "ON DUPLICATE KEY UPDATE `value` = tc.$attr_varchar;";
                    $store_null_query_varchar .= "ON DUPLICATE KEY UPDATE `value` = tc.$attr_varchar;";

                } else {
                    $store_def_query_varchar .= "ON DUPLICATE KEY UPDATE `entity_id` = tc.id;";
                    $store_null_query_varchar .= "ON DUPLICATE KEY UPDATE `entity_id` = tc.id;";
                }

                $this->db_magento->query($store_def_query_varchar);
                $this->db_magento->query($store_null_query_varchar);
            }

            // catalog_category_entity_int
            if ($categoriesToActiveConf) {
                $array_for_int = ['is_active' => $categoriesToActiveConf, 'is_anchor' => 1, 'include_in_menu' => 1];
            } else {
                $array_for_int = ['is_anchor' => 1, 'include_in_menu' => 1];
            }

            foreach ($array_for_int as $attr_int => $int) {

                $select_attribute_int = $this->db_magento->query("SELECT attribute_id FROM `{$this->tablePrefix}eav_attribute` WHERE attribute_code = '$attr_int' AND entity_type_id = $category_entity_type;");
                $attribute_int = $select_attribute_int->fetch()['attribute_id'];

                $store_def_query_int = "INSERT INTO {$this->tablePrefix}catalog_category_entity_int (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) SELECT $category_entity_type, $attribute_int , tc.store, tc.id, $int FROM {$this->tablePrefix}temp_cats tc WHERE tc.id <> 0 ";
                $store_null_query_int = "INSERT INTO {$this->tablePrefix}catalog_category_entity_int (`entity_type_id`, `attribute_id`, `store_id`, `entity_id`, `value`) SELECT $category_entity_type, $attribute_int , 0, tc.id, $int FROM {$this->tablePrefix}temp_cats tc WHERE store = '$default_store_id' AND tc.id <> 0 ";

                if ($categoriesConf && !$categoriesToActiveConf) {
                    $store_def_query_int .= "ON DUPLICATE KEY UPDATE `value` = $int;";
                    $store_null_query_int .= "ON DUPLICATE KEY UPDATE `value` = $int;";
                } else {
                    $store_def_query_int .= "ON DUPLICATE KEY UPDATE `entity_id` = tc.id;";
                    $store_null_query_int .= "ON DUPLICATE KEY UPDATE `entity_id` = tc.id;";
                }

                $this->db_magento->query($store_def_query_int);
                $this->db_magento->query($store_null_query_int);
            }
            // loading data to catalog_category_product;
            $this->loadDataToCatalogCategoryProduct();
        }

        // Deleting temp files
        $this->deleteTempFileForCategories();
        $this->deleteTempFileForCatalogCategoryProductInsert();

        // Deleting temp tables
        $this->deleteTempTableCats();
        $this->deleteTempTableProds();

        if (!empty($importProduct)) {
            $this->deleteFileFromArrayForManualLaunching();
        }

        $this->checkAndSetIceField();
    }

    public function runCategoriesSorting ()
    {

        $select_number_of_iteration = $this->db_magento->query("SELECT MAX(level) as max FROM `{$this->tablePrefix}catalog_category_entity`;");
        $number_of_iteration = $select_number_of_iteration->fetch()['max'] - 1;

        $position = 1;

        if ($number_of_iteration > 0) {

            for ($i = 1; $i <= $number_of_iteration; $i++) {

                $query_select_entity_id = "SELECT cce.entity_id FROM `{$this->tablePrefix}catalog_category_entity` cce LEFT JOIN `{$this->tablePrefix}catalog_category_product` ccp ON cce.entity_id = ccp.category_id WHERE ccp.category_id IS NULL AND SUBSTRING_INDEX(cce.path,'/',-1) = cce.entity_id;";
                $empty_categories = $this->db_magento->fetchAll($query_select_entity_id);

                $final_array = array_map(function ($element) {
                    return $element['entity_id'];
                }, $empty_categories);

                $catCollection = Mage::getModel('catalog/category')
                    ->getCollection()
                    ->addAttributeToSort('name', 'ASC');

                $select_category_entity_type = $this->db_magento->query("SELECT entity_type_id FROM `{$this->tablePrefix}eav_entity_type` WHERE entity_type_code = 'catalog_category';");
                $category_entity_type = $select_category_entity_type->fetch()['entity_type_id'];
                $select_attribute_is_active_id = $this->db_magento->query("SELECT attribute_id FROM `{$this->tablePrefix}eav_attribute` WHERE attribute_code = 'is_active' AND entity_type_id = $category_entity_type;");
                $attribute_is_active_id = $select_attribute_is_active_id->fetch()['attribute_id'];
                $update_hide_category = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/update_hide_category');

                $array_for_updating_is_active = [];

                foreach ($catCollection as $key => $category) {

                    $query = "UPDATE `{$this->tablePrefix}catalog_category_entity` SET position = :position WHERE entity_id = :cat_id ";
                    $this->db_magento->query($query, array(
                        ':position' => $position++,
                        ':cat_id' => $category->getId()
                    ));

                    if ($update_hide_category) {

                        if (in_array($category->getId(), $final_array)){
                            $count = count($category->getChildrenCategories());

                            if ($count == 0){
                                $array_for_updating_is_active[] = $category->getId();
                            }
                        }
                    }
                }

                $ids_for_update_is_active = implode(',', $array_for_updating_is_active);

                if (!empty($ids_for_update_is_active)) {
                    //$this->db_magento->query("UPDATE `{$this->tablePrefix}catalog_category_entity_int` SET `value` = 0 WHERE `attribute_id` = $attribute_is_active_id AND `entity_id` IN ($ids_for_update_is_active);");
                }
            }
        }
    }

    public function deleteOldProducts($DB_logger)
    {

        try {

            $this->db_magento->query("SELECT @is_iceimport_id := `attribute_id`
                                    FROM {$this->tablePrefix}eav_attribute
                                    WHERE attribute_code = 'is_iceimport'");

            $count_prod = $this->db_magento->fetchRow("SELECT count(t2.entity_id) AS count_prod FROM (SELECT cpe.entity_id
                                                        FROM {$this->tablePrefix}catalog_product_entity AS cpe
                                                        JOIN {$this->tablePrefix}catalog_product_entity_varchar AS cpev
                                                            ON cpe.entity_id = cpev.entity_id
                                                            AND cpev.value = 1
                                                            AND cpev.attribute_id = @is_iceimport_id
                                                            GROUP BY cpe.entity_id) t2");

            $count_prod = $count_prod['count_prod'];

            if ($count_prod > 0) {
                //iceimport products exists, amount > 0

                $count_del_prod = $this->db_magento->fetchRow("SELECT count(t1.entity_id) AS count__del_prod FROM (SELECT cpe.entity_id
                                                        FROM {$this->tablePrefix}catalog_product_entity AS cpe
                                                        JOIN {$this->tablePrefix}catalog_product_entity_varchar AS cpev
                                                            ON cpe.entity_id = cpev.entity_id
                                                            AND cpev.value = 1
                                                            AND cpev.attribute_id = @is_iceimport_id
                                                        LEFT JOIN {$this->tablePrefix}iceshop_iceimport_imported_product_ids AS iip
                                                            ON cpe.entity_id = iip.product_id
                                                        WHERE iip.product_id IS NULL GROUP BY cpe.entity_id) t1; ");

                if(!empty($count_del_prod['count__del_prod'])){
                    $count_del_prod = $count_del_prod['count__del_prod'];
                } else {
                    $count_del_prod = 0;
                }

                if ($count_del_prod > 0) {
                    //iceimport products to delete exists, amount > 0
                    $delete_old_products_tolerance = (int)Mage::getStoreConfig('iceshop_iceimport_importprod_root/importprod/delete_old_products_tolerance');

                    if (round(($count_del_prod / $count_prod * 100), 0) <= $delete_old_products_tolerance) {

                        //iceimport products to delete franction is less than allowed tolerance, deletion approved
                        $this->db_magento->query("DELETE cpe
                                    FROM {$this->tablePrefix}catalog_product_entity AS cpe
                                    JOIN {$this->tablePrefix}catalog_product_entity_varchar AS cpev
                                        ON cpe.entity_id = cpev.entity_id
                                        AND cpev.value = 1
                                        AND cpev.attribute_id = @is_iceimport_id
                                    LEFT JOIN {$this->tablePrefix}iceshop_iceimport_imported_product_ids AS iip
                                        ON cpe.entity_id = iip.product_id
                                    WHERE iip.product_id IS NULL");

                        $this->db_magento->query("TRUNCATE TABLE {$this->tablePrefix}iceshop_iceimport_imported_product_ids");
                    } else {
                        $error_message = 'Attempt to delete more old products than allowed in Iceimport configuration. Interruption of the process.';
                        $DB_logger->insertLogEntry('error_try_delete_product', $error_message);
                        $error_message2 = 'Old product percentage: ' . round(($count_del_prod / $count_prod * 100), 2) . '%';
                        $DB_logger->insertLogEntry('error_try_delete_product_percentage', $error_message2);

                        //flag to warning notice
                        $DB_logger->insertLogEntry('try_delete_product_percentage_warning_flag', 'SHOW');
                        $DB_logger->insertLogEntry('iceimport_count_delete_product', $count_del_prod);
                        print $error_message;
                        print $error_message2;
                        exit;
                    }
                }
                $DB_logger->insertLogEntry('iceimport_count_delete_product', $count_del_prod);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function processImageQueue($logFileName)
    {

        $queueList = $this->db_magento->fetchAll("SELECT `queue_id`, `entity_id`, `image_url` FROM `{$this->tablePrefix}iceshop_iceimport_image_queue` WHERE `is_downloaded` = 0");
        if (count($queueList) > 0) {
            $mediaDir = Mage::getBaseDir('media');
            foreach ($queueList as $queue) {

                    $queueId = $queue['queue_id'];
                    $productId = $queue['entity_id'];
                    $imageUrl = $queue['image_url'];

                try {
                    $preImageName = explode('/', $imageUrl);
                    $imageName = array_pop($preImageName);
                    if (file_exists($mediaDir . DS . $imageName)) {
                        $imageName = rand() . '_' . time() . $imageName;
                    }

                    if (file_put_contents($mediaDir . DS . $imageName, file_get_contents($imageUrl))) {
                        $product = Mage::getModel('catalog/product')->load($productId);
                        $product->addImageToMediaGallery($mediaDir . DS . $imageName,
                            ['image', 'small_image', 'thumbnail'],
                            true, true
                        );
                        $product->save();
                        $this->setImageAsDownloaded($queueId);
                        unset($product);
                    } else {
                        $this->setImageAsDownloadedError($queueId);
                        Mage::log('Unable download file to ' . $productId, $logFileName);
                        continue;
                    }
                } catch(Exception $e) {
                    $this->db_magento->query("UPDATE `{$this->tablePrefix}iceshop_iceimport_image_queue` SET `is_downloaded` = 2 WHERE `queue_id` = '$queueId';");
                }
            }
        }
    }

    public function setImageAsDownloaded($queueId = false)
    {

        if ($queueId) {
            $this->db_magento->query(
                "UPDATE `{$this->tablePrefix}iceshop_iceimport_image_queue`
                    SET is_downloaded = 1
                    WHERE queue_id = :queue_id",
                array(':queue_id' => $queueId)
            );
        }
    }

    public function setImageAsDownloadedError($queueId = false)
    {

        if ($queueId) {
            $this->db_magento->query(
                "UPDATE `{$this->tablePrefix}iceshop_iceimport_image_queue`
                    SET is_downloaded = 2
                    WHERE queue_id = :queue_id",
                array(':queue_id' => $queueId)
            );
        }
    }
    
    public function checkAndSetIceField()
    {

        $attribute = Mage::getModel('eav/config')->getAttribute('catalog_product', 'active_ice')->getData();
        if (isset($attribute['attribute_id'])) {

            $sql = "DROP PROCEDURE IF EXISTS FIELD_EXISTS;";
            $this->db_magento->query($sql);

            $sql = "CREATE PROCEDURE FIELD_EXISTS(
                    OUT _exists    BOOLEAN, -- return value
                    IN  tableName  CHAR(255) CHARACTER SET 'utf8', -- name of table to look for
                    IN  columnName CHAR(255) CHARACTER SET 'utf8', -- name of column to look for
                    IN  dbName     CHAR(255) CHARACTER SET 'utf8'       -- optional specific db
                ) BEGIN
                -- try to lookup db if none provided
                    SET @_dbName := IF(dbName IS NULL, database(), dbName);

                    IF CHAR_LENGTH(@_dbName) = 0
                    THEN -- no specific or current db to check against
                        SELECT
                            FALSE
                        INTO _exists;
                    ELSE -- we have a db to work with
                        SELECT
                            IF(count(*) > 0, TRUE, FALSE)
                        INTO _exists
                        FROM information_schema.COLUMNS c
                        WHERE
                            c.TABLE_SCHEMA = @_dbName
                            AND c.TABLE_NAME = tableName
                            AND c.COLUMN_NAME = columnName;
                    END IF;
                END;";
            $this->db_magento->query($sql);

            $sql = "CALL FIELD_EXISTS(@_exists, '{$this->tablePrefix}catalog_product_entity', 'active_ice', NULL);";
            $this->db_magento->query($sql);

            $sql = "SELECT @_exists;";
            $res = $this->db_magento->fetchCol($sql);
            if (array_shift($res)) {
                $options = Mage::getModel('eav/config')->getAttribute('catalog_product', 'active_ice')->getSource()->getAllOptions();
                $optionId = false;
                foreach ($options as $option) {
                    if ($option['label'] == 'Yes') {
                        $optionId = $option['value'];
                        break;
                    }
                }
                if ($optionId) {
                    $sql = "UPDATE `{$this->tablePrefix}catalog_product_entity` SET `active_ice` = '{$optionId}' WHERE `active_ice` IS NULL;";
                    $this->db_magento->query($sql);
                }
            }
        }
    }

    public function deleteTempTableProds ()
    {

        $this->db_magento->query("DROP TABLE IF EXISTS {$this->tablePrefix}import_feed;");
    }

    public function deleteTempTableCats ()
    {

        $this->db_magento->query("DROP TABLE IF EXISTS {$this->tablePrefix}temp_cats;");
    }

    public function deleteTempFileForCategories ()
    {

        if (file_exists($this->filenameCats)) {
            unlink($this->filenameCats);
        }
    }

    public function deleteTempFileForCatalogCategoryProductInsert ()
    {

        if (file_exists($this->filenameValues)) {
            unlink($this->filenameValues);
        }
    }

    public function deleteFileFromArrayForManualLaunching ()
    {

        if (file_exists($this->tempFeed)) {
            unlink($this->tempFeed);
        }
    }

    public function deleteTemFileForAttributes ()
    {

        if (file_exists($this->tempAttr)) {
            unlink($this->tempAttr);
        }
    }
}
