<?php
/**
 * category_functions.php
 *
 * Helpers for the folder selector (category tree) of the imaging diagnostic
 * form.
 *
 * Requires: globals.php already included (provides sqlStatement()/sqlQuery()).
 *
 * Pattern based on the odontogram module (php/odon_document_functions.php):
 *  - No hardcoded IDs.
 *  - Tree traversal via the `parent` field (not dependent on lft/rght).
 *  - All exposed categories are patient categories (aco_spec = 'patients|docs').
 */

/** aco_spec of visible/valid categories for patient documents */
define('IMAGING_ACO_SPEC', 'patients|docs');

/**
 * Returns the patient documents category tree as a flat ordered list
 * (id, name, parent, depth) prepared for rendering.
 *
 * @return array{0:array{id:int,name:string,parent:int,depth:int}}
 */
function imaging_get_category_tree(): array
{
    $result = sqlStatement(
        "SELECT id, name, parent FROM categories WHERE aco_spec = ? ORDER BY id",
        [IMAGING_ACO_SPEC]
    );

    $all = [];
    while ($row = sqlFetchArray($result)) {
        $id = (int)$row['id'];
        $all[$id] = [
            'id' => $id,
            'name' => (string)$row['name'],
            'parent' => (int)$row['parent'],
            'children' => [],
        ];
    }

    // Build child lists using the reference pointer without modifying the
    // original order: we only repoint to the children.
    foreach ($all as $id => &$node) {
        $pid = $node['parent'];
        // Prevents cycles (parent === id) and links to non-existent nodes.
        if ($pid !== $id && isset($all[$pid])) {
            $all[$pid]['children'][] = $id;
        }
    }
    unset($node);

    // Breadth-first traversal (BFS) to obtain a flat list in tree order.
    $depthById = [];
    $roots = [];
    foreach ($all as $id => $node) {
        if ($node['parent'] === 0 || $node['parent'] === $id) {
            $roots[] = $id;
        }
    }

    $tree = [];
    $queue = [];
    foreach ($roots as $rid) {
        $queue[] = [$rid, 0];
    }

    while (!empty($queue)) {
        [$id, $depth] = array_shift($queue);
        if (!isset($all[$id])) {
            continue;
        }
        $node = $all[$id];
        $tree[] = [
            'id' => $node['id'],
            'name' => $node['name'],
            'parent' => $node['parent'],
            'depth' => $depth,
        ];
        foreach ($node['children'] as $childId) {
            $queue[] = [$childId, $depth + 1];
        }
    }

    return $tree;
}

/**
 * Validates that a category belongs to the patient documents tree
 * (aco_spec = patients|docs). Traverses the parent chain up to the
 * root (parent 0 or parent === id).
 *
 * @return bool
 */
function imaging_is_valid_document_category(int $categoryId): bool
{
    if ($categoryId <= 0) {
        return false;
    }

    $current = $categoryId;
    $limit = 12;

    while ($limit-- > 0) {
        $row = sqlQuery(
            "SELECT parent FROM categories WHERE id = ? AND aco_spec = ? LIMIT 1",
            [$current, IMAGING_ACO_SPEC]
        );
        if (empty($row)) {
            return false;
        }
        $parent = (int)$row['parent'];
        // We reached the root -> it is valid.
        if ($parent === 0 || $parent === $current) {
            return true;
        }
        $current = $parent;
    }

    return false;
}

/**
 * Translates a form modality to a subcategory name under "Images".
 * Returns '' if no associated subcategory (the general one is used).
 */
function imaging_subcategory_name(string $modalidad): string
{
    $map = [
        'RMN' => 'Resonancia Magnética',
        'TC'   => 'Tomografía',
    ];
    return $map[$modalidad] ?? '';
}

/**
 * Default category for a modality:
 *  1. Modality subcategory under "Images" if it exists (MRI/CT).
 *  2. The general top-level "Images" category if it exists.
 *  3. 0 if none (the form fallback will be used).
 */
function imaging_default_category_id(string $modalidad): int
{
    $imagenes = sqlQuery(
        "SELECT id FROM categories WHERE name = 'Imágenes' AND parent = 1 ORDER BY id LIMIT 1"
    );
    $imagenesId = (int)($imagenes['id'] ?? 0);
    if ($imagenesId <= 0) {
        return 0;
    }

    $subName = imaging_subcategory_name($modalidad);
    if ($subName !== '') {
        $sub = sqlQuery(
            "SELECT id FROM categories WHERE parent = ? AND name = ? ORDER BY id LIMIT 1",
            [$imagenesId, $subName]
        );
        if (!empty($sub['id'])) {
            return (int)$sub['id'];
        }
    }

    return $imagenesId;
}

/**
 * Resolves the category where the report should be stored.
 *
 * If the user manually chose a folder (and it is valid), that one is used.
 * Otherwise, the automatic modality-based logic is applied
 * (imaging_default_category_id), and if that also doesn't exist, "Images"
 * is created.
 */
function imaging_resolve_category_id(int $userCategoryId, string $modalidad): int
{
    if ($userCategoryId > 0 && imaging_is_valid_document_category($userCategoryId)) {
        return $userCategoryId;
    }

    $auto = imaging_default_category_id($modalidad);
    if ($auto > 0) {
        return $auto;
    }

    // Fallback: create "Images" under the root (id = 1 "Categories") using the
    // CategoryTree class, which correctly maintains lft/rght (MPTT).
    $categoryTree = new \CategoryTree(1);
    $newId = $categoryTree->add_node(1, 'Imágenes', 'imaging', IMAGING_ACO_SPEC, '');

    return (int)$newId;
}

/**
 * Returns the name of a category given its flat list, or '' if not found.
 */
function imaging_category_name(array $flat, int $categoryId): string
{
    foreach ($flat as $node) {
        if ((int)$node['id'] === $categoryId) {
            return (string)$node['name'];
        }
    }
    return '';
}

/**
 * Renders the category tree as collapsible HTML.
 *
 * @param array $flat  Result from imaging_get_category_tree()
 * @param int   $selectedId  Category to pre-select (marks "selected")
 * @param int   $expandToId  Category whose subtree should be expanded (defaults
 *                           to the selected one or the general images category).
 * @return string
 */
function imaging_render_category_tree(array $flat, int $selectedId = 0, int $expandToId = 0): string
{
    if (empty($flat)) {
        return '<div class="imr-tree-empty">' . xlt('No categories available.') . '</div>';
    }

    // Index by id + child lists.
    $byId = [];
    foreach ($flat as $node) {
        $byId[$node['id']] = $node + ['children' => []];
    }

    foreach ($byId as $id => &$node) {
        $pid = $node['parent'];
        if ($pid !== $id && isset($byId[$pid])) {
            $byId[$pid]['children'][] = $id;
        }
    }
    unset($node);

    // Determine which subtree to expand by default: expand to the
    // selected category (so it is visible when editing) or to the
    // general "Images" category if no selection.
    $expandToId = $expandToId ?: $selectedId;
    $expanded = [];
    $cur = $expandToId;
    while ($cur > 0 && isset($byId[$cur])) {
        $expanded[$cur] = true;
        $p = (int)$byId[$cur]['parent'];
        if ($p === $cur || $p === 0) {
            break;
        }
        $cur = $p;
    }

    $roots = [];
    foreach ($byId as $id => $node) {
        if ($node['parent'] === 0 || $node['parent'] === $id) {
            $roots[] = $id;
        }
    }

    $html = '';
    foreach ($roots as $rid) {
        $html .= imaging_render_nodes_recursive($byId, $rid, 0, $selectedId, $expanded);
    }

    return $html;
}

function imaging_render_nodes_recursive(array $byId, int $id, int $level, int $selectedId, array $expanded): string
{
    $node = $byId[$id];
    $hasChildren = !empty($node['children']);
    $indent = $level * 18;
    $icon = $hasChildren ? 'fa-folder' : 'fa-folder-open-o';

    $cls = 'imr-tree-node';
    if ($hasChildren) {
        $cls .= ' imr-has-children';
    }
    if ($id === $selectedId) {
        $cls .= ' imr-selected';
    }

    $nameEsc = htmlspecialchars((string)$node['name'], ENT_QUOTES);
    $open = !empty($expanded[$id]);

    $html = sprintf(
        '<div class="%s" data-id="%d" data-name="%s" role="button" tabindex="0">
            <span class="imr-tree-indent" style="width:%dpx"></span>
            <span class="imr-tree-toggle%s"><i class="fa fa-caret-down"></i></span>
            <span class="imr-tree-icon"><i class="fa %s"></i></span>
            <span class="imr-tree-label">%s</span>
        </div>',
        $cls,
        $node['id'],
        $nameEsc,
        $indent,
        $hasChildren ? ($open ? '' : ' imr-collapsed') : ' imr-hidden-toggle',
        $icon,
        $nameEsc
    );

    if ($hasChildren) {
        $html .= sprintf(
            '<div class="imr-tree-children%s" id="imr-children-%d">',
            $open ? '' : ' imr-hidden',
            $node['id']
        );
        foreach ($node['children'] as $childId) {
            $html .= imaging_render_nodes_recursive($byId, $childId, $level + 1, $selectedId, $expanded);
        }
        $html .= '</div>';
    }

    return $html;
}
