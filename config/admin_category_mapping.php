<?php
/**
 * admin_category_mapping.php
 *
 * Configuration screen for the express portal: lets any logged-in
 * OpenEMR user classify document categories into imaging / laboratory /
 * other, backed by the express_portal_category_mapping table.
 *
 * Access control: intentionally NOT restricted to admin/super — this is
 * a low-risk settings screen (it only affects which portal tab a
 * category's documents show up under, it does not expose or alter
 * clinical data), and any clinician may need to adjust it. Matches the
 * same access level already used by new.php in this module (any
 * logged-in user via globals.php, no extra AclMain check).
 */
require_once(__DIR__ . "/../globals.php");
require_once("$srcdir/api.inc.php");
require_once(__DIR__ . '/../src/CategoryClassifier.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use App\CategoryClassifier;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

// Handle form submission: one <select> per category, POSTed as
// section[<category_id>] = 'imaging'|'laboratory'|'other'|''
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);

    foreach ($_POST['section'] ?? [] as $categoryId => $section) {
        $categoryId = (int)$categoryId;
        $section = in_array($section, ['imaging', 'laboratory', 'other'], true) ? $section : null;

        if ($section === null) {
            // Empty selection = remove any explicit mapping for this
            // category, letting it fall back to inheritance again.
            sqlStatement("DELETE FROM express_portal_category_mapping WHERE category_id = ?", [$categoryId]);
        } else {
            sqlStatement(
                "INSERT INTO express_portal_category_mapping (category_id, section, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE section = VALUES(section), updated_by = VALUES(updated_by)",
                [$categoryId, $section, $_SESSION['authUserID'] ?? null]
            );
        }
    }

    CategoryClassifier::clearCache();
    header('Location: admin_category_mapping.php?saved=1');
    exit;
}

// Build the full category tree, ordered by the nested-set `lft` column
// so parent/child relationships render in a natural top-down order.
$res = sqlStatement("SELECT id, name, parent, lft FROM categories ORDER BY lft");
$all = [];
while ($row = sqlFetchArray($res)) {
    $all[(int)$row['id']] = $row;
}

/**
 * Computes indentation depth for a category by counting how many
 * `parent` hops it takes to reach the tree root. Used purely for
 * visual indentation in the table, not for classification logic.
 */
function categoryDepth(array $all, int $id): int
{
    $depth = 0;
    $seen = [];
    while (!empty($all[$id]['parent']) && !isset($seen[$id])) {
        $seen[$id] = true;
        $id = (int)$all[$id]['parent'];
        $depth++;
    }
    return $depth;
}

$mappingRes = sqlStatement("SELECT category_id, section FROM express_portal_category_mapping");
$mapping = [];
while ($row = sqlFetchArray($mappingRes)) {
    $mapping[(int)$row['category_id']] = $row['section'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title>Category Mapping — Express Portal</title>
</head>
<body class="p-4">
<h3>Document Category Classification</h3>
<p class="text-muted">
    Only mark the root category of each branch — child categories
    automatically inherit the classification unless they have their own
    explicit value set below.
</p>
<?php if (!empty($_GET['saved'])): ?>
    <div class="alert alert-success">Saved.</div>
<?php endif; ?>
<form method="post">
    <input type="hidden" name="csrf_token_form" value="<?= attr(CsrfUtils::collectCsrfToken(session: $session)) ?>">
    <table class="table table-sm">
        <thead>
            <tr><th>Category</th><th>Section</th><th>Inherited from</th></tr>
        </thead>
        <tbody>
        <?php foreach ($all as $id => $cat):
            $depth = categoryDepth($all, $id);
            $own = $mapping[$id] ?? '';
            $inherited = CategoryClassifier::resolveSection($id);
        ?>
            <tr>
                <td style="padding-left: <?= $depth * 20 ?>px">
                    <?= text($cat['name']) ?> <small class="text-muted">(#<?= $id ?>)</small>
                </td>
                <td>
                    <select name="section[<?= $id ?>]" class="form-select form-select-sm">
                        <option value="" <?= $own === '' ? 'selected' : '' ?>>— No explicit value —</option>
                        <option value="imaging" <?= $own === 'imaging' ? 'selected' : '' ?>>Imaging</option>
                        <option value="laboratory" <?= $own === 'laboratory' ? 'selected' : '' ?>>Laboratory</option>
                        <option value="other" <?= $own === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </td>
                <td>
                    <?php if ($own === '' && $inherited !== null): ?>
                        <span class="badge bg-secondary">inherited: <?= text($inherited) ?></span>
                    <?php elseif ($own === '' && $inherited === null): ?>
                        <span class="badge bg-light text-dark">unclassified</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <button type="submit" class="btn btn-primary">Save</button>
</form>
</body>
</html>