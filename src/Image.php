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
        return $imgOut[1];
    }

    public function getBigUrl()
    {
        $this->load();
        if(preg_match('/<a href="([^"]+)"/', $this->imageData->i7, $fullImgOut))
            return $fullImgOut[1];
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
        //$this->load();
        //preg_match('/<a href="([^"]+)"/', $this->imageData->i7, $fullImgOut);

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
            'file_url'      => getenv('BASE_URL') . '/image/main?id=' . $this->getId(':'),
            //'file_url'      => $fullImgOut[1] ?? $imgOut[1],
            'preview_url'   => $this->thumb == null ? $this->getFileUrl() : $this->thumb,
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