<?php

use PhpQuery\PhpQuery;

class LoaderImage {
    protected $offset;
    protected $limit;
    protected $params;
    protected $list;

    protected $reverse = false;
    protected $skipped = 0;
    protected $pageNr = 0;
    protected $lastPage = null;
    
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
            return websiteRequest($this->params + ['p' => $numr-1], $this->list);
        } else {
            return websiteRequest($this->params + ['page' => $numr-1], $this->list);
        }
        
    }

    protected function loadPage()
    {
        if($this->reverse) {
            if($this->lastPage === null) {
                $html = $this->getSearchPage($this->pageNr);
                $pq=new PhpQuery;
                $pq->load_str($html);

                $pageElements = $pq->query('table.ptt tr td');
                $this->lastPage = $pageElements[count($pageElements)-2]->textContent;
            }

            $html = $this->getSearchPage($this->lastPage - $this->pageNr + 1);
            
            $pq=new PhpQuery;
            $pq->load_str($html);
        } else {
            $html = $this->getSearchPage($this->pageNr);
            $pq=new PhpQuery;
            $pq->load_str($html);

            $pageElements = $pq->query('table.ptt tr td');
            $this->lastPage = $pageElements[count($pageElements)-2]->textContent;
        }

        //header('Content-Type: text/html');
        //echo $html;

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

        if($this->lastPage && $this->pageNr > $this->lastPage)
            return 0;

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

            $this->results[] = $image->getPostData();
        }

        return $this->results;
    }
}

class LoaderGallery extends LoaderImage {
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

class LoaderImageGallery extends LoaderImage {
    
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

        while(count($this->results) < $this->limit) {
            $image = $this->nextImage();
            
            if(!$image)
                return $this->results;

            $this->results[] = $image->getPostData();
        }

        return $this->results;
    }
}