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

namespace MergeSavedCart\Configuration;

use PrestaShop\PrestaShop\Core\Configuration\DataConfigurationInterface;

/**
 * Decorates the core "Customer Settings" data configuration so
 * "Re-display cart at login" (PS_CART_FOLLOWING) can never be turned on from
 * the Back Office: leaving it on would make Context::updateCustomer() silently
 * swap in the customer's abandoned cart on login, bypassing the opt-in
 * restoration modal this module provides instead.
 */
class CustomerConfigurationGuard implements DataConfigurationInterface
{
    /**
     * @var DataConfigurationInterface
     */
    private $decorated;

    public function __construct(DataConfigurationInterface $decorated)
    {
        $this->decorated = $decorated;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration()
    {
        $configuration = $this->decorated->getConfiguration();
        $configuration['redisplay_cart_at_login'] = false;

        return $configuration;
    }

    /**
     * {@inheritdoc}
     */
    public function updateConfiguration(array $configuration)
    {
        $configuration['redisplay_cart_at_login'] = false;

        return $this->decorated->updateConfiguration($configuration);
    }

    /**
     * {@inheritdoc}
     */
    public function validateConfiguration(array $configuration)
    {
        return $this->decorated->validateConfiguration($configuration);
    }
}
