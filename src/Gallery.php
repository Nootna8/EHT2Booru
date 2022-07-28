<?php

use PhpQuery\PhpQuery;

class Gallery {
    protected $galleryData = null;

    protected $thumbUrl;
    protected $pagesHtml = [];
    protected $pagesImages = [];
    protected $lastImage = null;
    protected $loaded = false;
    protected $offset = 0;

    public function imagePage($num)
    {
        return 1 + floor($num / 40);
    }
    
    protected function __construct($galleryData, $loaded = false)
    {
        if(!$galleryData)
            throw new Exception("No data");
        $this->galleryData = $galleryData;
        $this->loaded = $loaded;
    }

    public function getGalleryId()
    {
        return $this->galleryData->gid;
    }

    public function getOffset()
    {
        return $this->offset;
    }

    public static function fromId($token, $id)
    {
        $galleryData = new stdClass();
        $galleryData->token = $token;
        $galleryData->gid = $id;
        return new self($galleryData);
    }

    public static function fromRow($pq, $row)
    {
        $galleryData = new stdClass();
        $link = $pq->query('td a', $row);
        $link = $link[0]->getAttribute("href");
        preg_match("/\/(\d+)\/([a-z0-9]+)\/$/", $link, $out);
        $galleryData->token = $out[2];
        $galleryData->gid = $out[1];

        $galleryData->title = $pq->xpath(".//div[@class='glink']", $row)[0]->textContent;
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

        return new self($galleryData, true);
    }

    public static function fromPage($id, $token, $page)
    {
        $ret = self::fromId($token, $id);
        $ret->loadFromHtml();
        return $ret;
    }

    public function getId($seperator = '#')
    {
        $id = 'gallery';
        $id .= $seperator.$this->galleryData->token;
        $id .= $seperator.$this->galleryData->gid;
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
                    $thumb = getenv('BASE_URL') . '/image/sample?gallery=' .
                     $this->getGalleryId() . '&token=' . $out[3] . '&page=' . $out[4] . '&width=' . $out[1] . '&x=' . $out[5] .'&height=' . $out[2];
                }

                $images[] = new Image($pageOut[2], $pageOut[1], $this, $thumb);
            }
            $this->pagesImages[$num] = $images;
        }

        return $this->pagesImages[$num];
    }

    public function loadFromHtml()
    {
        if($this->loaded)
            return;

        if(count($this->pagesHtml) == 0)
            $html = $this->getPageHtml(1);
        else
            $html = array_values($this->pagesHtml)[0];

        $pq=new PhpQuery;
        $pq->load_str($html);

        //$galleryData = new stdClass();
        //$galleryData->token = $token;
        //$galleryData->gid = $id;
        $this->galleryData->category = $pq->query('div#gdc div.cs')[0]->textContent;
        $this->galleryData->tags = [];

        $dataTable = $pq->query('div#gdd table tr');
        foreach($dataTable as $elm) {
            $cols = $pq->query('td', $elm);
            $k = $cols[0]->textContent;
            $v = $cols[1]->textContent;
            if($k == 'Length:') {
                $this->galleryData->filecount = intval(explode(' ', $v)[0]);
            } else if($k == 'Posted:') {
                $this->galleryData->posted_date = $v;
            }
        }

        $tags = $pq->xpath('//div[@id=\'taglist\']//a');
        foreach($tags as $t) {
            $this->galleryData->tags[] = str_replace('ta_', '', $t->getAttribute('id'));
        }

        $this->loaded = true;
    }
    
    public function load()
    {
        if($this->loaded)
            return;

        if(count($this->pagesHtml) > 0) {
            $this->loadFromHtml();
        } else {
            $response = apiRequest([
                'method'    => 'gdata',
                'gidlist'   => [[$this->galleryId, $this->galleryToken]],
                'namespace' => 1
            ]);
            $this->galleryData = $response->gmetadata[0];
            $this->loaded = true;
        }
    }

    public function getImages($num)
    {
        $html = $this->getPageHtml($this->imagePage($this->offset));
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

    public function nextImage($reverse=false)
    {
        $offset = $this->offset;
        if($reverse) {
            $this->loadFromHtml();
            $offset = $this->galleryData->filecount - $this->offset - 1;
        }

        $page = $this->imagePage($offset);
        $images = $this->getPageImages($page);

        if(count($images) == 0)
            return null;

        $subIndex = $offset % 40;
        if(count($images) <= $subIndex)
            return null;

        $this->lastImage = $images[$subIndex];
        $this->offset += 1;

        return $this->lastImage;
    }

    public function getTags()
    {
        $this->load();

        $tags = [];
        foreach($this->galleryData->tags as $t) {
            $pts = explode(':', $t);
            if(count($pts) > 1) {
                if($pts[0] == 'character') {
                    $tags[] = $pts[1] . '#' . $pts[0];
                }
                $tags[] = $pts[1];
                continue;
            }

            $tags[] = $t;
        }
        $tags[] = $this->getId();

        $categories = getCategories();
        foreach($categories as $c) {
            if($c['name'] == $this->galleryData->category) {
                $tags[] = $c['tag'] . '#category';
                break;
            }
        }

        return $tags;
    }

    public function getPostData($asImage = false)
    {
        $this->load();

        $data = [
            'tags'          => [],
            'author'        => null,
            'score'         => 0,//round($this->galleryData->rating),
            'created_at'    => $this->galleryData->posted_date,
            'parent_id'     => 123
        ];

        if($asImage) {
            $data['title'] = $this->galleryData->title;
            $data['id'] = $this->getId();
            $data['file_url'] = getenv('BASE_URL') . '/gallery/banner?id=' . $this->getId(':');
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

    public function checkFilter($filters)
    {
        $this->loadFromHtml();
        
    }
}