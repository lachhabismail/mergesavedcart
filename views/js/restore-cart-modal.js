document.addEventListener('DOMContentLoaded', function () {
  var modalElement = document.getElementById('mergesavedcart-restore-modal');

  if (!modalElement) {
    return;
  }

  var restoreUrl = modalElement.getAttribute('data-restore-url');
  var productCheckboxes = modalElement.querySelectorAll('.mergesavedcart-product-checkbox');
  var addButton = document.getElementById('mergesavedcart-add-selected');
  var dismissButton = document.getElementById('mergesavedcart-dismiss');

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
    var body = new URLSearchParams(Object.assign({ action: action }, extraFields));

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

    productCheckboxes.forEach(function (checkbox) {
      if (checkbox.checked) {
        selectedProducts.push({
          id_product: parseInt(checkbox.getAttribute('data-id-product'), 10),
          id_product_attribute: parseInt(checkbox.getAttribute('data-id-product-attribute'), 10),
          quantity: parseInt(checkbox.getAttribute('data-quantity'), 10),
        });
      }
    });

    postAction('add', { products: JSON.stringify(selectedProducts) }).then(function () {
      window.location.reload();
    });
  });

  dismissButton.addEventListener('click', function () {
    postAction('dismiss', {});
  });
});
