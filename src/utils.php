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

function websiteRequest($arguments, $urlAdd = null) {
    global $memcache;

    $url = 'http://e-hentai.org/' . $urlAdd;
    if($arguments) {
        $url .= '?' . http_build_query($arguments);
    }

    $doRequest = function() use ($url) {
        error_log($url);

        $headers = array(
            "Cookie: nw=1",
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt_array($ch, getProxy());
        $data = curl_exec($ch);
        curl_close($ch);

        if(stripos($data, 'Your IP address has been temporarily banned'))
            throw new Exception("Banned proxy");

        if(!$data)
            throw new Exception("No website response");

        return ['data' => $data, 'time' => time()];
    };

    $key = 'eht-request-cache-'.$url;
    $data = getCachedVal($key, $doRequest);
    if(time() - $data['time'] > 60*3) {
        $data = $doRequest();
        global $memcache;
        $memcache->set($key, $data);
    }
    
    return $data['data'];
}

function apiRequest($arguments) {
    $request = json_encode($arguments);
    $doRequest = function() use ($request) {
        error_log($request);

        $client = new RestClient(['base_url' => 'http://api.e-hentai.org/api.php', 'format' => 'json', 'curl_options' => getProxy()]);
        $data = $client->post('', $request)->decode_response();
        if(!$data)
            throw new Exception("No api response");
        
        return ['data' => $data, 'time' => time()];
    };

    $key = 'eht-api-cache-'.$request;
    $data = getCachedVal($key, $doRequest);
    if(time() - $data['time'] > 60*3) {
        $data = $doRequest();
        global $memcache;
        $memcache->set($key, $data);
    }
    
    return $data['data'];
}

function getCategories()
{
    return getCachedVal('eht-categories', function() {
        $categories = [];
        $html = websiteRequest([], '');
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

    if(!isset($input['login'], $input['password_hash']))
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