<?php

declare(strict_types=1);

namespace internetztube\slugEqualsTitle\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

/**
 * The settings name the enabled sections, category groups and product types by UID now (as Craft does; a renamed
 * handle no longer drops the type from the setting). Settings saved by 3.0 hold handles: this turns them into UIDs
 * in project config once. Handles of types that no longer exist, and the ";" placeholder 3.0 stored, are dropped.
 */
final class m261103_130000_settings_uids extends Migration
{
    public const SCHEMA_VERSION = '1.2.0';

    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        // project config changes travel with the YAML: an environment that already got them (its YAML's schema
        // version is this one or newer) must not apply them again
        $schemaVersion = $projectConfig->get('plugins.slug-equals-title.schemaVersion', true);
        if (is_string($schemaVersion) && version_compare($schemaVersion, self::SCHEMA_VERSION, '>=')) {
            return true;
        }
        $settings = $projectConfig->get('plugins.slug-equals-title.settings', true);
        if (is_array($settings)) {
            $projectConfig->set('plugins.slug-equals-title.settings', self::toUids(ProjectConfigHelper::unpackAssociativeArrays($settings)), 'Slug Equals Title: settings by UID');
        }
        return true;
    }

    /**
     * The settings with every handle replaced by the type's UID (a value that is no UID of a type and no handle of
     * an existing one is dropped).
     * @param array<string, mixed> $settings
     * @return array<string, list<string>>
     */
    public static function toUids(array $settings): array
    {
        $lists = [
            'enabledSections' => Craft::$app->getEntries()->getAllSections(),
            'enabledCategoryGroups' => Craft::$app->getCategories()->getAllGroups(),
            'enabledProductTypes' => Craft::$app->getPlugins()->isPluginEnabled('commerce') ? \craft\commerce\Plugin::getInstance()->getProductTypes()->getAllProductTypes() : [],
        ];
        $result = [];
        foreach ($lists as $name => $types) {
            $byHandle = [];
            $uids = [];
            foreach ($types as $type) {
                $byHandle[(string)$type->handle] = (string)$type->uid;
                $uids[] = (string)$type->uid;
            }
            $values = is_array($settings[$name] ?? null) ? $settings[$name] : [];
            $converted = [];
            foreach ($values as $value) {
                if (!is_string($value)) {
                    continue;
                }
                if (in_array($value, $uids, true)) {
                    $converted[] = $value;
                } elseif (isset($byHandle[$value])) {
                    $converted[] = $byHandle[$value];
                }
            }
            $result[$name] = array_values(array_unique($converted));
        }
        return $result;
    }

    public function safeDown(): bool
    {
        echo "m261103_130000_settings_uids cannot be reverted.\n";
        return false;
    }
}
