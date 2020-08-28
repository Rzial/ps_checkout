<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace PrestaShop\Module\PrestashopCheckout\Api\Payment;

use PrestaShop\Module\PrestashopCheckout\Api\Payment\Client\PaymentClient;
use PrestaShop\Module\PrestashopCheckout\Configuration\PrestaShopConfiguration;
use PrestaShop\Module\PrestashopCheckout\ExpressCheckout\ExpressCheckout;

/**
 * Handle request to maasland regarding the shop/merchant status
 */
class Shop extends PaymentClient
{
    /**
     * Generate the paypal link to onboard merchant
     *
     * @param string $merchantId
     *
     * @return array onboarding link
     */
    public function getMerchantIntegration($merchantId)
    {
        $this->setRoute('/payments/shop/get_merchant_integrations');

        return $this->post([
            'json' => json_encode([
                'merchant_id' => $merchantId,
            ]),
        ]);
    }

    /**
     * Used to notify PSL on settings update
     *
     * @return array
     */
    public function updateSettings()
    {
        $this->setRoute('/payments/shop/update_settings');

        /** @var \Ps_checkout $module */
        $module = \Module::getInstanceByName('ps_checkout');
        /** @var PrestaShopConfiguration $configuration */
        $configuration = $module->getService('ps_checkout.configuration');

        return $this->post([
            'json' => json_encode([
                'settings' => [
                    'cb' => (bool) $configuration->get('PS_CHECKOUT_CARD_PAYMENT_ENABLED'),
                    'express_in_product' => (bool) $configuration->get(ExpressCheckout::PS_CHECKOUT_EC_PRODUCT_PAGE),
                    'express_in_cart' => (bool) $configuration->get(ExpressCheckout::PS_CHECKOUT_EC_ORDER_PAGE),
                    'express_in_checkout' => (bool) $configuration->get(ExpressCheckout::PS_CHECKOUT_EC_CHECKOUT_PAGE),
                ],
            ]),
        ]);
    }
}