<?php
namespace CBSNorthStar\Models;

use CBSNorthStar\Models\ServingOption;
use CBSNorthStar\Models\Component;

class ProductDetail implements \JsonSerializable  {
    protected $servingOptions;
    protected $description;
    protected $id;
    protected $title;
    protected $image;
    protected $price;
    protected $product;
    protected $components;

    public function __construct($product)
    {
        $this->$product = $product;
        $this->id = $product->get_id();
        $this->title = $product->get_title();
        $this->image = $product->get_image();
        $this->price = $product->get_price();
        $this->description = $product->get_description();
        $this->servingOptions = $this->setServingOptions($product);
        $this->components = $this->setComponents($product);
    }

    public function setServingOptions($product) {



        $response = (new ServingOption())->load($product->get_id());


        return !empty($response['servingOptions']) ? $response['servingOptions'] : [];
    }

    public function setComponents($product) {
        $components = new Component();
        return $components->load($product->get_id());
    }

    public function jsonSerialize():mixed
    {
        $response = array();
        $response['id'] = $this->id;
        $response['title'] = $this->title;
        $response['description'] = $this->description;
        $response['image'] = $this->image;
        $response['price'] = $this->price;
        $response['servingOptions'] = $this->servingOptions;
        $response['components'] = $this->components['components'];

        return $response;
    }
}
