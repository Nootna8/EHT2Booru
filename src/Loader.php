<?php

use PhpQuery\PhpQuery;

class LoaderImage {
    protected $offset;
    protected $limit;
    protected $params;

    protected $skipped = 0;
    protected $pageNr = 0;
    protected $lastPage;
    
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

class LoaderGallery extends LoaderImage {
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