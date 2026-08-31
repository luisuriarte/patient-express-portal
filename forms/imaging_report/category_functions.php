<?php
/**
 * category_functions.php
 *
 * Helpers para el selector de carpeta (árbol de categorías) del formulario de
 * diagnóstico por imágenes.
 *
 * Requiere: globals.php ya incluido (dispone de sqlStatement()/sqlQuery()).
 *
 * Patrón basado en el módulo odontogram (php/odon_document_functions.php):
 *  - Sin IDs hardcodeados.
 *  - Recorrido del árbol por el campo `parent` (sin depender de lft/rght).
 *  - Todas las categorías expuestas son de pacientes (aco_spec = 'patients|docs').
 */

/** aco_spec de las categorías visibles/validas para documentos de pacientes */
define('IMAGING_ACO_SPEC', 'patients|docs');

/**
 * Devuelve el árbol de categorías de documentos de pacientes como lista plana
 * ordenada (id, name, parent, depth) preparada para renderizar.
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

    // Armar las listas de hijos usando el puntero de referencia sin modificar el
    // orden original: solo reapuntamos a los hijos.
    foreach ($all as $id => &$node) {
        $pid = $node['parent'];
        // Evita ciclos (parent === id) y enlaces a nodos inexistentes.
        if ($pid !== $id && isset($all[$pid])) {
            $all[$pid]['children'][] = $id;
        }
    }
    unset($node);

    // Recorrido por anchura (BFS) para obtener una lista plana en orden del árbol.
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
 * Valida que una categoría pertenezca al árbol de documentos de pacientes
 * (aco_spec = patients|docs). Recorre la cadena de padres hasta llegar a la
 * raíz (parent 0 o parent === id).
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
        // Llegamos a la raíz -> es válida.
        if ($parent === 0 || $parent === $current) {
            return true;
        }
        $current = $parent;
    }

    return false;
}

/**
 * Traduce una modalidad del formulario a un nombre de subcategoría bajo
 * "Imágenes". Devuelve '' si no hay subcategoría asociada (se usa la general).
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
 * Categoría por defecto para una modalidad:
 *  1. Subcategoría de modalidad bajo "Imágenes" si existe (RMN/TC).
 *  2. La categoría general "Imágenes" de nivel superior si existe.
 *  3. 0 si no hay ninguna (se usará el fallback del formulario).
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
 * Resuelve la categoría donde guardar el informe.
 *
 * Si el usuario eligió una carpeta manualmente (y es válida), se usa esa.
 * En caso contrario se aplica la lógica automática por modalidad
 * (imaging_default_category_id), y si tampoco existe se crea "Imágenes".
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

    // Fallback: crear "Imágenes" bajo la raíz (id = 1 "Categories") usando la
    // clase CategoryTree, que mantiene correctamente lft/rght (MPTT).
    $categoryTree = new \CategoryTree(1);
    $newId = $categoryTree->add_node(1, 'Imágenes', 'imaging', IMAGING_ACO_SPEC, '');

    return (int)$newId;
}

/**
 * Devuelve el nombre de una categoría dada su lista plana, o '' si no existe.
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
 * Renderiza el árbol de categorías como HTML colapsable.
 *
 * @param array $flat  Resultado de imaging_get_category_tree()
 * @param int   $selectedId  Categoría a preseleccionar (marca "selected")
 * @param int   $expandToId  Categoría cuyo subárbol se debe expandir (por defecto
 *                           seleccionada o la general de imágenes).
 * @return string
 */
function imaging_render_category_tree(array $flat, int $selectedId = 0, int $expandToId = 0): string
{
    if (empty($flat)) {
        return '<div class="imr-tree-empty">' . xlt('No hay categorías disponibles.') . '</div>';
    }

    // Índice por id + listas de hijos.
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

    // Determinar qué subárbol expandir por defecto: expandimos hasta la
    // categoría seleccionada (para que quede visible al editar) o hasta la
    // general "Imágenes" si no hay selección.
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
