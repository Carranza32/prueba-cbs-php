<?php
namespace CBSNorthStar\Views;


class LocationForm{
    public function render(){ ?>
    <?php
        $locationNumber = $_COOKIE['cbs_location_number']  ? $_COOKIE['cbs_location_number']: '';
        $label = 'Location';
        if(!empty(get_option('olo_location_field_label'))){
            $label = get_option('olo_location_field_label');
        }
        ?>
        <div id="location-form">
            <h2><?php echo $label ;?></h2>
            <div id="location-section" class="location-section">
                <div>
                    <input type="text" id="location-input" name="location-input" placeholder="Enter Location Name" value="<?php echo $locationNumber ;?> " required>
                </div>
            </div>
        </div>

    <?php }
}
