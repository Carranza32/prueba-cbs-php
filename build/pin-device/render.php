<?php
/**
 * Render the pin device component.
 *
 */
    $isEditorView = isset($_GET['action']);

    $redirectUrl = home_url($attributes["redirectPage"] ?? "/");

    if (!current_user_can('manage_options') && get_option('disable_kiosk_device_pin', false)) {
        wp_redirect($redirectUrl);
    }

    if(!$isEditorView && $_COOKIE['deviceConfigured'] ?? false) {
        wp_redirect($redirectUrl);
    }
?>

<div class="kiosk-device-pin-container">
    <div class="kiosk-device-pin">
        <div class="kiosk-device-pin__header">
            <div class="kiosk-device-pin__image_container">
                <?php
                 echo '<img class="kiosk-device-pin__image" src="' . $attributes["logo"] . '" alt="North Star Online Ordering" class="kiosk-device-pin__logo">';
                ?>
            </div>
        </div>
        <div class="kiosk-device-pin__body">
            <div id="device-pin-form-component" data-redirecturl="<?php echo $redirectUrl; ?>" data-disableddevicepin="<?php echo get_option('disable_kiosk_device_pin', false) ?>" >
                <form id="kiosk-device-pin__form" class="kiosk-device-pin__form">
                    <label for="device-pin" class="kiosk-device-pin__label">Enter Device Pin</label>
                    <div class="custom-input">
                        <input type="text" id="pin" name="pin" class="kiosk-device-pin__input loading" required>
                        <div id="spinner-container"></div>
                    </div>
                    <button type="submit" class="button wp-element-button kiosk-device-pin__submit">Launch</button>
                </form>
            </div>
        </div>
    </div>
</div>
