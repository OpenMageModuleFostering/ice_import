<?php
class Iceshop_Iceimport_Block_Adminhtml_System_Config_Form_Exportall extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /*
     * Set templated
     */
    protected function _construct()
    {
        parent::_construct();
    }

    /**
     * Return element html
     *
     * @param  Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        return $this->_toHtml();
    }

    /**
     * Return ajax url for button
     *
     * @return string
     */
    public function getAjaxCheckUrl()
    {
        return Mage::helper('adminhtml')->getUrl('adminhtml/iceimport/exportIceimportimagesCsvAll/');
    }

    /**
     * Generate button html
     *
     * @return string
     */
    public function getButtonHtml()
    {
            $prod_button = $this->getLayout()->createBlock('adminhtml/widget_button')
                ->setData(array(
                    'id' => 'iceimport_exportall_button',
                    'label' => $this->helper('adminhtml')->__('Export All to CSV'),
                    'onclick' => "setLocation('" . $this->getUrl('adminhtml/iceimport/exportIceimportimagesCsvAll/') . "')"
                ));
            $buttons = $prod_button->toHtml();
            return $buttons;
    }
}