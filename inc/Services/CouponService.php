<?php
namespace CBSNorthStar\Services;

class CouponService {
    public static function create(): CouponService {
        return new CouponService();
    }

  public function oloRemoveCouponSession(string $coupon): bool {

    if (!\WC()->session) {
        \WC()->session = new \WC_Session_Handler();
        \WC()->session->init();
    }

    $coupons = $_COOKIE['olo_coupon_codes'] ? explode(',', sanitize_text_field( $_COOKIE['olo_coupon_codes'] ) ) : [];
    if (empty($coupons)) {
        return false;
    }

    $coupon = wc_strtolower(trim($coupon));

    $coupons = array_values(array_filter($coupons, function ($code) use ($coupon) {
        return wc_strtolower(trim((string) $code)) !== $coupon;
    }));

    if (!empty($coupons)) {
        setcookie('olo_coupon_codes', implode(',', $coupons), 0, COOKIEPATH, COOKIE_DOMAIN);
        $_COOKIE['olo_coupon_codes'] = implode(',', $coupons);
    } else {
           
        setcookie('olo_coupon_codes', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);

        unset($_COOKIE['olo_coupon_codes']);
    }
    return true;
    }
}



