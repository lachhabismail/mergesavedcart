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

namespace MergeSavedCart\Service;

use Cart;
use Context;
use Db;
use Module;
use NwTld;
use Product;
use Tools;
use Validate;

/**
 * Centralizes the two separate steps of the restoration flow: finding an
 * abandoned cart's id right after login (cheap, id-only), and later, at
 * display time, turning a known abandoned cart id into the eligible-products
 * diff against the customer's current cart.
 */
class AbandonedCartFinder
{
    /**
     * Called once, right after login: most recent, not-yet-ordered cart
     * belonging to the customer, other than their current cart, that still
     * holds at least one product line. Returns only the id — no product data
     * is computed here, since the current cart can still change before the
     * customer ever sees the modal.
     *
     * When `nw_multidomainmanager` is enabled, this shop is really several
     * independent storefronts ("TLDs") sharing one PrestaShop install — a
     * cart started on one domain must never be proposed for restoration on
     * another, since product availability/pricing differ per TLD. The
     * candidate cart is therefore joined against `nw_order` (the module's
     * cart→TLD association table) and restricted to the current request's
     * TLD. If the module is active but the current request has no resolved
     * TLD, we can't safely vouch for any cart, so nothing is proposed.
     *
     * @param int $idCustomer
     * @param int $idCurrentCart
     *
     * @return int|null
     */
    public function findAbandonedCartId(int $idCustomer, int $idCurrentCart): ?int
    {
        $tldJoin = '';

        if ($this->isMultidomainManagerActive()) {
            $idTld = $this->getCurrentTldId();

            if ($idTld === null) {
                return null;
            }

            $tldJoin = ' INNER JOIN `' . _DB_PREFIX_ . 'nw_order` nwo ON nwo.`id_cart` = c.`id_cart` AND nwo.`id_nw_tld` = ' . $idTld;
        }

        $sql = 'SELECT c.`id_cart`
                FROM `' . _DB_PREFIX_ . 'cart` c
                INNER JOIN `' . _DB_PREFIX_ . 'cart_product` cp ON cp.`id_cart` = c.`id_cart`'
                . $tldJoin . '
                WHERE c.`id_customer` = ' . (int) $idCustomer . '
                    AND c.`id_cart` != ' . (int) $idCurrentCart . '
                    AND NOT EXISTS (
                        SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`id_cart` = c.`id_cart`
                    )
                GROUP BY c.`id_cart`
                ORDER BY c.`date_upd` DESC';

        $idCart = Db::getInstance()->getValue($sql);

        return $idCart ? (int) $idCart : null;
    }

    /**
     * Called at display time, from the abandoned cart id stashed in the
     * cookie by findAbandonedCartId(): validates that cart still qualifies
     * (right owner, no order since, still has products), then diffs its
     * product lines against the current cart **by id_product** — a product
     * already present in the current cart in any variant is excluded
     * entirely. Returns null if the cart no longer qualifies.
     *
     * @param int $idCustomer
     * @param int $idAbandonedCart
     * @param int $idCurrentCart
     *
     * @return array{id_abandoned_cart:int, products:array<int, array{id_product:int, id_product_attribute:int, quantity:int}>}|null
     */
    public function findEligibleProducts(int $idCustomer, int $idAbandonedCart, int $idCurrentCart): ?array
    {
        if ($idAbandonedCart === $idCurrentCart || !$this->isValidAbandonedCart($idCustomer, $idAbandonedCart)) {
            return null;
        }

        $currentCart = new Cart($idCurrentCart);
        $currentProductIds = array_map(
            function (array $product): int {
                return (int) $product['id_product'];
            },
            $currentCart->getProducts(true)
        );

        $abandonedCart = new Cart($idAbandonedCart);
        $eligible = [];

        foreach ($abandonedCart->getProducts(true) as $product) {
            if (in_array((int) $product['id_product'], $currentProductIds, true)) {
                continue;
            }

            $eligible[] = [
                'id_product' => (int) $product['id_product'],
                'id_product_attribute' => (int) $product['id_product_attribute'],
                'quantity' => (int) $product['cart_quantity'],
            ];
        }

        return [
            'id_abandoned_cart' => $idAbandonedCart,
            'products' => $eligible,
        ];
    }

    /**
     * @param int $idCustomer
     * @param int $idAbandonedCart
     *
     * @return bool
     */
    private function isValidAbandonedCart(int $idCustomer, int $idAbandonedCart): bool
    {
        $cart = new Cart($idAbandonedCart);

        if (!Validate::isLoadedObject($cart)
            || (int) $cart->id_customer !== $idCustomer
            || $cart->orderExists()
            || count($cart->getProducts(true)) === 0
        ) {
            return false;
        }

        return !$this->isMultidomainManagerActive() || $this->isSameTld($idAbandonedCart);
    }

    /**
     * True when the multi-storefront module is active and its TLD entity is
     * autoloadable — mirrors the guard convention already used by
     * wwwnutriconnect (`Module::isEnabled('nw_multidomainmanager')`) before
     * touching any `NwTld` API.
     *
     * @return bool
     */
    private function isMultidomainManagerActive(): bool
    {
        return Module::isEnabled('nw_multidomainmanager');
    }

    /**
     * The current request's active TLD id (`Context::getContext()->shop->nwTld->id`),
     * or null if no TLD resolved for this host (unmapped domain, back
     * office, CLI...). Callers must not treat a null result as "no
     * restriction" — it means the TLD is unknown, not universal.
     *
     * @return int|null
     */
    private function getCurrentTldId(): ?int
    {
        $shop = Context::getContext()->shop;

        if (isset($shop->nwTld) && Validate::isLoadedObject($shop->nwTld)) {
            return (int) $shop->nwTld->id;
        }

        return null;
    }

    /**
     * Whether $idCart is tagged (via nw_multidomainmanager's `nw_order`
     * table) with the same TLD as the current request. A cart with no TLD
     * association at all (never went through hookActionCartSave, e.g. a
     * pre-module or back-office cart) does not match — null never equals a
     * resolved TLD id, even if the current request's TLD also happened to
     * be unresolved.
     *
     * @param int $idCart
     *
     * @return bool
     */
    private function isSameTld(int $idCart): bool
    {
        $idTld = $this->getCurrentTldId();

        if ($idTld === null) {
            return false;
        }

        $cartTld = NwTld::getInstanceByCart($idCart);

        return $cartTld !== null && (int) $cartTld->id === $idTld;
    }

    /**
     * Deletes the abandoned cart once the customer has acted on the
     * restoration proposal (accepted or dismissed), so it isn't offered again
     * on a future login. Refuses if the cart doesn't belong to the customer,
     * or already has an order (Cart::delete()'s own safety check).
     *
     * @param int $idCustomer
     * @param int $idAbandonedCart
     *
     * @return bool
     */
    public function deleteAbandonedCart(int $idCustomer, int $idAbandonedCart): bool
    {
        $cart = new Cart($idAbandonedCart);

        if (!Validate::isLoadedObject($cart) || (int) $cart->id_customer !== $idCustomer) {
            return false;
        }

        return $cart->delete();
    }

    /**
     * Hydrates a slim product list with the name/price/image data the modal
     * template needs.
     *
     * @param array<int, array{id_product:int, id_product_attribute:int, quantity:int}> $products
     *
     * @return array<int, array>
     */
    public function presentProducts(array $products): array
    {
        $context = Context::getContext();
        $presented = [];

        foreach ($products as $productData) {
            $product = new Product((int) $productData['id_product'], false, $context->language->id);

            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            $idProductAttribute = (int) $productData['id_product_attribute'];
            $combinationImage = $idProductAttribute
                ? Product::getCombinationImageById($idProductAttribute, $context->language->id)
                : false;
            $cover = $combinationImage ?: Product::getCover((int) $productData['id_product']);
            $imageUrl = $cover
                ? $context->link->getImageLink($product->link_rewrite, $cover['id_image'], 'cart_default')
                : '';

            $quantity = (int) $productData['quantity'];
            $unitPrice = Product::getPriceStatic(
                (int) $productData['id_product'],
                true,
                $idProductAttribute ?: null
            );

            // Pre-formatted here, not in the template: core templates (e.g.
            // cart-detailed-product-line.tpl) always print an already-formatted
            // price string — there is no plain "Tools::displayPrice()" static
            // method callable from Smarty, only the Smarty-plugin-specific
            // Tools::displayPriceSmarty(), so formatting happens on this side.
            $locale = Tools::getContextLocale($context);

            $presented[] = [
                'id_product' => (int) $productData['id_product'],
                'id_product_attribute' => $idProductAttribute,
                'name' => $product->name,
                'combination' => $this->getCombinationLabel($idProductAttribute, $context->language->id),
                'quantity' => $quantity,
                'unit_price' => $locale->formatPrice($unitPrice, $context->currency->iso_code),
                'total_price' => $locale->formatPrice($unitPrice * $quantity, $context->currency->iso_code),
                // Raw numeric price (tax included, same basis as unit_price above),
                // never rendered in the template — it only feeds the JS-side
                // add_to_cart GTM event's item.price/ecommerce.value, which needs a
                // plain float rather than the locale-formatted display strings above.
                'price' => round($unitPrice, 2),
                'image_url' => $imageUrl,
            ];
        }

        return $presented;
    }

    /**
     * "30g • Chocolate"-style label for a combination's attribute values.
     * Not reused from Cart::getProducts()'s own 'attributes_small' field on
     * purpose: that one joins values with PS_ATTRIBUTE_ANCHOR_SEPARATOR (a
     * shop-configurable SEO anchor separator, "-" by default), not "•".
     *
     * @param int $idProductAttribute
     * @param int $idLang
     *
     * @return string
     */
    private function getCombinationLabel(int $idProductAttribute, int $idLang): string
    {
        if (!$idProductAttribute) {
            return '';
        }

        $attributeNames = Db::getInstance()->executeS(
            'SELECT al.`name`
             FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
             INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
             INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . (int) $idLang . '
             INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`
             WHERE pac.`id_product_attribute` = ' . (int) $idProductAttribute . '
             ORDER BY ag.`position` ASC, a.`position` ASC'
        );

        if (empty($attributeNames)) {
            return '';
        }

        return implode(' • ', array_column($attributeNames, 'name'));
    }
}
