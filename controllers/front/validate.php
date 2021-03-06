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

use PrestaShop\Module\PrestashopCheckout\Exception\PayPalException;
use PrestaShop\Module\PrestashopCheckout\Exception\PsCheckoutException;
use PrestaShop\Module\PrestashopCheckout\ValidateOrder;

/**
 * This controller receive ajax call to capture/authorize payment and create a PrestaShop Order
 */
class Ps_CheckoutValidateModuleFrontController extends ModuleFrontController
{
    /**
     * @var Ps_checkout
     */
    public $module;

    /**
     * @var string
     */
    private $paypalOrderId;

    /**
     * @see FrontController::postProcess()
     *
     * @todo Move logic to a Service
     */
    public function postProcess()
    {
        header('content-type:application/json');

        try {
            if (Tools::getValue('static_token') !== Tools::getToken(false)) {
                throw new PsCheckoutException('Bad token', PsCheckoutException::PSCHECKOUT_BAD_STATIC_TOKEN);
            }

            if (false === $this->checkIfContextIsValid()) {
                throw new PsCheckoutException('The context is not valid', PsCheckoutException::PRESTASHOP_CONTEXT_INVALID);
            }

            if (false === $this->checkIfPaymentOptionIsAvailable()) {
                throw new PsCheckoutException('This payment method is not available.', PsCheckoutException::PRESTASHOP_PAYMENT_UNAVAILABLE);
            }

            $cart = $this->context->cart;

            $customer = new Customer($cart->id_customer);

            if (false === Validate::isLoadedObject($customer)) {
                $this->sendBadRequestError(
                    new PsCheckoutException('Unable to load Customer', PsCheckoutException::PRESTASHOP_CONTEXT_INVALID)
                );
            }

            $bodyContent = file_get_contents('php://input');

            if (empty($bodyContent)) {
                throw new PsCheckoutException('Body cannot be empty', PsCheckoutException::PSCHECKOUT_VALIDATE_BODY_EMPTY);
            }

            $bodyValues = json_decode($bodyContent, true);

            if (empty($bodyValues)) {
                throw new PsCheckoutException('Body cannot be empty', PsCheckoutException::PSCHECKOUT_VALIDATE_BODY_EMPTY);
            }

            if (empty($bodyValues['orderID']) || false === Validate::isGenericName($bodyValues['orderID'])) {
                throw new PsCheckoutException('PayPal Order identifier invalid', PsCheckoutException::PAYPAL_ORDER_IDENTIFIER_MISSING);
            }

            $this->paypalOrderId = $bodyValues['orderID'];

            /** @var \PrestaShop\Module\PrestashopCheckout\Repository\PsCheckoutCartRepository $psCheckoutCartRepository */
            $psCheckoutCartRepository = $this->module->getService('ps_checkout.repository.pscheckoutcart');

            /** @var PsCheckoutCart|false $psCheckoutCart */
            $psCheckoutCart = $psCheckoutCartRepository->findOneByCartId((int) $this->context->cart->id);

            if (false !== $psCheckoutCart) {
                $psCheckoutCart->paypal_funding = $bodyValues['fundingSource'];
                $psCheckoutCart->isExpressCheckout = isset($bodyValues['isExpressCheckout']) ? (bool) $bodyValues['isExpressCheckout'] : false;
                $psCheckoutCart->isHostedFields = isset($bodyValues['isHostedFields']) ? (bool) $bodyValues['isHostedFields'] : false;
                $psCheckoutCartRepository->save($psCheckoutCart);
            }

            $this->module->getLogger()->info(sprintf(
                'ValidateOrder PayPal Order Id : %s Cart : %s',
                $bodyValues['orderID'],
                Validate::isLoadedObject($this->context->cart) ? (int) $this->context->cart->id : 0
            ));

            $currency = $this->context->currency;
            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

            /** @var \PrestaShop\Module\PrestashopCheckout\Repository\PaypalAccountRepository $accountRepository */
            $accountRepository = $this->module->getService('ps_checkout.repository.paypal.account');
            $merchandId = $accountRepository->getMerchantId();
            $payment = new ValidateOrder($bodyValues['orderID'], $merchandId);

            $dataOrder = [
                'cartId' => (int) $cart->id,
                'amount' => $total,
                'currencyId' => (int) $currency->id,
                'secureKey' => $customer->secure_key,
            ];

            // If the payment is rejected redirect the client to the last checkout step (422 error)
            // API call here
            $response = $payment->validateOrder($dataOrder);

            $this->context->cookie->__unset('paypalEmail');

            $this->sendOkResponse($response);
        } catch (Exception $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * Redirect to checkout page
     *
     * @param Exception $exception
     */
    private function sendBadRequestError(Exception $exception)
    {
        header('HTTP/1.0 400 Bad Request');

        echo json_encode([
            'status' => false,
            'httpCode' => 400,
            'body' => '',
            'exceptionCode' => $exception->getCode(),
            'exceptionMessage' => $exception->getMessage(),
        ]);
        exit;
    }

    /**
     * Redirect to order confirmation page
     *
     * @param array $response
     */
    private function sendOkResponse($response)
    {
        echo json_encode([
            'status' => true,
            'httpCode' => 200,
            'body' => [
                'paypal_status' => $response['status'],
                'paypal_order' => $response['paypalOrderId'],
                'paypal_transaction' => $response['transactionIdentifier'],
                'id_cart' => (int) $this->context->cart->id,
                'id_module' => (int) $this->module->id,
                'id_order' => (int) $this->module->currentOrder,
                'secure_key' => $this->context->customer->secure_key,
            ],
            'exceptionCode' => null,
            'exceptionMessage' => null,
        ]);
        exit;
    }

    /**
     * Check if the context is valid and if the module is active
     *
     * @return bool
     */
    private function checkIfContextIsValid()
    {
        return true === (bool) $this->module->active
            && true === Validate::isLoadedObject($this->context->cart)
            && true === Validate::isUnsignedInt($this->context->cart->id_customer)
            && true === Validate::isUnsignedInt($this->context->cart->id_address_delivery)
            && true === Validate::isUnsignedInt($this->context->cart->id_address_invoice);
    }

    /**
     * Check that this payment option is still available in case the customer changed
     * his address just before the end of the checkout process
     *
     * @todo Move to main module class
     *
     * @return bool
     */
    private function checkIfPaymentOptionIsAvailable()
    {
        $modules = Module::getPaymentModules();

        if (empty($modules)) {
            return false;
        }

        foreach ($modules as $module) {
            if (isset($module['name']) && $this->module->name === $module['name']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Exception $exception
     *
     * @todo To be refactored with Service Container in v2.0.0
     */
    private function handleException(Exception $exception)
    {
        $exceptionMessageForCustomer = $this->module->l('Error processing payment', 'translations');
        $notifyCustomerService = true;

        $paypalOrder = $this->paypalOrderId;

        if (false === Validate::isGenericName($paypalOrder)) {
            $paypalOrder = 'invalid';
        }

        switch ($exception->getCode()) {
            case PsCheckoutException::PAYPAL_PAYMENT_CARD_ERROR:
            case PsCheckoutException::PAYPAL_PAYMENT_CAPTURE_DECLINED:
            case PayPalException::CARD_TYPE_NOT_SUPPORTED:
            case PayPalException::INVALID_SECURITY_CODE_LENGTH:
            case PayPalException::CURRENCY_NOT_SUPPORTED_FOR_CARD_TYPE:
            case PayPalException::CURRENCY_NOT_SUPPORTED_FOR_COUNTRY:
            case PayPalException::INSTRUMENT_DECLINED:
            case PayPalException::MAX_NUMBER_OF_PAYMENT_ATTEMPTS_EXCEEDED:
            case PayPalException::PAYER_ACCOUNT_LOCKED_OR_CLOSED:
            case PayPalException::PAYER_ACCOUNT_RESTRICTED:
            case PayPalException::PAYER_CANNOT_PAY:
            case PayPalException::PAYER_COUNTRY_NOT_SUPPORTED:
            case PayPalException::REDIRECT_PAYER_FOR_ALTERNATE_FUNDING:
            case PayPalException::TRANSACTION_BLOCKED_BY_PAYEE:
            case PayPalException::TRANSACTION_REFUSED:
            case PayPalException::NO_EXTERNAL_FUNDING_DETAILS_FOUND:
                $this->sendBadRequestError($exception);
                break;
            case PayPalException::ORDER_ALREADY_CAPTURED:
                $psCheckoutCartCollection = new PrestaShopCollection('PsCheckoutCart');
                $psCheckoutCartCollection->where('paypal_order', '=', (int) $paypalOrder);

                /** @var PsCheckoutCart|false $psCheckoutCart */
                $psCheckoutCart = $psCheckoutCartCollection->getFirst();

                if (false === empty($psCheckoutCart)) {
                    // TODO get transaction identifier
                    $this->sendOkResponse(
                        [
                            'status' => $psCheckoutCart->paypal_status,
                            'paypalOrderId' => $psCheckoutCart->paypal_order,
                            'transactionIdentifier' => '',
                        ]
                    );
                }

                $exceptionMessageForCustomer = $this->module->l('Order cannot be saved', 'translations');
                break;
            case PsCheckoutException::PRESTASHOP_PAYMENT_UNAVAILABLE:
                $exceptionMessageForCustomer = $this->module->l('This payment method is unavailable', 'translations');
                break;
            case PsCheckoutException::PSCHECKOUT_HTTP_EXCEPTION:
                $exceptionMessageForCustomer = $this->module->l('Unable to call API', 'translations');
                break;
            case PsCheckoutException::PAYPAL_ORDER_IDENTIFIER_MISSING:
                $exceptionMessageForCustomer = $this->module->l('PayPal order identifier is missing', 'translations');
                $notifyCustomerService = false;
                break;
            case PsCheckoutException::PAYPAL_PAYMENT_METHOD_MISSING:
                $exceptionMessageForCustomer = $this->module->l('PayPal payment method is missing', 'translations');
                $notifyCustomerService = false;
                break;
            case PsCheckoutException::PRESTASHOP_CONTEXT_INVALID:
                $exceptionMessageForCustomer = $this->module->l('Cart is invalid', 'translations');
                $notifyCustomerService = false;
                break;
        }

        if (true === $notifyCustomerService) {
            $this->notifyCustomerService($exception);
            $this->module->getLogger()->error(sprintf(
                'ValidateOrder - Exception %s Order PayPal %s : %s',
                $exception->getCode(),
                $paypalOrder,
                $exception->getMessage()
            ));
        } else {
            $this->module->getLogger()->notice(sprintf(
                'ValidateOrder - Exception %s Order PayPal %s : %s',
                $exception->getCode(),
                $paypalOrder,
                $exception->getMessage()
            ));
        }

        // Preserve current cart from customer changes to allow merchant to see whats wrong
        $this->generateNewCart();

        header('HTTP/1.0 500 Internal Server Error');

        echo json_encode([
            'status' => false,
            'httpCode' => 500,
            'body' => '',
            'exceptionCode' => $exception->getCode(),
            'exceptionMessage' => $exceptionMessageForCustomer,
        ]);
        exit;
    }

    /**
     * @param Exception $exception
     *
     * @todo To be refactored with Service Container in v2.0.0
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function notifyCustomerService(Exception $exception)
    {
        $paypalOrderId = $this->paypalOrderId;
        $contacts = Contact::getContacts((int) $this->context->language->id);

        if (empty($contacts)) {
            return;
        }

        // Cannot use id_cart because we create a new cart to preserve current cart from customer changes
        $token = Tools::substr(
            Tools::encrypt(implode(
                '|',
                [
                    (int) $this->context->customer->id,
                    (int) $this->context->shop->id,
                    (int) $this->context->language->id,
                    (int) $exception->getCode(),
                    $paypalOrderId,
                    get_class($exception),
                ]
            )),
            0,
            12
        );

        $isThreadAlreadyCreated = (bool) Db::getInstance()->getValue('
            SELECT 1
            FROM ' . _DB_PREFIX_ . 'customer_thread
            WHERE id_customer = ' . (int) $this->context->customer->id . '
            AND id_shop = ' . (int) $this->context->shop->id . '
            AND status = "open"
            AND token = "' . pSQL($token) . '"
        ');

        // Prevent spam Customer Service on case of page refresh
        if (true === $isThreadAlreadyCreated) {
            return;
        }

        $message = $this->module->l('This message is sent automatically by module PrestaShop Checkout', 'translations') . PHP_EOL . PHP_EOL;
        $message .= $this->module->l('A customer encountered a processing payment error :', 'translations') . PHP_EOL;
        $message .= $this->module->l('Customer identifier:', 'translations') . ' ' . (int) $this->context->customer->id . PHP_EOL;
        $message .= $this->module->l('Cart identifier:', 'translations') . ' ' . (int) $this->context->cart->id . PHP_EOL;
        $message .= $this->module->l('PayPal order identifier:', 'translations') . ' ' . Tools::safeOutput($paypalOrderId) . PHP_EOL;
        $message .= $this->module->l('Exception identifier:', 'translations') . ' ' . (int) $exception->getCode() . PHP_EOL;
        $message .= $this->module->l('Exception detail:', 'translations') . ' ' . Tools::safeOutput($exception->getMessage()) . PHP_EOL . PHP_EOL;
        $message .= $this->module->l('If you need assistance, please contact our Support Team on PrestaShop Checkout configuration page on Help subtab.', 'translations') . PHP_EOL;

        $customerThread = new CustomerThread();
        $customerThread->id_customer = (int) $this->context->customer->id;
        $customerThread->id_shop = (int) $this->context->shop->id;
        $customerThread->id_order = (int) $this->module->currentOrder;
        $customerThread->id_lang = (int) $this->context->language->id;
        $customerThread->id_contact = (int) $contacts[0]['id_contact']; // Should be configurable
        $customerThread->email = $this->context->customer->email;
        $customerThread->status = 'open';
        $customerThread->token = $token;
        $customerThread->add();

        $customerMessage = new CustomerMessage();
        $customerMessage->id_customer_thread = $customerThread->id;
        $customerMessage->message = $message;
        $customerMessage->ip_address = (int) ip2long(Tools::getRemoteAddr());
        $customerMessage->user_agent = $_SERVER['HTTP_USER_AGENT'];
        $customerMessage->private = 1;
        $customerMessage->read = false;
        $customerMessage->add();
    }

    /**
     * @see FrontController::init()
     */
    private function generateNewCart()
    {
        $cart = clone $this->context->cart;
        $cart->id = 0;
        $cart->add();

        $this->context->cart = $cart;
    }
}
