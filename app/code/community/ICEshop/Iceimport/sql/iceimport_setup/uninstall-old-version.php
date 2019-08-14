<?php

class Uninstall_Capacitywebsolutions_Importproduct
{
    /**
     * Delete Iceimport extension with old namespace
     */
    public function uninstall()
    {
        $code_dir = Mage::getBaseDir('app') . '/code/community/Capacitywebsolutions/Importproduct';
        $etc_capacitywebsolutions_xml = Mage::getBaseDir('app') . '/etc/modules/Capacitywebsolutions_Importproduct.xml';

        $this->remove_dir($code_dir);
        $this->remove_file($etc_capacitywebsolutions_xml);
    }

    public function remove_dir($path)
    {
        if (file_exists($path) && is_dir($path)) {
            $dirHandle = opendir($path);
            while (false !== ($file = readdir($dirHandle))) {
                if ($file != '.' && $file != '..') {
                    $tmpPath = $path . '/' . $file;
                    chmod($tmpPath, 0777);

                    if (is_dir($tmpPath)) {
                        $this->remove_dir($tmpPath);
                    } else {
                        if (file_exists($tmpPath)) {
                            unlink($tmpPath);
                        }
                    }
                }
            }
            closedir($dirHandle);

            if (file_exists($path)) {
                rmdir($path);
            }
        }
    }

    public function remove_file($path)
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}