// Printful Catalog JavaScript (EDM-only; no modal, no legacy SDK)
(function ($) {
  'use strict';

  var PrintfulCatalog = {
    GRID_SEL:       '#pf-products-grid',
    CATALOG_SEL:    '#printful-catalog',
    SPINNER_SEL:    '#pf-catalog-spinner',
    LOAD_MORE_SEL:  '#load-more-products',

    init: function () {
      this.bindEvents();
    },

    bindEvents: function () {
      // Load more products
      $(document).on('click', '#load-more-products', this.loadMoreProducts);

      // "Design" buttons in the catalog/grid
      $(document).on('click', '.design-button', this.openDesignMaker);

      // Draft / Cart on the designer page
      $(document).on('click', '#save-design-draft', this.saveDesignDraft);
      $(document).on('click', '#add-design-to-cart', this.addDesignToCart);

      // My Designs actions
      $(document).on('click', '.edit-design', this.editDesign);
      $(document).on('click', '.add-to-cart-design', this.addSavedDesignToCart);
      $(document).on('click', '.delete-design', this.deleteDesign);

      // Filter buttons (catalog page)
      $(document).on('click', '.pf-filter-btn', this.applyCatalogFilter);

    },

    parseIds: function (str) {
      if (!str) return [];
      return String(str)
        .split(',')
        .map(function (s) { return parseInt(s, 10); })
        .filter(function (n) { return Number.isInteger(n) && n > 0; });
    },

    fetchProducts: function ($ctx, offset, limit, categoryIds, replace) {
      var $grid    = $ctx.find(PrintfulCatalog.GRID_SEL);     // <--- precise
      var $spinner = $ctx.find(PrintfulCatalog.SPINNER_SEL);  // <--- precise
      var $button  = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);

      if (!$grid.length) {
        console.warn('PF: grid not found at', PrintfulCatalog.GRID_SEL);
        return;
      }

      $button.prop('disabled', true);
      $spinner.show();

      $.ajax({
        url: printful_ajax.ajax_url,
        type: 'POST',
        data: {
          action: 'load_more_products',
          nonce:  printful_ajax.nonce,
          offset: offset,
          limit:  limit,
          category_ids: categoryIds || []
        },
        timeout: 10000
      }).done(function (resp) {
        $spinner.hide();

        if (resp && resp.success) {
          var html = (resp.data && resp.data.html) || '';
          if (replace) $grid.html(html); else $grid.append(html);

          var next = (resp.data && (resp.data.next_offset || resp.data.offset));
          var nextOffset = Number.isInteger(next) ? next : (offset + limit);
          $button.data('offset', nextOffset).attr('data-offset', nextOffset)
                .prop('disabled', false).show();

          console.debug('PF: grid updated (replace=' + !!replace + ') with', (html.match(/product-card/g)||[]).length, 'cards');
        } else {
          if (replace) $grid.html('');
          $button.hide();
          $ctx.find('.load-more-container .no-more-products, .load-more-container .error-message').remove();
          $ctx.find('.load-more-container').append('<p class="no-more-products">No more products to load.</p>');
        }
      }).fail(function (_xhr, status) {
        $spinner.hide();
        $button.prop('disabled', false).show();
        var msg = (status === 'timeout') ? 'Request timed out. Please try again.' : 'Error loading products. Please try again.';
        $ctx.find('.load-more-container').append('<p class="error-message">' + msg + '</p>');
      });
    },

    applyCatalogFilter: function (e) {
      e.preventDefault();

      var $btn      = $(this);
      var $ctx      = $(PrintfulCatalog.CATALOG_SEL);
      var $loadMore = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);
      var $grid     = $ctx.find(PrintfulCatalog.GRID_SEL);

      if (!$grid.length) { console.warn('PF: grid not found'); return; }

      // UI state
      $('.pf-filter-btn').removeClass('is-active');
      $btn.addClass('is-active');

      // Persist selection to DOM
      var idsStr = String($btn.attr('data-category-ids') || '');
      $ctx.attr('data-category-ids', idsStr);
      $loadMore.attr('data-category-ids', idsStr);

      // Reset paging + clear current grid so the change is visible immediately
      $loadMore.data('offset', 0).attr('data-offset', 0);
      $grid.empty();

      // Kick off the load (offset=0 forces "replace" in success handler)
      $loadMore.trigger('click');
    },

    loadMoreProducts: function (e) {
      e.preventDefault();

      var $ctx     = $(PrintfulCatalog.CATALOG_SEL);
      var $button  = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);
      var $spinner = $ctx.find(PrintfulCatalog.SPINNER_SEL);
      var $grid    = $ctx.find(PrintfulCatalog.GRID_SEL);

      if (!$grid.length) { console.warn('PF: grid not found'); return; }

      var offset = parseInt($button.data('offset'), 10) || 0;
      var limit  = parseInt($button.data('limit'), 10)  || 8;

      // Read current category selection (set by filter click)
      var idsStr = String($button.attr('data-category-ids') || $ctx.attr('data-category-ids') || '');
      var categoryIds = idsStr
        ? idsStr.split(',').map(function (s) { return parseInt(s, 10); }).filter(function (n) { return Number.isInteger(n) && n > 0; })
        : [];

      $button.prop('disabled', true);
      $spinner.show();

      $.ajax({
        url: printful_ajax.ajax_url,
        type: 'POST',
        data: {
          action: 'load_more_products',
          nonce:  printful_ajax.nonce,
          offset: offset,
          limit:  limit,
          category_ids: categoryIds // <-- CRITICAL: send selected categories
        },
        timeout: 10000,
        success: function (response) {
          $spinner.hide();

          if (response && response.success) {
            var html = (response.data && response.data.html) ? response.data.html : '';

            if (offset === 0) {            // <-- first page for a filter => replace
              $grid.html(html);
            } else {
              $grid.append(html);
            }

            var nextOffset = (response.data && (response.data.next_offset || response.data.offset)) || (offset + limit);
            $button.data('offset', nextOffset).attr('data-offset', nextOffset)
                  .prop('disabled', false).show();

            console.debug('PF: updated grid (offset=' + offset + ', next=' + nextOffset + ') cards=', (html.match(/product-card/g)||[]).length);
          } else {
            if (offset === 0) $grid.html('');
            $button.hide();
            $ctx.find('.load-more-container .no-more-products, .load-more-container .error-message').remove();
            $ctx.find('.load-more-container').append('<p class="no-more-products">No more products to load.</p>');
          }
        },
        error: function (_xhr, status) {
          $spinner.hide();
          $button.prop('disabled', false).show();
          var msg = (status === 'timeout') ? 'Request timed out. Please try again.' : 'Error loading products.';
          $ctx.find('.load-more-container').append('<p class="error-message">' + msg + '</p>');
        }
      });
    },

    // Redirect to the designer page carrying the PRINTFUL catalog id
    openDesignMaker: function (e) {
      e.preventDefault();
      // Expect data-pf-product-id on the button; fall back to data-product-id if you reused that
      var pfId = $(this).data('pf-product-id') || $(this).data('product-id');
      if (!pfId) {
        console.error('Missing Printful product id on .design-button');
        return;
      }
      var base = (window.printful_ajax && printful_ajax.design_page_url)
        ? printful_ajax.design_page_url
        : (window.location.origin + '/design/');
      var sep = base.indexOf('?') === -1 ? '?' : '&';
      window.location.href = base + sep + 'product_id=' + encodeURIComponent(pfId);
    },

    saveDesignDraft: function (e) {
      e.preventDefault();
      if (!window.printfulDesignMaker || typeof window.printfulDesignMaker.sendMessage !== 'function') {
        alert('Designer not ready yet.'); 
        return;
      }
      window.printfulDesignMaker.sendMessage({ event: 'saveDesign' });
    },

    addDesignToCart: function (e) {
      e.preventDefault();
      var designData = PrintfulCatalog.getDesignData();
      var designName = $('#design-name').val() || 'Untitled Design';
      var productId = PrintfulCatalog.getCurrentProductId();

      if (!designData) {
        alert('Please create a design before adding to cart.');
        return;
      }

      $.ajax({
        url: printful_ajax.ajax_url,
        type: 'POST',
        data: {
          action: 'add_design_to_cart',
          product_id: productId,
          design_data: JSON.stringify(designData),
          design_name: designName,
          nonce: printful_ajax.nonce
        },
        success: function (response) {
          if (response.success) {
            alert('Design added to cart!');
            if (printful_ajax.cart_url) {
              window.location.href = printful_ajax.cart_url;
            } else {
              window.location.href = '/cart/';
            }
          } else {
            alert('Error adding to cart: ' + response.data);
          }
        },
        error: function () {
          alert('Error adding to cart. Please try again.');
        }
      });
    },

    editDesign: function (e) {
      e.preventDefault();
      var designId = $(this).data('design-id');
      var url = (window.printful_ajax && printful_ajax.design_page_url)
        ? printful_ajax.design_page_url
        : (window.location.origin + '/design/');
      var sep = url.indexOf('?') === -1 ? '?' : '&';
      window.location.href = url + sep + 'design_id=' + encodeURIComponent(designId);
    },

    addSavedDesignToCart: function (e) {
      e.preventDefault();
      var designId = $(this).data('design-id');

      $.ajax({
        url: printful_ajax.ajax_url,
        type: 'POST',
        data: {
          action: 'add_saved_design_to_cart',
          design_id: designId,
          nonce: printful_ajax.nonce
        },
        success: function (response) {
          if (response.success) {
            alert('Design added to cart!');
            location.reload();
          } else {
            alert('Error adding to cart: ' + response.data);
          }
        },
        error: function () {
          alert('Error adding to cart. Please try again.');
        }
      });
    },

    deleteDesign: function (e) {
      e.preventDefault();
      var designId = $(this).data('design-id');

      $.ajax({
        url: printful_ajax.ajax_url,
        type: 'POST',
        data: {
          action: 'delete_design',
          design_id: designId,
          nonce: printful_ajax.nonce
        },
        success: function (response) {
          if (response.success) {
            alert('Design deleted!');
            document.getElementById('design-card-' + designId).remove();
          } else {
            alert('Error adding to cart: ' + response.data);
          }
        },
        error: function () {
          alert('Error deleting design. Please try again.');
        }
      });
    },

    // ---- Helpers ----
    getDesignData: function () {
      // EDM page should expose window.printfulDesignMaker
      if (window.printfulDesignMaker && typeof window.printfulDesignMaker.getDesignData === 'function') {
        return window.printfulDesignMaker.getDesignData();
      }
      if (typeof window.currentDesignData !== 'undefined') {
        return window.currentDesignData;
      }
      return null;
    },

    // IMPORTANT: read product_id (Printful catalog id), not WP post id
    getCurrentProductId: function () {
      var urlParams = new URLSearchParams(window.location.search);
      var pfId = urlParams.get('product_id') || urlParams.get('product_id'); // tolerate legacy
      if (!pfId) {
        var $edm = $('#printful-edm-container');
        if ($edm.length && $edm.data('pf-product-id')) pfId = $edm.data('pf-product-id');
      }
      return pfId ? parseInt(pfId, 10) : 0;
    },

    loadDesign: function (designData) {
      if (window.printfulDesignMaker && designData) {
        window.printfulDesignMaker.loadDesign(JSON.parse(designData));
      }
    }
  };

  $(document).ready(function () {
    PrintfulCatalog.init();
  });

  window.PrintfulCatalog = PrintfulCatalog;

  function ajaxUrl(){ return (window.ajaxurl || (window.PF_CATALOG && PF_CATALOG.ajaxurl) || '/wp-admin/admin-ajax.php'); }
  function ajaxNonce(){ return ((window.PF_CATALOG && PF_CATALOG.nonce) || ''); }

  function clearNoMore($scope){
    // Remove various possible markers/text containers
    $scope.find('.pf-no-more, .no-more-products, .printful-no-more').remove();
    // Fallback: remove any element whose text exactly matches the phrase (trimmed, case-sensitive)
    $scope.find('*').filter(function(){
      return $(this).children().length === 0 && ($(this).text().trim() === 'No more products to load.');
    }).remove();
  }

  // Delegated: filter clicks
  $(document).on('click', '.pf-filter-btn', function(e){
    e.preventDefault();
    var $btn  = $(this);
    var $wrap = $btn.closest('.printful-catalog-container');
    var $grid = $wrap.find('.pf-grid');
    var $more = $wrap.find('#load-more-products');

    // Clear exhausted state from previous empty load
    $wrap.removeClass('pf-exhausted');
    clearNoMore($wrap);
    $wrap.find('.pf-no-more').remove();
    $more.prop('disabled', false).show().text('Load More Products');

    // New selection from button (supports data-category-ids OR data-category-id)
    var newCsv = ($btn.data('categoryIds') || $btn.data('categoryId') || '').toString();

    // UI active state + reset offset
    $wrap.find('.pf-filter-btn').removeClass('is-active');
    $btn.addClass('is-active');
    $more.data('offset', 0);

    // Keep datasets in sync (container + button used by AJAX)
    $wrap.attr('data-category-ids', newCsv);
    $more.attr('data-category-ids', newCsv);

    // Optional: if your UX fetches first page on filter click, add a request here.
    // Otherwise the first "Load More" will fetch correctly using newCsv.
  });

  // Delegated: load-more clicks
  $(document).on('click', '#load-more-products', function(e){
    e.preventDefault();
    var $btn  = $(this);
    var $wrap = $btn.closest('.printful-catalog-container');
    var $grid = $wrap.find('.pf-grid');
    if ($btn.prop('disabled')) return;

    var offset = parseInt($btn.data('offset') || 0, 10);
    var limit  = parseInt($btn.data('limit')  || 8, 10);
    var csv    = ($btn.data('categoryIds') || $wrap.data('categoryIds') || '').toString();

    var data = {
      action: 'load_more_products',
      nonce:  ajaxNonce(),
      offset: offset,
      limit:  limit,
      category_id: csv
    };

    $btn.text('Loading…');

    $.post(ajaxUrl(), data, function(html){
      var trimmed = (html || '').trim();

      if (!trimmed || /class\s*=\s*["']pf-no-more["']/.test(trimmed)) {
        if (!$wrap.find('.pf-no-more').length) {
          $grid.append('<div class="pf-no-more">No more products to load.</div>');
        }
        $btn.prop('disabled', true).text('No more');
        return;
      }

      clearNoMore($wrap);

      $grid.append(trimmed);
      $btn.data('offset', offset + limit).text('Load More Products');
    }).fail(function(){
      $btn.text('Load More Products');
    });
  });

  $(document).on('change', '#pf-category-select', function () {
    var $sel  = jQuery(this);
    var $wrap = $sel.closest('.printful-catalog-container');
    var $grid = $wrap.find('.products-grid, .pf-grid');
    var $more = $wrap.find('#load-more-products');

    var csv = ($sel.val() || '').toString().trim();

    // Reset
    $wrap.find('.pf-no-more, .no-more-products, .printful-no-more').remove();
    $more.prop('disabled', false).show().text('Load More Products');
    $more.data('offset', 0);

    // Sync dataset for AJAX
    $wrap.attr('data-category-ids', csv);
    $more.attr('data-category-ids', csv);

    // Reload products immediately
    $grid.empty();
    $more.trigger('click');
  });

})(jQuery);
