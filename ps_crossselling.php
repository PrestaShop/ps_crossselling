<?php
/*
* 2007-2016 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
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
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Core\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_CrossSelling extends Module implements WidgetInterface
{
    protected $html;
    protected static $cache_order_products = array();

    public function __construct()
    {
        $this->name = 'ps_crossselling';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans(
            'Cross-selling',
            array(),
            'Modules.Crossselling.Admin'
        );
        $this->description = $this->trans(
            'Adds a "Customers who bought this product also bought..."' .
            ' section to every product page.',
            array(),
            'Modules.Crossselling.Admin'
        );
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        );
    }

    public function install()
    {
        $this->_clearCache('*');

        return parent::install()
            && $this->registerHook('displayProductButtons')
            && $this->registerHook('displayShoppingCart')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && Configuration::updateValue('CROSSSELLING_DISPLAY_PRICE', 0)
            && Configuration::updateValue('CROSSSELLING_NBR', 10);
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
        $this->html = '';

        if (Tools::isSubmit('submitCross')) {
            if (0 != Tools::getValue('displayPrice')
                && 1 != Tools::getValue('CROSSSELLING_DISPLAY_PRICE')) {
                $this->html .= $this->displayError('Invalid displayPrice');
            } elseif (!($product_nbr = Tools::getValue('CROSSSELLING_NBR'))
                || empty($product_nbr)) {
                $this->html .= $this->displayError(
                    'You must fill in the "Number of displayed products" field.'
                );
            } elseif (0 === (int)$product_nbr) {
                $this->html .= $this->displayError('Invalid number.');
            } else {
                Configuration::updateValue(
                    'CROSSSELLING_DISPLAY_PRICE',
                    (int)Tools::getValue('CROSSSELLING_DISPLAY_PRICE')
                );
                Configuration::updateValue(
                    'CROSSSELLING_NBR',
                    (int)Tools::getValue('CROSSSELLING_NBR')
                );
                $this->_clearCache('*');
                $this->html .= $this->displayConfirmation(
                    $this->trans(
                        'Settings updated successfully',
                        array(),
                        'Modules.Crossselling.Admin'
                    )
                );
            }
        }

        return $this->html.$this->renderForm();
    }

    /**
     * @param array $products_id an array of product ids
     * @return array
     */
    protected function getOrderProducts(array $products_id = array())
    {
        if (!empty(self::$cache_order_products)) {
            return self::$cache_order_products;
        }

        $q_orders = 'SELECT o.id_order
        FROM '._DB_PREFIX_.'orders o
        LEFT JOIN '._DB_PREFIX_.'order_detail od 
        ON (od.id_order = o.id_order)
        WHERE o.valid = 1
        AND od.product_id IN ('.implode(',', $products_id).')';
        $orders = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($q_orders);

        $final_products_list = array();

        if (0 < count($orders)) {
            $list = '';
            foreach ($orders as $order) {
                $list .= (int)$order['id_order'].',';
            }
            $list = rtrim($list, ',');

            $list_product_ids = join(',', $products_id);

            if (Group::isFeatureActive()) {
                $sql_groups_join = '
                LEFT JOIN `'._DB_PREFIX_.'category_product` cp 
                ON (cp.`id_category` = product_shop.id_category_default
                AND cp.id_product = product_shop.id_product)
                LEFT JOIN `'._DB_PREFIX_.'category_group` cg 
                ON (cp.`id_category` = cg.`id_category`)';
                $groups = FrontController::getCurrentCustomerGroups();
                $sql_groups_where = 'AND cg.`id_group` '.
                    (count($groups) ? 'IN ('.implode(
                        ',',
                        $groups
                    ) . ')' : '=' . (int)Group::getCurrent()->id);
            }

            $order_products = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
                SELECT DISTINCT od.product_id,
                                pl.name,
                                pl.description_short,
                                pl.link_rewrite,
                                p.reference,
                                i.id_image,
                                product_shop.show_price,
                                cl.link_rewrite category,
                                p.ean13,
                                stock.out_of_stock,
                                IFNULL(stock.quantity, 0) as quantity
                FROM '._DB_PREFIX_.'order_detail od
                LEFT JOIN '._DB_PREFIX_.'product p
                ON (p.id_product = od.product_id)
                '.Shop::addSqlAssociation('product', 'p').
                (Combination::isFeatureActive() ? 'LEFT JOIN `' .
                _DB_PREFIX_.'product_attribute` pa
                ON (p.`id_product` = pa.`id_product`)
                ' . Shop::addSqlAssociation(
                        'product_attribute',
                        'pa',
                        false,
                        'product_attribute_shop.`default_on` = 1'
                    ).'
                ' . Product::sqlStock(
                        'p',
                        'product_attribute_shop',
                        false,
                        $this->context->shop
                    ) :  Product::sqlStock(
                        'p',
                        'product',
                        false,
                        $this->context->shop
                    )).'
                LEFT JOIN '._DB_PREFIX_.'product_lang pl
                ON (pl.id_product = od.product_id' .
                Shop::addSqlRestrictionOnLang('pl').')
                LEFT JOIN '._DB_PREFIX_.'category_lang cl
                ON (cl.id_category = product_shop.id_category_default'
                    .Shop::addSqlRestrictionOnLang('cl').')
                LEFT JOIN '._DB_PREFIX_.'image i
                ON (i.id_product = od.product_id)
                '.(Group::isFeatureActive() ? $sql_groups_join : '').'
                WHERE od.id_order IN ('.$list.')
                AND pl.id_lang = '.(int)$this->context->language->id.'
                AND cl.id_lang = '.(int)$this->context->language->id.'
                AND od.product_id NOT IN ('.$list_product_ids.')
                AND i.cover = 1
                AND product_shop.active = 1
                '.(Group::isFeatureActive() ? $sql_groups_where : '').'
                ORDER BY RAND()
                LIMIT '.(int)Configuration::get('CROSSSELLING_NBR'));

            foreach ($order_products as &$order_product) {
                $order_product['id_product'] =
                    (int)$order_product['product_id'];
                $order_product['image'] = $this->context->link->getImageLink(
                    $order_product['link_rewrite'],
                    (int)$order_product['product_id'] . '-' .
                    (int)$order_product['id_image'],
                    ImageType::getFormattedName('home')
                );
                $order_product['link'] = $this->context->link->getProductLink(
                    (int)$order_product['product_id'],
                    $order_product['link_rewrite'],
                    $order_product['category'],
                    $order_product['ean13']
                );
                $order_product['allow_oosp'] =
                    Product::isAvailableWhenOutOfStock(
                        (int)$order_product['out_of_stock']
                    );

                $imageIndex = $order_product['product_id'] .
                    '-' . $order_product['id_image'];

                if (!isset($final_products_list[$imageIndex])) {
                    $final_products_list[$imageIndex] = $order_product;
                }
            }
        }

        $assembler = new ProductAssembler($this->context);

        $presenterFactory = new ProductPresenterFactory($this->context);
        $presentationSettings = $presenterFactory->getPresentationSettings();
        $presenter = new ProductListingPresenter(
            new ImageRetriever(
                $this->context->link
            ),
            $this->context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $this->context->getTranslator()
        );

        $products_for_template = array();
        $showPrice = Configuration::get('CROSSSELLING_DISPLAY_PRICE');
        foreach ($final_products_list as $rawProduct) {
            $presentedProduct = $presenter->present(
                $presentationSettings,
                $assembler->assembleProduct($rawProduct),
                $this->context->language
            );
            $presentedProduct['show_price'] = $showPrice;
            $products_for_template[] = $presentedProduct;
        }

        return self::$cache_order_products = $products_for_template;
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->_clearCache('*');
    }

    protected function _clearCache(
        $template,
        $cacheId = null,
        $compileId = null
    ) {
        parent::_clearCache(
            'module:ps_crossselling/views/templates/hook/ps_crossselling.tpl'
        );
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans(
                        'Settings',
                        array(),
                        'Modules.Crossselling.Admin'
                    ),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->trans(
                            'Display price on products',
                            array(),
                            'Modules.Crossselling.Admin'
                        ),
                        'name' => 'CROSSSELLING_DISPLAY_PRICE',
                        'desc' => $this->trans(
                            'Show the price on the products in the block.',
                            array(),
                            'Modules.Crossselling.Admin'
                        ),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans(
                                    'Enabled',
                                    array(),
                                    'Modules.Crossselling.Admin'
                                ),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans(
                                    'Disabled',
                                    array(),
                                    'Modules.Crossselling.Admin'
                                ),
                            ),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans(
                            'Number of displayed products',
                            array(),
                            'Modules.Crossselling.Admin'
                        ),
                        'name' => 'CROSSSELLING_NBR',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->trans(
                            'Set the number of products displayed in this' .
                            ' block.',
                            array(),
                            'Modules.Crossselling.Admin'
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans(
                        'Save',
                        array(),
                        'Modules.Crossselling.Admin'
                    ),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang =
            Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?
            Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') :
            0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCross';
        $helper->currentIndex = $this->context->link->getAdminLink(
                'AdminModules',
                false
            ) .
            '&configure=' . $this->name .
            '&tab_module=' . $this->tab .
            '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'CROSSSELLING_NBR' => Tools::getValue(
                'CROSSSELLING_NBR',
                Configuration::get('CROSSSELLING_NBR')
            ),
            'CROSSSELLING_DISPLAY_PRICE' => Tools::getValue(
                'CROSSSELLING_DISPLAY_PRICE',
                Configuration::get('CROSSSELLING_DISPLAY_PRICE')
            ),
        );
    }

    public function getWidgetVariables($hookName, array $configuration)
    {
        $products = $this->getOrderProducts();

        return array(
            'products' => $products,
        );
    }

    public function renderWidget($hookName, array $configuration)
    {
        $cacheId = 'ps_crossselling|';

        if ('displayShoppingCart' === $hookName) {
            $productIds = array_map(function ($elem) {
                return $elem['id_product'];
            }, $configuration['cart']->getProducts());
        } else {
            $productIds = array($configuration['product']['id_product']);
        }

        if (empty($productIds)) {
            return;
        }

        $cacheId = $this->getCacheId($cacheId . implode('-', $productIds));
        $isCached = $this->isCached(
            'module:ps_crossselling/views/templates/hook/ps_crossselling.tpl',
            $cacheId
        );

        if (!$isCached) {
            if (!count($this->getOrderProducts($productIds))) {
                return;
            }
            $this->smarty->assign(
                $this->getWidgetVariables($hookName, $configuration)
            );
        }

        return $this->fetch(
            'module:ps_crossselling/views/templates/hook/ps_crossselling.tpl',
            $cacheId
        );
    }
}
