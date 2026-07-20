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
     * @return string
     */
    public function hookDisplayModalContent()
    {
        /** @var AbandonedCartFinder $finder */
        $finder = $this->context->controller->getContainer()->get('mergesavedcart.abandoned_cart_finder');
        $idAbandonedCart = $finder->findAbandonedCartId((int) $this->context->customer->id, (int) $this->context->cart->id);
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
            return '';
        }

        $this->context->smarty->assign([
            'mergesavedcart_products' => $finder->presentProducts($proposal['products']),
            'mergesavedcart_restore_url' => $this->context->link->getModuleLink($this->name, 'restore', [], true),
            'mergesavedcart_abandoned_cart_id' => $idAbandonedCart,
        ]);

        return $this->fetch('module:mergesavedcart/views/templates/hook/restore-cart-modal.tpl');
    }

    public function hookActionFrontControllerSetMedia()
    {
        $this->context->controller->registerJavascript(
            'mergesavedcart-restore-modal',
            'modules/' . $this->name . '/views/js/restore-cart-modal.js',
            ['position' => 'bottom', 'priority' => 150]
        );
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
