/*  Enable keyboard navigation for tip buttons */
document.addEventListener('DOMContentLoaded', function () {

    function enhanceTipButtons(root = document) {

        const tips = root.querySelectorAll(
            '.wpcot-tip-value, .wpcot-tip-value-custom'
        );

        if (tips.length === 0) return;

        tips.forEach((tip, index) => {

            if (tip.dataset.keyboardReady) return;

            tip.dataset.keyboardReady = "true";

            tip.setAttribute('tabindex', '0');
            tip.setAttribute('role', 'button');

            const label = tip.querySelector('span')?.textContent || 'tip option';
            tip.setAttribute('aria-label', label);

            tip.addEventListener('keydown', function (e) {
                switch (e.key) {
                    case 'Enter':
                    case ' ':
                        { e.preventDefault();
                            tip.click();
                            break;
                       }

                    case 'ArrowRight':
                    case 'ArrowDown':
                        {
                            e.preventDefault();
                            const next = tips[index + 1];
                            if (next) next.focus();
                            break;
                        }

                    case 'ArrowLeft':
                    case 'ArrowUp':
                        {
                            e.preventDefault();
                            const prev = tips[index - 1];
                            if (prev) prev.focus();
                            break;
                        }

                    case 'Home':
                        {
                            e.preventDefault();
                            tips[0].focus();
                            break;
                        }

                    case 'End':
                        {
                            e.preventDefault();
                            tips[tips.length - 1].focus();
                            break;
                        }
                }
            });
        });
    }

    enhanceTipButtons();
});