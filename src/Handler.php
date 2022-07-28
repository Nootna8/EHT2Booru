<?php

class Handler {
    protected static $methods = [
        '/post/index.json'  => 'handlePosts',
        '/gallery/banner'   => 'handleBanner',
        '/image/sample'     => 'handleSample',
        '/image/main'       => 'handleGalleryImage',
        '/big_image'        => 'handleBigImage',
        '/'                 => 'loginForm',
        '/create-key'       => 'handleCreateKey'
    ];
    protected $method;

    protected $page;
    protected $tags;
    protected $categories;
    protected $gallery;
    protected $perGallery = 1;
    protected $reverse = false;
    protected $list = null;
    protected $filters = [];

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
            echo($e->getTraceAsString());
            error_log('Exception: ' . $e->getMessage());
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
                case 'date':
                    $this->filters['date'] = $pts[1];
                    $handled = true;
                    break;
                case 'category':
                    $this->categories[] = $pts[1];
                    $handled = true;
                    break;
                case 'gallery':
                    $this->gallery = Gallery::fromId($pts[1], $pts[2]);
                    $handled = true;
                    break;
                case 'sort':
                case 'order':
                    $this->handleSort($pts[1]);
                    $handled = true;
                    break;
            }

            if(!$handled)
                $this->tags[] = $t;
        }
    }

    protected function handleSort($sort)
    {
        if($sort == 'desc')
            $this->reverse = true;

        // if($sort == 'votes')
        //     $this->list = 'popular';

        if($sort == 'score')
            $this->list = 'toplist.php?tl=11';
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
        $limit = $this->getParam('limit', false, 50);
        $offset = ($this->page-1) * $limit;

        $this->handleTags();

        if($this->gallery != null) {
            $list = new LoaderImageGallery($offset, $limit, $this->gallery);
        } else {
            $params = ['f_search' => implode(' ', $this->tags), 'f_cats' => $this->handleCategories()];
            
            if($this->perGallery == -1) {
                $list = new LoaderImage($offset, $limit, $params, $this->list);
            } else {
                $list = new LoaderGallery($offset, $limit, $params, $this->list);
            }
        }

        if($this->reverse) {
            $list->setReverse(true);
        }

        if(count($this->filters) > 0) {
            $list->setFilters($this->filters);
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
        $imageData = getCachedVal($input_url, function() use($input_url) {
            $ret = file_get_contents($input_url);
            if(!$ret)
                throw new Exception("No " . $input_url);
            return $ret;
        });
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

    protected function handleBigImage()
    {
        list($it, $gt, $gToken, $gId, $iToken, $pageNr) = preg_split('/[:#]/', $this->getParam('id'));
        $gallery = Gallery::fromId($gToken, $gId);
        $image = new Image($pageNr, $iToken, $gallery);

        $url = $image->getBigUrl();
        $url = str_replace('https', 'http', $url);
        $client = new RestClient();
        $resp = $client->get($url);
        var_dump($resp);
    }

    protected function handleCreateKey()
    {
        $url = 'https://forums.e-hentai.org/';
        $client = new RestClient(['base_url' => $url, 'curl_options' => getProxy()]);
        $req = http_build_query([
            'UserName'  => $_POST['username'],
            'PassWord'  => $_POST['password']
        ]);
        $response = $client->post('index.php?act=Login&CODE=01', $req);
        var_dump($req, $response);
    }

    protected function loginForm() { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
</head>
<body>
    <form method="POST" action="/create-key">
        <input type="text" name="username" placeholder="username"/><br/>
        <input type="password" name="password" placeholder="***"/><br/>
        <input type="submit" />
    </form>    
</body>
</html>
<?php }

}