<?php

function iceimport_autoload($className = null)
{
    $path = dirname(__FILE__). DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR . 'Convert' . DIRECTORY_SEPARATOR . 'Adapter' . DIRECTORY_SEPARATOR . 'Iceimport.php';
    require_once($path);
}

spl_autoload_register('iceimport_autoload');

?>