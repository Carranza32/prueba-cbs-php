<?php
namespace CBSNorthStar\Views;

class LoginForm{
    public function render(){?>
        
            <dialog id="login-dialog">
                <div id="login-popup">
                    <button id="close-login-dialog" class="close-login-dialog" aria-label="Close login dialog">&times</button>
                    <h1 class="login-header">Log in</h1>
                    <form id="login-form">
                        <label class="login-label" for="username">Username or Email Address</label>
                        <input type="text" id="username" name="username" required autocomplete="username">
                        <div class="error-message" id="username-error-message"></div>
                        <label class="login-label"for="password">Password</label>
                        <div class="password-container">
                            <input type="password" id="password" name="password" required>
                            <button type="button" id="toggle-password" class="toggle-password" aria-label="Toggle password visibility">
                            <i id="eye-icon" class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="remember-forgot-container">
                            <label class="login-label" for="rememberme">
                                <input type="checkbox" id="rememberme" name="rememberme"> Remember me
                            </label>
                            <a href="<?php echo wp_lostpassword_url(); ?>" class="forgot-password-link">Forgot Password?</a>
                        </div>
                        <button type="submit" class="login-button">Login
                        <span id="spinner" class="spinner" style="display: none;"></span>
                        </button>
                        <div class="signup-message">
                            <span>Don't have an account?</span>
                            <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="signup-link">Sign up</a>
                        </div>
                        <button type="button" id="continue-as-guest" class="guest-button">Continue as guest</button>
                    </form>
                </div>
            </dialog>
    <?php }
}
