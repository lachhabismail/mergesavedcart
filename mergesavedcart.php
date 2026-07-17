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

class MergeSavedCart extends Module
{
    /**
     * Cookie key holding the abandoned cart's id, set once by
     * hookActionAuthentication() right after login and read by
     * hookDisplayModalContent()/hookActionFrontControllerSetMedia() on every
     * subsequent page view until the customer acts on the proposal (see
     * restore.php, which clears it via AbandonedCartFinder::deleteAbandonedCart()).
     */
    const ABANDONED_CART_COOKIE_KEY = 'mergesavedcartAbandonedCartId';

    public function __construct()
    {
        $this->name = 'mergesavedcart';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Nutriweb';
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Merge Saved Cart', [], 'Modules.Mergesavedcart.Admin');
        $this->description = $this->trans('Proposes restoring an abandoned cart\'s products when a customer logs in.', [], 'Modules.Mergesavedcart.Admin');

        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
    }

    /**
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && $this->registerHook('actionAuthentication')
            && $this->registerHook('displayModalContent')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionCustomerPreferencesPageSave');
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        return parent::uninstall();
    }

    /**
     * Right after login: looks up whether the customer has an abandoned
     * cart and stashes only its id in a cookie. No product data is computed
     * here — the current cart can still change before the customer ever
     * sees the modal, so the eligible-products diff is deferred to display
     * time (hookDisplayModalContent).
     *
     * @param array $params ['customer' => Customer]
     */
    public function hookActionAuthentication(array $params)
    {
        /** @var Customer $customer */
        $customer = $params['customer'];
            
        if (empty($customer) || !$customer->id) {
            return;
        }
        
        /** @var AbandonedCartFinder $finder */
        $finder = $this->context->controller->getContainer()->get('mergesavedcart.abandoned_cart_finder');
        $idAbandonedCart = $finder->findAbandonedCartId((int) $customer->id, (int) $this->context->cart->id);
        
        if ($idAbandonedCart) {
            $this->context->cookie->{self::ABANDONED_CART_COOKIE_KEY} = $idAbandonedCart;
        } else {
            unset($this->context->cookie->{self::ABANDONED_CART_COOKIE_KEY});
        }

        // Login always ends in a redirect (Tools::redirect()/redirectWithNotifications()),
        // which exits before smartyOutputContent() ever runs — the only place
        // Cookie::write() is normally called. Without this explicit call, the
        // change above would never reach the browser.
        $this->context->cookie->write();
    }

    /**
     * Renders the restoration proposal modal if the cookie set at login
     * still points to a valid abandoned cart with eligible products.
     *
     * Uses displayModalContent (not displayFooter) because this theme
     * renders that hook into `<div data-ps-target="modal-container">`,
     * placed right before `</body>` in layout-both-columns.tpl — outside the
     * nested column/layout block structure entirely. displayFooter fires
     * deep inside that nested structure instead, which risked the modal
     * being visually trapped by an ancestor's stacking context.
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayModalContent(array $params)
    {
        $idAbandonedCart = (int) $this->context->cookie->{self::ABANDONED_CART_COOKIE_KEY};
        if ($idAbandonedCart <= 0 || !$this->context->customer->isLogged()) {
            return '';
        }

        /** @var AbandonedCartFinder $finder */
        $finder = $this->context->controller->getContainer()->get('mergesavedcart.abandoned_cart_finder');
        $proposal = $finder->findEligibleProducts(
            (int) $this->context->customer->id,
            $idAbandonedCart,
            (int) $this->context->cart->id
        );

        if (empty($proposal['products'])) {
            unset($this->context->cookie->{self::ABANDONED_CART_COOKIE_KEY});

            return '';
        }

        $this->context->smarty->assign([
            'mergesavedcart_products' => $finder->presentProducts($proposal['products']),
            'mergesavedcart_restore_url' => $this->context->link->getModuleLink($this->name, 'restore', [], true),
        ]);

        return $this->fetch('module:mergesavedcart/views/templates/hook/restore-cart-modal.tpl');
    }

    public function hookActionFrontControllerSetMedia()
    {
        if (!empty($this->context->cookie->{self::ABANDONED_CART_COOKIE_KEY})) {
            $this->context->controller->registerJavascript(
                'mergesavedcart-restore-modal',
                'modules/' . $this->name . '/views/js/restore-cart-modal.js',
                ['position' => 'bottom', 'priority' => 150]
            );
        }
    }

    /**
     * Fired by the core "Customer Settings" form handler (src/Core/Form/Handler.php)
     * right after it calls CustomerPreferencesDataProvider::setData() — meaning
     * the write to PS_CART_FOLLOWING has already happened by the time this runs.
     * src/Configuration/CustomerConfigurationGuard.php (a decorator on
     * prestashop.adapter.customer.customer_configuration, see config/admin/services.yml)
     * is what actually prevents that write from ever persisting `true`; this
     * hook only adds a visible warning explaining why nothing changed, by
     * pushing into $params['errors'] (passed by reference, surfaced by the
     * controller via addFlashErrors()).
     *
     * @param array $params ['errors' => array<string>, 'form_data' => array]
     */
    public function hookActionCustomerPreferencesPageSave(array $params)
    {
        if (!empty($params['form_data']['redisplay_cart_at_login'])) {
            $params['errors'][] = $this->trans(
                'The "Re-display cart at login" option cannot be enabled: it conflicts with the abandoned cart restoration feature provided by the "%module%" module, and has been kept disabled.',
                ['%module%' => $this->displayName],
                'Modules.Mergesavedcart.Admin'
            );
        }
    }
}
