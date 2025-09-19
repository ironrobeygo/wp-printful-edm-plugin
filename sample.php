	<div id="printful-catalog"
		class="printful-catalog-container"
		data-selected-ids="<?php echo esc_attr(implode(',', $selected_ids)); ?>"
		data-category-ids="<?php echo esc_attr($show_all_csv); ?>">
		<div class="pf-filter-row">
			<label for="pf-category-select" class="pf-filter-label">Filter:</label>
			<select id="pf-category-select"
				class="pf-filter-select"
				data-show-all="<?php echo esc_attr($show_all_csv); ?>">
				<option value="<?php echo esc_attr($show_all_csv); ?>"
					<?php selected($current_csv, $show_all_csv); ?>>
					Show All
				</option>

				<?php foreach ($grouped as $groupLabel => $items): ?>
					<optgroup label="<?php echo esc_attr($groupLabel); ?>">
						<?php foreach ($items as $it): ?>
							<option value="<?php echo esc_attr((string)$it['id']); ?>"
								<?php selected($current_csv, (string)$it['id']); ?>>
								<?php echo esc_html($it['name']); ?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="pfc-topbar">

			<div class="pfc-filters-left">
				<div class="pfc-dropdown" data-filter="category">
					<button>Categories ▾</button>
					<div class="pfc-dropdown-panel">
						<label>
							<input type="radio" name="category_id"
								value="<?php echo esc_attr($show_all_csv); ?>"
								<?php checked($current_csv, $show_all_csv); ?>>
							Show All
						</label>
						<!-- Dynamic groups/options (same data as your top select) -->
						<?php foreach ($grouped as $groupLabel => $items): ?>
							<strong class="pfc-subgroup"><?php echo esc_html($groupLabel); ?></strong>
							<?php foreach ($items as $it): ?>
								<label>
									<input type="radio" name="category_id"
										value="<?php echo esc_attr((string)$it['id']); ?>"
										<?php checked($current_csv, (string)$it['id']); ?>>
									<?php echo esc_html($it['name']); ?>
								</label>
							<?php endforeach; ?>
						<?php endforeach; ?>
						<a href="#" data-clear="category">Clear</a>
					</div>
				</div>

				<div class="pfc-dropdown" data-filter="technique">
					<button>Technique ▾</button>
					<div class="pfc-dropdown-panel">
						<?php foreach ($__pfc_filters['technique'] as $o): ?>
							<label><input type="checkbox" name="technique[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
						<?php endforeach; ?>
						<a href="#" data-clear="technique">Clear</a>
					</div>
				</div>

				<div class="pfc-dropdown" data-filter="color">
					<button>Color ▾</button>
					<div class="pfc-dropdown-panel">
						<?php foreach ($__pfc_filters['color'] as $o): ?>
							<label><input type="checkbox" name="color[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
						<?php endforeach; ?>
						<a href="#" data-clear="color">Clear</a>
					</div>
				</div>

				<div class="pfc-dropdown" data-filter="branding_options">
					<button>Branding options ▾</button>
					<div class="pfc-dropdown-panel">
						<?php foreach ($__pfc_filters['branding_options'] as $o): ?>
							<label><input type="checkbox" name="branding_options[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
						<?php endforeach; ?>
						<a href="#" data-clear="branding_options">Clear</a>
					</div>
				</div>

				<div class="pfc-dropdown" data-filter="sizes">
					<button>Sizes ▾</button>
					<div class="pfc-dropdown-panel">
						<?php foreach ($__pfc_filters['sizes'] as $o): ?>
							<label><input type="checkbox" name="sizes[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
						<?php endforeach; ?>
						<a href="#" data-clear="sizes">Clear</a>
					</div>
				</div>

				<div class="pfc-dropdown" data-filter="all">
					<button>All filters ▾</button>
					<div class="pfc-dropdown-panel">
						<strong>Material</strong>
						<?php foreach ($__pfc_filters['material'] as $o): ?>
							<label><input type="checkbox" name="material[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
						<?php endforeach; ?>

						<strong>Models</strong>
						<?php foreach ($__pfc_filters['models'] as $o): ?>
							<label><input type="checkbox" name="models[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
						<?php endforeach; ?>

						<strong>More</strong>
						<?php foreach ($__pfc_filters['flags'] as $o): ?>
							<label><input type="checkbox" name="flags[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<div class="pfc-filters-right">
				<div class="pfc-dropdown" data-sort>
					<button>Most popular ▾</button>
					<div class="pfc-dropdown-panel">
						<label><input type="radio" name="sort" value="popular" checked> Most popular</label>
						<label><input type="radio" name="sort" value="price_low"> Price: Low → High</label>
						<label><input type="radio" name="sort" value="price_high"> Price: High → Low</label>
					</div>
				</div>
			</div>
		</div>


		<div id="pf-products-grid" class="products-grid">
			<?php foreach ($products as $product): ?>
				<div class="product-card" data-product-id="<?php echo esc_attr($product['id']); ?>">
					<div class="product-image">
						<img src="<?php echo esc_url($product['image']); ?>"
							alt="<?php echo esc_attr($product['title']); ?>"
							onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPk5vIEltYWdlPC90ZXh0Pjwvc3ZnPg=='" />
					</div>
					<div class="product-info">
						<h3 class="product-title"><?php echo esc_html($product['title']); ?></h3>
						<p class="product-id">ID: <?php echo esc_html($product['id']); ?></p>
						<p class="product-price">From $<?php echo esc_html(number_format($product['price'], 2)); ?></p>
						<button class="design-button pf-btn pf-btn--primary"
							data-product-id="<?php echo esc_attr($product['id']); ?>"
							data-modal="<?php echo $use_modal ? 'true' : 'false'; ?>">
							Design Product
						</button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div id="pf-catalog-spinner" class="loading-spinner" style="display:none;">Loading…</div>

		<div class="load-more-container">
			<button id="load-more-products"
				class="pf-btn pf-btn--secondary"
				data-offset="<?php echo intval($atts['limit']); ?>"
				data-limit="<?php echo intval($atts['limit']); ?>"
				data-category-ids="<?php echo esc_attr($show_all_csv); ?>">
				Load More Products
			</button>
		</div>
	</div>