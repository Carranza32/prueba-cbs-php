<?php
namespace CBSNorthStar\Views\Shortcodes;

class OrderTypeToggle
{
    public function render()
    {
        $ordertype = $_COOKIE['orderType'];
        
        if(!isset($ordertype)){
          setcookie("orderType", "2", time() + (30 * 24 * 60 * 60),  '/', "", is_ssl() , false );
          $ordertype = "2";
        }
        ob_start();
        ?>
    
        <input type="checkbox" id="toggle" class="toggleCheckbox" <?php echo $ordertype == 0 ? "checked" : "" ; ?>/>
            <label for="toggle" class='toggleContainer'>
                <div class="delivery-option">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" size="24" class="e-1upkuwl" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M18.5 3.5v2h3v16h-16v-3h8v-2h-10v-2h10v-2h-12v-2h4v-5h3v-2l2-2h6zm-2 0h-6v2h6z"></path></svg>
                    <span>Delivery </span>
                </div>
                <div class="instore-option">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" size="24" class="e-1upkuwl" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M18 3H6l-4 8h2v10h5v-7h6v7h5V11h2zM8 7h8v3H8z"></path></svg>
                    <span>In-Store</span>
                </div>
                </label>
        <?php
        return ob_get_clean();
    }
}
