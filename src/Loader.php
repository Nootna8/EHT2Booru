<?php

use PhpQuery\PhpQuery;

const GALLERIES_PER_SEARCH = 16;

class LoaderAllImages {
    protected $offset;
    protected $limit;
    protected $params;
    protected $list;

    protected $reverse = false;
    protected $skipped = 0;
    protected $pageNr = 0;
    
    protected $results = [];
    protected $galleries = [];
    protected $gallery;
    protected $filters = null;

    public function __construct($offset, $limit, $params, $list)
    {
        $this->offset = $offset;
        $this->limit = $limit;
        $this->params = $params;
        $this->list = $list;
    }

    public function setReverse($reverse)
    {
        $this->reverse = $reverse;
    }

    public function setFilters($filters)
    {
        $this->filters = $filters;
    }

    protected function getSearchPage($num)
    {
        if($this->list) {
            return websiteRequest($this->params + ['p' => $num-1], $this->list);
        } else {
            $nextPtr = $this->reverse ? 1 : null;

            $direction = $this->reverse ? 'prev' : 'next';

            $c = 0;
            while($c < $num) {
                if($c > 0 && $nextPtr == null) {
                    return null;
                }

                $html = websiteRequest($this->params + [$direction => $nextPtr]);

                $pq=new PhpQuery;
                $pq->load_str($html);

                $nextElm = $pq->query('a#u' . $direction);
                
                $nextPtr = null;
                if(count($nextElm) > 0) {
                    $nextPtr = (string)$nextElm[0]->getAttribute("href");
                    $nextPtr = explode($direction . '=', $nextPtr)[1];
                }

                $c ++;
            }
            
            return $html;
        }
    }

    protected function loadPage()
    {
        if($this->reverse) {
            $html = $this->getSearchPage($this->pageNr);
            
            $pq=new PhpQuery;
            $pq->load_str($html);
        } else {
            $html = $this->getSearchPage($this->pageNr);
            
            if($html == null) {
                $this->galleries = [];
                return 0;
            }

            $pq=new PhpQuery;
            $pq->load_str($html);
        }

        $this->galleries = [];
        foreach($pq->query('table.itg.gltc tr') as $row) {
            // Skip header
            $link = $pq->query('td a', $row);
            if(count($link) == 0) {
                continue;
            }
            $this->galleries[] = Gallery::fromRow($pq, $row);
        }

        return count($this->galleries);
    }
    
    protected function nextPage()
    {
        $this->pageNr ++;
        return $this->loadPage();
    }

    protected function nextGallery()
    {
        if(count($this->galleries) == 0) {
            if($this->nextPage() == 0) {
                return null;
            }
        }
        
        if($this->reverse)
            $this->gallery = array_pop($this->galleries);
        else
            $this->gallery = array_shift($this->galleries);
        

        //$this->gallery = array_shift($this->galleries);

        if($this->filters && !$this->gallery->checkFilter($this->filters))
            return $this->nextGallery();

        return $this->gallery->getNumImages();
    }

    protected function nextImage()
    {
        if(!$this->gallery)
            if(!$this->nextGallery())
                return null;

        $image = $this->gallery->nextImage($this->reverse);
        if(!$image) {
            if(!$this->nextGallery())
                return null;

            $image = $this->gallery->nextImage($this->reverse);
        }

        if($image == null) {
            //error_log("no next image");
        }

        return $image;
    }

    public function getResults()
    {
        while($this->skipped < $this->offset) {
            $numImages = $this->nextGallery();
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

            if(getCookieJar()) {
                $this->results[] = $image->getPostDataPromise();
            } else {
                $this->results[] = $image->getPostData();
            }
        }

        if(getCookieJar()) {
            return HttpPromise::all($this->results);
        } else {
            return $this->results;
        }
    }
}

class LoaderGalleries extends LoaderAllImages {
    protected $pageNr;
    protected $params;
    
    public function getResults()
    {
        $this->results = [];

        while($this->skipped < $this->offset) {
            if(!$this->nextGallery())
                return $this->results;

            $this->skipped ++;
        }

        while(count($this->results) < $this->limit) {
            if(!$this->nextGallery())
                return $this->results;

            $this->results[] = $this->gallery->getPostData(true);
        }

        return $this->results;
    }
}

class LoaderGalleryImages extends LoaderAllImages {
    
    public function __construct($offset, $limit, $gallery) {
        $this->offset = $offset;
        $this->limit = $limit;
        $this->gallery = $gallery;
    }

    protected function nextImage()
    {
        return $this->gallery->nextImage($this->reverse);
    }

    public function getResults()
    {
        $this->gallery->setOffset($this->offset);

        $usePromise = getCookieJar() == true;

        while(count($this->results) < $this->limit) {
            $image = $this->nextImage();
            
            if(!$image) {
                if($usePromise) {
                    return HttpPromise::all($this->results);
                } else {
                    return $this->results;
                }
            }

            $this->results[] = $image->getPostData($usePromise);
        }

        if($usePromise) {
            return HttpPromise::all($this->results);
        } else {
            return $this->results;
        }
    }
}