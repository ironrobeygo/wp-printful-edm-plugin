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

(function () {
  const root = document.getElementById("printful-catalog");
  if (!root) return; // Page doesn’t have the catalog; do nothing

  const container = root.querySelector(".pfc-topbar") || document;

  function collectFilters() {
    const pick = (name) =>
      Array.from(
        container.querySelectorAll(`input[name="${name}[]"]:checked`)
      ).map((i) => i.value);

    // NEW: categories (multi)
    const showAll =
      document.getElementById("pf-category-select")?.dataset?.showAll || "";
    let categories = Array.from(
      document.querySelectorAll('input[name="category_id[]"]:checked')
    ).map((i) => i.value);

    // If "Show All" is checked, treat as no category filter
    if (categories.includes(showAll)) categories = [];

    return {
      category: categories, // 👈 array
      technique: pick("technique"),
      color: pick("color"),
      branding_options: pick("branding_options"),
      sizes: pick("sizes"),
      material: pick("material"),
      models: pick("models"),
      flags: pick("flags"),
    };
  }

  container?.addEventListener("change", (e) => {
    if (!e.target.matches('input[type="checkbox"]')) return;

    // Special rules for category checkboxes
    if (e.target.name === "category_id[]") {
      const showAll =
        document.getElementById("pf-category-select")?.dataset?.showAll || "";
      const isShowAll = e.target.hasAttribute("data-role");

      if (isShowAll) {
        // If Show All was clicked, uncheck every other category
        document
          .querySelectorAll('input[name="category_id[]"]')
          .forEach((cb) => {
            if (cb !== e.target) cb.checked = false;
          });
      } else {
        // If a specific category was selected, uncheck Show All
        const showAllCb = document.querySelector(
          'input[name="category_id[]"][data-role="show-all"]'
        );
        if (showAllCb) showAllCb.checked = false;
      }
    }

    window.pfCatalogFilters = collectFilters();
    if (typeof window.pfReloadCatalog === "function") {
      window.pfReloadCatalog(true);
    }
  });

  container?.querySelectorAll("[data-clear]").forEach((a) => {
    a.addEventListener("click", (e) => {
      e.preventDefault();
      const group = a.getAttribute("data-clear");
      const selector =
        group === "category"
          ? 'input[name="category_id[]"]'
          : `input[name="${group}[]"]`;

      container.querySelectorAll(selector).forEach((i) => (i.checked = false));

      window.pfCatalogFilters = collectFilters();
      if (typeof window.pfReloadCatalog === "function") {
        window.pfReloadCatalog(true);
      }
    });
  });

  // expose current filters for your loader
  window.pfCatalogFilters = collectFilters();

  // Example: patch your existing loader to include filters
  // If you already do this, ignore.
  if (!window.pfFetchPagePatched) {
    window.pfFetchPagePatched = true;
    const orig = window.pfFetchPage;
    window.pfFetchPage = async function (reset = false) {
      // make sure your AJAX request body includes:
      //   filters: window.pfCatalogFilters
      return orig ? orig(reset) : null;
    };
  }
})();

(function () {
  const root = document.getElementById("printful-catalog");
  if (!root) return;

  // Use the actual IDs from your PHP (sample.php)
  const grid = root.querySelector("#pf-products-grid"); // was #pfc-grid
  const loadBt = root.querySelector("#load-more-products"); // was #pfc-load-more
  const meta = root.querySelector("#pfc-results-meta"); // optional (may not exist)
  const searchEl = root.querySelector("#pfc-search"); // optional (may not exist)
  const topbar = root.querySelector(".pfc-topbar"); // scope to root

  const state = {
    offset: 0,
    limit: 8,
    hasMore: false,
    nextOffset: null,
    filters: window.pfCatalogFilters || {},
    sort: "popular",
    q: "",
  };

  // Debounce helper
  const debounce = (fn, ms = 300) => {
    let t;
    return (...a) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...a), ms);
    };
  };

  function collectFiltersFromDOM() {
    const pick = (name) =>
      Array.from(root.querySelectorAll(`input[name="${name}[]"]:checked`)).map(
        (i) => i.value
      );

    const showAll =
      document.getElementById("pf-category-select")?.dataset?.showAll || "";
    let categories = Array.from(
      root.querySelectorAll('input[name="category_id[]"]:checked')
    ).map((i) => i.value);
    if (categories.includes(showAll)) categories = [];

    return {
      category: categories, // 👈 array
      technique: pick("technique"),
      color: pick("color"),
      branding_options: pick("branding_options"),
      sizes: pick("sizes"),
      material: pick("material"),
      models: pick("models"),
      flags: pick("flags"),
    };
  }

  function setResultsMeta(total) {
    meta.textContent =
      typeof total === "number" ? `Showing ${total} results` : "";
  }

  function productCard(p) {
    const price = p.min_price ? `From ${p.min_price}` : "";
    const img = p.thumbnail_url ? `<img src="${p.thumbnail_url}" alt="">` : "";
    return `
			<article class="pfc-card">
				<div class="pfc-thumb">${img}</div>
				<div class="pfc-title"><strong>${p.name || ""}</strong></div>
				${price ? `<div class="pfc-price">${price}</div>` : ``}
				<div class="pfc-actions"><a href="${
          p.url || "#"
        }" class="pfc-btn">Design product</a></div>
			</article>
			`;
  }

  function render(items, reset = false) {
    if (!grid) return;
    if (reset) grid.innerHTML = "";
    if (!items || !items.length) {
      if (reset)
        grid.innerHTML = `<div style="padding:20px;color:#666;">No products match your filters.</div>`;
      return;
    }
    grid.insertAdjacentHTML("beforeend", items.map(productCard).join(""));
  }

  async function fetchPage(reset = false) {
    if (!grid) return;
    if (reset) {
      state.offset = 0;
      state.nextOffset = null;
      grid.innerHTML = "";
    }

    const cats = state.filters?.category || [];
    const payload = {
      action: "get_products",
      nonce: ajaxNonce(),
      offset: state.offset,
      limit: state.limit,
      filters: state.filters,
      sort: state.sort,
      q: state.q,
      category_ids: cats,
      category_ids_csv: cats.join(","),
    };

    if (loadBt) loadBt.disabled = true;

    const res = await fetch(ajaxUrl(), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    })
      .then((r) => r.json())
      .catch(() => ({ success: false }));

	  console.log(payload);
    
	  if (!res?.success) {
      render([], true);
      if (meta) meta.textContent = "";
      if (loadBt) loadBt.style.display = "none";
      if (loadBt) loadBt.disabled = false;
      return;
    }

    const { items, hasMore, nextOffset, total } = res.data || {};
    render(items || [], reset);
    if (meta)
      meta.textContent =
        typeof total === "number" ? `Showing ${total} results` : "";
    state.hasMore = !!hasMore;
    state.nextOffset = nextOffset;
    state.offset = nextOffset ?? state.offset + (items?.length || 0);
    if (loadBt) {
      loadBt.style.display = state.hasMore ? "inline-block" : "none";
      loadBt.disabled = false;
    }
  }

  // Expose reloader for other scripts if needed
  window.pfReloadCatalog = function (reset = true) {
    state.filters = collectFiltersFromDOM();
    return fetchPage(!!reset);
  };

  // Sort handler
  topbar?.addEventListener("change", (e) => {
    if (e.target.name === "sort") {
      state.sort = e.target.value;
      fetchPage(true);
    }
  });

  root.addEventListener("change", (e) => {
    if (!e.target.matches('input[type="checkbox"][name$="[]"]')) return;

    // Category "Show All" exclusivity
    if (e.target.name === "category_id[]") {
      const isShowAll = e.target.hasAttribute("data-role");
      if (isShowAll) {
        root.querySelectorAll('input[name="category_id[]"]').forEach((cb) => {
          if (cb !== e.target) cb.checked = false;
        });
      } else {
        const showAllCb = root.querySelector(
          'input[name="category_id[]"][data-role="show-all"]'
        );
        if (showAllCb) showAllCb.checked = false;
      }
    }

    // Build CSV of selected categories (empty = show all)
    const boxes = Array.from(
      root.querySelectorAll('input[name="category_id[]"]:checked')
    );
    const showAllVal =
      document.getElementById("pf-category-select")?.dataset?.showAll || "";
    const ids = boxes.map((b) => b.value);
    const useCsv = ids.includes(showAllVal) ? "" : ids.join(",");

    // Sync with catalog datasets & trigger the existing loader
    const wrap = root.closest(".printful-catalog-container") || root;
    const load = wrap.querySelector("#load-more-products");
    const grid = wrap.querySelector("#pf-products-grid");

    wrap.setAttribute("data-category-ids", useCsv);
    load.setAttribute("data-category-ids", useCsv);
    load.dataset.offset = "0";
    load.setAttribute("data-offset", "0");

    if (grid) grid.innerHTML = "";
    load.dispatchEvent(new Event("click", { bubbles: true }));
  });

  root.querySelectorAll("[data-clear]").forEach((a) => {
    a.addEventListener("click", (e) => {
      e.preventDefault();
      const group = a.getAttribute("data-clear");
      const sel =
        group === "category"
          ? 'input[name="category_id[]"]'
          : `input[name="${group}[]"]`;
      root.querySelectorAll(sel).forEach((i) => (i.checked = false));

      // push "show all" to catalog
      const wrap = root.closest(".printful-catalog-container") || root;
      const load = wrap.querySelector("#load-more-products");
      const grid = wrap.querySelector("#pf-products-grid");

      wrap.setAttribute("data-category-ids", "");
      load.setAttribute("data-category-ids", "");
      load.dataset.offset = "0";
      load.setAttribute("data-offset", "0");

      if (grid) grid.innerHTML = "";
      load.dispatchEvent(new Event("click", { bubbles: true }));
    });
  });

  // Search (debounced)
  const onSearch = debounce(() => {
    state.q = (searchEl.value || "").trim();
    fetchPage(true);
  }, 350);
  searchEl?.addEventListener("input", onSearch);

  // Load more
  loadBt?.addEventListener("click", () => fetchPage(false));

  // Initial
  state.filters = collectFiltersFromDOM();
  fetchPage(true);

  function hasActiveFilters(f) {
    if (!f) return false;
    const groups = [
      "category",
      "technique",
      "color",
      "branding_options",
      "sizes",
      "material",
      "models",
      "flags",
    ];
    // any checked boxes?
    if (groups.some((g) => Array.isArray(f[g]) && f[g].length > 0)) return true;
    // search also counts as a filter (optional)
    if (state.q && state.q.trim() !== "") return true;
    return false;
  }
})();
