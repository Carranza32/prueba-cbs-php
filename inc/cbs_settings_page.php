<form method="POST">
    <label for="cbs_google_api">Google API Key</label>
    <input type="text" placeholder="Enter Google API Key" name="cbs_google_api" id="cbs_google_api" value="<?php echo $value; ?>">
    <?php //wp_nonce_field( 'cbs_option_page_northstar_action' ); ?>
    <input type="submit" value="Save" class="button button-primary button-large">
</form>