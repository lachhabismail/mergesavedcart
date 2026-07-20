{if !empty($mergesavedcart_products) || !isset($is_cachable_page) || (isset($is_cachable_page) && !$is_cachable_page)}
<div class="modal fade"
     id="mergesavedcart-restore-modal"
     tabindex="-1"
     aria-labelledby="mergesavedcart-restore-modal-label"
     aria-hidden="true"
     data-restore-url="{$mergesavedcart_restore_url}"
     data-bs-backdrop="static"
     data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header pb-2">
        <h2 class="modal-title" id="mergesavedcart-restore-modal-label">{l s='You have a saved cart' d='Modules.Mergesavedcart.Shop'}</h2>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3">
          {l s='Add these items back to your cart, or ignore them and keep shopping.' d='Modules.Mergesavedcart.Shop'}
        </p>
        <ul class="list-group list-group-flush"{if $mergesavedcart_products|@count > 4} style="max-height: 430px; overflow-y: auto;"{/if}>
          {foreach from=$mergesavedcart_products item=product}
            <li class="list-group-item d-flex align-items-center gap-3 py-3">
              <input class="form-check-input flex-shrink-0 mergesavedcart-product-checkbox"
                     type="checkbox"
                     checked
                     data-id-product="{$product.id_product}"
                     data-id-product-attribute="{$product.id_product_attribute}"
                     data-quantity="{$product.quantity}">
              {if $product.image_url}
                <div class="position-relative flex-shrink-0">
                  <img src="{$product.image_url}" alt="{$product.name|escape:'quotes'}" width="64" height="64">
                  <span class="badge badge-quantity sm">{$product.quantity}</span>
                </div>
              {/if}
              <div class="flex-grow-1">
                  {$product.name}
                  {if $product.combination}
                    <div class="text-muted small">{$product.combination}</div>
                  {/if}
              </div>
            </li>
          {/foreach}
        </ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="mergesavedcart-add-selected">
          {l s='Add to cart' d='Modules.Mergesavedcart.Shop'}
        </button>
        <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal" id="mergesavedcart-dismiss">
          {l s='No, thanks' d='Modules.Mergesavedcart.Shop'}
        </button>
      </div>
    </div>
  </div>
</div>
{/if}
