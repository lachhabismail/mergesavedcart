<?php
/**
 * Copyright since 2026 Nutriweb
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    Nutriweb
 * @copyright Since 2026 Nutriweb
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use MergeSavedCart\Service\AbandonedCartFinder;

/**
 * Handles the two actions the restoration modal can trigger: adding the
 * selected products to the current cart, or dismissing the proposal. Both
 * are logged lightly (no sensitive data, only ids/counts) for follow-up, and
 * both delete the abandoned cart afterwards so it isn't proposed again.
 */
class MergeSavedCartRestoreModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    /**
     * @return void
     */
    public function postProcess()
    {
        $idCustomer = $this->context->customer->isLogged() ? (int) $this->context->customer->id : 0;

        if (!$idCustomer) {
            $this->ajaxRender(json_encode(['success' => false]));

            return;
        }

        $action = Tools::getValue('action');

        if ($action === 'add') {
            $this->processAdd($idCustomer);
        } elseif ($action === 'dismiss') {
            $this->processDismiss($idCustomer);
        } else {
            $this->ajaxRender(json_encode(['success' => false]));
        }
    }

    /**
     * @param int $idCustomer
     *
     * @return void
     */
    private function processAdd(int $idCustomer)
    {
        $this->ensureValidCart($idCustomer);

        $products = json_decode(Tools::getValue('products'), true);
        $products = is_array($products) ? $products : [];

        $added = 0;

        foreach ($products as $product) {
            $idProduct = (int) ($product['id_product'] ?? 0);
            $idProductAttribute = (int) ($product['id_product_attribute'] ?? 0);
            $quantity = (int) ($product['quantity'] ?? 0);

            if ($idProduct <= 0 || $quantity <= 0) {
                continue;
            }

            if ($this->context->cart->updateQty($quantity, $idProduct, $idProductAttribute ?: null) !== false) {
                ++$added;
            }
        }

        PrestaShopLogger::addLog(
            sprintf('MergeSavedCart: customer #%d imported %d product(s) from an abandoned cart.', $idCustomer, $added),
            1,
            null,
            'Cart',
            (int) $this->context->cart->id
        );

        $this->deleteAbandonedCart($idCustomer);

        $this->ajaxRender(json_encode(['success' => true, 'added' => $added]));
    }

    /**
     * Mirrors PrestaShop's own fallback for when no valid cart is available
     * in context, gated by `Validate::isLoadedObject()` throughout (the same
     * check core itself uses — `is_object($object) && $object->id`, so it
     * covers both "not even a Cart instance" and "a Cart with no id yet" in
     * one guard, checked once up front and once again after `->add()`),
     * matching two distinct core precedents:
     * - `classes/controller/FrontController.php:440-454` constructs a fresh
     *   in-memory `Cart` when none was resolved from the session/cookie (this
     *   step normally never fires here, since `FrontController::init()` — the
     *   parent of this controller — already runs it before `postProcess()`;
     *   it's a defensive fallback for the case `$this->context->cart` isn't
     *   even a `Cart` instance).
     * - `controllers/front/CartController.php:481-486` persists that cart
     *   (`->add()`) the first time a product is actually added, and syncs the
     *   `id_cart` cookie — this is the realistic case: a cart resolved from
     *   context but never yet saved (no id) only gets one on first save.
     *
     * @param int $idCustomer
     *
     * @return void
     */
    private function ensureValidCart(int $idCustomer)
    {
        if (Validate::isLoadedObject($this->context->cart)) {
            return;
        }

        if (!($this->context->cart instanceof Cart)) {
            $cart = new Cart();
            $cart->id_lang = (int) $this->context->language->id;
            $cart->id_currency = (int) $this->context->currency->id;
            $cart->id_guest = (int) $this->context->cookie->id_guest;
            $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
            $cart->id_shop = $this->context->shop->id;
            $cart->id_customer = $idCustomer;
            $cart->id_address_delivery = (int) Address::getFirstCustomerAddressId($idCustomer);
            $cart->id_address_invoice = $cart->id_address_delivery;
            $this->context->cart = $cart;
        }

        $this->context->cart->add();

        if (Validate::isLoadedObject($this->context->cart)) {
            $this->context->cookie->id_cart = (int) $this->context->cart->id;

            // Same reasoning as deleteAbandonedCart() below: this is an
            // ajax=true controller, so Controller::run() never reaches
            // smartyOutputContent() (the only place Cookie::write() is
            // normally called) — without this explicit call, the new
            // id_cart would never reach the browser, and the next page
            // load would resolve a different (empty) cart again.
            $this->context->cookie->write();
        }
    }

    /**
     * @param int $idCustomer
     *
     * @return void
     */
    private function processDismiss(int $idCustomer)
    {
        PrestaShopLogger::addLog(
            sprintf('MergeSavedCart: customer #%d ignored the abandoned cart restoration proposal.', $idCustomer),
            1,
            null,
            'Cart',
            (int) $this->context->cart->id
        );

        $this->deleteAbandonedCart($idCustomer);

        $this->ajaxRender(json_encode(['success' => true]));
    }

    /**
     * Deletes the abandoned cart the proposal was built from, so it isn't
     * offered again on a future login, and clears the cookie pinning it.
     * Silently no-ops if the id is missing or doesn't belong to the customer.
     *
     * @param int $idCustomer
     *
     * @return void
     */
    private function deleteAbandonedCart(int $idCustomer)
    {
        $idAbandonedCart = (int) $this->context->cookie->{MergeSavedCart::ABANDONED_CART_COOKIE_KEY};

        if ($idAbandonedCart <= 0) {
            return;
        }

        /** @var AbandonedCartFinder $finder */
        $finder = $this->get('mergesavedcart.abandoned_cart_finder');

        if ($finder->deleteAbandonedCart($idCustomer, $idAbandonedCart)) {
            unset($this->context->cookie->{MergeSavedCart::ABANDONED_CART_COOKIE_KEY});

            // This is an ajax=true controller: Controller::run() only calls
            // display()/smartyOutputContent() (where Cookie::write() normally
            // happens) for non-ajax controllers. Without this explicit call,
            // the unset above would never reach the browser.
            $this->context->cookie->write();
        }
    }
}
