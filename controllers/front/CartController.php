<?php
/**
 * 2007-2015 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2015 PrestaShop SA
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

use PrestaShop\PrestaShop\Adapter\Cart\CartPresenter;

class CartControllerCore extends FrontController
{
    public $php_self = 'cart';

    protected $id_product;
    protected $id_product_attribute;
    protected $id_address_delivery;
    protected $customization_id;
    protected $qty;
    public $ssl = true;

    /**
     * This is not a public page, so the canonical redirection is disabled
     *
     * @param string $canonicalURL
     */
    public function canonicalRedirection($canonicalURL = '')
    {
    }

    /**
     * Initialize cart controller
     * @see FrontController::init()
     */
    public function init()
    {
        parent::init();

        // Send noindex to avoid ghost carts by bots
        header('X-Robots-Tag: noindex, nofollow', true);

        // Get page main parameters
        $this->id_product = (int)Tools::getValue('id_product', null);
        $this->id_product_attribute = (int)Tools::getValue('id_product_attribute', Tools::getValue('ipa'));
        $this->customization_id = (int)Tools::getValue('id_customization');
        $this->qty = abs(Tools::getValue('qty', 1));
        $this->id_address_delivery = (int)Tools::getValue('id_address_delivery');
    }

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        if (Configuration::isCatalogMode()) {
            Tools::redirect('index.php');
        }

        parent::initContent();

        $presenter = new CartPresenter();
        $presented_cart = $presenter->present($this->context->cart, $shouldSeparateGifts = true);

        $this->context->smarty->assign([
            'cart' => $presented_cart,
            'static_token' => Tools::getToken(false),
        ]);

        if (count($presented_cart['products']) > 0) {
            $this->setTemplate('checkout/cart');
        } else {
            $this->context->smarty->assign([
                'allProductsLink' => $this->context->link->getCategoryLink(Configuration::get('PS_HOME_CATEGORY')),
            ]);
            $this->setTemplate('checkout/cart-empty');
        }
    }

    public function displayAjaxUpdate()
    {
        if (Configuration::isCatalogMode()) {
            return;
        }

        $productsInCart = $this->context->cart->getProducts();
        $updatedProducts = array_filter($productsInCart, array($this, 'productInCartMatchesCriteria'));
        list(, $updatedProduct) = each($updatedProducts);
        $productQuantity = $updatedProduct['quantity'];

        if (!$this->errors) {
            $this->ajaxDie(Tools::jsonEncode([
                'success' => true,
                'id_product' => $this->id_product,
                'id_product_attribute' => $this->id_product_attribute,
                'quantity' => $productQuantity,
            ]));
        } else {
            $this->ajaxDie(Tools::jsonEncode([
                'hasError' => true,
                'errors' => $this->errors,
                'quantity' => $productQuantity,
            ]));
        }
    }


    public function displayAjaxRefresh()
    {
        if (Configuration::isCatalogMode()) {
            return;
        }

        ob_end_clean();
        header('Content-Type: application/json');
        $this->ajaxDie(Tools::jsonEncode([
            'cart_detailed' => $this->render('checkout/_partials/cart-detailed'),
            'cart_detailed_totals' => $this->render('checkout/_partials/cart-detailed-totals'),
            'cart_summary_items_subtotal' => $this->render('checkout/_partials/cart-summary-items-subtotal'),
            'cart_summary_totals' => $this->render('checkout/_partials/cart-summary-totals'),
            'cart_detailed_actions' => $this->render('checkout/_partials/cart-detailed-actions'),
            'cart_voucher' => $this->render('checkout/_partials/cart-voucher'),
        ]));
    }

    public function displayAjaxProductRefresh()
    {
        if (Configuration::isCatalogMode()) {
            return;
        }
        $url = $this->context->link->getProductLink(
            $this->id_product,
            null,
            null,
            null,
            $this->context->language->id,
            null,
            (int)Product::getIdProductAttributesByIdAttributes($this->id_product, Tools::getValue('group')),
            false,
            false,
            true,
            ['quantity_wanted' => (int)$this->qty]
        );
        ob_end_clean();
        header('Content-Type: application/json');
        $this->ajaxDie(Tools::jsonEncode([
            'success' => true,
            'productUrl' => $url
        ]));
    }

    public function postProcess()
    {
        $this->updateCart();
    }

    protected function updateCart()
    {
        // Update the cart ONLY if $this->cookies are available, in order to avoid ghost carts created by bots
        if ($this->context->cookie->exists() && !$this->errors && !($this->context->customer->isLogged() && !$this->isTokenValid())) {
            if (Tools::getIsset('add') || Tools::getIsset('update')) {
                $this->processChangeProductInCart();
            } elseif (Tools::getIsset('delete')) {
                $this->processDeleteProductInCart();
            } elseif (CartRule::isFeatureActive()) {
                if (Tools::getIsset('addDiscount')) {
                    if (!($code = trim(Tools::getValue('discount_name')))) {
                        $this->errors[] = $this->trans('You must enter a voucher code.', array(), 'Shop.Notifications.Error');
                    } elseif (!Validate::isCleanHtml($code)) {
                        $this->errors[] = $this->trans('The voucher code is invalid.', array(), 'Shop.Notifications.Error');
                    } else {
                        if (($cartRule = new CartRule(CartRule::getIdByCode($code))) && Validate::isLoadedObject($cartRule)) {
                            if ($error = $cartRule->checkValidity($this->context, false, true)) {
                                $this->errors[] = $error;
                            } else {
                                $this->context->cart->addCartRule($cartRule->id);
                            }
                        } else {
                            $this->errors[] = Tools::displayError('This voucher does not exists.');
                        }
                    }
                } elseif (($id_cart_rule = (int)Tools::getValue('deleteDiscount')) && Validate::isUnsignedId($id_cart_rule)) {
                    $this->context->cart->removeCartRule($id_cart_rule);
                    CartRule::autoAddToCart($this->context);
                }
            }
        } elseif (!$this->isTokenValid() && Tools::getValue('action') !== 'show' && !Tools::getValue('ajax')) {
            Tools::redirect('index.php');
        }
    }

    /**
     * This process delete a product from the cart
     */
    protected function processDeleteProductInCart()
    {
        $customization_product = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'customization`
		WHERE `id_cart` = '.(int)$this->context->cart->id.' AND `id_product` = '.(int)$this->id_product.' AND `id_customization` != '.(int)$this->customization_id);

        if (count($customization_product)) {
            $product = new Product((int)$this->id_product);
            if ($this->id_product_attribute > 0) {
                $minimal_quantity = (int)Attribute::getAttributeMinimalQty($this->id_product_attribute);
            } else {
                $minimal_quantity = (int)$product->minimal_quantity;
            }

            $total_quantity = 0;
            foreach ($customization_product as $custom) {
                $total_quantity += $custom['quantity'];
            }

            if ($total_quantity < $minimal_quantity) {
                $this->errors[] = $this->trans('You must add %d minimum quantity', array(!Tools::getValue('ajax'), $minimal_quantity), 'Shop.Notifications.Error');
                return false;
            }
        }

        if ($this->context->cart->deleteProduct($this->id_product, $this->id_product_attribute, $this->customization_id, $this->id_address_delivery)) {
            $data = array(
                'id_cart' => (int)$this->context->cart->id,
                'id_product' => (int)$this->id_product,
                'id_product_attribute' => (int)$this->id_product_attribute,
                'customization_id' => (int)$this->customization_id,
                'id_address_delivery' => (int)$this->id_address_delivery
            );

            Hook::exec('actionDeleteProductInCartAfter', $data);

            if (!Cart::getNbProducts((int)$this->context->cart->id)) {
                $this->context->cart->setDeliveryOption(null);
                $this->context->cart->gift = 0;
                $this->context->cart->gift_message = '';
                $this->context->cart->update();
            }
        }

        $removed = CartRule::autoRemoveFromCart();
        CartRule::autoAddToCart();
    }

    /**
     * This process add or update a product in the cart
     */
    protected function processChangeProductInCart()
    {
        $mode = (Tools::getIsset('update') && $this->id_product) ? 'update' : 'add';

        if (Tools::getIsset('group')) {
            $this->id_product_attribute = (int)Product::getIdProductAttributesByIdAttributes($this->id_product, Tools::getValue('group'));
        }

        if ($this->qty == 0) {
            $this->errors[] = $this->trans('Null quantity.', array(), 'Shop.Notifications.Error');
        } elseif (!$this->id_product) {
            $this->errors[] = $this->trans('Product not found', array(), 'Shop.Notifications.Error');
        }

        $product = new Product($this->id_product, true, $this->context->language->id);
        if (!$product->id || !$product->active || !$product->checkAccess($this->context->cart->id_customer)) {
            $this->errors[] = $this->trans('This product is no longer available.', array(), 'Shop.Notifications.Error');
            return;
        }

        $qty_to_check = $this->qty;
        $cart_products = $this->context->cart->getProducts();

        if (is_array($cart_products)) {
            foreach ($cart_products as $cart_product) {
                if ($this->productInCartMatchesCriteria($cart_product)) {
                    $qty_to_check = $cart_product['cart_quantity'];

                    if (Tools::getValue('op', 'up') == 'down') {
                        $qty_to_check -= $this->qty;
                    } else {
                        $qty_to_check += $this->qty;
                    }

                    break;
                }
            }
        }

        // Check product quantity availability
        if ($this->id_product_attribute) {
            if (!Product::isAvailableWhenOutOfStock($product->out_of_stock) && !Attribute::checkAttributeQty($this->id_product_attribute, $qty_to_check)) {
                $this->errors[] = $this->trans('There are not enough products in stock', array(), 'Shop.Notifications.Error');
            }
        } elseif ($product->hasAttributes()) {
            $minimumQuantity = ($product->out_of_stock == 2) ? !Configuration::get('PS_ORDER_OUT_OF_STOCK') : !$product->out_of_stock;
            $this->id_product_attribute = Product::getDefaultAttribute($product->id, $minimumQuantity);
            // @todo do something better than a redirect admin !!
            if (!$this->id_product_attribute) {
                Tools::redirectAdmin($this->context->link->getProductLink($product));
            } elseif (!Product::isAvailableWhenOutOfStock($product->out_of_stock) && !Attribute::checkAttributeQty($this->id_product_attribute, $qty_to_check)) {
                $this->errors[] = $this->trans('There are not enough products in stock', array(), 'Shop.Notifications.Error');
            }
        } elseif (!$product->checkQty($qty_to_check)) {
            $this->errors[] = $this->trans('There are not enough products in stock', array(), 'Shop.Notifications.Error');
        }

        // If no errors, process product addition
        if (!$this->errors) {
            // Add cart if no cart found
            if (!$this->context->cart->id) {
                if (Context::getContext()->cookie->id_guest) {
                    $guest = new Guest(Context::getContext()->cookie->id_guest);
                    $this->context->cart->mobile_theme = $guest->mobile_theme;
                }
                $this->context->cart->add();
                if ($this->context->cart->id) {
                    $this->context->cookie->id_cart = (int)$this->context->cart->id;
                }
            }

            // Check customizable fields
            if (!$product->hasAllRequiredCustomizableFields() && !$this->customization_id) {
                $this->errors[] = $this->trans('Please fill in all of the required fields, and then save your customizations.', array(), 'Shop.Notifications.Error');
            }

            if (!$this->errors) {
                $cart_rules = $this->context->cart->getCartRules();
                $available_cart_rules = CartRule::getCustomerCartRules($this->context->language->id, (isset($this->context->customer->id) ? $this->context->customer->id : 0), true, true, true, $this->context->cart, false, true);
                $update_quantity = $this->context->cart->updateQty($this->qty, $this->id_product, $this->id_product_attribute, $this->customization_id, Tools::getValue('op', 'up'), $this->id_address_delivery);
                if ($update_quantity < 0) {
                    // If product has attribute, minimal quantity is set with minimal quantity of attribute
                    $minimal_quantity = ($this->id_product_attribute) ? Attribute::getAttributeMinimalQty($this->id_product_attribute) : $product->minimal_quantity;
                    $this->errors[] = $this->trans('You must add %d minimum quantity', array($minimal_quantity), 'Shop.Notifications.Error');
                } elseif (!$update_quantity) {
                    $this->errors[] = $this->trans('You already have the maximum quantity available for this product.', array(), 'Shop.Notifications.Error');
                }
            }
        }

        $removed = CartRule::autoRemoveFromCart();
        CartRule::autoAddToCart();
    }

    /**
     * @param $productInCart
     * @return bool
     */
    function productInCartMatchesCriteria($productInCart) {
        return (
            !isset($this->id_product_attribute) ||
            (
                $productInCart['id_product_attribute'] == $this->id_product_attribute &&
                $productInCart['id_customization'] == $this->customization_id
            )
        ) && isset($this->id_product) && $productInCart['id_product'] == $this->id_product;
    }

    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();
        $presenter = new CartPresenter();
        $presented_cart = $presenter->present($this->context->cart);

        if (count($presented_cart['products']) == 0) {
            $page['body_classes']['cart-empty'] = true;
        }

        return $page;
    }
}
