<?php

class ICEshop_Iceimport_Helper_Data extends Mage_Core_Helper_Abstract
{

    /**
     * @param $extension_name
     * @return bool
     */
    public function isExtensionInstalled($extension_name)
    {
        if (!empty($extension_name)) {
            $modules = Mage::getConfig()->getNode('modules')->children();
            $modules = (array)$modules;
            if (array_key_exists($extension_name, (array)$modules)) {
                return true;
            }
        }
        return false;
    }


    /**
     * @param bool $json_encoded
     * @return array|string
     */
    public function getSystemInfo($json_encoded = false)
    {
        $results = array();
        try {
            $checker = Mage::helper('iceimport/system_systemcheck')->init();
            if (!empty($checker)) {
                $results['server'] = $checker->getSystem()->getServer()->getData();
                //$results['system'] = $checker->getSystem()->getMagento()->getData();
                //$results['php'] = $checker->getSystem()->getPhp()->getData();
                //$results['mysql'] = $checker->getSystem()->getMysql()->getData();
                //$results['requirements'] = $checker->getSystem()->getRequirements()->getData();
            }
        } catch (Exception $e) {
        }

        if ($json_encoded == true) {
            return json_encode($results);
        }
        return $results;
    }

    protected function _getButtonSettings($settings)
    {
        $default_settings = array(
            'getBeforeHtml' => '',
            'getId' => '',
            'getElementName' => '',
            'getTitle' => '',
            'getType' => '',
            'getClass' => '',
            'getOnClick' => '',
            'getStyle' => '',
            'getValue' => '',
            'getDisabled' => '',
            'getLabel' => '',
            'getAfterHtml' => ''
        );
        if (!empty($settings) && is_array($settings)) {
            foreach ($settings as $key => $setting) {
                $camel_key = str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
                $default_settings['get' . $camel_key] = $setting;
            }
        }
        return $default_settings;
    }

    /**
     * @param $settings
     * @return string
     */
    public function getButtonHtml($settings)
    {
        $settings = $this->_getButtonSettings($settings);
        $html = $settings['getBeforeHtml'] . '<button '
            . ($settings['getId'] ? ' id="' . $settings['getId'] . '"' : '')
            . ($settings['getElementName'] ? ' name="' . $settings['getElementName'] . '"' : '')
            . ' title="'
            . htmlspecialchars($settings['getTitle'] ? $settings['getTitle'] : $settings['getLabel'], ENT_QUOTES, null, false)
            . '"'
            . ' type="' . $settings['getType'] . '"'
            . ' class="scalable ' . $settings['getClass'] . ($settings['getDisabled'] ? ' disabled' : '') . '"'
            . ' onclick="' . $settings['getOnClick'] . '"'
            . ' style="' . $settings['getStyle'] . '"'
            . ($settings['getValue'] ? ' value="' . $settings['getValue'] . '"' : '')
            . ($settings['getDisabled'] ? ' disabled="disabled"' : '')
            . '><span><span><span>' . $settings['getLabel'] . '</span></span></span></button>' . $settings['getAfterHtml'];

        return $html;
    }

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

    public function toCamelCase($str, $capitalise_first_char = false) {
        if($capitalise_first_char) {
            $str[0] = strtoupper($str[0]);
        }
        $func = create_function('$c', 'return strtoupper($c[1]);');
        return preg_replace_callback('/_([a-z])/', $func, $str);
    }
}