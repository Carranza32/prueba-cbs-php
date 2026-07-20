document.addEventListener("DOMContentLoaded", () => {
  const stickyItems = document.querySelectorAll("#sticky-category-bar .category-item");
  // let — re-queried after scaffold restore so bounding rects stay accurate
  let sections = document.querySelectorAll(".product-category-section");

  const wrapper = document.querySelector('.products-sections-wrapper');
  const categoryBar = document.querySelector('.category-sections-container');

  // Flag to prevent observer updates during programmatic scroll
  let isScrollingProgrammatically = false;

  // View swap state
  let savedScaffoldHTML = null;
  let savedScrollTop = 0;
  let isSwapping = false;

  const CARD_HEIGHT = 360; // skeleton-thumb (250px) + button (50px) + title/price/padding (~60px)
  const GRID_GAP = 16;
  const SECTION_OVERHEAD = 80; // h2 title + section padding

  function calculatePlaceholderHeights() {
    if (!wrapper) return;

    const containerWidth = wrapper.clientWidth;
    const minCardWidth = containerWidth >= 800 ? 250 : 160;
    const columns = Math.max(1, Math.floor((containerWidth + GRID_GAP) / (minCardWidth + GRID_GAP)));

    sections.forEach(section => {
      if (section.classList.contains('loaded')) return;
      const productCount = parseInt(section.dataset.productCount, 10) || 4;
      const rows = Math.ceil(productCount / columns);
      const height = rows * (CARD_HEIGHT + GRID_GAP) + SECTION_OVERHEAD;
      section.style.minHeight = height + 'px';
    });
  }


  calculatePlaceholderHeights();

  sections.forEach(section => {
    const container = section.querySelector(".products-container");
    if (!container.dataset.page) container.dataset.page = "1";
  });

  // Set the first category as active on load before the observer fires.
  if (stickyItems[0]) stickyItems[0].classList.add('active');


  function updateActiveCategory() {
    if (isScrollingProgrammatically) return;

    const scrollContainer = document.querySelector('.products-sections-wrapper');
    if (!scrollContainer) return;
    const containerTop = scrollContainer.getBoundingClientRect().top;

    // Use live query so restored scaffold nodes are picked up
    const liveSections = scrollContainer.querySelectorAll('.product-category-section');
    let current = null;
    liveSections.forEach(section => {
      const sectionTop = section.getBoundingClientRect().top;
      if (sectionTop <= containerTop + 10) {
        current = section;
      }
    });

    if (!current && liveSections[0]) current = liveSections[0];
    if (!current) return;

    const category = current.dataset.categorySlug;
    stickyItems.forEach(item => {
      const isActive = item.dataset.targetCategory === category;
      item.classList.toggle('active', isActive);
      if (isActive) {
        item.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
      }
    });
  }


  const infiniteObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const section = entry.target.closest(".product-category-section");
        loadMoreProducts(section);
      }
    });
  }, {
    root: null,
    rootMargin: "800px 0px",
    threshold: 0
  });

  document.querySelectorAll(".infinite-trigger").forEach(t => infiniteObserver.observe(t));

  // Infinite loop: when the user scrolls to the bottom of the scroll
  // container jump back to the first category instantly.
  // The scroll container is .products-sections-wrapper (overflow-y:scroll),
  // not window — so we listen on that element.
  const scrollContainer = document.querySelector('.products-sections-wrapper');
  if (scrollContainer) {
    let loopTriggered = false;

    scrollContainer.addEventListener('scroll', () => {
      // Update the active category tab based on scroll position
      updateActiveCategory();

      // Skip infinite-loop logic while a view swap is active
      if (isScrollingProgrammatically || isSwapping) return;

      const liveSections = scrollContainer.querySelectorAll('.product-category-section');
      if (liveSections.length <= 1) return;

      // Infinite loop: jump back to first section when bottom is reached
      const atBottom = scrollContainer.scrollTop + scrollContainer.clientHeight >= scrollContainer.scrollHeight - 50;
      if (!atBottom || loopTriggered) return;

      loopTriggered = true;
      isScrollingProgrammatically = true;

      stickyItems.forEach(i => i.classList.remove('active'));
      if (stickyItems[0]) {
        stickyItems[0].classList.add('active');
        stickyItems[0].scrollIntoView({ behavior: 'instant', inline: 'center', block: 'nearest' });
      }

      if (liveSections[0]) liveSections[0].scrollIntoView({ behavior: 'instant' });

      setTimeout(() => {
        isScrollingProgrammatically = false;
        loopTriggered = false;
      }, 1000);
    }, { passive: true });
  }

  async function loadMoreProducts(section) {
    const container = section.querySelector(".products-container");
    const trigger = section.querySelector(".infinite-trigger");

    let page = parseInt(container.dataset.page || "1", 10);

    if (container.dataset.loading === "1") return;
    container.dataset.loading = "1";

    const slug = section.dataset.categorySlug;

    const hasRealProducts = container.querySelector("li:not(.skeleton-card)");
    const rect = section.getBoundingClientRect();
    const nearViewport = rect.top < (window.innerHeight + 250); // tweak 250

    if (!hasRealProducts && nearViewport) {
      addSkeleton(container, parseInt(section.dataset.productCount, 10) || 4);
    }

    try {
      // Scope to the active site (emitted on the scroll container) so the
      // endpoint never returns items from another site sharing this category.
      const siteId = document.querySelector(".infinite-scroll-menu-container")?.dataset?.siteId || "";
      const loadMoreUrl = `/wp-json/northstaronlineordering/v1/loadmore?category=${slug}&page=${page}`
        + (siteId ? `&site_id=${encodeURIComponent(siteId)}` : "");
      const response = await fetch(loadMoreUrl);
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const html = await response.text();

      removeSkeleton(container);

      if (html.trim() === "") {
        infiniteObserver.unobserve(trigger);
        return;
      }

      container.insertAdjacentHTML("beforeend", html);
      container.dataset.page = String(page + 1);
      section.classList.add('loaded');

    } catch (error) {
      console.error("Error loading more products:", error);
      removeSkeleton(container);
    } finally {
      container.dataset.loading = "0";
      section.classList.add('loaded');
      section.style.minHeight = '';
    }
  }


  const firstSection = sections[0];
  if (firstSection) {
    loadMoreProducts(firstSection);

    setTimeout(() => {
      const container = firstSection.querySelector(".products-container");
      const hasRealProducts = container.querySelector("li:not(.skeleton-card)");
      if (!hasRealProducts && container.dataset.loading === "1") {
        addSkeleton(container, parseInt(firstSection.dataset.productCount, 10) || 4);
      }
    }, 250);
  }

  stickyItems.forEach(item => {
    item.addEventListener("click", () => {
      const target = document.querySelector(`#section-${item.dataset.targetCategory}`);
      if (target) {
        isScrollingProgrammatically = true;
        stickyItems.forEach(i => i.classList.remove("active"));
        item.classList.add("active");

        const allSections = Array.from(sections);
        const targetIndex = allSections.indexOf(target);
        const scrollCont = document.querySelector('.products-sections-wrapper');


        for (let i = 0; i < targetIndex; i++) {
          const sec = allSections[i];
          if (!sec.classList.contains('loaded')) {
            const productsContainer = sec.querySelector('.products-container');
            if (productsContainer) {
              addSkeleton(productsContainer, parseInt(sec.dataset.productCount, 10) || 4);
              loadMoreProducts(sec);
            }
          }
        }


        let targetScrollTop = 0;
        for (let i = 0; i < targetIndex; i++) {
          targetScrollTop += allSections[i].offsetHeight;
        }

        if (scrollCont) {
          scrollCont.scrollTop = targetScrollTop;
        } else {
          target.scrollIntoView({ behavior: 'instant' });
        }

        // 3. Load the target section so its skeleton appears immediately.
        if (!target.classList.contains('loaded')) {
          loadMoreProducts(target);
        }
        // Re-enable observer after scroll completes
        setTimeout(() => {
          isScrollingProgrammatically = false;
        }, 1000);
      }
    });
  });


  if (wrapper && typeof ResizeObserver !== 'undefined') {
    let resizeDebounce = null;
    const resizeObserver = new ResizeObserver(() => {
      clearTimeout(resizeDebounce);
      resizeDebounce = setTimeout(() => {
        const hasUnloaded = Array.from(sections).some(s => !s.classList.contains('loaded'));
        if (!hasUnloaded) {
          resizeObserver.disconnect();
          return;
        }
        calculatePlaceholderHeights();
      }, 150);
    });
    resizeObserver.observe(wrapper);
  }

  function addSkeleton(container, count = 4) {
    if (container.querySelector(".skeleton-card")) return;

    const skeletonHtml = Array.from({ length: count }).map(() => `
      <li class="product skeleton-card" aria-hidden="true">
        <div class="skeleton-thumb"></div>
        <div class="skeleton-line title"></div>
        <div class="skeleton-line"></div>
        <div class="skeleton-line price"></div>
        <div class="skeleton-button"></div>
      </li>
    `).join("");

    container.insertAdjacentHTML("beforeend", skeletonHtml);
  }

  function removeSkeleton(container) {
    container.querySelectorAll(".skeleton-card").forEach(el => el.remove());
  }

  // ----------------------------------------------------------------
  // Sticky category bar hide / show
  // ----------------------------------------------------------------

  function hideCategoryBar() {
    if (!categoryBar) return;
    categoryBar.classList.remove('bar-showing');
    categoryBar.classList.add('bar-hiding');

    const collapse = () => {
      categoryBar.classList.remove('bar-hiding');
      // display:none is the only bulletproof way to remove layout contribution
      // regardless of margin-top, padding, or breakpoint quirks
      categoryBar.style.display = 'none';
    };

    categoryBar.addEventListener('animationend', function onHidden(e) {
      if (e.target !== categoryBar) return;
      categoryBar.removeEventListener('animationend', onHidden);
      collapse();
    });
    // Fallback for reduced-motion (animationend won't fire)
    setTimeout(() => {
      if (categoryBar.classList.contains('bar-hiding')) collapse();
    }, 220);
  }

  function showCategoryBar() {
    if (!categoryBar) return;
    // Restore display before animating in so the element has layout
    categoryBar.style.display = '';
    categoryBar.classList.remove('bar-hiding');
    categoryBar.classList.add('bar-showing');

    categoryBar.addEventListener('animationend', function onShown(e) {
      if (e.target !== categoryBar) return;
      categoryBar.removeEventListener('animationend', onShown);
      categoryBar.classList.remove('bar-showing');
    });
    setTimeout(() => {
      if (categoryBar.classList.contains('bar-showing')) {
        categoryBar.classList.remove('bar-showing');
      }
    }, 280);
  }

  // ----------------------------------------------------------------
  // Hidden-category view swap
  // ----------------------------------------------------------------

  function scrollToSection(slug) {
    const target = document.querySelector(`#section-${slug}`);
    if (!target || !wrapper) return;
    const allSecs = Array.from(wrapper.querySelectorAll('.product-category-section'));
    const idx = allSecs.indexOf(target);
    let top = 0;
    for (let i = 0; i < idx; i++) top += allSecs[i].offsetHeight;
    wrapper.scrollTop = top;
  }

  // Runs outClass animation, calls onSwap mid-transition, then runs inClass.
  // Works even when animation is disabled (reduced-motion) via setTimeout fallback.
  function animateWrapper(outClass, inClass, onSwap) {
    if (!wrapper) return;

    let swapped = false;
    const doSwap = (e) => {
      if (e && e.target !== wrapper) return; // ignore bubbled animationend from children
      if (swapped) return;
      swapped = true;
      wrapper.removeEventListener('animationend', doSwap);
      wrapper.classList.remove(outClass);
      onSwap();
      wrapper.classList.add(inClass);
    };

    wrapper.classList.add(outClass);
    wrapper.addEventListener('animationend', doSwap);
    // Fallback: fire after out-duration if animationend never fires
    setTimeout(() => doSwap(null), 250);
  }

  async function openCategoryView(slug, name) {
    if (isSwapping || !wrapper) return;
    isSwapping = true;

    savedScaffoldHTML = wrapper.innerHTML;
    savedScrollTop = wrapper.scrollTop;

    hideCategoryBar();

    animateWrapper('swapping-out', 'swapping-in', () => {
      const title = name || slug;

      const header = document.createElement('div');
      header.className = 'category-view-header';

      const backBtn = document.createElement('button');
      backBtn.className = 'category-view-back';
      backBtn.type = 'button';
      backBtn.textContent = '← Back';

      const heading = document.createElement('h2');
      heading.className = 'category-view-title';
      heading.textContent = title;

      header.appendChild(backBtn);
      header.appendChild(heading);

      const list = document.createElement('ul');
      list.className = 'products products-container category-view-products';
      list.dataset.page = '1';

      wrapper.innerHTML = '';
      wrapper.appendChild(header);
      wrapper.appendChild(list);

      // Always start the view from the top
      wrapper.scrollTop = 0;
      fetchCategoryProducts(slug);
    });

    setTimeout(() => { isSwapping = false; }, 800);
  }

  function closeCategoryView() {
    if (isSwapping || !wrapper || savedScaffoldHTML === null) return;
    isSwapping = true;

    const htmlToRestore = savedScaffoldHTML;
    const scrollToRestore = savedScrollTop;

    animateWrapper('swapping-out', 'swapping-in', () => {
      wrapper.innerHTML = htmlToRestore;
      wrapper.scrollTop = scrollToRestore;
      savedScaffoldHTML = null;
      savedScrollTop = 0;

      // Re-query sections so updateActiveCategory and scroll logic see live DOM nodes
      sections = wrapper.querySelectorAll('.product-category-section');

      // Re-attach IntersectionObserver to restored trigger elements
      wrapper.querySelectorAll('.infinite-trigger').forEach(t => infiniteObserver.observe(t));

      // Show the sticky bar
      showCategoryBar();

      // Sync active tab to restored scroll position
      requestAnimationFrame(() => updateActiveCategory());
    });

    setTimeout(() => { isSwapping = false; }, 800);
  }

  async function fetchCategoryProducts(slug) {
    const container = wrapper && wrapper.querySelector('.category-view-products');
    if (!container) return;

    addSkeleton(container, 4);

    try {
      const response = await fetch(`/wp-json/northstaronlineordering/v1/loadmore?category=${slug}&page=1`);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const html = await response.text();
      removeSkeleton(container);
      if (html.trim()) {
        container.insertAdjacentHTML('beforeend', html);
      }
    } catch (err) {
      console.error('Error loading category products:', err);
      removeSkeleton(container);
    }
  }

  // Delegated click: intercept _link_to_category product links.
  // Only attach when the infinity scroll container is present — prevents
  // this interceptor from running on other pages (e.g. product detail).
  const menuContainer = document.querySelector('.infinite-scroll-menu-container');
  if (menuContainer) menuContainer.addEventListener('click', (e) => {
    const link = e.target.closest('a[href*="cat_slug="]');
    if (!link) return;

    const url = new URL(link.href, window.location.origin);
    const slug = url.searchParams.get('cat_slug');
    const name = url.searchParams.get('cat_name') || slug;

    if (!slug) return;

    e.preventDefault();

    const sectionExists = !!document.querySelector(`#section-${slug}`);
    if (sectionExists) {
      scrollToSection(slug);
    } else {
      openCategoryView(slug, name);
    }
  });

  // Delegated click: Back button inside view swap
  if (wrapper) {
    wrapper.addEventListener('click', (e) => {
      if (e.target.closest('.category-view-back')) {
        closeCategoryView();
      }
    });
  }

  // URL-on-load: trigger view swap or scroll for ?cat_slug
  const urlParams = new URLSearchParams(window.location.search);
  const urlSlug = urlParams.get('cat_slug');
  const urlName = urlParams.get('cat_name') || urlSlug;
  if (urlSlug) {
    setTimeout(() => {
      const sectionExists = !!document.querySelector(`#section-${urlSlug}`);
      if (sectionExists) {
        scrollToSection(urlSlug);
      } else {
        openCategoryView(urlSlug, urlName);
      }
    }, 300);
  }
});
