/*!
 * PF Claim Draft — auto-claim ?pf_draft=TOKEN after login
 * Expects window.printful_ajax = { ajax_url, nonce } to be localized.
 */
(function(){
  const params = new URLSearchParams(location.search);
  const token  = params.get('pf_draft');
  if (!token) return;

  const ajaxUrl = (window.printful_ajax && window.printful_ajax.ajax_url) || (window.ajaxurl || '/wp-admin/admin-ajax.php');
  const wpNonce = (window.printful_ajax && window.printful_ajax.nonce) || '';
  const body = new URLSearchParams({ action: 'printful_claim_draft', nonce: wpNonce, token });

  fetch(ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body
  })
  .then(r => r.json())
  .then(res => {
    if (res && res.success && res.data && res.data.redirect) {
      // Optional: show a toast/notice here before redirecting
      window.location.assign(res.data.redirect);
    }
  })
  .catch(() => { /* no-op */ });
})();
