<?php
namespace CBSNorthStar\Models;

class ProductParams {
    public $postId;
    public $name;
    public $price;
    public $description;
    public $numberOfPlacements;
    public $type;
    public $comboQualifierIds;
    public $termId;
    public $img;
    public $components;
    public $siteId;
    public $itemId;
    public $mediaItemId;
    public $displayOrder;
    public $servingOptions;
    public $status;
    public $available;
    public $linkToCategory;
    public $activeStart;
    public $activeStop;

    public function __construct($params) {
        $this->postId = $params['postId'] ?? null;
        $this->name = $params['proName'];
        $this->price = $params['proprice'];
        $this->description = $params['proDes'];
        $this->numberOfPlacements = $params['numberOfPlacements'];
        $this->type = $params['type'];
        $this->comboQualifierIds = $params['comboQualifierIds'];
        $this->termId = $params['termId'];
        $this->img = $params['proImg'];
        $this->components = $params['components'];
        $this->siteId = $params['siteid'];
        $this->itemId = $params['itemid'];
        $this->mediaItemId = $params['mediaItemId'];
        $this->displayOrder = $params['displayOrder'];
        $this->servingOptions = $params['servingOptions'];
        $this->status = $params['status'] ?? null;
        $this->available = $params['available'] ?? null;
        $this->linkToCategory = $params['linkToCategory'] ?? null;
        $this->activeStart = $params['activeStart'] ?? null;
        $this->activeStop = $params['activeStop'] ?? null;
    }
}
