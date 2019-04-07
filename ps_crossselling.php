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
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;
use PrestaShop\PrestaShop\Core\Module\WidgetInterface;
use PrestaShop\PrestaShop\Core\Product\ProductListingPresenter;
use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

class Ps_Crossselling extends Module implements WidgetInterface
{
    /**
     * @var string
     */
    protected $templateFile;

    const HOOKS = [
        'displayFooterProduct',
        'actionObjectProductUpdateAfter',
        'actionObjectProductDeleteAfter',
    ];

    public function __construct()
    {
        $this->name = 'ps_crossselling';
        $this->author = 'PrestaShop';
        $this->version = '3.0.0';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '1.7.4.0',
            'max' => _PS_VERSION_,
        ];
        parent::__construct();

        $this->displayName = $this->trans('Cross-selling', [], 'Modules.Crossselling.Admin');
        $this->description = $this->trans('Offer your customers the possibility to buy matching items when on a product page.', [], 'Modules.Crossselling.Admin');

        $this->templateFile = 'module:ps_crossselling/views/templates/hook/ps_crossselling.tpl';
    }

    /**
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && Configuration::updateValue('CROSSSELLING_DISPLAY_PRICE', 1)
            && Configuration::updateValue('CROSSSELLING_NBR', 8)
            && $this->registerHook(static::HOOKS);
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('CROSSSELLING_DISPLAY_PRICE')
            && Configuration::deleteByName('CROSSSELLING_NBR');
    }

    /**
     * @return string
     */
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

        if (Tools::isSubmit('submitClearCache') && $this->clearCache()) {
            $html .= $this->displayConfirmation($this->trans('All caches cleared successfully', [], 'Admin.Advparameters.Notification'));
        }

        return $html . $this->renderForm();
    }

    /**
     * @param string $hookName
     * @param array $configuration
     *
     * @return array
     */
    public function getWidgetVariables($hookName, array $configuration)
    {
        $widgetVariables = [];
        $productIds = $this->getProductIds($configuration);

        if (!empty($productIds)) {
            $orderedProductIds = $this->getOrderProducts($productIds);

            $orderedProductsForTemplate = [];

            if (!empty($orderedProductIds)) {
                $assembler = new ProductAssembler($this->context);
                $presenterFactory = new ProductPresenterFactory($this->context);
                $presentationSettings = $presenterFactory->getPresentationSettings();
                $presenter = new ProductListingPresenter(
                    new ImageRetriever($this->context->link),
                    $this->context->link,
                    new PriceFormatter(),
                    new ProductColorsRetriever(),
                    $this->context->getTranslator()
                );
                $presentationSettings->showPrices = (bool) Configuration::get('CROSSSELLING_DISPLAY_PRICE');

                foreach ($orderedProductIds as $orderedProductId) {
                    $orderedProductsForTemplate[] = $presenter->present(
                        $presentationSettings,
                        $assembler->assembleProduct(['id_product' => $orderedProductId]),
                        $this->context->language
                    );
                }
            }

            if (!empty($orderedProductsForTemplate)) {
                $widgetVariables['products'] = $orderedProductsForTemplate;
            }
        }

        return $widgetVariables;
    }

    /**
     * @param string $hookName
     * @param array $configuration
     *
     * @return string|null
     */
    public function renderWidget($hookName, array $configuration)
    {
        $productIds = $this->getProductIds($configuration);

        if (empty($productIds)) {
            return null;
        }
        $variables = $this->getWidgetVariables($hookName, $configuration);

        if (empty($variables)) {
            return null;
        }

        $this->smarty->assign($variables);

        return $this->fetch($this->templateFile);
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
                'buttons' => [
                    'clear_cache' => [
                        'id' => 'clear_cache',
                        'title' => $this->trans('Clear cache', [], 'Admin.Advparameters.Feature'),
                        'icon' => 'process-icon-eraser',
                        'href' => $this->context->link->getAdminLink(
                            'AdminModules',
                            true,
                            [],
                            [
                                'configure' => $this->name,
                                'submitClearCache' => 1,
                            ]
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (bool) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCross';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) .
            '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                'CROSSSELLING_NBR' => Tools::getValue('CROSSSELLING_NBR', (int) Configuration::get('CROSSSELLING_NBR')),
                'CROSSSELLING_DISPLAY_PRICE' => Tools::getValue('CROSSSELLING_DISPLAY_PRICE', (bool) Configuration::get('CROSSSELLING_DISPLAY_PRICE')),
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Gets Product ids used to retrieve related products already ordered
     *
     * @param array{cookie?: Cookie, cart?: Cart, product?: array{id_product: int}, altern: int} $configuration Hooks arguments sent to the widget
     *
     * @return int[] Product ids in current Cart and/or Product id viewed
     */
    protected function getProductIds(array $configuration)
    {
        $productIds = [];
        $cart = (isset($configuration['cart'])) ? $configuration['cart'] : $this->context->cart;

        if (Validate::isLoadedObject($cart)) {
            $products = $cart->getProducts();
            if (!empty($products)) {
                foreach ($products as $product) {
                    $productIds[] = (int) $product['id_product'];
                }
            }
        }

        if (!empty($configuration['product']['id_product']) && !in_array($configuration['product']['id_product'], $productIds)) {
            $productIds[] = (int) $configuration['product']['id_product'];
        }

        return $productIds;
    }

    /**
     * Gets Product ids already ordered related to given product Ids
     *
     * @param int[] $productIds Product ids to find related ordered products
     *
     * @return int[] Related Product ids already ordered
     */
    protected function getOrderProducts(array $productIds = [])
    {
        $orderedProductIds = [];
        $db = Db::getInstance((bool) _PS_USE_SQL_SLAVE_);

        try {
            /** @var FilesystemCache $cache */
            $cache = $this->get('ps_crossselling.cache.products');
            $cacheKey = $this->getCacheId() . '|' . implode('|', $productIds);

            if ($cache->has($cacheKey)) {
                return $cache->get($cacheKey);
            }
        } catch (ServiceNotFoundException $exception) {
            // Some aggressive cache can cause temporary issue until be updated
            $cache = null;
            $cacheKey = null;
        }

        $queryOrderIds = new DbQuery();
        $queryOrderIds->select('o.id_order');
        $queryOrderIds->from('orders', 'o');
        $queryOrderIds->innerJoin('order_detail', 'od', 'od.id_order = o.id_order');
        $queryOrderIds->where('o.valid = 1' . Shop::addSqlRestriction(Shop::SHARE_ORDER, 'o'));
        $queryOrderIds->where('od.product_id IN (' . implode(',', $productIds) . ')');
        $queryOrderIds->orderBy('o.date_add DESC');
        $queryOrderIds->limit(1000);

        $resultQueryOrderIds = $db->executeS($queryOrderIds);

        if (!empty($resultQueryOrderIds)) {
            $orderIds = [];

            foreach ($resultQueryOrderIds as $resultQueryOrderId) {
                $orderIds[] = (int) $resultQueryOrderId['id_order'];
            }

            $queryOrderedProductIds = new DbQuery();
            $queryOrderedProductIds->select('DISTINCT od.product_id');
            $queryOrderedProductIds->from('order_detail', 'od');
            $queryOrderedProductIds->innerJoin('product', 'p', 'p.id_product = od.product_id');
            $queryOrderedProductIds->join(Shop::addSqlAssociation('product', 'p'));
            $queryOrderedProductIds->orderBy('RAND()');
            $queryOrderedProductIds->limit((int) Configuration::get('CROSSSELLING_NBR'));
            $queryOrderedProductIds->where('product_shop.active = 1');
            $queryOrderedProductIds->where('od.product_id NOT IN (' . implode(',', $productIds) . ')');
            $queryOrderedProductIds->where('od.id_order IN (' . implode(',', $orderIds) . ')');

            if (Group::isFeatureActive()) {
                $queryOrderedProductIds->innerJoin('category_product', 'cp', 'cp.id_category = product_shop.id_category_default AND cp.id_product = product_shop.id_product');
                $queryOrderedProductIds->innerJoin('category_group', 'cg', 'cp.id_category = cg.id_category');
                $groups = FrontController::getCurrentCustomerGroups();
                if (!empty($groups)) {
                    $queryOrderedProductIds->where('cg.id_group IN (' . implode(',', $groups) . ')');
                } else {
                    $queryOrderedProductIds->where('cg.id_group = ' . (int) Configuration::get('PS_UNIDENTIFIED_GROUP'));
                }
            }

            $resultQueryOrderedProductIds = $db->executeS($queryOrderedProductIds);

            if (!empty($resultQueryOrderedProductIds)) {
                foreach ($resultQueryOrderedProductIds as $resultQueryOrderedProductId) {
                    $orderedProductIds[] = (int) $resultQueryOrderedProductId['product_id'];
                }

                if ($cache) {
                    $cache->set($cacheKey, $orderedProductIds);
                }
            }
        }

        return $orderedProductIds;
    }

    /**
     * Clear cache after product change
     */
    public function hookActionObjectProductUpdateAfter()
    {
        $this->clearCache();
    }

    /**
     * Clear cache after product deletion
     */
    public function hookActionObjectProductDeleteAfter()
    {
        $this->clearCache();
    }

    /**
     * @return bool
     */
    protected function clearCache()
    {
        // Avoid cache clearing on PrestaShop installation
        if (defined('PS_INSTALLATION_IN_PROGRESS') && constant('PS_INSTALLATION_IN_PROGRESS')) {
            return true;
        }

        // Avoid cache clearing on product import
        if (defined('PS_MASS_PRODUCT_CREATION') && constant('PS_MASS_PRODUCT_CREATION')) {
            return true;
        }

        try {
            /** @var FilesystemCache $cache */
            $cache = $this->get('ps_crossselling.cache.products');

            return $cache->clear();
        } catch (ServiceNotFoundException $exception) {
            // Some aggressive cache can cause temporary issue until be updated
            return false;
        }
    }
}
