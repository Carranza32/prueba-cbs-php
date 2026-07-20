<?php
$showNameInput = $attributes['showGuestName'] == 'true' ? true : false;
$phonePromptField = get_option('disable_phone_field', false);

?>

<div class="guest-identifier-block" data-showguestinput="<?php echo esc_attr($attributes['showGuestName']) ?>" data-showGuestValue="<?php echo $showNameInput ?>">
<dialog id="guest-identifier-dialog" class="guest-identifier-dialog">
    <div class="guest-identifier-dialog-content">
        <button type="button" class="close close-button-guest-identifier">
            <span id="close-guest-identifier-dialog">&times;</span>
        </button>
        <form id="guest-identifier-form" class="guest-identifier-form">
            <?php if($showNameInput): ?>
                <div class="guest-identifier-field">
                    <label for="guest-name">Name</label>
                    <input type="text" id="guest-name" class="guest-identifier-name" name="guest-name" autofocus>
                </div>
            <?php endif; ?>
<?php if(!$phonePromptField): ?>
            <div class="guest-identifier-field">
                <label for="guest-phone">Phone Number</label>
                <input type="text" id="guest-phone"  class="guest-identifier-phone" name="guest-phone" maxlength="14" required <?php echo !$showNameInput ? 'autofocus' : '' ?>>
            </div>
<?php endif; ?>
            </form>

        <div class="numeric-pad <?php echo !$phonePromptField ? 'show' : 'hide'; ?>">
            <button type="button" class="numeric-pad-button" value="1">1</button>
            <button type="button" class="numeric-pad-button" value="2">2</button>
            <button type="button" class="numeric-pad-button" value="3">3</button>
            <button type="button" class="numeric-pad-button" value="4">4</button>
            <button type="button" class="numeric-pad-button" value="5">5</button>
            <button type="button" class="numeric-pad-button" value="6">6</button>
            <button type="button" class="numeric-pad-button" value="7">7</button>
            <button type="button" class="numeric-pad-button" value="8">8</button>
            <button type="button" class="numeric-pad-button" value="9">9</button>
            <button type="button" class="numeric-pad-button zero-button" value="0">0</button>
            <button type="button" id="backspace-button" class="numeric-pad-button backspace-button" value="backspace"></button>
        </div>

        <p class="guest-identifier-notice">We will use this to notify you when your order is ready</p>
        <a id="submit-guest-identifier" class="submit-guest-identifier" href="/checkout">Continue <span class="dashicons dashicons-update spin-icon"></span></a>
    </div>
</dialog>

</div>
