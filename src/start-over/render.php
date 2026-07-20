<?php
use CBSNorthStar\Repositories\ConfigurationRepository;
$home = home_url();

$configuration = ConfigurationRepository::create();
$site = $configuration->getDetails();
$siteDetails = $configuration->getSiteDetails($site->id);

$isDeviceConfigured = $_COOKIE['deviceConfigured'] ?? false;

if(!$isDeviceConfigured && !get_option('disable_kiosk_device_pin', false) && isset($attributes['devicePinSlug'])) {
    wp_redirect('/'.$attributes['devicePinSlug']);
    exit;
}

?>
<div class="start-over-block"  data-time="<?php echo $attributes["time"] ;?>">
    <a href="<?php echo $home; ?>" class="start-over-button" data-idlepageslug="<?php echo esc_attr($attributes['idlePageSlug'])?>" >
        <div id="start-over-icon" class="start-over__icon"></div>
        <span class="start-over__text">Start Over</span>
    </a>
</div>
