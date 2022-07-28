<?php

class Handler {
    protected static $methods = [
        '/post/index.json'  => 'handlePosts',
        '/full_banner'      => 'handleBanner',
        '/sample_image'     => 'handleSample',
        '/gallery_image'    => 'handleGalleryImage'
    ];
    protected $method;

    protected $page;
    protected $tags;
    protected $categories;
    protected $gallery;
    protected $perGallery = 1;

    public function __construct($method, $params)
    {
        $this->method = $method;
        $this->params = $params;
    }

    public static function fromWeb()
    {
        $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        if(!isset(self::$methods[$path]))
            throw new Exception("Unsupported:" . $path);

        return new self(self::$methods[$path], $_GET);
    }

    protected function getParam($key, $required = true, $default = null)
    {
        if(!isset($this->params[$key])) {
            if($required)
                throw new Exception("Missing: " . $key);
            return $default;
        }

        return $this->params[$key];
    }

    public function handle()
    {
        try {
            return $this->{$this->method}();
        } catch (Exception $e) {
            error_log('Exception: ' . $e->getMessage());
            exit;
        }
    }

    protected function handleTags()
    {
        $this->tags = [];
        $this->categories = [];

        $tags = array_filter(explode(' ', $this->getParam('tags', false)));
        
        $categories = getCategories();
        $catMap = array_combine(
            array_column($categories, 'tag'),
            array_column($categories, 'id')
        );

        foreach($tags as $t) {
            if(isset($catMap[$t])) {
                $this->categories[] = $t;
                continue;
            }

            if($t == '*') {
                $this->perGallery = -1;
                continue;
            }

            $pts = preg_split('/[:#]/', $t);
            $handled = false;

            switch($pts[0]) {
                case 'category':
                    $this->categories[] = $pts[1];
                    $handled = true;
                    break;
                case 'gallery':
                    $this->gallery = Gallery::fromPage($pts[2], $pts[1], $this->page);
                    $handled = true;
                    break;
                case 'order':
                    $handled = true;
                    break;
            }

            if(!$handled)
                $this->tags[] = $t;
        }
    }

    protected function handleCategories()
    {
        $categories = getCategories();
        $catMap = array_combine(
            array_column($categories, 'tag'),
            array_column($categories, 'id')
        );

        $filterCategories = 0;
        if($this->categories) {
            foreach($catMap as $tag => $id) {
                if(in_array($tag, $this->categories))
                    continue;

                $filterCategories |= $id;
            }
        }
        return $filterCategories;
    }

    protected function handlePosts()
    {
        $this->page = $this->getParam('page', false, 1);
        $this->handleTags();

        if($this->gallery != null) {
            $results = $this->gallery->getPageImages($this->page);
            $results = array_map(function($i) { return $i->getPostData(); }, $results);
            header("Content-type: application/json");
            echo(json_encode($results));
            return;
        }

        $params = ['f_search' => implode(' ', $this->tags), 'f_cats' => $this->handleCategories()];
        
        if($this->perGallery == -1) {
            $limit = $this->getParam('limit', false, 50);
            $offset = ($this->page-1) * $limit;
            $list = new LoaderImage($offset, $limit, $params);
        } else {
            $list = new LoaderGallery($this->page, $params);
        }

        header("Content-type: application/json");
        echo(json_encode($list->getResults()));
    }

    protected function handleBanner()
    {
        list($gt, $gToken, $gId) = preg_split('/[:#]/', $this->getParam('id'));
        $gallery = Gallery::fromPage($gId, $gToken, 1);
        $image = $gallery->nextImage();
        $url = $image->getPostData()['file_url'];
        header('Location: ' . $url);
    }

    protected function handleSample()
    {
        global $memcache;
        $input_url = 'https://ehgt.org/m/' . $this->getParam('token') . '/' . $this->getParam('gallery') . '-' . $this->getParam('page') . '.jpg';
        $imageData = $memcache->get($input_url);
        if(!$imageData) {
            error_log('fetch: ' . $input_url);
            $imageData = file_get_contents($input_url);
            $memcache->set($input_url, $imageData);
        }
        $image = imagecreatefromstring($imageData);
        
        $rect = [
            'width'     => $this->getParam('width'),
            'height'    => $this->getParam('height'),
            'x'         => $this->getParam('x'),
            'y'         => 0
        ];

        $img_out = imagecrop($image, $rect);
        
        header("Content-type: image/png");
        imagepng($img_out);
    
        imagedestroy($image);
        imagedestroy($img_out);
    }

    protected function handleGalleryImage()
    {
        list($it, $gt, $gToken, $gId, $iToken, $pageNr) = preg_split('/[:#]/', $this->getParam('id'));
        $gallery = Gallery::fromId($gToken, $gId);
        $image = new Image($pageNr, $iToken, $gallery);
        header('Location: ' . $image->getFileUrl());
    }
}