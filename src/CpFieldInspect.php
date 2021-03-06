<?php
/**
 * CP Field Inspect plugin for Craft CMS 3.x
 *
 * Inspect field handles and easily edit field settings
 *
 * @link      http://mmikkel.no
 * @copyright Copyright (c) 2017 Mats Mikkel Rummelhoff
 */

namespace mmikkel\cpfieldinspect;

use Craft;
use craft\base\Plugin;
use craft\db\Query;
use craft\db\Table;
use craft\events\PluginEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use craft\services\Plugins;

use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
 * @author    Mats Mikkel Rummelhoff
 * @package   CpFieldInspect
 * @since     1.0.0
 *
 *
 * Plugin icon credit: CUSTOMIZE SEARCH by creative outlet from the Noun Project
 *
 */

/**
 * Class CpFieldInspect
 * @package mmikkel\cpfieldinspect
 *
 */
class CpFieldInspect extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * CpFieldInspect::$plugin
     *
     * @var CpFieldInspect
     */
    public static $plugin;

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * CpFieldInspect::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        $request = Craft::$app->getRequest();
        // this will break the fields and plugins initialization
        // https://github.com/craftcms/cms/issues/4944
        // https://github.com/mmikkel/CpFieldInspect-Craft/issues/11
        // $fields = Craft::$app->getFields()->getAllFields();
        if (/*!$user->getIsAdmin() || */ !$request->getIsCpRequest() || $request->getIsConsoleRequest()) {
            return;
        }

        // this is hacky and ugly but I don't know an alternative... we can't populate the user yet ¯\_(ツ)_/¯
        $session = Craft::$app->getSession();
        $id = $session->getHasSessionId() || $session->getIsActive() ? $session->get(Craft::$app->getUser()->idParam) : null;
        if(empty($id) === true){
            return;
        }
        $isAdmin = (new Query())
            ->select('admin')
            ->from(Table::USERS)
            ->where(['id' => $id])
            ->scalar();
        if((bool)$isAdmin === false){
            return;
        }

        // Handler: EVENT_AFTER_LOAD_PLUGINS
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_LOAD_PLUGINS,
            function () {
                $this->doIt();
            }
        );

        /**
         * Logging in Craft involves using one of the following methods:
         *
         * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
         * Craft::info(): record a message that conveys some useful information.
         * Craft::warning(): record a warning message that indicates something unexpected has happened.
         * Craft::error(): record a fatal error that should be investigated as soon as possible.
         *
         * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
         *
         * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
         * the category to the method (prefixed with the fully qualified class name) where the constant appears.
         *
         * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
         * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
         *
         * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
         */
        Craft::info(
            Craft::t(
                'cp-field-inspect',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * @return bool
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    protected function doIt()
    {

        $request = Craft::$app->getRequest();

        if ($request->getIsAjax()) {

            if (!$request->getIsPost()) {
                return;
            }

            $segments = $request->getSegments();
            $actionSegment = $segments[count($segments) - 1];

            if ($actionSegment !== 'get-editor-html') {
                return false;
            }

            Craft::$app->getView()->registerJs('Craft.CpFieldInspectPlugin.initElementEditor();');

        } else {

            $redirectUrl = \implode('?', \array_filter([\implode('/', $request->getSegments()), $request->getQueryStringWithoutPath()]));

            $data = [
                'fields' => [],
                'entryTypeIds' => [],
                'baseEditFieldUrl' => \rtrim(UrlHelper::cpUrl('settings/fields/edit'), '/'),
                'baseEditEntryTypeUrl' => \rtrim(UrlHelper::cpUrl('settings/sections/sectionId/entrytypes'), '/'),
                'baseEditGlobalSetUrl' => \rtrim(UrlHelper::cpUrl('settings/globals'), '/'),
                'baseEditCategoryGroupUrl' => \rtrim(UrlHelper::cpUrl('settings/categories'), '/'),
                'baseEditCommerceProductTypeUrl' => \rtrim(UrlHelper::cpUrl('commerce/settings/producttypes'), '/'),
                'redirectUrl' => Craft::$app->getSecurity()->hashData($redirectUrl),
            ];

            $sectionIds = Craft::$app->getSections()->getAllSectionIds();
            foreach ($sectionIds as $sectionId) {
                $entryTypes = Craft::$app->getSections()->getEntryTypesBySectionId($sectionId);
                $data['entryTypeIds'][(string)$sectionId] = [];
                foreach ($entryTypes as $entryType) {
                    $data['entryTypeIds'][(string)$sectionId][] = $entryType->id;
                }
            }


            // this will break the fields and plugins initialization
            // https://github.com/craftcms/cms/issues/4944
            // https://github.com/mmikkel/CpFieldInspect-Craft/issues/11
            // $fields = Craft::$app->getFields()->getAllFields();

            // query for the fields myself because otherwise it will mess up the users field layout
            $fields = (new Query())
                ->select([
                             'fields.id',
                             'fields.handle',
                         ])
                ->from(['{{%fields}} fields'])
                ->where(['context' => 'global'])
                ->orderBy(['fields.name' => SORT_ASC, 'fields.handle' => SORT_ASC])
                ->all();


            $data['fields'] = ArrayHelper::index($fields, 'handle');
            $view = Craft::$app->getView();
            $view->registerAssetBundle(CpFieldInspectBundle::class);
            $view->registerJs('Craft.CpFieldInspectPlugin.init(' . \json_encode($data) . ');');
        }
    }

}
