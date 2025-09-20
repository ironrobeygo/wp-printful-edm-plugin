// Printful Catalog JavaScript (EDM-only; no modal, no legacy SDK)
(function ($) {
  ("use strict");

  var PrintfulCatalog = {
    GRID_SEL: "#pf-products-grid",
    CATALOG_SEL: "#printful-catalog",
    SPINNER_SEL: "#pf-catalog-spinner",
    LOAD_MORE_SEL: "#load-more-products",

    init: function () {
      this.bindEvents();
    },

    bindEvents: function () {
      // Load more products
      $(document).on("click", "#load-more-products", this.loadMoreProducts);

      // "Design" buttons in the catalog/grid
      $(document).on("click", ".design-button", this.openDesignMaker);

      // Draft / Cart on the designer page
      $(document).on("click", "#save-design-draft", this.saveDesignDraft);
      $(document).on("click", "#add-design-to-cart", this.addDesignToCart);

      // My Designs actions
      $(document).on("click", ".edit-design", this.editDesign);
      $(document).on("click", ".add-to-cart-design", this.addSavedDesignToCart);
      $(document).on("click", ".delete-design", this.deleteDesign);

      // Filter buttons (catalog page)
      $(document).on("click", ".pf-filter-btn", this.applyCatalogFilter);

      $(document).on("click", '[data-role="reset_filters"]', function (e) {
        e.preventDefault();
        PrintfulCatalog.resetAllFilters();
      });

      $(document).on(
        "change",
        '.pfc-dropdown[data-filter="category"] .pfc-dropdown-panel input[name="category_id[]"]',
        this.onCategoryFilterChange
      );

      $(document).on(
        "click",
        '.pfc-dropdown [data-clear="category"]',
        function (e) {
          e.preventDefault();
          var $panel = $(this)
            .closest(".pfc-dropdown")
            .find(".pfc-dropdown-panel");
          $panel.find('input[name="category_id[]"]').prop("checked", false);
          // Re-check “Show All” if present
          $panel
            .find('input[name="category_id[]"][data-role="show-all"]')
            .prop("checked", true);
          PrintfulCatalog.onCategoryFilterChange.call($panel.get(0));
        }
      );

      $(document).on(
        "change",
        '.pfc-dropdown[data-filter="technique"] .pfc-dropdown-panel input[name="technique[]"]',
        this.onFacetGenericChange
      );

      $(document).on(
        "click",
        '.pfc-dropdown [data-clear="technique"]',
        function (e) {
          e.preventDefault();
          var $panel = $(this)
            .closest(".pfc-dropdown")
            .find(".pfc-dropdown-panel");
          $panel.find('input[name="technique[]"]').prop("checked", false);
          PrintfulCatalog.onFacetGenericChange.call($panel.get(0));
        }
      );

      $(document).on(
        "change",
        '.pfc-dropdown[data-filter="placements"] .pfc-dropdown-panel input[name="placements[]"]',
        this.onFacetGenericChange
      );

      $(document).on(
        "click",
        '.pfc-dropdown [data-clear="placements"]',
        function (e) {
          e.preventDefault();
          var $panel = $(this)
            .closest(".pfc-dropdown")
            .find(".pfc-dropdown-panel");
          $panel.find('input[name="placements[]"]').prop("checked", false);
          PrintfulCatalog.onFacetGenericChange.call($panel.get(0));
        }
      );

      $(document).on(
        "change",
        '.pfc-dropdown[data-filter="color"] .pfc-dropdown-panel input[name="color[]"]',
        this.onFacetGenericChange
      );

      $(document).on(
        "click",
        '.pfc-dropdown [data-clear="color"]',
        function (e) {
          e.preventDefault();
          var $panel = $(this)
            .closest(".pfc-dropdown")
            .find(".pfc-dropdown-panel");
          $panel.find('input[name="color[]"]').prop("checked", false);
          PrintfulCatalog.onFacetGenericChange.call($panel.get(0));
        }
      );

      $(document).on(
        "change",
        '.pfc-dropdown[data-filter="sizes"] .pfc-dropdown-panel input[name="sizes[]"]',
        this.onFacetGenericChange
      );

      $(document).on(
        "click",
        '.pfc-dropdown [data-clear="sizes"]',
        function (e) {
          e.preventDefault();
          var $panel = $(this)
            .closest(".pfc-dropdown")
            .find(".pfc-dropdown-panel");
          $panel.find('input[name="sizes[]"]').prop("checked", false);
          PrintfulCatalog.onFacetGenericChange.call($panel.get(0));
        }
      );
    },

    parseIds: function (str) {
      if (!str) return [];
      return String(str)
        .split(",")
        .map(function (s) {
          return parseInt(s, 10);
        })
        .filter(function (n) {
          return Number.isInteger(n) && n > 0;
        });
    },

    fetchProducts: function ($ctx, offset, limit, categoryIds, replace) {
      var $grid = $ctx.find(PrintfulCatalog.GRID_SEL); // <--- precise
      var $spinner = $ctx.find(PrintfulCatalog.SPINNER_SEL); // <--- precise
      var $button = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);

      if (!$grid.length) {
        console.warn("PF: grid not found at", PrintfulCatalog.GRID_SEL);
        return;
      }

      $button.prop("disabled", true);
      $spinner.show();

      $.ajax({
        url: printful_ajax.ajax_url,
        type: "POST",
        data: {
          action: "load_more_products",
          nonce: printful_ajax.nonce,
          offset: offset,
          limit: limit,
          category_ids: categoryIds || [],
        },
        timeout: 30000,
		beforeSend: function () {
		    $button.prop("disabled", true).addClass("is-loading");
		    $spinner.show();
		},
      })
        .done(function (resp) {
            $spinner.hide();

            var data = (resp && resp.data) || {};
            var ok = !!(resp && resp.success);
            var html = data.html || "";
            var hasMore =
              typeof data.has_more === "boolean" ? data.has_more : true;
            var nextOffset =
              data.next_offset != null ? data.next_offset : data.offset;
            if (typeof nextOffset !== "number") nextOffset = offset + limit;

            if (ok) {
              if (replace) {
                $grid.html(html);
              } else {
                if (html && html.trim()) $grid.append(html);
              }

              if ((!html || !html.trim()) && hasMore) {
                $button
                  .data("offset", nextOffset)
                  .attr("data-offset", nextOffset);
                // try again immediately to skip barren slice
                PrintfulCatalog.loadMoreProducts($.Event("manual"));
                return;
              }

              if (hasMore === false) {
                if (replace && (!html || !html.trim())) $grid.html("");
                $button.hide();
                $ctx
                  .find(
                    ".load-more-container .no-more-products, .load-more-container .error-message"
                  )
                  .remove();
                $ctx
                  .find(".load-more-container")
                  .append(
                    '<p class="no-more-products">No more products to load.</p>'
                  );
                return;
              }

              $button
                .data("offset", nextOffset)
                .attr("data-offset", nextOffset)
                .prop("disabled", false)
                .show();

              console.debug(
                "PF: grid updated (replace=" + !!replace + ") with",
                (html.match(/product-card/g) || []).length,
                "cards"
              );
            } else {
              if (replace) $grid.html("");
              $button.hide();
              $ctx
                .find(
                  ".load-more-container .no-more-products, .load-more-container .error-message"
                )
                .remove();
              $ctx
                .find(".load-more-container")
                .append(
                  '<p class="no-more-products">No more products to load.</p>'
                );
            }
        })
        .fail(function (_xhr, status) {
			$spinner.hide();
      		$button.prop("disabled", false).removeClass("is-loading").show();
          var msg =
            status === "timeout"
              ? "Request timed out. Please try again."
              : "Error loading products. Please try again.";
          $ctx
            .find(".load-more-container")
            .append('<p class="error-message">' + msg + "</p>");
        })
		.always(function () {
			$spinner.hide();
			$button.prop("disabled", false).removeClass("is-loading").show();
		});
    },

    applyCatalogFilter: function (e) {
      e.preventDefault();

      var $btn = $(this);
      var $ctx = $(PrintfulCatalog.CATALOG_SEL);
      var $loadMore = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);
      var $grid = $ctx.find(PrintfulCatalog.GRID_SEL);

      if (!$grid.length) {
        console.warn("PF: grid not found");
        return;
      }

      // UI state
      $(".pf-filter-btn").removeClass("is-active");
      $btn.addClass("is-active");

      // Persist selection to DOM
      var idsStr = String($btn.attr("data-category-ids") || "");
      $ctx.attr("data-category-ids", idsStr);
      $loadMore.attr("data-category-ids", idsStr);

      PrintfulCatalog.refreshBrandingOptionsFromCsv(idsStr);

      // Reset paging + clear current grid so the change is visible immediately
      $loadMore.data("offset", 0).attr("data-offset", 0);
      $grid.empty();

      PrintfulCatalog.loadMoreProducts($.Event("manual"));
    },

    loadMoreProducts: function (e) {
      if (e && e.preventDefault) e.preventDefault();

      var $ctx = $(PrintfulCatalog.CATALOG_SEL);
      var $button = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);
      var $spinner = $ctx.find(PrintfulCatalog.SPINNER_SEL);
      var $grid = $ctx.find(PrintfulCatalog.GRID_SEL);

      if (!$grid.length) {
        console.warn("PF: grid not found");
        return;
      }

      var offset = parseInt($button.data("offset"), 10) || 0;
      var limit = parseInt($button.data("limit"), 10) || 8;

      PrintfulCatalog.setGridColumns($grid, limit);

      var filters = $button.data("pfFilters") || $ctx.data("pfFilters") || {};

      // Read current category selection (set by filter click)
      var idsStr = String(
        $button.attr("data-category-ids") ||
          $ctx.attr("data-category-ids") ||
          ""
      );

      console.log($ctx);

      var categoryIds = idsStr
        ? idsStr
            .split(",")
            .map(function (s) {
              return parseInt(s, 10);
            })
            .filter(function (n) {
              return Number.isInteger(n) && n > 0;
            })
        : [];

      $button.prop("disabled", true);
      $spinner.show();

      $.ajax({
        url: printful_ajax.ajax_url,
        type: "POST",
        traditional: true,
        data: {
          action: "load_more_products",
          nonce: printful_ajax.nonce,
          offset: offset,
          limit: limit,
          "category_ids[]":
            filters.category_ids && filters.category_ids.length
              ? filters.category_ids
              : categoryIds,
          "technique[]": filters.technique || [],
          "placements[]": filters.placements || [],
          "color[]": filters.color || [],
          "sizes[]": filters.sizes || [],
        },
        timeout: 30000,
        beforeSend: function () {
          $button.prop("disabled", true).addClass("is-loading");
          $spinner.show();
        },
        success: function (response) {
          $spinner.hide();

          var data = (response && response.data) || {};
          var ok = !!(response && response.success);
          var html = data.html || "";
          var hasMore =
            typeof data.has_more === "boolean" ? data.has_more : true;
          var nextOffset =
            data.next_offset != null ? data.next_offset : data.offset;
          if (typeof nextOffset !== "number") nextOffset = offset + limit;

          if (ok) {
            if (offset === 0) {
              $grid.html(html);
            } else {
              if (html && html.trim()) $grid.append(html);
            }

            // If this slice produced no visible items but server says there’s more,
            // advance the raw offset and immediately try again (quietly).
            if ((!html || !html.trim()) && hasMore) {
              $button
                .data("offset", nextOffset)
                .attr("data-offset", nextOffset);
              PrintfulCatalog.loadMoreProducts($.Event("manual"));
              return;
            }

            // True exhaustion ONLY when server says so.
            if (hasMore === false) {
              if (offset === 0 && (!html || !html.trim())) $grid.html("");
              $button.hide();
              $ctx
                .find(
                  ".load-more-container .no-more-products, .load-more-container .error-message"
                )
                .remove();
              $ctx
                .find(".load-more-container")
                .append(
                  '<p class="no-more-products">No more products to load.</p>'
                );
              return;
            }

            // Otherwise advance and keep button enabled
            $button
              .data("offset", nextOffset)
              .attr("data-offset", nextOffset)
              .prop("disabled", false)
              .show();

            console.debug(
              "PF: updated grid (offset=" +
                offset +
                ", next=" +
                nextOffset +
                ") cards=",
              (html.match(/product-card/g) || []).length
            );
          } else {
            if (offset === 0) $grid.html("");
            $button.hide();
            $ctx
              .find(
                ".load-more-container .no-more-products, .load-more-container .error-message"
              )
              .remove();
            $ctx
              .find(".load-more-container")
              .append(
                '<p class="no-more-products">No more products to load.</p>'
              );
          }
        },
        error: function (_xhr, status) {
          $spinner.hide();
          $button.prop("disabled", false).removeClass("is-loading").show();
          var msg =
            status === "timeout"
              ? "Request timed out. Please try again."
              : "Error loading products.";
          $ctx
            .find(".load-more-container")
            .append('<p class="error-message">' + msg + "</p>");
        },
        complete: function () {
          // ensure cleanup even if success handler throws
          $spinner.hide();
          $button.prop("disabled", false).removeClass("is-loading").show();
        },
      });
    },

    // Redirect to the designer page carrying the PRINTFUL catalog id
    openDesignMaker: function (e) {
      e.preventDefault();
      // Expect data-pf-product-id on the button; fall back to data-product-id if you reused that
      var pfId = $(this).data("pf-product-id") || $(this).data("product-id");
      if (!pfId) {
        console.error("Missing Printful product id on .design-button");
        return;
      }
      var base =
        window.printful_ajax && printful_ajax.design_page_url
          ? printful_ajax.design_page_url
          : window.location.origin + "/design/";
      var sep = base.indexOf("?") === -1 ? "?" : "&";
      window.location.href =
        base + sep + "product_id=" + encodeURIComponent(pfId);
    },

    saveDesignDraft: function (e) {
      e.preventDefault();
      if (
        !window.printfulDesignMaker ||
        typeof window.printfulDesignMaker.sendMessage !== "function"
      ) {
        alert("Designer not ready yet.");
        return;
      }
      window.printfulDesignMaker.sendMessage({ event: "saveDesign" });
    },

    addDesignToCart: function (e) {
      e.preventDefault();
      var designData = PrintfulCatalog.getDesignData();
      var designName = $("#design-name").val() || "Untitled Design";
      var productId = PrintfulCatalog.getCurrentProductId();

      if (!designData) {
        alert("Please create a design before adding to cart.");
        return;
      }

      $.ajax({
        url: printful_ajax.ajax_url,
        type: "POST",
        data: {
          action: "add_design_to_cart",
          product_id: productId,
          design_data: JSON.stringify(designData),
          design_name: designName,
          nonce: printful_ajax.nonce,
        },
        success: function (response) {
          if (response.success) {
            alert("Design added to cart!");
            if (printful_ajax.cart_url) {
              window.location.href = printful_ajax.cart_url;
            } else {
              window.location.href = "/cart/";
            }
          } else {
            alert("Error adding to cart: " + response.data);
          }
        },
        error: function () {
          alert("Error adding to cart. Please try again.");
        },
      });
    },

    editDesign: function (e) {
      e.preventDefault();
      var designId = $(this).data("design-id");
      var url =
        window.printful_ajax && printful_ajax.design_page_url
          ? printful_ajax.design_page_url
          : window.location.origin + "/design/";
      var sep = url.indexOf("?") === -1 ? "?" : "&";
      window.location.href =
        url + sep + "design_id=" + encodeURIComponent(designId);
    },

    addSavedDesignToCart: function (e) {
      e.preventDefault();
      var designId = $(this).data("design-id");

      $.ajax({
        url: printful_ajax.ajax_url,
        type: "POST",
        data: {
          action: "add_saved_design_to_cart",
          design_id: designId,
          nonce: printful_ajax.nonce,
        },
        success: function (response) {
          if (response.success) {
            alert("Design added to cart!");
            location.reload();
          } else {
            alert("Error adding to cart: " + response.data);
          }
        },
        error: function () {
          alert("Error adding to cart. Please try again.");
        },
      });
    },

    deleteDesign: function (e) {
      e.preventDefault();
      var designId = $(this).data("design-id");

      $.ajax({
        url: printful_ajax.ajax_url,
        type: "POST",
        data: {
          action: "delete_design",
          design_id: designId,
          nonce: printful_ajax.nonce,
        },
        success: function (response) {
          if (response.success) {
            alert("Design deleted!");
            document.getElementById("design-card-" + designId).remove();
          } else {
            alert("Error adding to cart: " + response.data);
          }
        },
        error: function () {
          alert("Error deleting design. Please try again.");
        },
      });
    },

    // ---- Helpers ----
    getDesignData: function () {
      // EDM page should expose window.printfulDesignMaker
      if (
        window.printfulDesignMaker &&
        typeof window.printfulDesignMaker.getDesignData === "function"
      ) {
        return window.printfulDesignMaker.getDesignData();
      }
      if (typeof window.currentDesignData !== "undefined") {
        return window.currentDesignData;
      }
      return null;
    },

    // IMPORTANT: read product_id (Printful catalog id), not WP post id
    getCurrentProductId: function () {
      var urlParams = new URLSearchParams(window.location.search);
      var pfId = urlParams.get("product_id") || urlParams.get("product_id"); // tolerate legacy
      if (!pfId) {
        var $edm = $("#printful-edm-container");
        if ($edm.length && $edm.data("pf-product-id"))
          pfId = $edm.data("pf-product-id");
      }
      return pfId ? parseInt(pfId, 10) : 0;
    },

    loadDesign: function (designData) {
      if (window.printfulDesignMaker && designData) {
        window.printfulDesignMaker.loadDesign(JSON.parse(designData));
      }
    },

    // Called whenever a category checkbox changes
    onCategoryFilterChange: function () {
      var $ctx = $(PrintfulCatalog.CATALOG_SEL);
      var $panel = $(this).closest(".pfc-dropdown-panel");

      // Inputs in the category panel
      var $allBox = $panel.find(
        'input[name="category_id[]"][data-role="show-all"]'
      );
      var $allItems = $panel.find('input[name="category_id[]"]').not($allBox);

      // If user ticks “Show All”, uncheck all specific categories
      if ($(this).is($allBox)) {
        if ($allBox.is(":checked")) {
          $allItems.prop("checked", false);
        }
      } else {
        // If user selects any specific category, uncheck “Show All”
        if ($allItems.is(":checked")) {
          $allBox.prop("checked", false);
        }
      }

      // Build the CSV we store on the container/button (what your AJAX reads)
      var selected = $panel
        .find('input[name="category_id[]"]:checked')
        .map(function () {
          return String($(this).val() || "").trim();
        })
        .get();

      var csv;
      if (!selected.length) {
        // No selection -> fall back to “Show All” value if present
        csv = $allBox.length ? String($allBox.val() || "") : "";
      } else if (
        $allBox.length &&
        selected.indexOf(String($allBox.val())) !== -1
      ) {
        // “Show All” is checked -> use its CSV of allowed categories
        csv = String($allBox.val() || "");
      } else {
        // One or more specific categories
        csv = selected.join(",");
      }

      PrintfulCatalog.refreshBrandingOptionsFromCsv(csv);
      PrintfulCatalog.applyCategoryIds(csv);
    },

    // Apply CSV to DOM, reset paging, and reload
    applyCategoryIds: function (csv) {
      var $ctx = $(PrintfulCatalog.CATALOG_SEL);
      var $grid = $ctx.find(PrintfulCatalog.GRID_SEL);
      var $loadMore = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);

      $ctx.attr("data-category-ids", csv);
      $loadMore.attr("data-category-ids", csv);

      PrintfulCatalog.refreshBrandingOptionsFromCsv(csv);

      // Reset paging + clear grid (so change is visible instantly)
      $loadMore.data("offset", 0).attr("data-offset", 0);
      $grid.empty();

      PrintfulCatalog.resetExhausted($ctx);
      // Call the loader directly so we don't depend on a click firing on a disabled element
      PrintfulCatalog.loadMoreProducts($.Event("manual"));
    },

    onFacetGenericChange: function () {
      var $ctx = $(PrintfulCatalog.CATALOG_SEL);
      var $grid = $ctx.find(PrintfulCatalog.GRID_SEL);
      var $loadMore = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);

      PrintfulCatalog.resetExhausted($ctx);

      // Gather filters…
      var filters = {
        technique: gatherChecked('input[name="technique[]"]'),
        placements: gatherChecked('input[name="placements[]"]'),
        color: gatherChecked('input[name="color[]"]'),
        sizes: gatherChecked('input[name="sizes[]"]'),
      };

      $ctx.data("pfFilters", filters);
      $loadMore.data("pfFilters", filters);

      PrintfulCatalog.updateDropdownLabels();

      $loadMore.data("offset", 0).attr("data-offset", 0);
      $grid.empty();
      PrintfulCatalog.loadMoreProducts($.Event("manual"));

      function gatherChecked(sel, coerceNumeric) {
        return $ctx
          .find(sel + ":checked")
          .map(function () {
            var v = String($(this).val() || "").trim();
            if (coerceNumeric && /^\d+$/.test(v)) return parseInt(v, 10);
            return v;
          })
          .get();
      }
    },

    resetExhausted: function ($ctx) {
      var $more = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);
      $ctx.find(".pf-no-more, .pf-empty").remove();
      $ctx
        .find(
          ".load-more-container .no-more-products, .load-more-container .error-message"
        )
        .remove();

      // clear guards so next fetch can run
      $ctx.removeClass("pf-exhausted");
      $more
        .attr("data-exhausted", "0")
        .prop("disabled", false)
        .removeClass("is-disabled")
        .show();
    },

    setGridColumns: function ($grid, limit) {
      // Rule: aim for ~2 rows on desktop (8=>4, 6=>3, 4=>2, 2=>1)
      var colsDesktop = Math.max(
        1,
        Math.min(6, Math.floor((parseInt(limit, 10) || 8) / 2))
      );
      // Derive tablet/mobile caps (never higher than desktop)
      var colsTablet = Math.min(3, colsDesktop);
      var colsMobile = Math.min(2, colsDesktop);

      if ($grid && $grid.length) {
        var el = $grid.get(0);
        el.style.setProperty("--pf-cols", colsDesktop);
        el.style.setProperty("--pf-cols-md", colsTablet);
        el.style.setProperty("--pf-cols-sm", colsMobile);
      }
    },
  };

  PrintfulCatalog.captureDefaultDropdownLabels = function () {
    var $ctx = $(PrintfulCatalog.CATALOG_SEL);
    $ctx.find(".pfc-dropdown > .pfc-trigger").each(function () {
      var $btn = $(this);
      if (!$btn.attr("data-label-default")) {
        var base = ($btn.text() || "").replace(/\s*▾\s*$/, "").trim();
        $btn.attr("data-label-default", base);
      }
    });
  };

  // Recompute button labels to show a count of selected items
  PrintfulCatalog.updateDropdownLabels = function () {
    var $ctx = $(PrintfulCatalog.CATALOG_SEL);

    $ctx.find(".pfc-dropdown").each(function () {
      var $dd = $(this);
      var $btn = $dd.find("> .pfc-trigger");
      var $pane = $dd.find(".pfc-dropdown-panel");

      if (!$btn.length) return;

      var base =
        $btn.attr("data-label-default") ||
        ($btn.text() || "").replace(/\s*▾\s*$/, "").trim();

      var count = 0;
      if ($pane.length) {
        count += $pane.find('input[type="checkbox"]:checked').length;
        count += $pane.find('input[type="radio"]:checked').length;
      }

      var label = base + (count ? " (" + count + ")" : "");
      $btn.text(label + " ▾");
    });
  };

  $(document).ready(function () {
    PrintfulCatalog.init();

    var $ctx = $(PrintfulCatalog.CATALOG_SEL);
    var $loadMore = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);
	$ctx.data("inStockOnly", ($ctx.attr("data-in-stock-only") || "0") === "1");

    // Capture the shortcode's default "Show all" categories once
    var initialCsv =
      ($ctx.attr("data-category-ids") || "").trim() ||
      ($(".pf-filter-btn.is-active").attr("data-category-ids") || "").trim() ||
      "";

    // Persist the default for future resets
    $ctx.attr("data-default-category-ids", initialCsv);
    $loadMore.attr("data-default-category-ids", initialCsv);

	var initialLimit = parseInt($loadMore.data("limit"), 10) || 8;
	PrintfulCatalog.setGridColumns(
		$ctx.find(PrintfulCatalog.GRID_SEL),
		initialLimit
	);

    // Ensure working attrs start from that default on first paint
    if (!$ctx.attr("data-category-ids"))
      $ctx.attr("data-category-ids", initialCsv);
    if (!$loadMore.attr("data-category-ids"))
      $loadMore.attr("data-category-ids", initialCsv);

	$ctx.data("pfBooted", false);

    // Populate placements for the default categories
    if (typeof PrintfulCatalog.refreshPlacementsFromCsv === "function") {
      PrintfulCatalog.refreshPlacementsFromCsv(initialCsv);
    } else if (
      typeof PrintfulCatalog.refreshBrandingOptionsFromCsv === "function"
    ) {
      PrintfulCatalog.refreshBrandingOptionsFromCsv(initialCsv);
    }

	$ctx.data("pfBooted", true);

    // Capture default labels and set initial counts
    PrintfulCatalog.captureDefaultDropdownLabels();
    PrintfulCatalog.updateDropdownLabels();
  });
  
  

  window.PrintfulCatalog = PrintfulCatalog;

  function ajaxUrl() {
    return (
      window.ajaxurl ||
      (window.PF_CATALOG && PF_CATALOG.ajaxurl) ||
      "/wp-admin/admin-ajax.php"
    );
  }
  function ajaxNonce() {
    return (window.PF_CATALOG && PF_CATALOG.nonce) || "";
  }

  function clearNoMore($scope) {
    // Remove various possible markers/text containers
    $scope.find(".pf-no-more, .no-more-products, .printful-no-more").remove();
    // Fallback: remove any element whose text exactly matches the phrase (trimmed, case-sensitive)
    $scope
      .find("*")
      .filter(function () {
        return (
          $(this).children().length === 0 &&
          $(this).text().trim() === "No more products to load."
        );
      })
      .remove();
  }

  var PF_DEFAULT_PLACEMENTS = {
    front: "Front print",
    back: "Back print",
    sleeve_left: "Left sleeve print",
    sleeve_right: "Right sleeve print",
    label_inside: "Inside label",
    label_outside: "Outside label",
    pocket: "Pocket",
    hood: "Hood",
  };

  var PF_CATEGORY_FAMILIES = {
    tops: [23, 24, 25, 26, 27, 85, 108, 30, 31, 32, 33, 34, 35, 89],
    hoodies: [28, 29, 36, 37],
    shoes: [220],
    hats: [40, 41, 42, 43, 44, 45, 46, 47],
  };

  var PF_FAMILY_PLACEMENTS = {
    tops: {
      front: "Front print",
      back: "Back print",
      sleeve_left: "Left sleeve print",
      sleeve_right: "Right sleeve print",
      label_inside: "Inside label",
      label_outside: "Outside label",
      pocket: "Pocket",
    },
    hoodies: {
      front: "Front print",
      back: "Back print",
      sleeve_left: "Left sleeve print",
      sleeve_right: "Right sleeve print",
      hood: "Hood",
    },
    pants: {
      leg_left: "Left leg",
      leg_right: "Right leg",
      top_front: "Top front",
      top_back: "Top back",
      belt_front: "Belt front",
      belt_back: "Belt back",
    },
    shoes: {
      shoe_quarters_left: "Quarters (left)",
      shoe_quarters_right: "Quarters (right)",
      shoe_tongue_left: "Tongue (left)",
      shoe_tongue_right: "Tongue (right)",
      inside1: "Inside 1",
      inside2: "Inside 2",
      background: "Background",
    },
    hats: {
      embroidery_front: "Front (embroidery)",
      embroidery_back: "Back (embroidery)",
      embroidery_right: "Right (embroidery)",
      embroidery_left: "Left (embroidery)",
    },
  };

  function _parseCsvToIds(csv) {
    if (!csv) return [];
    return String(csv)
      .split(",")
      .map(function (s) {
        return parseInt(s, 10);
      })
      .filter(function (n) {
        return Number.isInteger(n) && n > 0;
      });
  }
  function _familiesForIds(idList) {
    var fams = new Set();
    Object.keys(PF_CATEGORY_FAMILIES).forEach(function (fam) {
      var ids = PF_CATEGORY_FAMILIES[fam] || [];
      for (var i = 0; i < idList.length; i++) {
        if (ids.indexOf(idList[i]) !== -1) {
          fams.add(fam);
          break;
        }
      }
    });
    return Array.from(fams);
  }
  function _placementsForFamilies(fams) {
    var out = {};
    fams.forEach(function (fam) {
      var defs = PF_FAMILY_PLACEMENTS[fam] || {};
      Object.keys(defs).forEach(function (k) {
        out[k] = defs[k];
      });
    });
    return out;
  }

  // Reset back to the shortcode's "Show All" selection (not empty)
  PrintfulCatalog.resetAllFilters = function () {
    var $ctx = $(PrintfulCatalog.CATALOG_SEL);
    var $grid = $ctx.find(PrintfulCatalog.GRID_SEL);
    var $loadMore = $ctx.find(PrintfulCatalog.LOAD_MORE_SEL);

    // 1) Resolve the default categories CSV captured at startup
    var defaultCsv = (
      $ctx.attr("data-default-category-ids") ||
      $loadMore.attr("data-default-category-ids") ||
      ""
    ).trim();

    // 2) Persist to working attributes
    $ctx.attr("data-category-ids", defaultCsv);
    $loadMore.attr("data-category-ids", defaultCsv);

    // 3) Reflect the default in the Categories dropdown checkboxes
    var defaultIds = defaultCsv
      ? defaultCsv
          .split(",")
          .map(function (s) {
            return String(parseInt(s, 10));
          })
          .filter(Boolean)
      : [];

    var $catChecks = $ctx.find(
      '.pfc-dropdown[data-filter="categories"] input[type="checkbox"]'
    );
    if ($catChecks.length) {
      $catChecks.each(function () {
        var v = String($(this).val());
        $(this).prop("checked", defaultIds.indexOf(v) !== -1);
      });
    }

    // 4) Highlight the top "Show All" button that corresponds to defaultCsv (if present)
    var $btns = $ctx.find(".pf-filter-btn");
    $btns.removeClass("is-active");
    var $match = $btns.filter(function () {
      return ($(this).attr("data-category-ids") || "").trim() === defaultCsv;
    });
    if ($match.length) {
      $match.addClass("is-active");
    }

    // 5) Clear other facet checkboxes (technique/placements/color/sizes)
    $ctx
      .find('.pfc-dropdown[data-filter="technique"]  input[type="checkbox"]')
      .prop("checked", false);
    $ctx
      .find('.pfc-dropdown[data-filter="placements"] input[type="checkbox"]')
      .prop("checked", false);
    $ctx
      .find('.pfc-dropdown[data-filter="color"]      input[type="checkbox"]')
      .prop("checked", false);
    $ctx
      .find('.pfc-dropdown[data-filter="sizes"]      input[type="checkbox"]')
      .prop("checked", false);

    // 6) Rebuild placements based on the restored default categories
    if (typeof PrintfulCatalog.refreshPlacementsFromCsv === "function") {
      PrintfulCatalog.refreshPlacementsFromCsv(defaultCsv);
    }

    // 7) Reset paging + "no more products" state and reload
    $loadMore.data("offset", 0).attr("data-offset", 0);
    $grid.empty();
    if (typeof PrintfulCatalog.resetExhausted === "function") {
      PrintfulCatalog.resetExhausted($ctx);
    }

	PrintfulCatalog.updateDropdownLabels();

    PrintfulCatalog.loadMoreProducts($.Event("manual"));
  };

  PrintfulCatalog.renderPlacementsDropdown = function (allowedMap) {
    var $ctx = $(PrintfulCatalog.CATALOG_SEL);
    var $panel = $ctx.find(
      '.pfc-dropdown[data-filter="placements"] .pfc-dropdown-panel'
    );
    if (!$panel.length) return;

    // Remember any checked options
    var prevChecked = $panel
      .find('input[name="placements[]"]:checked')
      .map(function () {
        return $(this).val();
      })
      .get();

    var keys = Object.keys(allowedMap || {});
    if (!keys.length)
      (allowedMap = PF_DEFAULT_PLACEMENTS), (keys = Object.keys(allowedMap));

    // Rebuild inputs (preserve an existing "Clear" link if present)
    var $clear = $panel.find('[data-clear="placements"]').first().detach();
    $panel.empty();

    keys.forEach(function (k) {
      var label = allowedMap[k] || k;
      var checked = prevChecked.indexOf(k) !== -1 ? " checked" : "";
      $panel.append(
        '<label class="pfc-check item block py-1">' +
          '<input type="checkbox" name="placements[]" value="' +
          k +
          '"' +
          checked +
          "> " +
          label +
          "</label>"
      );
    });

    if ($clear && $clear.length) $panel.append($clear);

	var $ctx = $(PrintfulCatalog.CATALOG_SEL);
	if ($ctx.data("pfBooted")) {
		PrintfulCatalog.onFacetGenericChange.call($panel.get(0));
	}
  };

  PrintfulCatalog.refreshBrandingOptionsFromCsv = function (csv) {
    var $ctx = $(PrintfulCatalog.CATALOG_SEL);

    // 1) Try the csv we were given
    var ids = _parseCsvToIds(csv);

    // 2) If empty (e.g., "Show All"), try current container attr
    if (!ids.length) {
      ids = _parseCsvToIds($ctx.attr("data-category-ids") || "");
    }

    // 3) If still empty, try active top category button
    if (!ids.length) {
      var btnCsv =
        $(".pf-filter-btn.is-active").attr("data-category-ids") || "";
      ids = _parseCsvToIds(btnCsv);
    }

    // 4) Derive families -> allowed placements; if none -> default set
    var fams = _familiesForIds(ids);
    var allowed = _placementsForFamilies(fams);
    if (!Object.keys(allowed).length) allowed = PF_DEFAULT_PLACEMENTS;

    PrintfulCatalog.renderPlacementsDropdown(allowed);
  };
})(jQuery);
