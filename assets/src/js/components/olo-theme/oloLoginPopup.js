//this file handle the login popup that show before the checkout page
document.addEventListener('DOMContentLoaded', function() {
    const loginDialog = document.getElementById('login-dialog');
    const continueAsGuestButton = document.getElementById('continue-as-guest');
    const closeLoginDialogButton = document.getElementById('close-login-dialog');
    const togglePasswordButton = document.getElementById('toggle-password');
    const passwordInput = document.getElementById('password');
    const usernameInput = document.getElementById('username');
    const errorMessage = document.getElementById('username-error-message');
    const spinner = document.getElementById('spinner');

    // Redirect target captured per popup-open; the submit listener below is
    // bound once and reads this closure variable so it can't stack across
    // repeated popup opens (cart fragments can re-trigger the Pay anchor click
    // multiple times per page-load).
    let pendingRedirect = null;

    // Delegate so the listener survives cart-fragment swaps (wc_fragment_refresh
    // replaces .cart-collaterals, which contains the Pay anchor).
    document.addEventListener('click', function(e) {
        if (!loginDialog) return;
        const checkoutButton = e.target.closest('.checkout-button');
        if (!checkoutButton) return;
        checkoutButton.removeAttribute('disabled');
        showLoginPopup(e, checkoutButton);
    });

    function showLoginPopup(e, checkoutButton) {
        pendingRedirect = checkoutButton.href;
        e.preventDefault();
        loginDialog.showModal();
        usernameInput.focus();
    }

    const loginForm = document.getElementById('login-form');
    loginForm?.addEventListener('submit', function(e) {
        e.preventDefault();
        const username = usernameInput.value;
        const password = passwordInput.value;
        const rememberme = document.getElementById('rememberme').checked ? 'true' : 'false';

        const data = {
            action: 'ajax_login',
            username: username,
            password: password,
            remember: rememberme,
            security: olo_vars_object.ajax_nonce
        };
        spinner.style.display = 'inline-block';
        errorMessage.textContent = '';

        fetch(olo_vars_object.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams(data)
        })
        .then(response => response.json())
        .then(res => {
            spinner.style.display = 'none';
            if(res === -1) {
                errorMessage.textContent = 'Security check failed. Please refresh the page and try again.';
                return;
            }
            const { data } = res;
            if (data?.loggedin) {
                spinner.style.display = 'inline-block';
                // Force a fresh page load and regenerates nonces for the logged-in user
                // Adding a cache-busting parameter prevents browser from serving cached checkout page
                const url = new URL(pendingRedirect, window.location.origin);
                url.searchParams.set('_', Date.now());
                window.location.href = url.toString();
            }
            else {
                errorMessage.textContent = data?.message;
            }
        })
        .catch(() => {
            spinner.style.display = 'none';
            errorMessage.textContent = 'Login failed. Please try again.';
        });
    });

    continueAsGuestButton?.addEventListener('click', function() {
        window.location.href = '/checkout';
    });

    closeLoginDialogButton?.addEventListener('click', function() {
        usernameInput.value = '';
        passwordInput.value = '';
        loginDialog.close();

    });

    togglePasswordButton?.addEventListener('click', function() {
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            togglePasswordButton.innerHTML = '<i id="eye-icon" class="fas fa-eye-slash"></i>';
        } else {
            passwordInput.type = 'password';
            togglePasswordButton.innerHTML= '<i id="eye-icon" class="fas fa-eye"></i>';
        }
    });
});