<?php
class ICEshop_Iceimport_Adminhtml_IceimportimagesController extends Mage_Adminhtml_Controller_Action
{


    /**
     * Return some checking result
     *
     * @return void
     */
    public function checkAction()
    {

        $result =  Mage::getModel('iceimport/observer')->importImages();
        Mage::app()->getResponse()->setBody($result);
    }

    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/config/iceimport_information');
    }

}