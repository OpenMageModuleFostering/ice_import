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
     * Check is notification available to current user
     * @return bool
     */
    public function isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/config/iceshop_iceimport_importprod_root');
    }
}
