<?php

namespace CBSNorthStar\Views\Shortcodes;

/**
 * Shortcode [timeslot_info]
 *
 * Renders the selected nav time-slot info bar (date, time, edit + ASAP buttons).
 * Single element, styled responsively for all breakpoints.
 *
 * Returns empty string when no slot is selected or time-slots are disabled.
 */
class TimeslotInfoShortcode
{
    public function render(): string
    {
        return self::html();
    }

    /**
     * Render the time-slot info bar.
     */
    public static function html(): string
    {
        // Do not show timeslot info on the thank-you page
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return '';
        }

        if (!function_exists('carbon_get_theme_option')
            || !carbon_get_theme_option('olo_enable_time_slots')) {
            return '';
        }

        $slotTime = isset($_COOKIE['oloNavTimeslotTime']) ? sanitize_text_field($_COOKIE['oloNavTimeslotTime']) : '';
        $slotDate = isset($_COOKIE['oloNavTimeslotDate']) ? sanitize_text_field($_COOKIE['oloNavTimeslotDate']) : '';

        if (!$slotTime || !$slotDate) {
            return '';
        }

        $dt          = \DateTime::createFromFormat('Y-m-d', $slotDate);
        $displayDate = $dt ? $dt->format('D, M j') : $slotDate;

        // Literal wall-clock CBS returned (offset ignored), not the UTC instant.
        $displayTime = \CBSNorthStar\Helpers\TimeSlotValueParser::formatDisplayTime($slotTime);

        ob_start();
        ?>
        <div class="olo-ts-header-info">
            <span class="olo-ts-header-label"><?php esc_html_e('Timeslot', 'olo'); ?></span>
            <span class="olo-ts-header-date"><?php echo esc_html($displayDate); ?></span>
            <span class="olo-ts-header-sep">-</span>
            <span class="olo-ts-header-time"><?php echo esc_html($displayTime); ?></span>
            <button type="button" class="olo-ts-header-edit"
                    aria-label="<?php esc_attr_e('Change time slot', 'olo'); ?>"
                    title="<?php esc_attr_e('Change time slot', 'olo'); ?>">
                <i class="fa fa-pencil-alt" aria-hidden="true"></i>
            </button>
            <button type="button" class="olo-ts-header-asap"
                    aria-label="<?php esc_attr_e('Switch to earliest available time slot', 'olo'); ?>"
                    title="ASAP">
                <?php esc_html_e('ASAP', 'olo'); ?>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }
}
