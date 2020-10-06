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
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Update main function for module version 2.0.0
 *
 * @param Ps_checkout $module
 *
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    return (bool) Configuration::updateGlobalValue('PS_CHECKOUT_LOGGER_MAX_FILES', '15')
        && (bool) Configuration::updateGlobalValue('PS_CHECKOUT_LOGGER_LEVEL', \Monolog\Logger::ERROR)
        && (bool) Configuration::updateGlobalValue('PS_CHECKOUT_LOGGER_HTTP', '0')
        && (bool) Configuration::updateGlobalValue('PS_CHECKOUT_LOGGER_HTTP_FORMAT', 'DEBUG')
        && (bool) Configuration::updateGlobalValue('PS_CHECKOUT_INTEGRATION_DATE', Ps_checkout::INTEGRATION_DATE)
        && (bool) $module->registerHook(Ps_checkout::HOOK_LIST)
        && (bool) (new PrestaShop\Module\PrestashopCheckout\Database\TableManager())->createTable();
}