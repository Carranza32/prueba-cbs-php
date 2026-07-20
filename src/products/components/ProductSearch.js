import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';

const ProductSearch = () => {
  const [searchTerm, setSearchTerm] = useState('');
  const previousSearch = useRef('');
  const debounceTimeout = useRef(null);

  useEffect(() => {
    const productList = document.querySelector('#product-list');
    const searchResults = document.querySelector('#search-results');

    if (previousSearch.current !== '' && searchTerm.trim() === '') {
      productList.style.display = 'flex';
      searchResults.style.display = 'none';
      return;
    }
    if (searchTerm.trim() === '') {
      return;
    }
    if (searchTerm.trim().length < 2) {
    return;
  }

    if (debounceTimeout.current) {
      clearTimeout(debounceTimeout.current);
    }

    if(searchTerm.trim().length > 2 ){
      fetchProducts(searchTerm);
    }

    previousSearch.current = searchTerm;

    return () => clearTimeout(debounceTimeout.current);
  }, [searchTerm]);

  const fetchProducts = async (term) => {
    try {
      // Scope the search to the active site (cookie or block attribute) so it
      // never returns items from another site sharing a category name.
      const siteId = document.getElementById('products-block-wrapper')?.dataset?.siteId || '';
      const response = await axios.get('/wp-json/northstaronlineordering/v1/searchProducts', {
        params: siteId ? { search: term, site_id: siteId } : { search: term }
      });

      const container = document.querySelector('#search-results');
      const productList = document.querySelector('#product-list');

      if (container) {
        container.innerHTML = Array.isArray(response.data)
          ? response.data.join('')
          : response.data;
        productList.style.display = 'none';
        container.style.display = 'flex';
      }


    } catch (error) {
      console.error('Error fetching products:', error);
    }
  };

  return (
    <input
      type="text"
      id="search-input"
      className="search-input"
      placeholder="Search products..."
      value={searchTerm}
      onChange={(e) => setSearchTerm(e.target.value)}
    />
  );
};

export default ProductSearch;