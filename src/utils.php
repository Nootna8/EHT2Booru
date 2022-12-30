<?php

use PhpQuery\PhpQuery;

global $memcache;
$memcache = new Memcached;
$memcache->addServer('localhost', 11211) or die ("Could not connect to mem");
isset($memcache->getStats()['localhost:11211']) or die ("Could not connect to mem");

function getCachedVal($key, $loader) {
    global $memcache;
    $val = $memcache->get($key);
    if(!$val) {
        $val = $loader();
        $memcache->set($key, $val);
    }
    return $val;
}

function getProxy()
{
    global $memcache;
    $proxies = explode(';', getenv('EHT_PROXIES'));
    if(count($proxies) == 0)
        $proxies = [''];

    $pos = getCachedVal('proxy-position', function() {
        return 0;
    });

    $uses = getCachedVal('proxy-'.$pos.'-uses', function() {
        return 0;
    });

    if($uses > 10) {
        $memcache->set('proxy-'.$pos.'-uses', 0);
        $pos ++;
        if($pos >= count($proxies))
            $pos = 0;
        $memcache->set('proxy-position', $pos);
        return getProxy();
    }

    $memcache->set('proxy-'.$pos.'-uses', $uses + 1);

    error_log('proxy: ' . $pos);

    if($proxies[$pos])
        return [CURLOPT_PROXY => $proxies[$pos]];
    else
        return [];
}

function getCategories()
{
    return getCachedVal('eht-categories', function() {
        $categories = [];
        $html = websiteRequest([], '');
        if(!$html) {
            throw new Exception("No html");
        }
        $pq=new PhpQuery;
        $pq->load_str($html);
        $elements = $pq->query('div.cs');
        foreach($elements as $element) {
            $categories[] = [
                'id'    => explode('_', $element->getAttribute('id'))[1],
                'name'  => $element->textContent,
                'tag'   => str_replace(' ', '-', strtolower($element->textContent))
            ];
        }
        return $categories;
    });
}

function getCookieJar($input=null)
{
    if(!$input)
        $input = $_GET;

    //return null;

    if(!isset($input['login']) || strlen($input['login']) == 0)
        return null;
    if(!isset($input['password_hash']) || strlen($input['password_hash']) == 0)
        return null;

    global $memcache;
    $iv = $memcache->get('ssl-iv');
    if(!$iv)
        return false;

    $key = 'cookiejar-' . openssl_encrypt($input['login'], "AES-128-CTR", $input['password_hash'], 0, $iv);
    $val = $memcache->get($key);
    if(!$val)
        return false;

    $val = openssl_decrypt($val, "AES-128-CTR", $input['password_hash'], 0, $iv);
    if(!$val)
        return false;
    
    $val = json_decode($val, true);
    if(!is_array($val))
        return false;

    return $val;
}
