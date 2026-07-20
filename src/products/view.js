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

import React from 'react';
import {createRoot} from 'react-dom/client';
import ProductSearch from './components/ProductSearch';
import { Spinner } from "@wordpress/components";
import Breadcrumbs from './components/Breadcrumbs';

import "./view.css";

console.log( 'Hello World! (from create-block-cbs-blocks block)' );

createRoot(document.getElementById("loading-more-container")).render(<Spinner />);

// Safe sessionStorage wrappers — throws in Safari private mode and when storage quota is exceeded.
function safeSessionGet( key ) {
	try { return sessionStorage.getItem( key ); } catch ( e ) { return null; }
}
function safeSessionSet( key, value ) {
	try { sessionStorage.setItem( key, value ); } catch ( e ) { /* quota or private mode */ }
}

function safeSessionGetJson( key, fallback = null ) {
   try {
       const raw = sessionStorage.getItem( key );
       return raw ? JSON.parse( raw ) : fallback;
   } catch ( e ) {
       return fallback;
   }
}

//attempt to load products from session storage

// Detect a daypart (menu) OR location (site) change and invalidate the product
// cache if either changed. The server scopes reads by site + menu, so the client
// cache must invalidate on both — otherwise switching to a site that shares the
// same active menu would replay the previous site's items (sibling of OE-26387).
const _wrapper = document.getElementById('products-block-wrapper');
const _activeMenu = _wrapper?.dataset?.activeMenu ?? '';
const _activeSite = _wrapper?.dataset?.siteId ?? '';
const _activeCatalogVersion = _wrapper?.dataset?.catalogVersion ?? '0';
const _storedMenu = safeSessionGet('currentMenu');
const _storedSite = safeSessionGet('currentSite');
const _storedCatalogVersion = safeSessionGet('currentCatalogVersion');
const normalize = v => (v || '').trim();
const _activeMenuNorm = normalize(_activeMenu);
const _storedMenuNorm = normalize(_storedMenu);
const _activeSiteNorm = normalize(_activeSite);
const _storedSiteNorm = normalize(_storedSite);
const _activeCatalogVersionNorm = normalize(_activeCatalogVersion);
const _storedCatalogVersionNorm = normalize(_storedCatalogVersion);

if (_activeMenuNorm !== _storedMenuNorm || _activeSiteNorm !== _storedSiteNorm || _activeCatalogVersionNorm !== _storedCatalogVersionNorm) {
	try {
		sessionStorage.removeItem('productCache');
		sessionStorage.removeItem('currentCategorySlug');
		sessionStorage.removeItem('reloading');
	} catch (e) {
		/* private mode */
	}
	if (_activeMenuNorm) {
		safeSessionSet('currentMenu', _activeMenuNorm);
	} else {
		try { sessionStorage.removeItem('currentMenu'); } catch (e) { /* private mode */ }
	}
	if (_activeSiteNorm) {
		safeSessionSet('currentSite', _activeSiteNorm);
	} else {
		try { sessionStorage.removeItem('currentSite'); } catch (e) { /* private mode */ }
	}
	if (_activeCatalogVersionNorm) {
		safeSessionSet('currentCatalogVersion', _activeCatalogVersionNorm);
	} else {
		try { sessionStorage.removeItem('currentCatalogVersion'); } catch (e) { /* private mode */ }
	}
}

let reloading = safeSessionGet( 'reloading' ) === 'true';
let productCache = new Map( safeSessionGetJson( 'productCache', [] ) );


let _categorySectionsCache = null;

function setActiveTab(categorySlug) {
	const categoriesList = document.querySelectorAll('.category-list-item');
	categoriesList.forEach((category, index) => {
		if(category.dataset.categoryslug === categorySlug) {
			category.classList.add('active');
			currentCategorySlug = categorySlug;
			safeSessionSet( 'currentCategorySlug', currentCategorySlug );
		}
		else {
			category.classList.remove('active');
		}
	});
}

const scrollRoot = document.querySelector('#products-block-wrapper');
let savedScrollTop = 0;
const productsPerRequest = document.getElementById('products-block-wrapper').dataset.numberofproducts;
// Active site resolved server-side (cookie or block attribute). Sent with every
// products request so the REST endpoint can scope items even without the cookie.
const siteId = document.getElementById('products-block-wrapper')?.dataset?.siteId ?? '';
let currentPage = 1;
let totalPages = 1;
let currentCategorySlug = '';
let movingByClick = false;

/**
 * Returns the slug of the category section whose top edge is closest to —
 * but has not yet scrolled past — the top of the visible scroll area.
 * Sections are expected to be <li id="[slug]"> direct children of #product-list.
 */
function getActiveCategorySlugFromScroll() {
	const productList = document.getElementById( 'product-list' );
	if ( ! productList || productList.getClientRects().length === 0 ) {
		return null;
	}

	if ( ! _categorySectionsCache ) {
		_categorySectionsCache = Array.from( document.querySelectorAll( '#product-list > li[id]' ) );
	}
	const sections = _categorySectionsCache;
	if ( ! sections.length ) return null;
	const maxScrollTop = scrollRoot.scrollHeight - scrollRoot.clientHeight;
	const atBottom = maxScrollTop > 0 && scrollRoot.scrollTop >= maxScrollTop - 1;
	if ( atBottom ) {
		const categoryItems = document.querySelectorAll( '.category-list-item' );
		if ( categoryItems.length ) {
			const lastSlug = categoryItems[ categoryItems.length - 1 ].dataset.categoryslug;
			if ( document.querySelector( `#product-list > li#${ CSS.escape( lastSlug ) }` ) ) {
				return lastSlug;
			}
		}
		return sections[ sections.length - 1 ].id;
	}


	const trackingLine = scrollRoot.getBoundingClientRect().top + 20;
	let activeSlug = null;

	for ( const section of sections ) {
		if ( section.getBoundingClientRect().top <= trackingLine ) {
			activeSlug = section.id;
		} else {
			// Sections are in DOM order (top to bottom), so we can stop early.
			break;
		}
	}
	return activeSlug;
}

// Throttle scroll-based detection with requestAnimationFrame.
let scrollRafId = null;
scrollRoot.addEventListener( 'scroll', () => {
	if ( movingByClick || scrollRafId ) return;
	scrollRafId = requestAnimationFrame( () => {
		scrollRafId = null;
		if ( movingByClick ) return;
		if ( reloading ) return;
		const slug = getActiveCategorySlugFromScroll();
		if ( slug && slug !== currentCategorySlug ) {
			setActiveTab( slug );
		}
	} );
} );

// Reset movingByClick once the container comes to rest after a click-initiated scroll.
// Set up once here instead of adding a new listener on every click.
( function setupScrollCompleteHandler() {
	let isScrolling;
	scrollRoot.addEventListener( 'scroll', () => {
		if ( ! movingByClick ) return;
		window.clearTimeout( isScrolling );
		isScrolling = window.setTimeout( () => {
			movingByClick = false;
			reloading = false;
		}, 500 );
	}, false );
}() );


const resumeScrollTracking = () => {
   movingByClick = false;
   reloading = false;
};
scrollRoot.addEventListener( 'touchstart', resumeScrollTracking, { passive: true } );
scrollRoot.addEventListener( 'wheel', resumeScrollTracking, { passive: true } );

const getProductsByCategorySlug = async(categorySlug, parentCategorySlug = null) => {
	currentPage = currentPage > totalPages ? 1 : currentPage;
	const baseUrl = '/wp-json/northstaronlineordering/v1/productsLoop';
	const queryParams = new URLSearchParams({
		cat_slug: encodeURIComponent(categorySlug),
		page: currentPage,
		limit: productsPerRequest,
		parent_cat_slug: encodeURIComponent(parentCategorySlug)
	});
	if (siteId) { queryParams.set('site_id', siteId); }
	const url = `${baseUrl}?${queryParams.toString()}`;
	const cachedProducts = getProductsFromCache(categorySlug);
	if(cachedProducts !== null) {
		currentPage = 1;
		totalPages = 1;
		const newProducts = cachedProducts;
		return newProducts;
	}
	try {
		const response = await fetch(url);
		console.log("products response:", response);

		if(response.ok) {
			const data = await response.json();
			currentPage = data.totalPages >1 ? currentPage+1: 1;
			totalPages = data.totalPages;
			storeProductsInCache(categorySlug, data);

			return data;
		}
		throw new Error('Error fetching products');
	} catch (error) {
		console.error(error);
	}
}

jQuery(document).ready(() => {
	const cardDeck = document.querySelector('.products');
	let isLoading = false;
	let isCategoryClickHandled = false;

	const handleCategoryClick = (event) => {
		event.preventDefault();
		if(isLoading) {return;}
		if (isCategoryClickHandled) return;
		const categorySlug = event.target.dataset.categoryslug;
		if(event.target.classList.contains('active')) {return};
		isCategoryClickHandled = true;
		const movedToCategory = moveToSelectedCategory(categorySlug);
		if(movedToCategory) {
			isCategoryClickHandled = false;
			return;
		}
		isLoading = true;
		currentPage = 1;
		const overlay = document.querySelector('.overlay');
			overlay.classList.remove('hidden');
		getProductsByCategorySlug(categorySlug).then((newProducts) => {
			appendNewProducts(newProducts.html, cardDeck);
			isLoading = false;
			setTimeout(() => {
				moveToSelectedCategory(categorySlug);
				isCategoryClickHandled = false;
				overlay.classList.add('hidden');
			}, 100);
		});
	}

	const categories = document.querySelector('.categories');

	categories?.addEventListener('click', handleCategoryClick);

	const prevActiveCategory = safeSessionGet( 'currentCategorySlug' );
	let initialTimeOut = 1500;
	function moveToCategoryWithDiamicTimeout(category, timeout) {
		setTimeout(() => {
			const moved = moveToSelectedCategory(category);
			if (!moved && timeout < 5000) {
				timeout += 500;
				console.log('retrying to move to category', category);
				moveToCategoryWithDiamicTimeout(category, timeout);
			}
		}, timeout);
	}
	if(prevActiveCategory) {
		moveToCategoryWithDiamicTimeout(prevActiveCategory, initialTimeOut);
	} else {
		reloading = false;
	}
	window.addEventListener('beforeunload', () => {
		safeSessionSet( 'reloading', 'true' );
		safeSessionSet( 'productCache', JSON.stringify( Array.from( productCache.entries() ) ) );
	});

	function handleQuickAddClick(event) {
		const target = event.target;
		if(target.classList.contains('quick-add', 'active')&&target.classList.contains('active')) {
			const overlay = document.querySelector('.overlay');
			overlay.classList.remove('hidden');
		}

		if(target.classList.contains('linkto')) {

			const overlay = document.querySelector('.overlay');
			overlay.classList.remove('hidden');
			handleSubcategoryClick(event, cardDeck, isLoading);
		}
	}
	cardDeck.addEventListener('click', handleQuickAddClick);

	function setUpSelectedCategory() {
		const storedCategory = safeSessionGet( 'currentCategorySlug' );
		const reloadingFlag = safeSessionGet( 'reloading' );
		if(currentCategorySlug || storedCategory || reloadingFlag){ return};
		const categoriesList = document.querySelectorAll('.category-list-item');
		const firstCategory = categoriesList[0];
		if(firstCategory) {
			setActiveTab(firstCategory.dataset.categoryslug);
		}
	}
	setUpSelectedCategory();
});


const moveToSelectedCategory = (categorySlug) => {
	const productList = document.querySelector('#product-list');
	const searchResults = document.querySelector('#search-results');
	productList.style.display = 'flex';
	searchResults.style.display = 'none';
	const existCategory = document.querySelector( `#${ CSS.escape( categorySlug ) }` );
	let moved = false;
	if(existCategory) {
		movingByClick = true;
		const container = document.querySelector('#products-block-wrapper');
		const offset = 10;
		const elementTop = existCategory.getBoundingClientRect().top;
		const containerTop = container.getBoundingClientRect().top;
		const scrollTo = elementTop - containerTop + container.scrollTop - offset;

		setActiveTab(categorySlug);

		container.scrollTo({
		top: scrollTo,
		behavior: 'smooth'
	});
		moved = true;
	}
	return moved;
}

// Namespace cached entries by the active site AND menu so a category slug shared
// across sites/daypart menus can never replay another site's or menu's items if
// the cache outlives a location/menu transition (defense-in-depth for OE-26399 /
// OE-26387; the server filter is authoritative).
function productCacheKey(categorySlug) {
	return `${categorySlug}::${_activeSiteNorm}::${_activeMenuNorm}`;
}

function storeProductsInCache(categorySlug, html) {
	productCache.set(productCacheKey(categorySlug), html);
}

function getProductsFromCache(categorySlug) {

	if(productCache.has(productCacheKey(categorySlug))) {
		return productCache.get(productCacheKey(categorySlug));
	}
	return null;
}

function appendNewProducts(newProducts, cardDeck) {
	requestAnimationFrame(() => {
		const fragment = document.createDocumentFragment();
		const tempDiv = document.createElement('div');
		tempDiv.innerHTML = newProducts;

		while (tempDiv.firstChild) {
			fragment.appendChild(tempDiv.firstChild);
		}
		cardDeck.appendChild(fragment);
		_categorySectionsCache = null;
	});
}

async function getProductsByCategories(categorySlugs) {
	const missingCategories = [];
	const productResults = [];
	if(productCache.size !== 0) {
		const cachedProducts = categorySlugs.map((categorySlug) => {
			const products = getProductsFromCache(categorySlug);
			if(products === null) {
				missingCategories.push(categorySlug);
			}
			return products

		}).filter((products) => products !== null);

		if(cachedProducts.length>0) {
			if(missingCategories.length === 0) {
				const newProducts = {html: cachedProducts.join('')};
				return newProducts;
			}
			productResults.push(cachedProducts);
		}
	}

	const categories = missingCategories.length > 0 ? missingCategories : categorySlugs;
	const baseUrl = '/wp-json/northstaronlineordering/v1/productsLoop';
	const queryParams = new URLSearchParams({
		cat_slug: encodeURIComponent(categories.join(','))
	});
	if (siteId) { queryParams.set('site_id', siteId); }
	const url = `${baseUrl}?${queryParams.toString()}`;

	try {
		const response = await fetch(url);


		if(response.ok) {
			const data = await response.json();
			const result = processResults(data, productResults, categorySlugs);
			return result;
		}
		throw new Error('Error fetching products');
	} catch (error) {
		console.error(error);
	}

}

function processResults(data, productResults, categories) {
	if(productResults.length === 0) {
		return data;
	}

	const combinedHtml = productResults.join('')+data.html;
	const parser = new DOMParser();
	const doc = parser.parseFromString(combinedHtml, 'text/html');
	const liElements = Array.from(doc.querySelectorAll('li'));

	liElements.sort((a, b) => {
		const aIndex = categories.indexOf(a.id);
		const bIndex = categories.indexOf(b.id);
		return aIndex - bIndex;
	});

	const sortedHtml = liElements.map(li => li.outerHTML).join('');
	const newProducts = { html: sortedHtml };
	return newProducts;
}

function loadCategoriesInBackground() {
	const categoriesList = document.querySelectorAll('.category-list-item');
	const cardDeck = document.querySelector('.products');
	const chunkSize = categoriesList.length * 0.25;
	const results = [];
	const resultChuncks = [];
	const requestQueue=[];
	let isProcessing = false;

	const chunkedCategories = Array.from(categoriesList).reduce((acc, category, index) => {
		const chunkIndex = Math.floor(index / chunkSize);
		if(!acc[chunkIndex]) {
			acc[chunkIndex] = [];
		}
		acc[chunkIndex].push(category.dataset.categoryslug);
		return acc;
	}, []);

	chunkedCategories.forEach((chunk, index) => {
		addRequestToQueue(() => processCategories(chunk, index));
	});

	async function processCategories(chunck, index) {

			const newProducts = await getProductsByCategories(chunck);
			results.push(newProducts);
			resultChuncks[index] = newProducts;
			return newProducts;

	}

	function addRequestToQueue(request) {
		requestQueue.push(request);
		processRequestQueue();
	}

	function processRequestQueue() {
		if(isProcessing) {return;}

		if(requestQueue.length === 0) {
			console.log('All requests processed');
			document.getElementById('loading-more-container').classList.add('hidden');
			cacheResults();
			return;
		}

		isProcessing = true;
		const request = requestQueue.shift();
		request().then(() => {
			processResults();
			isProcessing = false;
			processRequestQueue();
		});
	}

	function processResults() {
		const newProducts = results.shift();
		appendNewProducts(newProducts.html, cardDeck);
	}

	function cacheResults() {
		const tempDiv = document.createElement('div');
		resultChuncks.forEach((chunk) => {
			tempDiv.innerHTML = chunk.html;
			categoriesList.forEach((categoryElement) => {
				const categorySlug = categoryElement.dataset.categoryslug;
				const productCategory = tempDiv.querySelector( `#${ CSS.escape( categorySlug ) }` );
				if(productCategory === null) {
					return;
				}
				storeProductsInCache(categorySlug, productCategory.outerHTML);
			});
		});
	}
}

function handleSubcategoryClick(event, cardDeck, isLoading) {
	event.preventDefault();
	if(isLoading) {return;}
	const categorySlug = event.target.dataset.categoryslug;
	currentPage = 1;
	getProductsByCategorySlug(categorySlug, currentCategorySlug).then((newProducts) => {
		renderSubcategories(newProducts.html, cardDeck, newProducts.parentTerm || null, newProducts.categoryNames[0]);
	});
}

function renderSubcategories(html, cardDeck, parentName = null, categoryName = null) {
	requestAnimationFrame(() => {
		const tempDiv = document.createElement('div');
		const subCategoryList = document.getElementById('sub-category');
		tempDiv.innerHTML = html;
		const fragment = document.createDocumentFragment();
		if (parentName && tempDiv.firstChild) {
			const breadcrumbsContainer = document.createElement('div');
			breadcrumbsContainer.id = 'breadcrumbs-container';
			fragment.appendChild(breadcrumbsContainer);
			createRoot(breadcrumbsContainer).render(
				<Breadcrumbs
				parent={parentName}
				currentCategory={categoryName}
				onParentClick={handleBreadcrumbParentClick}
				/>
			);
		}
		subCategoryList.innerHTML = '';
		fragment.appendChild(tempDiv.firstChild);
		savedScrollTop = scrollRoot?.scrollTop ||0;
		cardDeck.style.display='none';
		subCategoryList.appendChild(fragment);
		subCategoryList.style.display='flex';
	});
	const overlay = document.querySelector('.overlay');
	overlay.classList.add('hidden');
}


function handleBreadcrumbParentClick() {
    const cardDeck = document.getElementById('product-list');
    const subcategoryDiv = document.getElementById('sub-category');
    const breadcrumbsContainer = document.getElementById('breadcrumbs-container');

	cardDeck.style.display = 'flex';
	subcategoryDiv.style.display = 'none';
	breadcrumbsContainer.style.display = 'none';

	if(scrollRoot) {
		scrollRoot.scrollTo({
			top: savedScrollTop,
			behavior: 'auto'
		});
	}
}
loadCategoriesInBackground();

createRoot(document.getElementById('search-box')).render(<ProductSearch />);