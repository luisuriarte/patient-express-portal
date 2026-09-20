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
$globalsLoaded = false;
foreach ([
    dirname(__DIR__, 2) . '/interface/globals.php',
    dirname(__DIR__, 3) . '/interface/globals.php',
    '/var/www/html/origen.ar/demo/interface/globals.php',
    '/var/www/html/origen.ar/hcd/interface/globals.php'
] as $globalsPath) {
    if (file_exists($globalsPath)) {
        require_once $globalsPath;
        $globalsLoaded = true;
        break;
    }
}
if (!$globalsLoaded) {
    die("OpenEMR globals.php not found");
}
$srcdir = class_exists(\OpenEMR\Core\OEGlobalsBag::class)
    ? \OpenEMR\Core\OEGlobalsBag::getInstance()->getSrcDir()
    : ($GLOBALS['srcdir'] ?? dirname($globalsPath, 2) . '/library');
$GLOBALS['srcdir'] = $srcdir;
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
<body class="bg-light">
<div class="container-fluid py-4 px-4">

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <div>
                <h4 class="mb-0 fw-bold"><?= xlt('Document Category Classification') ?></h4>
                <p class="text-muted small mb-0 mt-1">
                    <?= xlt('Only mark the root category of each branch — child categories automatically inherit the classification unless they have their own explicit value set below.') ?>
                </p>
            </div>
        </div>

        <?php if (!empty($_GET['saved'])): ?>
            <div class="alert alert-success rounded-0 mb-0 py-2">
                <i class="fa-solid fa-circle-check me-1"></i> <?= xlt('Saved.') ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token_form" value="<?= attr(CsrfUtils::collectCsrfToken(session: $session)) ?>">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4"><?= xlt('Category') ?></th>
                                <th style="width: 220px"><?= xlt('Section') ?></th>
                                <th><?= xlt('Inherited from') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($all as $id => $cat):
                            $depth = categoryDepth($all, $id);
                            $own = $mapping[$id] ?? '';
                            $inherited = CategoryClassifier::resolveSection($id);
                            $badgeClass = match ($inherited) {
                                'imaging' => 'bg-info-subtle text-info-emphasis',
                                'laboratory' => 'bg-success-subtle text-success-emphasis',
                                'other' => 'bg-secondary-subtle text-secondary-emphasis',
                                default => 'bg-light text-muted'
                            };
                        ?>
                            <tr>
                                <td class="ps-4" style="padding-left: <?= 40 + $depth * 22 ?>px">
                                    <i class="fa-solid fa-folder text-warning me-1"></i>
                                    <?= text($cat['name']) ?>
                                    <span class="text-muted small">#<?= $id ?></span>
                                </td>
                                <td>
                                    <select name="section[<?= $id ?>]" class="form-select form-select-sm rounded-2">
                                        <option value="" <?= $own === '' ? 'selected' : '' ?>><?= xlt('— No explicit value —') ?></option>
                                        <option value="imaging" <?= $own === 'imaging' ? 'selected' : '' ?>><?= xlt('Imaging') ?></option>
                                        <option value="laboratory" <?= $own === 'laboratory' ? 'selected' : '' ?>><?= xlt('Laboratory') ?></option>
                                        <option value="other" <?= $own === 'other' ? 'selected' : '' ?>><?= xlt('Other') ?></option>
                                    </select>
                                </td>
                                <td>
                                    <?php if ($own === '' && $inherited !== null): ?>
                                        <span class="badge rounded-pill <?= attr($badgeClass) ?>"><?= text($inherited) ?></span>
                                    <?php elseif ($own === '' && $inherited === null): ?>
                                        <span class="badge rounded-pill bg-light text-muted"><?= xlt('unclassified') ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="text-muted small">
                    <i class="fa-solid fa-chart-simple me-1"></i>
                    <strong><?= count($all) ?></strong> <?= xlt('categories') ?>
                </span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="window.close()">
                        <i class="fa-solid fa-times me-1"></i><?= xlt('Cancel') ?>
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk me-1"></i><?= xlt('Save') ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

</div>
</body>
</html>