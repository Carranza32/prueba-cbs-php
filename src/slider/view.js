/**
 * Use this file for JavaScript code that you want to run in the front-end
 * on posts/pages that contain this block.
 *
 * When this file is defined as the value of the `viewScript` property
 * in `block.json` it will be enqueued on the front end of the site.
 *
 * Example:
 *
 * ```js
 * {
 *   "viewScript": "file:./view.js"
 * }
 * ```
 *
 * If you're not making any changes to this file because your project doesn't need any
 * JavaScript running in the front-end, then you should delete this file and remove
 * the `viewScript` property from `block.json`.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/#view-script
 */

/* eslint-disable no-console */
import "./view.css";
/* eslint-enable no-console */

document.addEventListener('DOMContentLoaded', () => {
    const next = document.getElementById('next');
    const previous = document.getElementById('previous');
    const slider = document.getElementById('slider');

    const interval = slider.getAttribute('data-interval');
    const intervalInSeconds = parseInt(interval, 10);
    const intervalInMilliseconds = intervalInSeconds * 1000;
    const slides = slider.querySelectorAll('li');
    const paginationWrap = document.querySelector('#pagination-wrap ul');
    let currentIndex = 0; // Start with the first slide
    const totalSlides = slides.length;
    const intervalTime = intervalInMilliseconds;

    function updateSlider() {
        slider.style.transition = 'transform 0.5s ease-in-out';
        slider.style.transform = `translateX(-${currentIndex * 100}%)`;

        // Update active pagination
        const paginationItems = paginationWrap.querySelectorAll('li');
        paginationItems.forEach((item, index) => {
            item.classList.toggle('active', index === currentIndex);
        });
    }

    slider.addEventListener('transitionend', () => {
        if (currentIndex === totalSlides) {
            slider.style.transition = 'none';
            currentIndex = 0; // Loop back to the first slide
            slider.style.transform = `translateX(-${currentIndex * 100}%)`;
        }
    });

    function nextSlide() {
        currentIndex = (currentIndex + 1) % totalSlides;
        updateSlider();
    }

    next.addEventListener('click', () => {
        currentIndex = (currentIndex + 1) % totalSlides;
        updateSlider();
    });

    previous.addEventListener('click', () => {
        currentIndex = (currentIndex - 1 + totalSlides) % totalSlides;
        updateSlider();
    });

    // Create pagination
    for (let i = 0; i < totalSlides; i++) {
        const li = document.createElement('li');
        li.addEventListener('click', () => {
            currentIndex = i;
            updateSlider();
        });
        paginationWrap.appendChild(li);
    }

    setInterval(nextSlide, intervalTime);

    updateSlider(); // Initial update to set the first slide
});