<?php

class Handler {
    protected static $methods = [
        '/post/index.json'  => 'handlePosts',
        '/gallery/banner'   => 'handleBanner',
        '/image/sample'     => 'handleSample',
        '/image/main'       => 'handleGalleryImage',
        '/image/big'        => 'handleBigImage',
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
            if(getCookieJar() === false)
                throw new Exception("Invalid login");

            return $this->{$this->method}();
        } catch (Exception $e) {
            http_response_code(500);
            echo($e->getTraceAsString());
            error_log('Exception: ' . $e->getMessage());
            throw $e;
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

        $cookieJar = getCookieJar();
        if(!$cookieJar)
            throw new Exception("Not logged in");

        $cookieHeader = [];
        foreach($cookieJar as $k=>$v)
            $cookieHeader[] = $k.'='.$v;
        $cookieHeader = implode('; ', $cookieHeader);

        $ch = curl_init($image->getBigUrl());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Host: e-hentai.org',
            'User-Agent: curl/7.68.0',
            'Cookie: '.$cookieHeader
        ));
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $lines = array_map('trim', explode("\n", $response));
        foreach($lines as $line) {
            //Header end here
            if(strlen($line) == 0)
                break;
            
            $pts = explode(':', $line, 2);
            if($pts[0] != 'location')
                continue;

            header('Location: ' . $pts[1]);
            return;
        }

        header('Location: ' . $image->getFileUrl());
    }

    protected function handleCreateKey()
    {
        $url = 'https://forums.e-hentai.org/index.php?act=Login&CODE=01';
        $request = http_build_query([
            'CookieDate'        => '1',
            'b'                 => 'ds',
            'UserName'          => $_POST['username'],
            'PassWord'          => $_POST['password'],
            'ipb_login_submit'  => 'Login!'
        ]);

        $cacheKey = 'login-' . hash('sha256', $url.$request);
        global $memcache;
        $response = $memcache->get($cacheKey);
        if(!$response) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'CookieDate'        => '1',
                'b'                 => 'ds',
                'UserName'          => $_POST['username'],
                'PassWord'          => $_POST['password'],
                'ipb_login_submit'  => 'Login!'
            ]));
            //curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/103.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            if(stripos($response, 'The captcha was not entered correctly') !== false) {
                $cap = 'https://www.recaptcha.net/recaptcha/api2/anchor?ar=1&k=6LewqhoTAAAAAIaVzC8y6XguPSIZLc5JWqICgHfT&co=aHR0cHM6Ly9mb3J1bXMuZS1oZW50YWkub3JnOjQ0Mw..&hl=en-GB&v=CHIHFAf1bjFPOjwwi5Xa4cWR&size=normal&cb=4acvlr6sp0du';
                
                $this->loginForm($_POST['username'], $_POST['password'], $cap);
                exit;
            }

            if(stripos($response, 'You are now logged in') === false) {
                var_dump($response);
                echo 'Login failed';
                exit;
            }

            $memcache->set($cacheKey, $response);
        }

        $cookieJar = [];
        $lines = array_map('trim', explode("\n", $response));
        foreach($lines as $line) {
            //Header end here
            if(strlen($line) == 0)
                break;
            
            $pts = explode(':', $line, 2);
            if($pts[0] != 'set-cookie')
                continue;

            if(!preg_match('/([a-z_]+)=([a-z0-9]+)/', $pts[1], $out))
                continue;

            $cookieJar[$out[1]] = $out[2];
        }

        $iv = getCachedVal('ssl-iv', function() {
            return openssl_random_pseudo_bytes(16);
        });

        $pass_hash = hash('sha1', "choujin-steiner--" . $_POST['password'] . "--");
        $key = 'cookiejar-' . openssl_encrypt($_POST['username'], "AES-128-CTR", $pass_hash, 0, $iv);
        $memcache->set($key, openssl_encrypt(json_encode($cookieJar), "AES-128-CTR", $pass_hash, 0, $iv));

        echo 'Set';
    }

    protected function loginForm($user=null, $pass=null, $cap=null) { ?>
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
        <input type="text" value="<?php echo($user); ?>" name="username" placeholder="username"/><br/>
        <input type="password" value="<?php echo($pass); ?>" name="password" placeholder="***"/><br/>
        <input type="submit" />

        <?php
        if($cap) echo '<br /><iframe src="' . $cap . '" />';
        ?>
    </form>    
</body>
</html>
<?php }

}