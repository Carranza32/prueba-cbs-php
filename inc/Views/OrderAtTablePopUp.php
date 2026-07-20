<?php
namespace CBSNorthStar\Views;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OrderAtTablePopUp {
    private static $instance = null;

    private function __construct() {
        // High priority to ensure it prints at the very end of <body>
        add_action('wp_footer', [$this, 'renderOrderAtTablePopUp'], 999);
    }

    public static function create(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function renderOrderAtTablePopUp(): void {
        ob_start(); ?>
        <dialog id="successDialog" class="order-at-table-dialog" aria-labelledby="successTitle" aria-describedby="successDesc">
            <button class="close-x" id="closeX" aria-label="<?php echo esc_attr__( 'Close', 'northstaronlineordering' ); ?>" type="button">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>

            <div class="modal-head">
                <div class="icon-circle" aria-hidden="true">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                        <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div>
                    <h2 class="modal-title" id="successTitle"><?php echo esc_html__( 'Success!', 'northstaronlineordering' ); ?></h2>
                    <p class="modal-desc" id="successDesc"><?php echo esc_html__( 'Your action was completed correctly.', 'northstaronlineordering' ); ?></p>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn-primary" id="okBtn" type="button"><?php echo esc_html__( 'OK', 'northstaronlineordering' ); ?></button>
            </div>
        </dialog>

        <dialog id="errorDialog" class="order-at-table-dialog" aria-labelledby="errorTitle" aria-describedby="errorDesc" aria-modal="true">
            <button class="close-x" id="closeXError" aria-label="<?php echo esc_attr__( 'Close', 'northstaronlineordering' ); ?>" type="button">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>

            <div class="modal-head">
                <div class="icon-circle" aria-hidden="true"
                    style="background:rgba(239,68,68,.12);color:#ef4444">
                    <!-- red X icon -->
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 6l12 12M6 18L18 6"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <div>
                    <h2 class="modal-title" id="errorTitle"><?php echo esc_html__( 'Something went wrong', 'northstaronlineordering' ); ?></h2>
                    <p class="modal-desc" id="errorDesc"><?php echo esc_html__( 'Please try again in a moment.', 'northstaronlineordering' ); ?></p>
                </div>
            </div>

            <div class="modal-actions">
                 <button class="btn-primary" id="okBtnError" type="button" autofocus><?php echo esc_html__( 'OK', 'northstaronlineordering' ); ?></button>
            </div>
        </dialog>

        <?php  echo ob_get_clean();
        ?>
        <?php
    }
}
