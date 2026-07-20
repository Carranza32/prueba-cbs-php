<?php
namespace CBSNorthStar\Init;

use CBSNorthStar\Init\Loaders\ComponentLoader;
use CBSNorthStar\Init\Loaders\SaveProductLoader;
use CBSNorthStar\Init\Loaders\AddToCartLoader;
use CBSNorthStar\Init\Loaders\AddGiftCardLoader;
use CBSNorthStar\Init\Loaders\LoginToCheckoutLoader;
use CBSNorthStar\Init\Loaders\LoyaltyLoader;
use CBSNorthStar\Init\Loaders\ServingOptionLoader;
use CBSNorthStar\Init\Loaders\DeleteProductsLoader;
use CBSNorthStar\Init\Loaders\CartEditLoader;
use CBSNorthStar\Init\Shortcodes\Register;
use CBSNorthStar\Init\Loaders\OrderAtTableLoader;
use CBSNorthStar\Init\Loaders\CarbonFieldsLoader;
use CBSNorthStar\Init\Loaders\CouponsLoader;
use CBSNorthStar\Init\Loaders\TimeSlotsLoader;


class ScriptLoader
{
    protected function register(): array
    {
        return [
            DeleteProductsLoader::class,
            SaveProductLoader::class,
            ComponentLoader::class,
            AddToCartLoader::class,
            AddGiftCardLoader::class,
            LoginToCheckoutLoader::class,
            ServingOptionLoader::class,
            OrderAtTableLoader::class,
            LoyaltyLoader::class,
            CartEditLoader::class,
            Register::class,
            CarbonFieldsLoader::class,
            CouponsLoader::class,
            TimeSlotsLoader::class,
        ];
    }

    public function loadScripts()
    {
        foreach ($this->register() as $loader) {
            $loaderInstance = $loader::create(); // Create instance
            if ($loaderInstance) {
                $loaderInstance->registerScripts(); // Register scripts if the instance is valid
            }
        }
    }
}
