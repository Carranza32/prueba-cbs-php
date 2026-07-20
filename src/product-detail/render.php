<?php

$product = wc_get_product( $attributes['productId'] );



if ( ! $product ) {
    echo '<div id="product-detail" data-components-dropdown="'.($attributes['components'] ? 'true' : 'false').'"></div>';
    return;
}

$servingOptions = get_post_meta($product->get_id(), '_serving_options', true);

$variationIdMenuArray = get_post_meta($product->get_id(), '_menu_variation_id_array', true);

if ( $product->is_type( 'variable' ) ) {
    
    $variation_ids = $product->get_children();
    $variations = array_map( function( $id ) {
        return wc_get_product( $id );
    }, $variation_ids );
}

$productAttributes = $product->get_attributes();

$html = '';

$defaultAttributes = $product->get_default_attributes();
foreach($productAttributes as $attribute) {


    $attribute_data = $attribute->get_data();
    $attribute_name = $attribute->get_taxonomy(); // The taxonomy slug name
    $attribute_terms = $attribute->get_terms(); // The terms
    $attribute_slugs = $attribute->get_slugs(); // The term slugs


    $html ='<fieldset class="serving-option-list"><legend>'.wc_attribute_label($attribute_name).'</legend><div class="serving-option-container">';
    foreach($attribute_slugs as $servingOptionName) {
        $checked = $defaultAttributes[$attribute_name] == $servingOptionName ? 'checked' : '';
        $html .= '<input type="radio" id="'.$servingOptionName.'" name="'.$attribute_name.'" value="' . $servingOptionName . '"'.$checked.'>';
        $html .= '<label class="serving-option" for="'.$servingOptionName.'">' . $servingOptionName . '</label>';
    }
    $html .= '</div></fieldset>';
    
}
?>

<div id="product-detail" data-components-dropdown="<?php echo $attributes['components'] ? 'true' : 'false'; ?>"></div>

<section id="popup" class="modal <?php echo $attributes['visibility'] === 'visible' ? '': 'hidden' ?>" tabindex="-1" role="dialog">
    <div class="flex">
        <button class="btn-close">⨉</button>
    </div>
    <div class="col-2">
        <div><?php echo $product->get_image() ?></div>
        <div class="product-detail">
            <div class="flex">
                <h2><?php echo $product->get_title()?></h2>
                <p><?php echo wc_price($product->get_price())?></p>
            </div>
            <p><?php echo $product->get_description()?></p>
            <div class="components-section">
                <div>
                    <h3>Both Sides</h3>
                    <ul id="both-sides-components" class="taglist">
                        <li>Component 1</li>
                        <li>Component 2</li>
                        <li>Component 3</li>
                        
                    </ul>
                </div>
                <div>
                    <h3>Left Side</h3>
                    <ul id="left-sides-components" class="taglist">
                        <li>Component 1</li>
                        <li>Component 2</li>
                    </ul>
                    </div>
                <div>
                    <h3>Right Side</h3>
                    <ul id="right-sides-components" class="taglist">
                        <li>Component 1</li>
                        <li>Component 2</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <hr>
    <div>
        <?php echo $html ?>
    </div>
    <div class="footer flex">
        <div>
            <div>
                <button>-</button>
                <div class="quantity-number">1</div>
                <button>+</button>
            </div>
        </div>
        <button class="btn-add-to-cart">Add to cart</button>
</section>
<div class="overlay hidden"></div>

