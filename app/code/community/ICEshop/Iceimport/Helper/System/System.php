<?php

/**
 * Class Iceshop_Iceimport_Helper_System_System
 */
class Iceshop_Iceimport_Helper_System_System extends Mage_Core_Helper_Abstract
{
    /**
     * Sorts a multi-dimensional array with the given values
     *
     * Seen and modified from: http://www.firsttube.com/read/sorting-a-multi-dimensional-array-with-php/
     *
     * @param  array $arr Array to sort
     * @param  string $key Field to sort
     * @param  string $dir Direction to sort
     * @return array  Sorted array
     */
    public function sortMultiDimArr($arr, $key, $dir = 'ASC')
    {
        foreach ($arr as $k => $v) {
            $b[$k] = strtolower($v[$key]);
        }

        if ($dir == 'ASC') {
            asort($b);
        } else {
            arsort($b);
        }
        foreach ($b as $key => $val) {
            $c[] = $arr[$key];
        }

        return $c;
    }

    /**
     * Activate/Deactivate a Magento module
     *
     * @param  string $name
     * @return string
     */
    public function deactivateModule($name)
    {
        $isDeactivationPossible = true;
        foreach (Mage::getConfig()->getNode('modules')->children() as $moduleName => $item) {
            if ($moduleName == $name) {
                continue;
            }
            if ($item->depends) {
                foreach ($item->depends->children() as $depend) {
                    if ($depend->getName() == $name) {
                        if ((string)Mage::getConfig()->getModuleConfig($moduleName)->is('active', 'true')) {
                            $isDeactivationPossible = false;
                        }
                    }
                }
            }
        }

        if ($isDeactivationPossible) {
            $xmlPath = Mage::getBaseDir() . DS . 'app' . DS . 'etc' . DS . 'modules' . DS . $name . '.xml';
            if (file_exists($xmlPath)) {
                $xmlObj = new Varien_Simplexml_Config($xmlPath);

                $currentValue = (string)$xmlObj->getNode('modules/' . $name . '/active');
                if ($currentValue == 'true') {
                    $value = false;
                } else {
                    $value = true;
                }

                $xmlObj->setNode(
                    'modules/' . $name . '/active',
                    $value ? 'true' : 'false'
                );

                if (is_writable($xmlPath)) {
                    $xmlData = $xmlObj->getNode()->asNiceXml();
                    @file_put_contents($xmlPath, $xmlData);
                    Mage::app()->getCacheInstance()->clean();
                    if ($value) {
                        $status = $this->__('The module "%s" has been successfully activated.', $name);
                    } else {
                        $status = $this->__('The module "%s" has been successfully deactivated.', $name);
                    }
                } else {
                    $status = $this->__('File %s is not writable.', $xmlPath);
                }
            } else {
                $status = $this->__(
                    'Module %s is probably not installed. File %s does not exist.',
                    $name,
                    $xmlPath
                );
            }
        } else {
            $status = $this->__('Module can\'t be deactivated because it is a dependency of another module which is still active.');
        }

        return $status;
    }

    /**
     * Retrieve a collection of all rewrites
     *
     * @return Varien_Data_Collection Collection
     */
    public function getRewriteCollection()
    {
        $collection = new Varien_Data_Collection();
        $rewrites = $this->_loadRewrites();

        foreach ($rewrites as $rewriteNodes) {
            foreach ($rewriteNodes as $n) {
                $nParent = $n->xpath('..');
                $module = (string)$nParent[0]->getName();
                $nSubParent = $nParent[0]->xpath('..');
                $component = (string)$nSubParent[0]->getName();

                if (!in_array($component, array('blocks', 'helpers', 'models'))) {
                    continue;
                }

                $pathNodes = $n->children();
                foreach ($pathNodes as $pathNode) {
                    $path = (string)$pathNode->getName();
                    $completePath = $module . '/' . $path;

                    $rewriteClassName = (string)$pathNode;

                    $instance = Mage::getConfig()->getGroupedClassName(
                        substr($component, 0, -1),
                        $completePath
                    );

                    $collection->addItem(
                        new Varien_Object(
                            array(
                                'path' => $completePath,
                                'rewrite_class' => $rewriteClassName,
                                'active_class' => $instance,
                                'status' => ($instance == $rewriteClassName)
                            )
                        )
                    );
                }
            }
        }

        return $collection;
    }

    /**
     * Return all rewrites
     *
     * @return array All rwrites
     */
    protected function _loadRewrites()
    {
        $fileName = 'config.xml';
        $modules = Mage::getConfig()->getNode('modules')->children();

        $return = array();
        foreach ($modules as $modName => $module) {
            if ($module->is('active')) {
                $configFile = Mage::getConfig()->getModuleDir('etc', $modName) . DS . $fileName;
                if (file_exists($configFile)) {
                    $xml = file_get_contents($configFile);
                    $xml = simplexml_load_string($xml);

                    if ($xml instanceof SimpleXMLElement) {
                        $return[$modName] = $xml->xpath('//rewrite');
                    }
                }
            }
        }

        return $return;
    }

    /**
     * Retrieve a collection of all modules
     *
     * @return Varien_Data_Collection Collection
     */
    public function getModulesCollection()
    {
        $sortValue = Mage::app()->getRequest()->getParam('sort', 'name');
        $sortValue = strtolower($sortValue);

        $sortDir = Mage::app()->getRequest()->getParam('dir', 'ASC');
        $sortDir = strtoupper($sortDir);

        $modules = $this->_loadModules();
        $modules = $this->sortMultiDimArr($modules, $sortValue, $sortDir);

        $collection = new Varien_Data_Collection();
        foreach ($modules as $val) {
            $item = new Varien_Object($val);
            $collection->addItem($item);
        }

        return $collection;
    }

    /**
     * Loads the module configurations and checks for some criteria and
     * returns an array with the current modules in the Magento instance.
     *
     * @return array Modules
     */
    protected function _loadModules()
    {
        $modules = array();
        $config = Mage::getConfig();
        foreach ($config->getNode('modules')->children() as $item) {
            $active = ($item->active == 'true') ? true : false;
            $codePool = (string)$config->getModuleConfig($item->getName())->codePool;
            $path = $config->getOptions()->getCodeDir() . DS . $codePool . DS . uc_words($item->getName(), DS);
            $pathExists = file_exists($path);
            $pathExists = $pathExists ? true : false;
            $configExists = file_exists($path . '/etc/config.xml');
            $configExists = $configExists ? true : false;
            $version = (string)$config->getModuleConfig($item->getName())->version;

            $dependencies = '-';
            if ($item->depends) {
                $depends = array();
                foreach ($item->depends->children() as $depend) {
                    $depends[] = $depend->getName();
                }
                if (is_array($depends) && count($depends) > 0) {
                    asort($depends);
                    $dependencies = implode("\n", $depends);
                }
            }

            $modules[$item->getName()] = array(
                'name' => $item->getName(),
                'active' => $active,
                'code_pool' => $codePool,
                'path' => $path,
                'path_exists' => $pathExists,
                'config_exists' => $configExists,
                'version' => $version,
                'dependencies' => $dependencies
            );
        }

        return $modules;
    }

    /**
     * Retrieve a collection of all events
     *
     * @return Varien_Data_Collection Collection
     */
    public function getEventsCollection()
    {
        $sortValue = Mage::app()->getRequest()->getParam('sort', 'event');
        $sortValue = strtolower($sortValue);

        $sortDir = Mage::app()->getRequest()->getParam('dir', 'ASC');
        $sortDir = strtoupper($sortDir);

        $events = $this->_loadEvents();
        $events = $this->sortMultiDimArr($events, $sortValue, $sortDir);

        $collection = new Varien_Data_Collection();
        foreach ($events as $item) {
            $values = $item['children'];
            if (is_array($values)) {
                asort($values);
            }

            $val = array(
                'event' => $item['event'],
                'location' => implode("\n", $values)
            );

            $item = new Varien_Object($val);
            $collection->addItem($item);
        }

        return $collection;
    }

    /**
     * Return all events
     *
     * @return array All events
     */
    protected function _loadEvents()
    {
        $fileName = 'config.xml';
        $modules = Mage::getConfig()->getNode('modules')->children();

        $events = array();
        foreach ($modules as $modName => $module) {
            if ($module->is('active')) {
                $configFile = Mage::getConfig()->getModuleDir('etc', $modName) . DS . $fileName;
                if (file_exists($configFile)) {
                    $xml = file_get_contents($configFile);
                    $xml = simplexml_load_string($xml);

                    if ($xml instanceof SimpleXMLElement) {
                        $events[$modName] = $xml->xpath('//events');
                    }
                }
            }
        }

        $return = array();
        foreach ($events as $eventNodes) {
            foreach ($eventNodes as $n) {
                $pathNodes = $n->children();

                foreach ($pathNodes as $pathNode) {
                    $eventName = (string)$pathNode->getName();
                    $instance = $pathNode->xpath('observers/node()/class');
                    $instance = (string)current($instance);
                    $instance = Mage::getConfig()->getModelClassName($instance);

                    if (!array_key_exists($eventName, $return)) {
                        $return[$eventName] = array(
                            'event' => $eventName,
                            'children' => array()
                        );
                    }
                    if (!in_array($instance, $return[$eventName])) {
                        $return[$eventName]['children'][] = $instance;
                    }
                }
            }
        }

        return $return;
    }

    /**
     * Checks if one or more caches are active
     *
     * @return string Cache Message
     */
    public function checkCaches()
    {
        $active = 0;
        $inactive = 0;
        foreach (Mage::app()->getCacheInstance()->getTypes() as $type) {
            $tmp = $type->getData();
            if ($tmp['status']) {
                $active++;
            } else {
                $inactive++;
            }
        }

        return $this->__(
            '%s caches active, %s caches inactive',
            $active,
            $inactive
        );
    }

    /**
     * Checks if all indexes are up-to-date
     *
     * @return string Indexes Message
     */
    public function checkIndizes()
    {
        $ready = 0;
        $processing = 0;
        $reindex = 0;

        $collection = Mage::getResourceModel('index/process_collection');
        foreach ($collection as $item) {
            $tmp = $item->getData();
            if ($tmp['status'] == 'pending') {
                $ready++;
            } elseif ($tmp['status'] == 'working') {
                $processing++;
            } else {
                $reindex++;
            }
        }

        return $this->__(
            '%s indexes are ready, %s indexes are working, %s indexes need reindex',
            $ready,
            $processing,
            $reindex
        );
    }

    /**
     * Returns a small system check report with some essential properties
     *
     * @return array Extension Check Result
     */
    public function checkSystem()
    {
        return $this->_extensionCheck(
            array(
                'curl',
                'dom',
                'gd',
                'hash',
                'iconv',
                'mcrypt',
                'pcre',
                'pdo',
                'pdo_mysql',
                'simplexml'
            )
        );
    }

    /**
     * Checks some kind of essential properties
     *
     * @param  string $extensions Extensions to check
     * @return array  Array with failed and passed checks
     */
    protected function _extensionCheck($extensions)
    {
        $fail = array();
        $pass = array();

        if (version_compare(phpversion(), '5.2.0', '<')) {
            $fail[] = 'You need <strong>PHP 5.2.0</strong> (or greater)';
        } else {
            $pass[] = 'You have <strong>PHP 5.2.0</strong> (or greater)';
        }

        if (!ini_get('safe_mode')) {
            $pass[] = 'Safe Mode is <strong>off</strong>';

            $con = Mage::getSingleton('core/resource')->getConnection('core_read');
            $version = $con->getServerVersion();

            if (version_compare($version, '4.1.20', '<')) {
                $fail[] = 'You need <strong>MySQL 4.1.20</strong> (or greater)';
            } else {
                $pass[] = 'You have <strong>MySQL 4.1.20</strong> (or greater)';
            }
        } else {
            $fail[] = 'Safe Mode is <strong>on</strong>';
        }

        foreach ($extensions as $extension) {
            if (!extension_loaded($extension)) {
                $fail[] = 'You are missing the <strong>' . $extension . '</strong> extension';
            } else {
                $pass[] = 'You have the <strong>' . $extension . '</strong> extension';
            }
        }

        return array('pass' => $pass, 'fail' => $fail);
    }
}
