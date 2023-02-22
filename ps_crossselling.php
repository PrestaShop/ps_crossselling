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
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;
use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_Crossselling extends Module implements WidgetInterface
{
    const LIMIT_FACTOR = 50;
    private $templateFile;

    public function __construct()
    {
        $this->name = 'ps_crossselling';
        $this->tab = 'pricing_promotion';
        $this->author = 'PrestaShop';
        $this->version = '2.0.2';
        $this->need_instance = 0;

        $this->ps_versions_compliancy = [
            'min' => '1.7.2.0',
            'max' => _PS_VERSION_,
        ];

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Cross-selling', [], 'Modules.Crossselling.Admin');
        $this->description = $this->trans('Offer your customers the possibility to buy matching items when on a product page.', [], 'Modules.Crossselling.Admin');

        $this->templateFile = 'module:ps_crossselling/views/templates/hook/ps_crossselling.tpl';
    }

    public function install()
    {
        $this->_clearCache('*');

        return parent::install()
            && Configuration::updateValue('CROSSSELLING_DISPLAY_PRICE', 1)
            && Configuration::updateValue('CROSSSELLING_NBR', 8)
            && $this->registerHook('displayFooterProduct')
            && $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall()
    {
        $this->_clearCache('*');

        return parent::uninstall()
            && Configuration::deleteByName('CROSSSELLING_DISPLAY_PRICE')
            && Configuration::deleteByName('CROSSSELLING_NBR');
    }

    public function getContent()
    {
        $html = '';

        if (Tools::isSubmit('submitCross')) {
            if (0 != Tools::getValue('displayPrice') && 1 != Tools::getValue('CROSSSELLING_DISPLAY_PRICE')) {
                $html .= $this->displayError('Invalid displayPrice');
            } elseif (!($product_nbr = Tools::getValue('CROSSSELLING_NBR')) || empty($product_nbr)) {
                $html .= $this->displayError($this->trans('You must fill in the "Number of displayed products" field.', [], 'Modules.Crossselling.Admin'));
            } elseif (0 === (int) $product_nbr) {
                $html .= $this->displayError($this->trans('Invalid number.', [], 'Modules.Crossselling.Admin'));
            } else {
                Configuration::updateValue('CROSSSELLING_DISPLAY_PRICE', (int) Tools::getValue('CROSSSELLING_DISPLAY_PRICE'));
                Configuration::updateValue('CROSSSELLING_NBR', (int) Tools::getValue('CROSSSELLING_NBR'));

                $this->_clearCache('*');

                $html .= $this->displayConfirmation($this->trans('The settings have been updated.', [], 'Admin.Notifications.Success'));
            }
        }

        return $html . $this->renderForm();
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $products = OrderDetail::getList((int) $params['id_order']);
        foreach ($products as $p) {
            $this->_clearCache('*', $this->getCacheIdKey([$p['product_id']]));
        }
    }

    protected function _clearCache($template, $cacheId = null, $compileId = null)
    {
        parent::_clearCache($this->templateFile, $cacheId);
    }

    public function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Admin.Global'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Display price on products', [], 'Modules.Crossselling.Admin'),
                        'name' => 'CROSSSELLING_DISPLAY_PRICE',
                        'desc' => $this->trans('Show the price on the products in the block.', [], 'Modules.Crossselling.Admin'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Number of displayed products', [], 'Modules.Crossselling.Admin'),
                        'name' => 'CROSSSELLING_NBR',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->trans('Set the number of products displayed in this block.', [], 'Modules.Crossselling.Admin'),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCross';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) .
            '&configure=' . $this->name .
            '&tab_module=' . $this->tab .
            '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$fields_form]);
    }

    public function getConfigFieldsValues()
    {
        return [
            'CROSSSELLING_NBR' => Tools::getValue('CROSSSELLING_NBR', Configuration::get('CROSSSELLING_NBR')),
            'CROSSSELLING_DISPLAY_PRICE' => Tools::getValue('CROSSSELLING_DISPLAY_PRICE', Configuration::get('CROSSSELLING_DISPLAY_PRICE')),
        ];
    }

    public function getCacheIdKey($productIds)
    {
        return parent::getCacheId('ps_crossselling|' . implode('|', $productIds));
    }

    private function getProductIds($hookName, array $configuration)
    {
        if ('displayShoppingCart' === $hookName || 'displayShoppingCartFooter' === $hookName) {
            $productIds = array_map(function ($elem) {
                return $elem['id_product'];
            }, $configuration['cart']->getProducts());
        } else {
            $productIds = [$configuration['product']['id_product']];
        }

        return array_unique($productIds);
    }

    public function getWidgetVariables($hookName, array $configuration)
    {
        $productIds = $this->getProductIds($hookName, $configuration);
        if (!empty($productIds)) {
            $products = $this->getOrderProducts($productIds);

            if (!empty($products)) {
                return [
                    'products' => $products,
                ];
            }
        }

        return false;
    }

    public function renderWidget($hookName, array $configuration)
    {
        $productIds = $this->getProductIds($hookName, $configuration);

        if (empty($productIds)) {
            return;
        }

        if (!$this->isCached($this->templateFile, $this->getCacheIdKey($productIds))) {
            $variables = $this->getWidgetVariables($hookName, $configuration);

            if (empty($variables)) {
                return false;
            }

            $this->smarty->assign($variables);
        }

        return $this->fetch($this->templateFile, $this->getCacheIdKey($productIds));
    }

    protected function getOrderProducts(array $productIds = [])
    {
        $q_orders = 'SELECT o.id_order
        FROM ' . _DB_PREFIX_ . 'orders o
        LEFT JOIN ' . _DB_PREFIX_ . 'order_detail od ON (od.id_order = o.id_order)
        WHERE o.valid = 1
        AND od.product_id IN (' . implode(',', $productIds) . ')
        ORDER BY o.id_order DESC
        LIMIT ' . ((int) Configuration::get('CROSSSELLING_NBR')) * static::LIMIT_FACTOR;

        $orders = Db::getInstance((bool) _PS_USE_SQL_SLAVE_)->executeS($q_orders);

        if (0 < count($orders)) {
            $list = '';
            foreach ($orders as $order) {
                $list .= (int) $order['id_order'] . ',';
            }
            $list = rtrim($list, ',');
            $list_product_ids = join(',', $productIds);

            $sql_groups_join = $sql_groups_where = '';
            if (Group::isFeatureActive()) {
                $sql_groups_join = '
                LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON (cp.`id_category` = product_shop.id_category_default AND cp.id_product = product_shop.id_product)
                LEFT JOIN `' . _DB_PREFIX_ . 'category_group` cg ON (cp.`id_category` = cg.`id_category`)';
                $groups = FrontController::getCurrentCustomerGroups();
                $sql_groups_where = 'AND cg.`id_group` ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '=' . (int) Group::getCurrent()->id);
            }

            $order_products = Db::getInstance((bool) _PS_USE_SQL_SLAVE_)->executeS('
                SELECT DISTINCT od.product_id
                FROM ' . _DB_PREFIX_ . 'order_detail od
                LEFT JOIN ' . _DB_PREFIX_ . 'product p ON (p.id_product = od.product_id)
                ' . Shop::addSqlAssociation('product', 'p') .
                $sql_groups_join . '
                WHERE od.id_order IN (' . $list . ')
                AND od.product_id NOT IN (' . $list_product_ids . ')
                AND product_shop.visibility IN (\'both\',\'catalog\')
                AND product_shop.active = 1
                ' . $sql_groups_where . '
                ORDER BY RAND()
                LIMIT ' . (int) Configuration::get('CROSSSELLING_NBR')
            );
        }

        if (!empty($order_products)) {
            $showPrice = (bool) Configuration::get('CROSSSELLING_DISPLAY_PRICE');

            $assembler = new ProductAssembler($this->context);

            $presenterFactory = new ProductPresenterFactory($this->context);
            $presentationSettings = $presenterFactory->getPresentationSettings();
            if (version_compare(_PS_VERSION_, '1.7.5', '>=')) {
                $presenter = new \PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter(
                    new ImageRetriever(
                        $this->context->link
                    ),
                    $this->context->link,
                    new PriceFormatter(),
                    new ProductColorsRetriever(),
                    $this->context->getTranslator()
                );
            } else {
                $presenter = new \PrestaShop\PrestaShop\Core\Product\ProductListingPresenter(
                    new ImageRetriever(
                        $this->context->link
                    ),
                    $this->context->link,
                    new PriceFormatter(),
                    new ProductColorsRetriever(),
                    $this->context->getTranslator()
                );
            }

            $productsForTemplate = [];

            $presentationSettings->showPrices = $showPrice;

            if (is_array($order_products)) {
                foreach ($order_products as $productId) {
                    $productsForTemplate[] = $presenter->present(
                        $presentationSettings,
                        $assembler->assembleProduct(['id_product' => $productId['product_id']]),
                        $this->context->language
                    );
                }
            }

            return $productsForTemplate;
        }

        return false;
    }
}
