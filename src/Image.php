<?php

use PhpQuery\PhpQuery;

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

    public function getFileUrl()
    {
        $this->load();
        preg_match('/id="img" src="([^"]+)"/', $this->imageData->i3, $imgOut);
        return html_entity_decode($imgOut[1]);
    }

    public function getBigUrl()
    {
        $this->load();
        if(isset($this->imageData->bigUrl))
            return $this->imageData->bigUrl;

        return null;
    }

    public function getId($seperator = ':')
    {
        $id = 'image' . $seperator;
        $id .= $this->gallery->getId($seperator);
        $id .= $seperator . $this->imageToken;
        $id .= $seperator . $this->pageNr;
        return $id;
    }

    public function getTags()
    {
        $tags = $this->gallery->getTags();
        //$tags[] = $this->getId();
        return $tags;
    }

    protected function parseFileSize($str)
    {
        $pts = explode(' ', $str, 2);
        if($pts[1] == 'KB')
            return $pts[0] * 1024;

        if($pts[1] == 'MB')
            return $pts[0] * 1024 * 1024;

        return null;
    }

    protected function load($asPromise = false)
    {
        if($this->imageData)
            return;

        $showKeyKey = 'showkey-' . date('Y-m-d') . '-' . $this->gallery->getGalleryId();
        $me = $this;
        $showKey = getCachedVal($showKeyKey, function() use ($me) {
            $html = websiteRequest([], 's/' . $me->imageToken . '/' . $me->gallery->getGalleryId() . '-' . $me->pageNr);
            preg_match('/var showkey="([a-z0-9]+)"/', $html, $out);
            return $out[1];
        });

        $req = new HttpRequest('API', [
            'method'    => 'showpage',
            'gid'       => $this->gallery->getGalleryId(),
            'page'      => $this->pageNr,
            'imgkey'    => $this->imageToken,
            'showkey'   => $showKey
        ]);

        $promise = new HttpPromise($req);
        $promise->then(function($response) use ($me) {
            $me->imageData = json_decode($response);
            if(preg_match('/<a href="([^"]+)"/', $me->imageData->i7, $fullImgOut)) {
                $me->imageData->bigUrl = html_entity_decode($fullImgOut[1]);
    
                preg_match('/original (\\d+) x (\\d+) (.+) source/', $me->imageData->i7, $fullSizeOut);
                $me->imageData->bigX = $fullSizeOut[1];
                $me->imageData->bigY = $fullSizeOut[2];
                $me->imageData->bigFileSize = $me->parseFileSize($fullSizeOut[3]);
            }
            return false;
        });

        if($asPromise) {
            return $promise;
        }

        return $promise->resolve();
    }

    public function getPostData($asPromise = false)
    {
        if($asPromise) {
            return $this
                ->load(true)
                ->then([$this, 'getPostData']);
        }

        if(getCookieJar())
            $this->load();

        $siteBase = getenv('E_SITE_BASE');
        if(!$siteBase) {
            $siteBase = 'http://e-hentai.org/';
        }

        $ret = [
            'id'            => $this->getId(),
            'tags'          => implode(' ', $this->getTags()),
            'status'        => 'active',
            'source'        => $siteBase . 's/' . $this->imageToken . '/' . $this->gallery->getGalleryId() . '-' . $this->pageNr,
        ] + $this->gallery->getPostData();

        $normalFileUrl = getenv('BASE_URL') . '/image/main?id=' . $this->getId(':');

        $ret['preview_url'] = $normalFileUrl;
        if($this->thumb)
            $ret['preview_url'] = $this->thumb;

        $ret['file_url'] = $normalFileUrl;
        $ret['width'] = $this->imageData->x;
        $ret['height'] = $this->imageData->y;
        $ret['file_size'] = $this->imageData->si;

        if(getCookieJar() && $this->getBigUrl()) {
            $ret['sample_width'] = $ret['width'];
            $ret['sample_height'] = $ret['height'];
            $ret['sample_url'] = $ret['file_url'];
            $ret['sample_size'] = $ret['file_size'];
        
            $fileUrl = getenv('BASE_URL') . '/image/big?id=' . $this->getId(':') . '&login='.$_GET['login'].'&password_hash='.$_GET['password_hash'];
            $ret['file_url'] = $fileUrl;
            $ret['width'] = $this->imageData->bigX;
            $ret['height'] = $this->imageData->bigY;
            $ret['file_size'] = $this->imageData->bigFileSize;
        }

        return $ret;
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