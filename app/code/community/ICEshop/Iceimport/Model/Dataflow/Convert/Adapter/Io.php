<?php

class ICEshop_Iceimport_Model_Dataflow_Convert_Adapter_Io extends Mage_Dataflow_Model_Convert_Adapter_Io
{
    /**
     * Load data
     *
     * @return Mage_Dataflow_Model_Convert_Adapter_Io
     */
    public function load()
    {
        if (!$this->getResource()) {
            return $this;
        }

        $batchModel = Mage::getSingleton('dataflow/batch');
        $destFile = $batchModel->getIoAdapter()->getFile(true);

        $result = $this->getResource()->read($this->getVar('filename'), $destFile);
        $filename = $this->getResource()->pwd() . '/' . $this->getVar('filename');
        if (false === $result) {
            $message = Mage::helper('dataflow')->__('Could not load file: "%s".', $filename);
            Mage::throwException($message);
        } else {
            $message = Mage::helper('dataflow')->__('Loaded successfully: "%s".', $filename);
            $this->addException($message);
        }
        //add imported filename in statistic
        $db_helper = Mage::helper('iceimport/db');
        $db_helper->insertLogEntry('import_filename', $this->getVar('filename'), 'info');

        $this->setData($result);
        return $this;
    }
}
