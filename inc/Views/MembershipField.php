<?php
namespace CBSNorthStar\Views;

 
class MembershipField {
    public function __construct() {
        add_filter('woocommerce_checkout_fields', [$this, 'customMembershipField']);
        add_action('woocommerce_checkout_update_order_meta', [$this , 'saveMembershipIdCheckOutField']);
        add_action('woocommerce_admin_order_data_after_billing_address',[$this , 'displayMembershipIdInAdminOrdrMeta' ], 10, 1);
        add_filter('manage_edit-shop_order_columns', [$this , 'addMembershipIdOrderColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this , 'displayMembershipIdOrderColumnData'], 10, 2);
    }
 
    private static $instance = null;
 
    public static function create(): ?MembershipField
    {
      if (self::$instance === null) {
        self::$instance = new MembershipField();
      }
  
      return self::$instance;
    }
    /**
     * 
     *
     * @return string  add new field to checkout page
     */
    public function customMembershipField($fields) {
        $membershipField = array(
            'membership' => array(
                'label'       => __('Membership ID', 'woocommerce'),
                'placeholder' => _x('Add Membership ID', 'placeholder', 'woocommerce'),
                'required'    => true,
                'class'       => array('form-row-wide'),
                'clear'       => true,
                'type'        => 'text',
                'priority'    => 21, // Set priority to position it after first name
            ),
        );
      
        // Merge the membership field after the first name field
        $fields['billing'] = array_slice($fields['billing'], 0, 1, true) + $membershipField + array_slice($fields['billing'], 1, null, true);
      
        return $fields;
    }
    public function saveMembershipIdCheckOutField( $order_id ) {
        if (!empty($_POST['membership'])) {
            update_post_meta($order_id, '_membership_id', sanitize_text_field($_POST['membership']));
        }
    }
    public function displayMembershipIdInAdminOrdrMeta($order){
        $membershipId = get_post_meta($order->get_id(), '_membership_id', true);
        if ($membershipId) {
            echo '<p><strong>' . __('Membership ID:', 'woocommerce') . '</strong> ' . esc_html($membershipId) . '</p>';
        }
    }
    public function addMembershipIdOrderColumn($columns) {
        $columns['membership_id'] = __('Membership ID', 'woocommerce');
        return $columns;
    }
    public function displayMembershipIdOrderColumnData($column, $post_id) {
        if ($column == 'membership_id') {
            $membershipId = get_post_meta($post_id, '_membership_id', true);
            echo !empty($membershipId) ? esc_html($membershipId) : '—';
        }
    }
}