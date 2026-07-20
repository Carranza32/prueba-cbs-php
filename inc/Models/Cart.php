<?php
namespace CBSNorthStar\Models;

class Cart{
    public function load(){


        $items=WC()->cart->get_cart();
        $subtotal = WC()->cart->subtotal;
        $totals =  WC()->cart->total;
        $taxes = WC()->cart->get_totals();
        $tax = $taxes["fee_total"];

        foreach($items as $product)
        {
         $item = wc_get_product($product["product_id"]);
         $imageId  = get_post_meta( $product["product_id"], '_thumbnail_id', true );
         $postImg = wp_get_attachment_image_src( $imageId, 'thumbnail' );
         $product["name"]=(string)$item->get_title();
         if($imageId){
           $product["image"] = $postImg[0];
         }
         $products[]=$product;
       }
        return [
            "items" => $products,
            "count" => WC()->cart->get_cart_contents_count(),
            "subtotal" => $subtotal,
            "tax"   => (float)$tax ,
            "total" => $totals
           ];
    }
}
