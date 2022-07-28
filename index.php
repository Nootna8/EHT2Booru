<?php

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/src/utils.php');
require_once(__DIR__ . '/src/Gallery.php');
require_once(__DIR__ . '/src/Handler.php');
require_once(__DIR__ . '/src/Image.php');
require_once(__DIR__ . '/src/Loader.php');

$proxies = [
    [],
    // [
    //     CURLOPT_PROXYTYPE   => CURLPROXY_HTTP,
    //     CURLOPT_PROXY       => '164.90.203.198',
    //     CURLOPT_PROXYPORT   => 80
    // ],
    // [
    //     CURLOPT_PROXYTYPE   => CURLPROXY_HTTP,
    //     CURLOPT_PROXY       => '143.47.177.25',
    //     CURLOPT_PROXYPORT   => 80
    // ],
    // [
    //     CURLOPT_PROXYTYPE   => CURLPROXY_HTTP,
    //     CURLOPT_PROXY       => '206.189.11.141',
    //     CURLOPT_PROXYPORT   => 80
    // ],
    // [
    //     CURLOPT_PROXYTYPE   => CURLPROXY_HTTP,
    //     CURLOPT_PROXY       => '191.101.251.3',
    //     CURLOPT_PROXYPORT   => 80
    // ]
];

//error_log(print_r($_SERVER, true));
$handler = Handler::fromWeb();
$handler->handle();