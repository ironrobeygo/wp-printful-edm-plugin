// assets/printful-catalog.js
(function () {
  if (window.__printful_catalog_js_loaded) return;
  window.__printful_catalog_js_loaded = true;
  const CatalogEDM = {
    // ---- state (filled by init) ----
    uniqueId: "",
    productId: "",
    designId: "",
    storeId: "",
    currentUserId: 0,
    currency: "AUD",
    externalId: "",

    // runtime
    ajaxUrl: "/wp-admin/admin-ajax.php",
    wpNonce: "",
    cartNonce: "",
    addToCartAction: "add_design_to_cart",
    variants: [],
    currentUnitPrice: "0.00",
    state: { selectedVariantIds: [], usedPlacements: [] },
    pricingByVariantId: new Map(),
    userInitiatedSave: false,

    // ---- boot ----
    init(cfg = {}) {
      // console.log('CatalogEDM init', cfg);
      // 1) config from data-attrs or cfg
      const root = document.getElementById("design-maker-container");
      if (!root && !cfg.uniqueId) {
        console.warn(
          "[CatalogEDM] #design-maker-container not found and no config provided"
        );
        return this;
      }
      console.log("CatalogEDM init", { root, cfg });
      this.uniqueId 		= cfg.uniqueId ?? root?.dataset.uniqueId ?? "";
      this.productId 		= cfg.productId ?? root?.dataset.productId ?? "";
      this.designId 		= cfg.designId ?? root?.dataset.designId ?? "";
      this.storeId 			= cfg.storeId ?? root?.dataset.storeId ?? "";
      this.externalId 		= cfg.externalProductId ?? root?.dataset.externalProductId ?? "";
      this.currentUserId 	= Number(cfg.currentUserId ?? root?.dataset.userId ?? 0);
      this.currency 		= (cfg.currency ?? root?.dataset.currency ?? "AUD") || "AUD";

      // JSON variants via cfg or <script type="application/json" id="pf-variants-UNIQ">
      if (Array.isArray(cfg.variants)) {
        this.variants = cfg.variants;
      } else {
        try {
          const vTag = document.getElementById(`pf-variants-${this.uniqueId}`);
          this.variants = vTag ? JSON.parse(vTag.textContent) : [];
        } catch {
          this.variants = [];
        }
      }

      // 2) globals from localized script
      this.ajaxUrl 			= (window.printful_ajax && printful_ajax.ajax_url) || this.ajaxUrl;
      this.wpNonce 			= (window.printful_ajax && printful_ajax.nonce) || "";
      this.cartNonce 		= (window.printful_ajax && printful_ajax.cart_nonce) || "";
      this.addToCartAction 	= (window.printful_ajax && printful_ajax.add_to_cart_action) || this.addToCartAction;

      // 3) wire UI & listeners
      this.bindButtons();
      window.addEventListener("message", this.onMessage.bind(this), false);

      // 4) initialize EDM (this snippet only handles NEW flow as per your code)
      this.initializeEDM();

      return this;
    },

    // ---- helpers from your snippet ----
    isPrintful(origin) {
      try {
        const h = new URL(origin).hostname;
        return h === "www.printful.com" || h.endsWith(".printful.com");
      } catch {
        return false;
      }
    },

    onMessage(e) {
      // 1) Never handle EDM traffic
      if (this.isPrintful(e.origin)) return;

      // 2) Only handle our own channel
      const d = e.data;
      if (!d || d.channel !== "pf-catalog-rpc" || typeof d.type !== "string")
        return;

      // …your custom message handling here (left empty to avoid posting back into EDM)…
    },

    buildExternalId(pid) {
      return `u${this.currentUserId || 0}:p${pid}:s:${
        this.uniqueId || Date.now()
      }`;
    },

    requestEdmNonce(productId, extId) {
      const data = new URLSearchParams();
      data.append("action", "get_edm_token");
      if (productId) data.append("product_id", productId);
      if (extId) data.append("external_product_id", extId);
      if (this.wpNonce) data.append("nonce", this.wpNonce);

      return fetch(this.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: data,
      }).then((r) => r.json());
    },

    nukeOverlays() {
      document
        .querySelectorAll(
          "#printful-edm-container .overlay, #printful-edm-container .edm-loading"
        )
        .forEach((el) => el.remove());
    },

    async saveDesignToServer({ templateId, externalId, designName }) {
      const body = new URLSearchParams({
        action: "printful_save_template",
        nonce: this.wpNonce,
        template_id: templateId,
        external_product_id: externalId,
        product_id: this.productId || "",
        store: this.storeId || "",
        design_name: designName,
        generate_mockup: "1",
        unit_price: this.currentUnitPrice,
        currency: this.currency,
        variant_id: this.state.selectedVariantIds[0] || "",
      });

      return fetch(this.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body,
      }).then((r) => r.json());
    },

    guestSaveTemplate({ templateId, externalId, productId, designName }) {
      const body = new URLSearchParams({
        action: "printful_guest_save_template",
        nonce: this.wpNonce,
        template_id: templateId,
        external_product_id: externalId,
        product_id: productId || "",
        store: this.storeId || "",
        design_name: designName || "Untitled Design",
        unit_price: this.currentUnitPrice,
        currency: this.currency,
        variant_id: this.state.selectedVariantIds[0] || "",
      });
      return fetch(this.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body,
      }).then((r) => r.json());
    },

    // ---- core init as an object method ----
    initializeEDM() {
      if (!this.productId && !this.designId) {
        console.warn("[CatalogEDM] No productId/designId — not initializing.");
        return;
      }

      if (this.designId) {
        this.initEdmForEdit(this.designId, this.storeId);
		return;
      }

	  this.initializeDesignMaker(this.productId, this.storeId);
    },

    initializeDesignMaker(productId, storeId) {
      console.log(productId, storeId);
      try {
        const extId = this.buildExternalId(productId);

        this.requestEdmNonce(productId, extId)
          .then((resp) => {
            const nonce = resp && resp.success ? resp.data?.nonce : resp?.nonce;
            if (!nonce) {
              console.error("EDM nonce not a string:", resp);
              this.showFallbackMessage("Unable to get EDM token.");
              return;
            }

            try {
              // optional mapping if you later need color/size → variant id
              const variantIdByKey = new Map();
              this.variants.forEach((v) => {
                variantIdByKey.set(
                  `${String(v.color || "").toLowerCase()}::${String(
                    v.size || ""
                  ).toLowerCase()}`,
                  v.id
                );
              });

              // PFDesignMaker config (unchanged from your snippet, but inside object)
              window._pf = new PFDesignMaker({
                elemId: "printful-edm-container",
                nonce: nonce,
                externalProductId: extId,
                initProduct: { productId: parseInt(productId || 0, 10) },
                show_unavailability_info: true,
                allowOnlyOneColorToBeSelected: true,
                allowOnlyOneSizeToBeSelected: true,

                onIframeLoaded: () => {
                  this.nukeOverlays();
                  const iframe = document.querySelector(
                    "#printful-edm-container iframe"
                  );
                  if (!iframe) return;
                  iframe.setAttribute("scrolling", "no");
                  iframe.style.width = "100%";
                  iframe.style.height = window.innerHeight + "px";
                },

                onTemplateSaved: (templateId) => {
                  // gate to prevent "save on load"
                  if (!this.userInitiatedSave) {
                    console.debug("[gate] Ignoring early onTemplateSaved");
                    return;
                  }
                  this.userInitiatedSave = false;

                  const nameInput =
                    document.getElementById(`design-name-${this.uniqueId}`) ||
                    document.getElementById("design-name-input");
                  const designName =
                    nameInput && nameInput.value
                      ? nameInput.value
                      : "Untitled Design";

                  this.saveDesignToServer({
                    templateId,
                    externalId: extId,
                    designName,
                  })
                    .then(({ success, data }) => {
                      if (!success && data?.error === "auth_required") {
                        this.guestSaveTemplate({
                          templateId,
                          externalId: extId,
                          productId,
                          designName,
                        })
                          .then(({ success: gOk, data: g }) => {
                            const target =
                              gOk && g?.redirect
                                ? g.redirect
                                : data && data.redirect;
                            if (target) window.location.assign(target);
                          })
                          .catch(() => {
                            if (data && data.redirect)
                              window.location.assign(data.redirect);
                          });
                        return;
                      }
                      if (!success) {
                        alert(
                          (data && (data.message || data.error)) ||
                            "Could not save your design."
                        );
                        return;
                      }
                      if (data?.redirect) location.assign(data.redirect);
                    })
                    .catch((err) => {
                      console.error("Save AJAX error", err);
                      alert("Could not save your design. Please try again.");
                    });
                },

                onDesignStatusUpdate: ({
                  selectedVariantIds = [],
                  usedPlacements = [],
                }) => {
                  this.state.selectedVariantIds = selectedVariantIds;
                  this.state.usedPlacements = usedPlacements;
                },

                onPricingStatusUpdate: (variantPriceList = []) => {
                  if (!variantPriceList.length) return;
                  const price = Number(variantPriceList[0].price || 0);
                  this.currentUnitPrice = price.toFixed(2);
                  const priceEl = document.querySelector("#pf-live-price");
                  if (priceEl)
                    priceEl.textContent = this.money(price, this.currency);
                },

                onError: (e) => {
                  console.error("EDM error:", e);
                  alert("Design Maker error: " + e);
                },

                livePricingConfig: {
                  useLivePricing: true,
                  useAccountBasedPricing: false,
                  showPricesInPlacementsTabs: true,
                  livePricingCurrency: this.currency,
                },
                debug: true,
              });
            } catch (e) {
              console.error(e);
              this.showFallbackMessage("Unable to initialize design maker.");
            }
          })
          .catch((err) => {
            console.error(err);
            this.showFallbackMessage("Nonce request failed.");
          });
      } catch (error) {
        console.error(error);
        this.showFallbackMessage("Unable to initialize design maker.");
      }
    },

	async initEdmForEdit(designId, storeId) {
		let templateId = this.designId;
		let productId  = this.productId;
		let extId      = this.externalId;
	    
		if (!templateId) {
		  alert("No template associated with this design.");
		  return;
		}
		if (!extId) {
		  console.error("[CatalogEDM] Missing external_product_id for re-edit");
		  alert("Cannot re-open design: missing external_product_id.");
		  return;
		}
	  
		try {
		  const resp  = await this.requestEdmNonce(productId, extId);
		  const nonce = resp && resp.success ? resp.data?.nonce : resp?.nonce;
	  
		  if (!nonce || typeof nonce !== "string") {
			console.error("EDM nonce error for edit mode:", resp);
			this.showFallbackMessage("Could not initialize the designer (token).");
			return;
		  }
	  
		  // IMPORTANT: Do NOT pass initProduct for edit mode
		  window._pf = new PFDesignMaker({
			elemId: "printful-edm-container",
			nonce: nonce,
			externalProductId: extId,
	  
			show_unavailability_info: true,
			allowOnlyOneColorToBeSelected: true,
			allowOnlyOneSizeToBeSelected: true,
	  
			onIframeLoaded: () => {
			  this.nukeOverlays();
			  const iframe = document.querySelector("#printful-edm-container iframe");
			  if (!iframe) return;
			  iframe.setAttribute("scrolling", "no");
			  iframe.style.width  = "100%";
			  iframe.style.height = window.innerHeight + "px";
			},
	  
			onTemplateSaved: (savedTemplateId) => {
			  if (!this.userInitiatedSave) {
				console.debug("[gate] Ignoring early onTemplateSaved (edit mode)");
				return;
			  }
			  this.userInitiatedSave = false;
	  
			  const nameInput  = document.getElementById(`design-name-${this.uniqueId}`) || document.getElementById("design-name-input");
			  const designName = (nameInput && nameInput.value) ? nameInput.value : "Untitled Design";
	  
			  this.saveDesignToServer({
				templateId: savedTemplateId || templateId,
				externalId: extId,
				designName,
			  })
			  .then(({ success, data }) => {
				if (!success && data?.error === "auth_required") {
				  this.guestSaveTemplate({
					templateId: savedTemplateId || templateId,
					externalId: extId,
					productId,
					designName,
				  })
				  .then(({ success: gOk, data: g }) => {
					const target = (gOk && g?.redirect) ? g.redirect : (data && data.redirect);
					if (target) window.location.assign(target);
				  })
				  .catch(() => {
					if (data && data.redirect) window.location.assign(data.redirect);
				  });
				  return;
				}
	  
				if (!success) {
				  alert((data && (data.message || data.error)) || "Could not save your design.");
				  return;
				}
	  
				if (data?.redirect) location.assign(data.redirect);
			  })
			  .catch((err) => {
				console.error("Save AJAX error (edit mode)", err);
				alert("Could not save your design. Please try again.");
			  });
			},
	  
			onDesignStatusUpdate: ({ selectedVariantIds = [], usedPlacements = [] }) => {
			  this.state.selectedVariantIds = selectedVariantIds;
			  this.state.usedPlacements     = usedPlacements;
			},
	  
			onPricingStatusUpdate: (variantPriceList = []) => {
			  if (!variantPriceList.length) return;
			  const price = Number(variantPriceList[0].price || 0);
			  this.currentUnitPrice = price.toFixed(2);
			  const priceEl = document.querySelector("#pf-live-price");
			  if (priceEl) priceEl.textContent = this.money(price, this.currency);
			},
	  
			onError: (e) => {
			  console.error("EDM error (edit mode):", e);
			  alert("Design Maker error: " + e);
			},
	  
			livePricingConfig: {
			  useLivePricing: true,
			  useAccountBasedPricing: false,
			  showPricesInPlacementsTabs: true,
			  livePricingCurrency: this.currency,
			},
			debug: true,
		  });
		} catch (error) {
		  console.error(error);
		  this.showFallbackMessage("Unable to initialize design maker (edit mode).");
		}	
	},

    // ---- UI & misc ----
    bindButtons() {
      // Save button
      const saveBtn = document.getElementById("edm-save-btn");
      if (saveBtn) {
        saveBtn.addEventListener("click", (e) => {
          e.preventDefault();
          this.userInitiatedSave = true;
          if (window._pf?.sendMessage) {
            window._pf.sendMessage({ event: "saveDesign" });
          }
        });
      }

      // Add to cart
      const atcBtn = document.getElementById("edm-add-to-cart-btn");
      if (atcBtn) {
        atcBtn.addEventListener("click", (e) => {
          e.preventDefault();
          this.handleAddToCart({ productId: this.productId });
        });
      }
    },

    handleAddToCart(cartData) {
      const nameInput =
        document.getElementById(`design-name-${this.uniqueId}`) ||
        document.getElementById("design-name-input");
      const designName =
        nameInput && nameInput.value ? nameInput.value : "My Design";

      const params = new URLSearchParams({
        action: this.addToCartAction,
        nonce: this.cartNonce || this.wpNonce,
        product_id: String(cartData.productId || this.productId),
        external_product_id: "", // you can stash extId if you need it
        template_id: "",
        design_name: designName,
        unit_price: this.currentUnitPrice || "",
        currency: this.currency,
        variant_id: this.state.selectedVariantIds[0] || "",
      });

      fetch(this.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: params,
      })
        .then((r) => r.json())
        .then((res) => {
          if (!res || res.success !== true) {
            const d = (res && res.data) || {};
            if (d.error === "auth_required") {
              alert(d.message || "Please log in to add this to your cart.");
              if (d.redirect) window.location.assign(d.redirect);
              return;
            }
            console.error("Add to cart failed", res);
            alert("Could not add to cart.");
            return;
          }
          if (res.data && res.data.redirect)
            window.location.assign(res.data.redirect);
        })
        .catch((err) => {
          console.error("Add to cart error", err);
          alert("Could not add to cart.");
        });
    },

    updatePriceUI() {
      // (kept from your snippet; not strictly needed since we update in onPricingStatusUpdate)
      const selected = this.state.selectedVariantIds.length
        ? this.state.selectedVariantIds
        : [...this.pricingByVariantId.keys()];
      let min = Infinity,
        max = 0,
        currency = this.currency,
        first = null;

      for (const vid of selected) {
        const info = this.pricingByVariantId.get(vid);
        if (!info) continue;
        currency = info.currency || currency;
        min = Math.min(min, info.effective);
        max = Math.max(max, info.effective);
        if (!first) first = info;
      }
      if (!isFinite(min)) return;

      const label =
        min === max
          ? this.money(min, currency)
          : `${this.money(min, currency)} – ${this.money(max, currency)}`;
      const priceEl = document.querySelector("#pf-live-price");
      if (priceEl) priceEl.textContent = "From: " + label;

      const unit = first?.effective ?? min;
      const btn = document.querySelector("#pf-add-to-cart");
      if (btn) {
        btn.dataset.unitPrice = unit.toFixed(2);
        btn.dataset.currency = currency;
      }
    },

    money(n, ccy) {
      return new Intl.NumberFormat(undefined, {
        style: "currency",
        currency: ccy,
      }).format(n);
    },

    showFallbackMessage(message) {
      const containerId = `${this.uniqueId}-container`;
      const host =
        document.getElementById(containerId) ||
        document.getElementById("printful-edm-container");
      if (host) {
        host.innerHTML =
          '<div class="edm-fallback"><p>' + message + "</p></div>";
      }
    },
  };

  // expose globally
  window.PrintfulCatalogEDM = CatalogEDM;

  // auto-init if root exists
  document.addEventListener("DOMContentLoaded", function () {
    // const root = document.getElementById('design-maker-container');
    // if (root)
    window.PrintfulCatalogEDM.init();
  });
})();
