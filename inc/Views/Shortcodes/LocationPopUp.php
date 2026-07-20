<?php
namespace CBSNorthStar\Views\Shortcodes;

use CBSNorthStar\Set_sessions_for_site;

class LocationPopUp
{

    public function render($atts) {
        $siteId = $atts['siteid'] ? $atts['siteid'] : $_GET['site_id'] ;
        $currentSiteId = $_COOKIE['siteid'] ? $_COOKIE['siteid'] : $siteId;
        
        $wcSiteId = null;
        if (function_exists('WC') && WC()->session) {
            $wcSession = WC()->session;
            $wcSiteId = $wcSession->get('siteid');

            if ($wcSiteId) {
                $currentSiteId = $wcSiteId;
            } else {
                $wcSession->set('siteid', $siteId);
            }
        }
            if ($siteId && (!isset($_COOKIE['siteid']) || $_COOKIE['siteid'] != $siteId)) {
                if (!headers_sent()) {
                    setcookie("siteid", $siteId, time() + 86400, '/', "", is_ssl(), true);
                    // session cookie (expires=0), dies on browser close.
                    // httponly=false so JS in reloadPage() can also rewrite it consistently.
                    setcookie("locationSelected", "1", 0, '/', "", is_ssl(), false);
                } else {
                    echo "<script>document.cookie = 'siteid=" . htmlspecialchars($siteId, ENT_QUOTES, 'UTF-8') . "; path=/; expires=' + new Date(new Date().getTime() + 86400 * 1000).toUTCString() + ';';</script>";
                    echo "<script>document.cookie = 'locationSelected=1; path=/; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');</script>";
                }
                echo "<script>sessionStorage.removeItem('keepHide');</script>";
            }
        ob_start();

        ?>
            <div id="popuplocation">
                <p>Your selected location:  <strong><?php echo htmlspecialchars((new Set_sessions_for_site())->getSiteName($currentSiteId), ENT_QUOTES, 'UTF-8'); ?></strong></p>
                <button id="continueBtn" onclick="reloadPage()">Continue</button>
                <button id="redirectBtn" onclick="redirectPage()">Change Location</button>
            </div>
            <script>
                function reloadPage() {
                    const popup = document.getElementById('popuplocation');
                    if(popup) {
                        popup.style.display = 'none';
                        sessionStorage.setItem('keepHide', 'true');
                        // user confirmed selection — set session cookie.
                        document.cookie = 'locationSelected=1; path=/; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
                    }
                }
                function redirectPage() {
                    window.location.href = '<?php echo home_url('/locations'); ?>';
                    sessionStorage.removeItem('keepHide');
                }

                (function() {
                    function getCookie(name) {
                        let value = `; ${document.cookie}`;
                        let parts = value.split(`; ${name}=`);
                        if (parts.length === 2) return parts.pop().split(';').shift();
                    }

                    function setCookie(name, value, days) {
                        let expires = "";
                        if (days) {
                            let date = new Date();
                            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                            expires = "; expires=" + date.toUTCString();
                        }
                        document.cookie = name + "=" + (value || "") + expires + "; path=/";
                    }

                    if (!getCookie('siteid')) {
                        setCookie('siteid', '<?php echo htmlspecialchars($siteId, ENT_QUOTES, 'UTF-8'); ?>', 1);
                        // shortcode has explicit site context, mark selection for this session.
                        document.cookie = 'locationSelected=1; path=/; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
                    }

                    const keepHide = sessionStorage.getItem('keepHide');
                    const popup = document.getElementById('popuplocation');
                    if (popup && keepHide) {
                        popup.style.display = 'none';
                    }
                })();
            </script>
            <?php
            return ob_get_clean();
        }
    
}
