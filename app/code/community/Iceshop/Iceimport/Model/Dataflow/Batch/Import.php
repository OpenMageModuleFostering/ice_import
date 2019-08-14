<?php
/**
 * Dataflow Batch import model
 *
 * @method Mage_Dataflow_Model_Resource_Batch_Import _getResource()
 * @method Mage_Dataflow_Model_Resource_Batch_Import getResource()
 * @method int getBatchId()
 * @method Mage_Dataflow_Model_Batch_Import setBatchId(int $value)
 * @method int getStatus()
 * @method Mage_Dataflow_Model_Batch_Import setStatus(int $value)
 *
 * @category    Mage
 * @package     Mage_Dataflow
 * @author      Magento Core Team <core@magentocommerce.com>
 */
class Iceshop_Iceimport_Model_Dataflow_Batch_Import extends Mage_Dataflow_Model_Batch_Import
{
    protected $_connRes = null;

    protected function _construct()
    {
        $this->_connRes = Mage::getSingleton('core/resource')->getConnection('core_write');
        $this->_init('dataflow/batch_import');

    }

    public function setBatchData($data)
    {

        if ('"libiconv"' == ICONV_IMPL) {
            foreach ($data as $item){
                foreach ($item as &$value) {
                    $value = iconv('utf-8', 'utf-8//IGNORE', $value);
                }
            }
        }

        $batch_import = Mage::getSingleton('core/resource')->getTableName('dataflow/batch_import');
        $insert_query = "INSERT INTO ".$batch_import." (`batch_id`, `batch_data`, `status`) VALUES ";
        foreach ($data as $item){
            if(!empty($item)){
                $insert_query .= "(".$this->getBatchId().", '".addslashes(serialize($item))."', 1), ";
            }
        }
        $insert_query = substr($insert_query, 0, -2);

        $this->_connRes->query($insert_query);

        return $this;
    }

}
