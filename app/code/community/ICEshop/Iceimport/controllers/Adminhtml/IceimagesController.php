<?php

class Iceshop_Icecatlive_Adminhtml_IceimagesController extends Mage_Adminhtml_Controller_Action
{

    public function getGridTable()
    {
        return $this->getResponse()->setBody(
            $this->getLayout()->createBlock('iceimport/adminhtml_images_list_grid')->toHtml()
        );
    }
}