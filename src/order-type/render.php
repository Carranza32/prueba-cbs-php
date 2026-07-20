<div id="kiosk-order-type__container" class="order-type-block alignfull" data-idlepage="<?php echo esc_attr($attributes['idlePage']?? "") ?>" data-redirectpage="<?php echo esc_attr($attributes["redirectPage"] ?? "")?>"
    data-ordertype="<?php echo esc_attr(get_option('ordertype_setting') ?? "") ?>">
    <div class="order-type-block-top kiosk-full-with">
        <button id="kiosk-order-type__close" class="kiosk-order-type__close-button">X</button>
    </div>
    <div class="order-type-block__inner kiosk-full-with">
        <div class="order-type-block__header">
            <h2 class="order-type-block__title">What do you want to create?</h2>
        </div>
        <div class="order-type-types-container kiosk-full-with">
            <?php
                if(isset($attributes['DineIn']) || isset($attributes['ToGo']) || isset($attributes['Delivery'])){
                        $orderTypes = array($attributes['DineIn'], $attributes['ToGo'], $attributes['Delivery']);
                }
            ?>
            <?php if($orderTypes): ?>
                
                <?php foreach($orderTypes as $orderType): ?>
                    <?php if(!$orderType['enabled']) {continue; } ?>
                    <?php
                        $redirect_url = '';
                        if (!empty($attributes["redirectPage"])) {
                            $page = get_page_by_path($attributes["redirectPage"]);
                            if ($page) {
                                $redirect_url = get_permalink($page->ID);
                            }
                        }
                    ?>
                    <a href="<?php echo esc_url($redirect_url) ?>" class="kiosk-order-type__button kiosk-full-with" data-areaid="<?php echo esc_attr($orderType['area']) ?>" style="text-decoration: none;" data-ordertypeid="<?php echo esc_attr($orderType['value']); ?>">
                        <span class="kiosk-order-type__icon" id="<?php echo esc_attr($orderType['value']) ?>-icon"></span>
                        <?php
                            $buttonText = $orderType['customName'] ?: $orderType['name'];
                            $buttonText = trim($buttonText);
                            if (strpos(strtolower($buttonText), 'order') === false) {
                                $buttonText .= ' Order';
                            }
                        ?>
                        <span class="kiosk-order-type__text"><?php echo esc_html($buttonText) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
