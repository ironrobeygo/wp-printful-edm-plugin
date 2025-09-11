<?php
/**
 * Design Maker Page Template
 * This file should be placed in your theme's directory as page-design-product.php
 * or create a page template and assign it to a page with slug 'design-product'
 */

get_header(); ?>

<div class="design-maker-container">
    <div class="design-maker-header">
        <div class="design-info">
            <h1>Design Your Product</h1>
            <input type="text" id="design-name" class="design-name-input" placeholder="Enter design name..." value="My Design">
        </div>
        <div class="design-actions">
            <button id="save-design-draft">Save Draft</button>
            <button id="add-design-to-cart">Add to Cart</button>
        </div>
    </div>
    
    <div id="pf-edm" class="edm-container" style="min-height:640px"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get product ID from URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const productId = urlParams.get('product_id');
    const designId = urlParams.get('design_id');
    
    if (productId) {
        initializeDesignMaker(productId);
    } else if (designId) {
        loadExistingDesign(designId);
    }
    
    function initializeDesignMaker(productId) {
        // Initialize Printful's Embedded Design Maker
        const edmUrl = `https://www.printful.com/a/embed?product=${productId}&mode=design`;
        document.getElementById('printful-edm').src = edmUrl;
        
        // Set up EDM communication
        setupEDMCommunication();
    }
    
    function loadExistingDesign(designId) {
        // Load existing design data
        jQuery.ajax({
            url: printful_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'get_design_data',
                design_id: designId,
                nonce: printful_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    const designData = response.data;
                    document.getElementById('design-name').value = designData.design_name;
                    
                    // Initialize EDM with existing design
                    const edmUrl = `https://www.printful.com/a/embed?product=${designData.product_id}&mode=design`;
                    document.getElementById('printful-edm').src = edmUrl;
                    
                    // Set up communication and load design when EDM is ready
                    setupEDMCommunication(designData.design_data);
                }
            }
        });
    }
    
    function setupEDMCommunication(existingDesignData = null) {
        // Listen for messages from the EDM iframe
        window.addEventListener('message', function(event) {
            if (event.origin !== 'https://www.printful.com') {
                return;
            }
            
            if (event.data.type === 'edm-ready') {
                // EDM is ready, load existing design if available
                if (existingDesignData) {
                    event.source.postMessage({
                        type: 'load-design',
                        data: JSON.parse(existingDesignData)
                    }, event.origin);
                }
            }
            
            if (event.data.type === 'design-updated') {
                // Store the current design state
                window.currentDesignData = event.data.design;
            }
        });
        
        // Override the global functions for the main JavaScript file
        window.printfulDesignMaker = {
            getDesignData: function() {
                return window.currentDesignData || null;
            },
            loadDesign: function(designData) {
                const iframe = document.getElementById('printful-edm');
                if (iframe.contentWindow) {
                    iframe.contentWindow.postMessage({
                        type: 'load-design',
                        data: designData
                    }, 'https://www.printful.com');
                }
            }
        };
    }
});
</script>

<?php get_footer(); ?>