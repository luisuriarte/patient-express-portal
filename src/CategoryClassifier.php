<?php
/**
 * CategoryClassifier.php
 *
 * Resolves which express-portal section (imaging / laboratory / other)
 * each OpenEMR document category belongs to, using the
 * express_portal_category_mapping table.
 *
 * Classification model:
 *   - Only the ROOT category of each branch needs an explicit row.
 *   - Any unmapped category inherits the classification of its nearest
 *     mapped ancestor (following categories.parent).
 *   - A category with no mapped ancestor is unclassified (null).
 *
 * The categories tree and the mapping are loaded once per request and
 * cached statically; clearCache() is available for the admin screen
 * after saving.
 *
 * @package   OpenEMR
 * @author    Centro Médico Origen
 */

namespace App;

class CategoryClassifier
{
    /** @var array{all:array<int,array{parent:int,section:?string}>}|null */
    private static $cache = null;

    /**
     * Loads (once) the full categories tree and the current mapping.
     *
     * @return array{all:array<int,array{parent:int,section:?string}>}
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $all = [];
        $res = sqlStatement("SELECT id, parent FROM categories");
        if ($res) {
            while ($row = sqlFetchArray($res)) {
                $all[(int)$row['id']] = [
                    'parent'  => (int)($row['parent'] ?? 0),
                    'section' => null,
                ];
            }
        }

        $mappingRes = sqlStatement("SELECT category_id, section FROM express_portal_category_mapping");
        if ($mappingRes) {
            while ($row = sqlFetchArray($mappingRes)) {
                $id = (int)($row['category_id'] ?? 0);
                if ($id > 0 && isset($all[$id])) {
                    $all[$id]['section'] = (string)$row['section'];
                }
            }
        }

        self::$cache = ['all' => $all];
        return self::$cache;
    }

    /**
     * Resolves the section of a category by walking up the parent chain
     * until an explicitly mapped ancestor is found (memoized).
     *
     * @param  array<int,array{parent:int,section:?string}> $all
     * @param  array<int,?string>                            $memo
     */
    private static function resolveId(array &$all, int $id, array &$memo, int $depth = 0): ?string
    {
        if ($id <= 0 || $depth > 20) {
            return null;
        }
        if (array_key_exists($id, $memo)) {
            return $memo[$id];
        }
        $node = $all[$id] ?? null;
        if ($node === null) {
            return $memo[$id] = null;
        }
        if (!empty($node['section'])) {
            return $memo[$id] = $node['section'];
        }
        $parent = (int)$node['parent'];
        if ($parent <= 0 || $parent === $id) {
            return $memo[$id] = null;
        }
        return $memo[$id] = self::resolveId($all, $parent, $memo, $depth + 1);
    }

    /**
     * Returns every category id whose resolved classification equals the
     * requested section. Explicitly mapped roots are included together
     * with every unmapped descendant that inherits from them.
     *
     * @return int[]
     */
    public static function getCategoryIdsForSection(string $section): array
    {
        $data = self::load();
        $all  = $data['all'];
        $memo = [];
        $ids  = [];

        foreach ($all as $id => $node) {
            if (self::resolveId($all, $id, $memo) === $section) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Resolves the section for a single category id, or null when the
     * category (and none of its ancestors) has an explicit mapping.
     */
    public static function resolveSection(int $categoryId): ?string
    {
        $data = self::load();
        $all  = $data['all'];
        $memo = [];
        return self::resolveId($all, $categoryId, $memo);
    }

    /**
     * Drops the static cache (call after saving the admin screen).
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}