<?php

class Proxy {
    protected $data = [];

    public static function getProxy($forceNext = false)
    {
        $proxies = getCachedVal('proxies', function() {
            return [];
        });
        if(count($proxies) == 0) {
            return null;
        }

        $pos = getCachedVal('proxy-position', function() {
            return 0;
        });
        if($pos >= count($proxies)) {
            $pos = 0;
        }

        $proxy = new Proxy($proxies[$pos]);
        
        $uses = 0;
        $state = $proxy->getState();
        if(isset($state->uses)) {
            $uses = $state->uses;
        }

        if($uses > 15 || $forceNext) {
            $pos = getCachedVal('proxy-position', function() {
                return 0;
            });
            global $memcache;
            $memcache->set('proxy-position', $pos + 1);
            $proxy->setStateField('uses', 0);
            return Proxy::getProxy();
        }

        return $proxy;
    }

    public function use($handle)
    {
        error_log("Using proxy: " . $this->data[CURLOPT_PROXY]);
        curl_setopt_array($handle, $this->data);
        $this->addStateField('usesTotal');
        $this->addStateField('uses');
    }

    protected function getCacheKey()
    {
        return 'proxy-' . $this->data[CURLOPT_PROXY] . '-state';
    }

    public function getState()
    {
        global $memcache;
        return getCachedVal($this->getCacheKey(), function() {
            return new stdClass();
        });
    }

    public function addStateField($field)
    {
        $state = $this->getState();
        $val = 0;
        if(isset($state->{$field})) {
            $val = $state->{$field};
        }
        $val ++;
        $state->{$field} = $val;
        
        global $memcache;
        $memcache->set($this->getCacheKey(), $state);
    }

    public function setStateField($field, $value)
    {
        $state = $this->getState();
        $state->{$field} = $value;
        
        global $memcache;
        $memcache->set($this->getCacheKey(), $state);
    }

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function testIp()
    {
        $ch = curl_init('http://ifconfig.me/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt_array($ch, $proxy);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    } 

    public function testEht()
    {
        $request = new HttpRequest('SITE', [], null, $p);
        try {
            $html = $request->doRequest();
            return true;
        }
        catch(Exception $e) {
            return false;
        }
    }
}

class HttpRequest {
    protected $urlAdd;
    protected $methop;
    protected $arguments;
    protected $result = null;
    protected $handle = null;
    protected $multiHandle = null;
    protected $time = null;
    protected $proxy = [];
    public $tries = 0;

    protected function getCacheKey()
    {
        return '3-eht-http-cache-'.md5($this->urlAdd.'-'.$this->method.'-'.json_encode($this->arguments));
    }

    public function __construct($method, $arguments, $urlAdd = null, $proxy = [])
    {
        $this->method = $method;
        $this->arguments = $arguments;
        $this->urlAdd = $urlAdd;
        $this->time = time();
        $this->proxy = $proxy;
    }

    public function prepareCurl()
    {
        if($this->handle) {
            curl_close($this->handle);
        }

        $url = null;
        if($this->method == 'API') {
            $url = 'http://api.e-hentai.org/api.php'; 
        }
        else if($this->method == 'SITE') {
            $url = 'http://e-hentai.org/' . $this->urlAdd;

            if($this->arguments) {
                $url .= '?' . http_build_query($this->arguments);
            }
        }
        else {
            throw new \Exception("Unsupported method: " . $this->method);
        }

        $this->handle = curl_init($url);

        if($this->method == 'SITE') {
            curl_setopt($this->handle, CURLOPT_HTTPHEADER, [
                'Cookie: nw=1'
            ]);
        }

        if($this->method == 'API') {
            curl_setopt($this->handle, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);

            curl_setopt($this->handle, CURLOPT_POST, 1 );
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, json_encode($this->arguments) );             
        }

        curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->handle, CURLOPT_TIMEOUT, 2);

        if(is_array($this->proxy)) {
            curl_setopt_array($this->handle, $this->proxy);
        } elseif($this->proxy instanceof Proxy) {
            $this->proxy->use($this->handle);
        }

        if($this->multiHandle) {
            curl_multi_add_handle($this->multiHandle, $this->handle);
        }

        if(!$this->handle) {
            throw Exception("Curl init failed!");
        }

        return $this->handle;
    }

    public function setMultiHandle($mh)
    {
        if($this->multiHandle != null && $mh == null) {
            curl_multi_remove_handle($this->multiHandle, $this->handle);
        }

        $this->multiHandle = $mh;
    }

    public function doRequestInternal()
    {
        if($this->multiHandle) {
            if($this->handle == null) {
                throw new Exception("Not prepared");
            }
            $this->result = curl_multi_getcontent($this->handle);
        } else {
            $this->prepareCurl();
            $this->result = curl_exec($this->handle);
            curl_close($this->handle);
        }
        
        $this->handle = null;

        if(stripos($this->result, 'Your IP address has been temporarily banned'))
            throw new Exception("Banned proxy");

        if(!$this->result)
            throw new Exception("No result");

        if($this->method == 'SITE' && stripos($this->result, 'cloudflare') !== false) {
            throw new Exception("Cloudflare flagged");
        }

        $ret = ['data' => $this->result, 'time' => $this->time];

        global $memcache;
        $memcache->set($this->getCacheKey(), $ret);

        return $ret;
    }

    public function doRequest()
    {
        $this->tries = 0;
        while($this->tries < 3) {
            $this->proxy = Proxy::getProxy($this->tries > 0);

            try {
                $data = $this->doRequestInternal();
                return $data;
            }
            catch(Exception $e) {
                $this->proxy->addStateField('fails');
                $this->tries ++;
                error_log("Http ex: " . $e->getMessage());
            }
        }

        throw new Exception("Request failed");
    }

    public function getResult($cacheOnly = false)
    {
        error_log('Http: ' . $this->method . ' - ' . $this->urlAdd . ' - ' . json_encode($this->arguments));
        
        $key = $this->getCacheKey();
        global $memcache;

        if($cacheOnly) {
            $data = $memcache->get($key);
            if(!$data || $this->time - $data['time'] > 60*3) {
                return null;
            }

            return $data['data'];
        }

        $data = getCachedVal($key, [$this, 'doRequest']);
        if($this->time - $data['time'] > 60*3) {
            $data = $this->doRequest();
            global $memcache;
            $memcache->set($key, $data);
        }

        //error_log("Http resonse: " . $data['data']);

        return $data['data'];
    }
}

class HttpPromise {
    protected $request;
    protected $handlers = [];
    protected $result = null;

    public static function all($promises)
    {
        $promisePile = [];
        foreach($promises as $promise) {
            $data = $promise->request->getResult(true);
            if($data) {
                $promise->handleResult($data);
            } else {
                $promisePile[] = $promise;
            }
        }

        while(count($promisePile) > 0)
        {
            $batches = array_chunk($promisePile, 3);
            $promisePile = [];

            foreach($batches as $batch) {
                error_log("Prep batch");
                $mh = curl_multi_init();

                foreach($batch as $promise) {
                    $promise->request->setMultiHandle($mh);
                    $promise->request->prepareCurl();
                }

                //execute the multi handle
                do {
                    $status = curl_multi_exec($mh, $active);
                    if ($active) {
                        curl_multi_select($mh);
                    }
                } while ($active && $status == CURLM_OK);
                error_log("Batch done");

                foreach($promises as $promise) {
                    $promise->request->setMultiHandle(null);
                }

                curl_multi_close($mh);

                foreach($promises as $promise) {
                    try {
                        $responses[] = $promise->request->doRequestInternal();
                    } catch(Exception $e) {
                        error_log("Http ex: " . $e);
                        
                        $promise->request->tries ++;
                        if($promise->request->tries < 3) {
                            $promisePile[] = $promise;
                        }
                    }
                }
            }
        }

        return array_map(function($p) {
            return $p->resolve();
        }, $promises);
    }

    public function __construct($request)
    {
        $this->request = $request;
    }

    public function then($handler)
    {
        $this->handlers[] = $handler;
        return $this;
    }

    protected function handleResult($result)
    {
        foreach($this->handlers as $handler) {
            $result = $handler($result);
        }
        $this->result = $result;
    }

    public function resolve()
    {
        if($this->result) {
            return $this->result;
        }

        $result = $this->request->getResult();
        $this->handleResult($result);
        return $this->result;
    }
}

function websiteRequest($arguments, $urlAdd = null) {
    $req = new HttpRequest('SITE', $arguments, $urlAdd);
    return $req->getResult();
}

function apiRequest($arguments) {
    $req = new HttpRequest('API', $arguments);
    $result = $req->getResult();
    return json_decode($result);
}