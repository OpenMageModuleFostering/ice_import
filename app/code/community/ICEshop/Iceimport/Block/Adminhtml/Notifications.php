<?php

/**
 * Class ICEshop_Iceimport_Block_Adminhtml_Notifications
 */
class ICEshop_Iceimport_Block_Adminhtml_Notifications extends Mage_Adminhtml_Block_Template
{
    /**
     * @return array|string
     */
    public function getIceimportProblemsDigest()
    {
        $checker = Mage::helper('iceimport/system_systemcheck')->init();
        $problems_digest = $checker->getExtensionProblemsDigest();
        $response = array();
        if($checker->checkSetWarning()){
          if ($problems_digest->getCount() != 0) {
              $problems = $problems_digest->getProblems();
              foreach ($problems as $problem_name => $problem_value) {
                  $response[] = array($problem_name => $problem_value);
              }
          }
        }
        return $response;
    }

    /**
     * Get index management url
     *
     * @return string
     */
    public function getManageUrl()
    {
        return Mage::helper("adminhtml")->getUrl("*/system_config/edit", array('section' => 'iceimport_information'));
    }

    /**
     * Get index management url
     *
     * @return string
     */
    public function getSettingsPage()
    {
        return Mage::helper("adminhtml")->getUrl("*/system_config/edit", array('section' => 'iceshop_iceimport_importprod_root'));
    }

    /**
     * Check is notification available to current user
     * @return bool
     */
    public function isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/config/iceshop_iceimport_importprod_root');
    }

    /*
     * Check last import date
     * @return bool
     */
    public function checkLastFinishedImport(){

        $message_1 = 'The import of your assortment via Iceimport did not start. Is something changed in the configuration? See the dashboard for more information. Please contact our support team at supportdesk@iceshop.nl';
        $message_2 = 'Iceimport started but did not yet finished or failed. Is something changed with your shop? See the dashboard for more information. Please contact our support team at supportdesk@iceshop.nl';

        $warningTime = 60*60*30; // 30 hours
        $timezoneLocal = Mage::app()->getStore()->getConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE);
        $db = Mage::helper('iceimport/db');
        $last_started_timestamp = $db->getLogEntryByKey('iceimport_import_started');
        $last_finished_timestamp = $db->getLogEntryByKey('iceimport_import_ended');

        //get only time if this isset
        $last_started_timestamp = ((isset($last_started_timestamp['log_value']))&&($last_started_timestamp['log_value'] != ''))?$last_started_timestamp['log_value']:'';
        $last_finished_timestamp = ((isset($last_finished_timestamp['log_value']))&&($last_finished_timestamp['log_value'] != ''))?$last_finished_timestamp['log_value']:'';
        $currentDateTime =  Mage::getModel('core/date')->timestamp(time());
        //case 1
        if(($last_started_timestamp != '') && ($last_finished_timestamp == '')){
            $start_time_ice = $this->parseCustomDate($last_started_timestamp);
            if($start_time_ice){
                if(($currentDateTime-$start_time_ice)>$warningTime){
                    return $message_1;
                }
            }
        }
        //case 2
        if(($last_started_timestamp != '') && ($last_finished_timestamp != '')){
            $finish_time_ice = $this->parseCustomDate($last_finished_timestamp);
            if($finish_time_ice){
                if(($currentDateTime-$finish_time_ice)>$warningTime){
                    return $message_2;
                }
            }
        }
        return false;
    }
    
    public function parseCustomDate($date){
        if(($date != '') && ($date != null) && ($date != false)) {
            //we first explode string to date and time and after expolde to year month date and hour minute seconds
            $date = explode(' ',$date);
            if(isset($date[0])){
                $date['date'] = explode('/',$date[0]);
                unset($date[0]);
            }
            if(isset($date[1])){
                $date['time'] = explode(':',$date[1]);
                unset($date[1]);
            }
            //check if all exists and in need out quantity
            if((isset($date['time'])) && (isset($date['date'])) && (count($date['time']) == 3) && (count($date['date']) == 3)){
                $unix_timestamp = mktime($date['time'][0],$date['time'][1], $date['time'][2],$date['date'][0], $date['date'][1], $date['date'][2]);
                return $unix_timestamp;
            }
            return false;
        }
        return false;
    }

    /**
     * Check warning flag
     * @return bool
     */
    public function checkWarning()
    {
        $message = 'At last import was attempt to delete more old products than allowed in Iceimport configuration, please go to configuration page and check the parameter (Tolerance of difference (%)).';
        $db = Mage::helper('iceimport/db');
        $log_record = $db->getLogEntryByKey('try_delete_product_percentage_warning_flag');
        if ((!empty($log_record)) && ($log_record['log_value'] == 'SHOW')) {
            return $message;
        }
        return false;
    }

    /**
     * Check first launch of admin panel after install/reinstall module
     * @return bool|string
     */
    public function firstLaunch()
    {
        $message = 'We detected that its your first launch of admin panel after install/reinstall ICEImport module. For this reason we recommend you check settings of module (Attributes mapping etc.)';
        $db = Mage::helper('iceimport/db');
        $log_record = $db->getLogEntryByKey('first_start');
        if ((!empty($log_record)) && ($log_record['log_value'] == 'yes')) {
            $accept = Mage::app()->getRequest()->getParam('accept');
            $section = Mage::app()->getRequest()->getParam('section');
            if (($accept == 1) && ($section == 'iceshop_iceimport_importprod_root')) {
                $db->deleteLogEntry('first_start');
                return false;
            } else {
                return $message;
            }
        } else {
            return false;
        }
    }

    /**
     * Get settings page with parameter accept
     *
     * @return string
     */
    public function getSettingsPageWithAccepting()
    {
        return Mage::helper("adminhtml")->getUrl("*/system_config/edit", array('section' => 'iceshop_iceimport_importprod_root', 'accept' => true));
    }

    /**
     * Check of attribute mapping
     * @return bool|string
     */
    public function checkSetMapping()
    {
        $message = "The Iceimport extensions doesn't work properly, please go to configuration page and check settings which not set.";

        $checks = array(
            'iceshop_iceimport_importprod_root/importprod/attribute_mapping_mpn',
            'iceshop_iceimport_importprod_root/importprod/attribute_mapping_brand_name',
            'iceshop_iceimport_importprod_root/importprod/attribute_mapping_ean',
        );

        $resource = Mage::getSingleton('core/resource');
        $tableName = $resource->getTableName('core_config_data');
        $readConnection = $resource->getConnection('core_read');
        foreach ($checks as $check) {
            $query = 'SELECT * FROM ' . $tableName . ' WHERE path = "' . $check . '"';
            $results = $readConnection->fetchAll($query);
            if (empty($results)) {
                return $message;
            }
        }
        return false;
    }
}
