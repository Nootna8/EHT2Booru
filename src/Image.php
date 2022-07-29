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

    public function getId($seperator = '#')
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
        $tags[] = $this->getId();
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

        if(preg_match('/<a href="([^"]+)"/', $this->imageData->i7, $fullImgOut)) {
            $this->imageData->bigUrl = html_entity_decode($fullImgOut[1]);

            preg_match('/original (\\d+) x (\\d+) (.+) source/', $this->imageData->i7, $fullSizeOut);
            $this->imageData->bigX = $fullSizeOut[1];
            $this->imageData->bigY = $fullSizeOut[2];
            $this->imageData->bigFileSize = $this->parseFileSize($fullSizeOut[3]);
        }
    }

    public function getPostData()
    {
        if(getCookieJar())
            $this->load();

        $ret = [
            'id'            => $this->getId(),
            'tags'          => implode(' ', $this->getTags()),
            //'has_comments'  => false,
            'status'        => 'active',
            //'has_children'  => false,
            //'has_notes'     => false,
            //'rating'        => 's',
            //'creator_id'    => 123,

            'source'        => 'https://e-hentai.org/s/' . $this->imageToken . '/' . $this->gallery->getGalleryId() . '-' . $this->pageNr,
            
            //'md5'           => 'adfc1a6da575574f9cccc5c3aa33270b'
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