<?php 
use CBSNorthStar\Views\QuickOrderButtons;
use CBSNorthStar\Repositories\ConfigurationRepository;

$configuration = ConfigurationRepository::create();
$site = $configuration->getDetails();
$siteDetails = $configuration->getSiteDetails($site->id);
$siteId = $siteDetails[0]->siteid;
$pindevicedisable = get_option('disable_kiosk_device_pin');

if($pindevicedisable){
    setcookie('siteid', $siteId, time() + 3600, '/');
}
if (get_option('ordertype_setting') !== "3" && get_option('ordertype_setting') !== null) {
    $ordertype = get_option('ordertype_setting');
    setcookie("orderType", $ordertype, time() + (30 * 24 * 60 * 60),  '/', "", is_ssl() , false );
}
?>

<div class="blog-archive"
>
<!-- add render here -->
<?php
if (!class_exists('WooCommerce')  && is_admin()) {
    return;
}
usort($attributes["dropdowns"], function($a, $b) {
    return $a['position'] <=> $b['position'];
});?>
    <div id="wrapper">
        <div id="slider-wrap">
            <ul id="slider" data-interval="<?php echo $attributes["interval"];?>">
            <?php foreach ($attributes["dropdowns"] as $key => $field) { ?>
                <li data-color="#e74c3c" style="background-image:url(<?php echo $field["imageUrl"]; ?>)">
                    <div>
                    </div>
                    <?php if ($field["menuitem"] ) {
                        $product = wc_get_product($field["menuitem"]);
                        if ( ! $product ) {
                            return false;
                        }
                        echo (new QuickOrderButtons($product))->render($attributes["slug"]);
                        ?>
                    <?php }else{ ?>
                        <a href="/<?php echo $attributes["slug"] ; ?> " > Tap to start order </a>
                   <?php }
                     ?>
                </li>
            <?php } ?>
        </ul>
            <!--controls-->
            <div class="btns" id="next"><i class="fa fa-arrow-right"></i></div>
            <div class="btns" id="previous"><i class="fa fa-arrow-left"></i></div>
            <div id="pagination-wrap">
                <ul></ul>
            </div>
            <!--controls-->
        </div>
    </div>

</div>
