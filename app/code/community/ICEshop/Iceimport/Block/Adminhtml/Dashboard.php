<?php

class ICEshop_Iceimport_Block_Adminhtml_Dashboard extends Mage_Adminhtml_Block_Template
{
    /**
     * Get statistics for dashboard
     * @return array
     */
    public function getStatistics()
    {
        $helper = Mage::helper('iceimport');
        $db = Mage::helper('iceimport/db');
        $checker = Mage::helper('iceimport/system_systemcheck')->init();

        $table_name = $db->getTableName('dataflow_profile_history');

        //get data
        $last_started_by_cron = $db->getLogEntryByKey('iceimport_import_started');
        $last_finished_by_cron = $db->getLogEntryByKey('iceimport_import_ended');
        $import_status_cron = $db->getLogEntryByKey('iceimport_import_status_cron');
        $last_imported_products_count = $db->getLogEntryByKey('iceimport_count_imported_products');
        $import_filename = $db->getLogEntryByKey('import_filename');
        $productivity = $checker->getModulePerformance($last_imported_products_count, $last_started_by_cron, $last_finished_by_cron);
        $currently_imported_products = $db->getRowsCount($db->_prefix . "iceshop_iceimport_imported_product_ids");
        $data_flows = $db->getRowCountByField($db->getTableName('dataflow_batch_import'), 'batch_id', false, ' ORDER BY 1 DESC LIMIT 50');

        $last_run = $db->readQuery("SELECT `performed_at` FROM {$table_name} WHERE `profile_id` = 3 ORDER BY `performed_at` DESC LIMIT 1");
        $last_deleted_products_count = $db->getLogEntryByKey('iceimport_count_delete_product');

        if (!empty($currently_imported_products) && ($import_status_cron['log_value'] == 'Running' || $import_status_cron['log_value'] == 'Failed')) {
            $additional = array(
                'label' => $helper->__("Currently products imported"),
                'value' => $currently_imported_products . ' from ' . $data_flows[0]['row_count']
            );
        }

        //packing response
        $response = array(
            array(
                'label' => $helper->__('Started last time at'),
                'value' => (!empty($last_started_by_cron['log_value'])) ? $last_started_by_cron['log_value'] : $helper->__("Never started till now")
            ),
            array(
                'label' => $helper->__('Finished last time at'),
                'value' => (!empty($last_finished_by_cron['log_value'])) ? $last_finished_by_cron['log_value'] : $helper->__("Never finished till now"),
            ),
            array(
                'label' => $helper->__('Import cron process status'),
                'value' => (!empty($import_status_cron['log_value'])) ? $import_status_cron['log_value'] : $helper->__("Never started till now"),
            ),
            array(
                'label' => $helper->__('Products imported last time'),
                'value' => (!empty($last_imported_products_count['log_value'])) ? $last_imported_products_count['log_value'] : 0,
            ),
            array(
                'label' => $helper->__('Import file name'),
                'value' => (!empty($import_filename['log_value'])) ? $import_filename['log_value'] : $helper->__("Never started till now"),
            )
        );

        if (!empty($additional))
            $response[] = $additional;

        return $response;
    }

    /**
     * @return string
     */
    public function getProfiles($profileName = null)
    {
        $html = '';
        $database_query = Mage::getSingleton('core/resource')->getConnection('core_write');
        $db = Mage::helper('iceimport/db');
        $tablePrefix = '';
        $tPrefix = (array)Mage::getConfig()->getTablePrefix();
        if (!empty($tPrefix)) {
            $tablePrefix = $tPrefix[0];
        }
        $profiles = $database_query->fetchAll("SELECT `profile_id`, `name`  FROM `{$tablePrefix}dataflow_profile`");
        if (!empty($profiles)) {
            $html .= '<select id="ice_export_profiles">';
            foreach ($profiles as $profile) {
                if ((!empty($profileName)) && ($profileName == $profile["name"])) {
                    $html .= '<option value="' . $profile["profile_id"] . '" selected = "true">' . $profile["name"] . "</option>";
                } else {
                    $html .= '<option value="' . $profile["profile_id"] . '">' . $profile["name"] . "</option>";
                }
            }
            $html .= '</select>';
        }
        return $html;

    }

    /**
     * Get index management url
     *
     * @return string
     */
    public function getManageUrl()
    {
        return Mage::helper("adminhtml")->getUrl("*/system_config/edit", array('section' => 'iceshop_iceimport_importprod_root'));
    }

}