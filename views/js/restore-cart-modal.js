// Registering a 'DOMContentLoaded' listener only works if this script runs
// before that event fires. This file is enqueued via registerJavascript()
// with position 'bottom', but once combined/cached by PrestaShop's CCC
// feature it can end up executing after the event already fired — a
// listener registered for an event that already happened never runs.
// Guarding on document.readyState covers both cases: run immediately if the
// document is already parsed, otherwise wait.
//
// On pages served from Cloudflare's edge cache (cf_smart_cache), this
// hook's HTML isn't even in the page yet when this script runs at all — the
// modal is injected later via cf_smart_cache's refresh.js AJAX call, which
// carries its own duplicated copy of everything below (initMergeSavedCartModal
// in cf_smart_cache/views/js/refresh.js). Any change here must be mirrored
// there too.
function mergesavedcartInit() {
  var modalElement = document.getElementById('mergesavedcart-restore-modal');

  if (!modalElement) {
    return;
  }

  var restoreUrl = modalElement.getAttribute('data-restore-url');
  var idAbandonedCart = modalElement.getAttribute('data-id-abandoned-cart');
  var currency = modalElement.getAttribute('data-currency') || '';
  var productCheckboxes = modalElement.querySelectorAll('.mergesavedcart-product-checkbox');
  var addButton = document.getElementById('mergesavedcart-add-selected');
  var dismissButton = document.getElementById('mergesavedcart-dismiss');

  // Mirrors the gtm module's own event_id shape (event + timestamp + random
  // token) closely enough for consistency, without reaching into that
  // module's JS — datalayer.js keeps its push()/makeEventId() private inside
  // its own IIFE, so there is no shared function to call. Every other module
  // in this ecosystem that emits GTM events pushes directly onto the shared
  // window.dataLayer following the same conventions instead of a shared API.
  function makeEventId(event) {
    return event + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
  }

  // Prefers the full GA4 item (item_brand, item_variant, external_id,
  // categories...) that AbandonedCartFinder::presentProducts() builds via the
  // gtm module's own GtmDataLayer::mapItem() when that module is active —
  // falls back to a bare item_id/item_name/price/quantity when it isn't (or
  // if the JSON is somehow malformed), so this still works without gtm.
  function buildGtmItem(checkbox, idProduct, idProductAttribute, quantity) {
    var raw = checkbox.getAttribute('data-gtm-item');
    if (raw) {
      try {
        var item = JSON.parse(raw);
        item.quantity = quantity;
        return item;
      } catch (e) { /* fall through to the plain item below */ }
    }
    return {
      item_id: idProduct + (idProductAttribute ? '-' + idProductAttribute : ''),
      item_name: checkbox.getAttribute('data-name') || '',
      price: parseFloat(checkbox.getAttribute('data-price')) || 0,
      quantity: quantity,
    };
  }

  // Fires a standard GA4 add_to_cart event for the items the customer chose
  // to keep from the saved cart. Only called after restore.php confirms the
  // add actually succeeded (see addButton handler below) — never optimistically
  // on click, since a failed/aborted request must not report a phantom add.
  function pushAddToCart(items) {
    if (!items.length) {
      return;
    }

    var value = 0;
    for (var i = 0; i < items.length; i++) {
      value += items[i].price * items[i].quantity;
    }
    value = Math.round(value * 100) / 100;

    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ ecommerce: null });

    var evt = {
      event: 'add_to_cart',
      event_id: makeEventId('add_to_cart'),
      ecommerce: { currency: currency, value: value, items: items },
    };
    if (window.gtmUserId) {
      evt.user_id = String(window.gtmUserId);
    }
    window.dataLayer.push(evt);
  }

  // No global `bootstrap`/Modal class is available to this classically-loaded
  // script (the theme only ever imports Bootstrap as an ES module inside its
  // own Vite bundle, e.g. quickview.ts), so this can't call Modal.show()
  // directly the way quickview.ts does. Instead: briefly give the modal
  // data-bs-toggle="modal" data-bs-target="#self" (self-referencing) so a
  // dispatched click is caught by Bootstrap's own document-level data-api
  // listener — the same native mechanism already handling the
  // data-bs-dismiss buttons below — then immediately remove both attributes.
  // They must not stay: every click *inside* the open modal (the footer
  // buttons, anything) bubbles up through this same element, which would
  // otherwise keep matching the selector and re-toggle (close) the modal on
  // any interaction. dispatchEvent() is synchronous, so removing right after
  // guarantees no other click can land in between.
  modalElement.setAttribute('data-bs-toggle', 'modal');
  modalElement.setAttribute('data-bs-target', '#' + modalElement.id);
  modalElement.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  modalElement.removeAttribute('data-bs-toggle');
  modalElement.removeAttribute('data-bs-target');

  function postAction(action, extraFields) {
    var body = new URLSearchParams(Object.assign({ action: action, id_abandoned_cart: idAbandonedCart }, extraFields));

    // credentials/X-Requested-With match themes/custom/src/js/quickview.ts's own
    // fetch() call — without explicit credentials, this shop's multiple shop
    // URLs/ports (mymuscle.prod:8888, .fr:8888, .it:8888) risk the request
    // landing on a session/cart the browser didn't actually send its cookie
    // for, silently operating on the wrong (or a freshly created) cart.
    return fetch(restoreUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      body: body.toString(),
      credentials: 'same-origin',
    });
  }

  addButton.addEventListener('click', function () {
    var selectedProducts = [];
    var gtmItems = [];

    productCheckboxes.forEach(function (checkbox) {
      if (checkbox.checked) {
        var idProduct = parseInt(checkbox.getAttribute('data-id-product'), 10);
        var idProductAttribute = parseInt(checkbox.getAttribute('data-id-product-attribute'), 10);
        var quantity = parseInt(checkbox.getAttribute('data-quantity'), 10);

        selectedProducts.push({
          id_product: idProduct,
          id_product_attribute: idProductAttribute,
          quantity: quantity,
        });

        gtmItems.push(buildGtmItem(checkbox, idProduct, idProductAttribute, quantity));
      }
    });

    postAction('add', { products: JSON.stringify(selectedProducts) }).then(function (response) {
      return response.json().catch(function () {
        return null;
      }).then(function (data) {
        // Only report the event once the server confirms the add actually
        // happened — matches the ecosystem-wide rule of never emitting a
        // GTM event from an optimistic/assumed-successful client action.
        if (data && data.success) {
          pushAddToCart(gtmItems);
        }
      });
    }).then(function () {
      window.location.reload();
    });
  });

  dismissButton.addEventListener('click', function () {
    postAction('dismiss', {});
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mergesavedcartInit);
} else {
  mergesavedcartInit();
}
