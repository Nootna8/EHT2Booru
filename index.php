<?php

require_once(__DIR__ . '/vendor/autoload.php');
use PhpQuery\PhpQuery;
use LSS\Array2XML;

global $memcache;
$memcache = new Memcached;
$memcache->addServer('localhost', 11211) or die ("Could not connect");

global $proxies, $proxyPos;
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
$proxyPos = 0;

function getProxy()
{
    global $memcache, $proxies;
    $pos = $memcache->get('proxy-position');
    if(!$pos)
        $pos = 0;

    $uses = $memcache->get('proxy-'.$pos.'-uses');
    if(!$uses)
        $uses = 0;

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

    return $proxies[$pos];
}

function websiteRequest($arguments, $urlAdd = null) {
    global $memcache;

    $url = 'http://e-hentai.org/' . $urlAdd;
    if($arguments) {
        $url .= '?' . http_build_query($arguments);
    }
    $key = 'eht1-cache-'.$url.round(time() / 120);
    $data = $memcache->get($key);
    if(!$data) {
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

        $memcache->set($key, $data);
    }
    
    return $data;
}

function apiRequest($arguments) {
    global $memcache;

    $request = json_encode($arguments);
    $key = 'eht-api-cache-'.$request.round(time() / 120);
    $data = $memcache->get($key);

    if(!$data) {
        error_log($request);
        $client = new RestClient(['base_url' => 'http://api.e-hentai.org/api.php', 'format' => 'json', 'curl_options' => getProxy()]);
        $data = $client->post('', $request)->decode_response();
        if(!$data)
            throw new Exception("No api response");
        $memcache->set($key, $data);
    }
    
    return $data;
}

function getCategories()
{
    global $memcache;
    $categories = $memcache->get('eht-categories');
    if(!$categories) {
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
        $memcache->set('eht-categories', $categories);
    }
    return $categories;
}

class Image {
    protected $pageNr;
    protected $imageToken;
    protected $imageData;
    protected $gallery;
    protected $thumb;

    public function __construct($pageNr, $imageToken, $gallery, $thumb=null)
    {
        $this->pageNr = $pageNr;
        $this->imageToken = $imageToken;
        $this->gallery = $gallery;
        $this->thumb = $thumb;
    }

    public function getId()
    {
        $id = 'image:';
        $id .= $this->gallery->getId();
        $id .= ':' . $this->imageToken;
        $id .= ':' . $this->pageNr;
        return $id;
    }

    public function getTags()
    {
        $tags = $this->gallery->getTags();
        $tags[] = $this->getId();
        return $tags;
    }

    protected function load()
    {
        if($this->imageData)
            return;

        global $memcache;
        $showKeyKey = 'showkey-' . date('Y-m-d') . '-' . $this->gallery->getGalleryId();
        $showKey = $memcache->get($showKeyKey);
        if(!$showKey) {
            $html = websiteRequest([], 's/' . $this->imageToken . '/' . $this->gallery->getGalleryId() . '-' . $this->pageNr);
            preg_match('/var showkey="([a-z0-9]+)"/', $html, $out);
            $showKey = $out[1];
            $memcache->set($showKeyKey, $showKey);
        }

        $this->imageData = apiRequest([
            'method'    => 'showpage',
            'gid'       => $this->gallery->getGalleryId(),
            'page'      => $this->pageNr,
            'imgkey'    => $this->imageToken,
            'showkey'   => $showKey
        ]);
    }

    public function getPostData()
    {
        $this->load();
        
        preg_match('/id="img" src="([^"]+)"/', $this->imageData->i3, $imgOut);
        preg_match('/<a href="([^"]+)"/', $this->imageData->i7, $fullImgOut);

        return [
            'id'            => $this->getId(),
            'tags'          => implode(' ', $this->getTags()),
            //'has_comments'  => false,
            'status'        => 'active',
            //'has_children'  => false,
            //'has_notes'     => false,
            //'rating'        => 's',
            //'creator_id'    => 123,
            'width'         => $this->imageData->x,
            'height'        => $this->imageData->y,
            'source'        => 'https://e-hentai.org/s/' . $this->imageToken . '/' . $this->gallery->getGalleryId() . '-' . $this->pageNr,
            'file_size'     => $this->imageData->si,
            'file_url'      => $imgOut[1],
            //'file_url'      => $fullImgOut[1] ?? $imgOut[1],
            'preview_url'   => $this->thumb == null ? $imgOut[1] : $this->thumb,
            //'md5'           => 'adfc1a6da575574f9cccc5c3aa33270b'
        ] + $this->gallery->getPostData();
    }

    public function nextImage()
    {
        $this->load();

        $nextNr = $this->pageNr+1;
        if(preg_match('/load_image\\(' . $nextNr . ', \'([a-z0-9]+)\'\\)/', $this->imageData->i3, $out) !== 1)
            return null;

        return new self($nextNr, $out[1], $this->gallery);
    }
}

class Gallery {
    protected $galleryData = null;

    protected $thumbUrl;
    protected $pagesHtml = [];
    protected $pagesImages = [];
    protected $lastImage = null;
    protected $loaded = false;
    protected $offset = 0;
    
    protected function __construct($galleryData)
    {
        $this->galleryData = $galleryData;
    }

    public function getGalleryId()
    {
        return $this->galleryData->gid;
    }

    public static function fromRow($pq, $row)
    {
        $galleryData = new stdClass();
        $link = $pq->query('td a', $row);
        $link = $link[0]->getAttribute("href");
        preg_match("/\/(\d+)\/([a-z0-9]+)\/$/", $link, $out);
        $galleryData->token = $out[2];
        $galleryData->gid = $out[1];

        $galleryData->filecount = explode(' ', $pq->query('td.gl4c div', $row)[1]->textContent)[0];

        $thumb = $pq->query('td.gl2c div.glthumb div img', $row);
        $galleryData->thumbUrl = $thumb[0]->getAttribute('data-src');
        if(!$galleryData->thumbUrl) {
            $galleryData->thumbUrl = $thumb[0]->getAttribute('src');
        }

        $galleryData->posted_date = $pq->query('div#posted_' . $galleryData->gid)[0]->textContent;
        $galleryData->category = $pq->query('td.gl1c div.cn')[0]->textContent;

        $galleryData->tags = [];
        $tags = $pq->query('td.gl3c a div div.gt', $row);
        foreach($tags as $t) {
            $galleryData->tags[] = $t->getAttribute('title');
        }

        return new self($galleryData);
    }

    public static function fromPage($id, $token, $page)
    {
        $html = websiteRequest(['p' => $page-1], 'g/' . $id . '/' . $token . '/');
        $pq=new PhpQuery;
        $pq->load_str($html);

        $galleryData = new stdClass();
        $galleryData->token = $token;
        $galleryData->gid = $id;
        $galleryData->category = $pq->query('div#gdc div.cs')[0]->textContent;
        $galleryData->tags = [];

        $dataTable = $pq->query('div#gdd table tr');
        foreach($dataTable as $elm) {
            $cols = $pq->query('td', $elm);
            $k = $cols[0]->textContent;
            $v = $cols[1]->textContent;
            if($k == 'Length:') {
                $galleryData->filecount = intval(explode(' ', $v)[0]);
            } else if($k == 'Posted:') {
                $galleryData->posted_date = $v;
            }
        }

        $tags = $pq->xpath('//div[@id=\'taglist\']//a');
        foreach($tags as $t) {
            $galleryData->tags[] = str_replace('ta_', '', $t->getAttribute('id'));
        }
        
        return new self($galleryData);
    }

    public function getId()
    {
        $id = 'gallery';
        $id .= ':'.$this->galleryData->token;
        $id .= ':'.$this->galleryData->gid;
        return $id;
    }

    public function setOffset($offset)
    {
        if($this->offset != $offset) {
            $this->offset = $offset;
            $this->lastImage = null;
        }
    }

    public function getNumImages()
    {
        return $this->galleryData->filecount;
    }

    protected function getPageHtml($num)
    {
        if(!isset($this->pagesHtml[$num]))
            $this->pagesHtml[$num] = websiteRequest(['p' => $num-1], 'g/' . $this->galleryData->gid . '/' . $this->galleryData->token . '/');

        return $this->pagesHtml[$num];
    }

    
    public function getPageImages($num)
    {
        if($num > ceil($this->galleryData->filecount / 40))
            return [];
        if(!isset($this->pagesImages[$num])) {
            $html = $this->getPageHtml($num);
            $pq=new PhpQuery;
            $pq->load_str($html);

            $images = [];
            
            $elements = $pq->query('div.gdtm div');
            foreach($elements as $elm) {
                $pageUrl = $pq->query('a', $elm)[0]->getAttribute('href');
                if(preg_match("/s\\/([a-z0-9]+)\\/\\d+-(\\d+)$/", $pageUrl, $pageOut) !== 1)
                    continue;

                $thumb = '';
                if(preg_match('/width:(\d+)px.+height:(\d+)px.+url\([^\)]+\/(\d+)\/\d+-(\d+)[^\)]+\) -(\d+)px/', $elm->getAttribute('style'), $out)) {
                    $thumb = getenv('BASE_URL') . '/sample_image?gallery=' .
                     $this->getGalleryId() . '&token=' . $out[3] . '&page=' . $out[4] . '&width=' . $out[1] . '&x=' . $out[5] .'&height=' . $out[2];
                }

                $images[] = new Image($pageOut[2], $pageOut[1], $this, $thumb);
            }
            $this->pagesImages[$num] = $images;
        }

        return $this->pagesImages[$num];
    }
    
    public function load()
    {
        if($this->loaded)
            return;

        $response = apiRequest([
            'method'    => 'gdata',
            'gidlist'   => [[$this->galleryId, $this->galleryToken]],
            'namespace' => 1
        ]);

        $this->galleryData = $response->gmetadata[0];
        $this->numImages = $this->galleryData->filecount;
        $this->loaded = true;
        //$this->numPages = ceil($this->numImages / 40);
        
        /*
        $html = $this->getPageHtml(1);
        $pq=new PhpQuery;
        $pq->load_str($html);

        $dataTable = $pq->query('div#gdd table tr');
        foreach($dataTable as $elm) {
            $cols = $pq->query('td', $elm);
            $k = $cols[0]->textContent;
            $v = $cols[1]->textContent;
            if($k == 'Length:') {
                $this->numImages = intval(explode(' ', $v)[0]);
            }
        }
        
        $this->numPages = ceil($this->numImages / 40);
        $this->name = $pq->query('h1#gn')[0]->textContent;
        */
    }

    public function getImages($num)
    {
        $html = $this->getPageHtml(1 + floor($this->offset / 40));
        $pq=new PhpQuery;
        $pq->load_str($html);
        $elements = $pq->query('div.gdtm div a');
        $startUrl = $elements[$this->offset%40]->getAttribute('href');

        preg_match("/s\\/([a-z0-9]+)\\/\\d+-(\\d+)$/", $startUrl, $out);

        $images = [];
        $image = new Image($out[2], $out[1], $this);
        while(count($images) < $num) {
            $images[] = $image;
            $image = $image->getNextImage();
            if(!$image)
                break;

        }
        return $images;
    }

    public function nextImage()
    {
        error_log('next gallery image');

        if($this->offset > $this->galleryData->filecount)
            return null;

        if($this->lastImage == null) {
            $html = $this->getPageHtml(1 + floor($this->offset / 40));
            $pq=new PhpQuery;
            $pq->load_str($html);
            $elements = $pq->query('div.gdtm div a');
            $startUrl = $elements[$this->offset%40]->getAttribute('href');

            preg_match("/s\\/([a-z0-9]+)\\/\\d+-(\\d+)$/", $startUrl, $out);
            $this->lastImage = new Image($out[2], $out[1], $this);
            $this->offset ++;
        } else {
            $this->lastImage = $this->lastImage->nextImage();
            $this->offset ++;
        }

        return $this->lastImage;
    }

    public function getTags()
    {
        $tags = $this->galleryData->tags;
        $tags[] = $this->getId();

        $categories = getCategories();
        foreach($categories as $c) {
            if($c['name'] == $this->galleryData->category) {
                $tags[] = 'category:' . $c['tag'];
                break;
            }
        }

        return $tags;
    }

    public function getPostData($asImage = false)
    {
        $data = [
            'tags'          => [],
            'author'        => null,
            'score'         => 0,//round($this->galleryData->rating),
            'created_at'    => $this->galleryData->posted_date,
            'parent_id'     => 123
        ];

        if($asImage) {
            $data['id'] = $this->getId();
            $data['file_url'] = getenv('BASE_URL') . '/full_banner?id=' . $this->galleryData->gid . '&token=' . $this->galleryData->token;
            $data['preview_url'] = $this->galleryData->thumbUrl;
            $data['source'] = 'https://e-hentai.org/g/' . $this->galleryData->gid . '/' . $this->galleryData->token . '/';
            $data['has_children'] = true;

            $data['tags'] = implode(' ', $this->getTags());
        }

        if(isset($this->galleryData->parent_gid)) {
            $data['parent_id'] = $this->galleryData->parent_gid . '-' . $this->galleryData->parent_key;
        }

        foreach($this->galleryData->tags as $tagLine) {
            if(strpos($tagLine, ':')) {
                list($type, $tag) = explode(':', $tagLine);
                //$data['tags'][] = $tag;

                if($type == 'artist')
                    $data['author'] = $tag;
            }
        }

        
        return $data;
    }
}

class ImageList {
    protected $skipped = 0;
    protected $pageNr = 0;
    protected $lastPage;
    protected $offset;
    protected $limit;
    protected $params;
    protected $results = [];
    protected $galleries = [];
    protected $gallery;

    public function __construct($offset, $limit, $params)
    {
        $this->offset = $offset;
        $this->limit = $limit;
        $this->params = $params;
    }

    protected function loadPage()
    {
        $html = websiteRequest($this->params + ['page' => $this->pageNr-1]);
        $pq=new PhpQuery;
        $pq->load_str($html);

        $pageElements = $pq->query('table.ptt tr td');
        $this->lastPage = $pageElements[count($pageElements)-2]->textContent;
        //error_log($this->lastPage);

        $this->galleries = [];
        foreach($pq->query('table.itg.gltc tr') as $row) {
            // Skip header
            $link = $pq->query('td a', $row);
            if(count($link) == 0)
                continue;
            $this->galleries[] = Gallery::fromRow($pq, $row);

            /*
            $link = $pq->query('td a', $row);
            if(count($link) == 0)
                continue;
            $link = $link[0]->getAttribute("href");
            if(preg_match("/\/(\d+)\/([a-z0-9]+)\/$/", $link, $out) !== 1)
                continue;
            
            $imageCount = explode(' ', $pq->query('td.gl4c div', $row)[1]->textContent)[0];
            $this->galleries[] = new Gallery($out[1], $out[2], $imageCount);
            */
        }

        return count($this->galleries);
    }
    
    protected function nextPage()
    {
        //error_log('next page');
        $this->pageNr ++;

        if($this->lastPage && $this->pageNr > $this->lastPage)
            return 0;

        return $this->loadPage();
    }

    protected function nextGallery()
    {
        //error_log('next gallery');

        if(count($this->galleries) == 0) {
            if($this->nextPage() == 0) {
                //error_log("no next gallery");
                return null;
            }
        }
            
        $this->gallery = array_shift($this->galleries);
        return $this->gallery->getNumImages();
    }

    protected function nextImage()
    {
        if(!$this->gallery)
            if(!$this->nextGallery())
                return null;

        $image = $this->gallery->nextImage();
        if(!$image) {
            if(!$this->nextGallery())
                return null;

            $image = $this->gallery->nextImage();
        }

        if($image == null) {
            //error_log("no next image");
        }

        return $image;
    }

    public function getResults()
    {
        while($this->skipped < $this->offset) {
            //error_log("offset: " . $this->offset);
            //error_log("skipped: " . $this->skipped);
            $numImages = $this->nextGallery();
            //error_log("batch: " . $numImages);
            if(!$numImages)
                return $this->results;

            $toSkip = $this->offset - $this->skipped;
            if($numImages <= $toSkip) {
                $this->skipped += $numImages;
                continue;
            }
            if($numImages > $toSkip) {
                $this->gallery->setOffset($toSkip);
            }
            $this->skipped = $this->offset;
        }

        while(count($this->results) < $this->limit) {
            $image = $this->nextImage();
            if(!$image)
                return $this->results;

            $this->results[] = $image->getPostData();
        }

        return $this->results;
    }
}

class GalleryList extends ImageList {
    protected $pageNr;
    protected $params;
    
    public function __construct($pageNr, $params)
    {
        $this->pageNr = $pageNr;
        $this->params = $params;
    }

    public function getResults()
    {
        $this->loadPage();

        foreach($this->galleries as $gallery) {
            $this->results[] = $gallery->getPostData(true);
        }

        return $this->results;
    }
}

function handleRequest($input) {
    $response = [];

    $categories = getCategories();
    $catMap = array_combine(
        array_column($categories, 'tag'),
        array_column($categories, 'id')
    );

    $limit = $input['limit'] ?? 100;
    $limit = 50;
    $page = $input['page'] ?? 1;
    $offset = ($page-1) * $limit;

    $tags = array_filter(explode(' ', $input['tags'] ?? ''));
    $searchTags = [];
    $searchCategories = [];
    $gallery = null;
    $imageMode = false;

    foreach($tags as $t) {
        if($t == '*') {
            $imageMode = true;
        } else if(isset($catMap[$t])) {
            $searchCategories[] = $t;
        } else if(strpos($t, ':')) {
            list($type, $tag) = explode(':', $t, 2);
            if($type == 'category') {
                $searchCategories[] = $tag;
            } else if($type == 'order') {

            } else if($type == 'gallery') {
                list($token, $id) = explode(':', $tag);
                $gallery = Gallery::fromPage($id, $token, $page);
            } else {
                $searchTags[] = $t;
            }
        } else {
            $searchTags[] = $t;
        }
    }

    if($gallery != null) {
        $images = $gallery->getPageImages($page);
        $results = [];
        foreach($images as $i) {
            $results[] = $i->getPostData();
        }
        return $results;
    }

    $filterCategories = 0;
    if($searchCategories) {
        foreach($catMap as $tag => $id) {
            if(in_array($tag, $searchCategories))
                continue;

            $filterCategories |= $id;
        }
    }

    $params = ['f_search' => implode(' ', $searchTags), 'f_cats' => $filterCategories];
    if($imageMode) {
        $list = new ImageList($offset, $limit, ['f_search' => implode(' ', $searchTags), 'f_cats' => $filterCategories]);
    } else {
        $list = new GalleryList($page, $params);
    }
    $results = $list->getResults();

    return $results;
}

//error_log(print_r($_SERVER, true));

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if($path == '/post/index.json') {
    $response = handleRequest($_GET);
    echo json_encode($response);
}
else if($path== '/full_banner') {
    $gallery = Gallery::fromPage($_GET['id'], $_GET['token'], 1);
    $image = $gallery->nextImage();
    $url = $image->getPostData()['file_url'];
    header('Location: ' . $url);
}
else if($path == '/sample_image') {
    $input_url = 'https://ehgt.org/m/' . $_GET['token'] . '/' . $_GET['gallery'] . '-' . $_GET['page'] . '.jpg';
    $imageData = $memcache->get($input_url);
    if(!$imageData) {
        error_log('fetch: ' . $input_url);
        $imageData = file_get_contents($input_url);
        $memcache->set($input_url, $imageData);
    }
    $image = imagecreatefromstring($imageData);
    $rect = $_GET;
    $rect['y'] = 0;
    $img_out = imagecrop($image, $rect);
    
    header("Content-type: image/png");
    imagepng($img_out);

    imagedestroy($image);
    imagedestroy($img_out);
}
else {
    throw new Exception('Unsupported: ' . $path);
}



/*
header('Content-Type: application/xml');
$xml = Array2XML::createXML('posts', $response);
$xml->preserveWhiteSpace = false;
$xml->formatOutput = false;
echo $xml->saveXML();
*/