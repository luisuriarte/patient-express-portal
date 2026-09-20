<?php

/**
 * Script to pick a procedure order type from the compendium.
 *
 * Modified to add:
 *  - A visible "Provider" (lab) selector so the user can change providers
 *    without closing and reopening the popup.
 *  - A "Procedure Type" selector that filters by procedure_type_name within
 *    the selected provider.
 *  - Both filters are applied before the text search.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2013 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");

use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;

$order = (int) ($_REQUEST['order'] ?? null);
// labid: the pre-selected provider from the calling form (editable in the popup).
$labid = (int) ($_REQUEST['labid'] ?? 0);
// Order type (imaging, laboratory_test, procedure, etc.) sent from the form.
// When it is 'imaging', the search only shows the DIAGNOSTIC IMAGING subtree.
$otype = trim((string) ($_REQUEST['otype'] ?? ''));
// Procedure type name filter (chosen by the user within the popup, persists on search).
$filter_ptn = trim((string) ($_REQUEST['filter_ptn'] ?? $otype));

//////////////////////////////////////////////////////////////////////
// The form was submitted with the selected code type.
if (isset($_GET['typeid'])) {
    $grporders = [];
    $typeid = (int) $_GET['typeid'];
    $name = '';
    $ptrow = [];
    $codes = '';
    $transport = '';
    $testid = '';
    $proctype_name = '';
    $pt_lab_id = 0;
    if ($typeid) {
        $ptrow = sqlQuery("SELECT * FROM procedure_type WHERE procedure_type_id = ?", [$typeid]);
        $name = $ptrow['name'] ?? '';
        $proctype = trim((string) ($ptrow['procedure_type'] ?? ''));
        $codes = ($proctype === 'pro') ? '' : ($ptrow['related_code'] ?? '');
        $transport = trim($ptrow['transport'] ?? '');
        $testid = trim((string) ($ptrow['procedure_code'] ?? ''));
        $proctype_name = trim((string) ($ptrow['procedure_type_name'] ?? ''));
        // Pass lab_id back so common.php can auto-select the correct provider
        $pt_lab_id = (int)($ptrow['lab_id'] ?? 0);

        if (($ptrow['procedure_type'] ?? '') == 'fgp') {
            $res = sqlStatement("SELECT * FROM procedure_type WHERE parent = ? && procedure_type = 'for' ORDER BY seq, name, procedure_type_id", [$typeid]);
            while ($row = sqlFetchArray($res)) {
                $grporders[] = $row;
            }
        }
    }
    ?>
    <script src="<?php echo OEGlobalsBag::getInstance()->getWebRoot() ?>/interface/main/tabs/js/include_opener.js?v=<?php echo attr_url(OEGlobalsBag::getInstance()->getString('v_js_includes')); ?>"></script>
    <script>
        if (opener.closed) {
            alert(<?php echo xlj('The destination form was closed; I cannot act on your selection.'); ?>);
        }
        else {
            <?php
            if (isset($_GET['addfav'])) {
                $order = json_encode($ptrow);
                echo "opener.set_new_fav($order);\nwindow.close();";
            }
            $i = 0;
            $t = 0;
            do {
                if (!isset($grporders[$i]['procedure_type_id'])) {
                    echo "opener.set_proc_type(" . js_escape($typeid) . ", " . js_escape($name) . ", " . js_escape($codes) . ", " . js_escape($transport) . ", " . js_escape($proctype_name) . ", " . js_escape($testid) . ", '0', " . js_escape($pt_lab_id) . ");\n";
                } else {
                    $t = count($grporders) - $i;
                    $typeid = $grporders[$i]['procedure_type_id'] + 0;
                    $name = ($grporders[$i]['name']);
                    $codes = ($grporders[$i]['related_code']);
                    $transport = trim((string) ($ptrow['transport'] ?? ''));
                    $testid = trim((string) ($ptrow['procedure_code'] ?? ''));
                    $proctype_name = trim((string) ($ptrow['procedure_type_name'] ?? ''));
                    // For grouped orders, use the lab_id of the parent group row
                    $grp_lab_id = (int)($grporders[$i]['lab_id'] ?? $pt_lab_id);
                    echo "opener.set_proc_type(" . js_escape($typeid) . ", " . js_escape($name) . ", " . js_escape($codes) . ", " . js_escape($transport) . ", " . js_escape($proctype_name) . ", " . js_escape($testid) . ", " . js_escape($t) . ", " . js_escape($grp_lab_id) . ");\n";
                }
                // This is to generate the "Questions at Order Entry" for the Procedure Order form.
                // GET parms needed for this are: formid, formseq.
                if (isset($_GET['formid'])) {
                    if ($typeid) {
                        require_once("qoe.inc.php");
                        $qoe_init_javascript = '';
                        echo ' opener.set_proc_html("';
                        echo generate_qoe_html($typeid, (int)$_GET['formid'], 0, (int)$_GET['formseq']);
                        echo '", "' . $qoe_init_javascript . '");' . "\n";
                    } else {
                        echo ' opener.set_proc_html("", "");' . "\n";
                    }
                }
                $i++;
            } while ($grporders[$i]['procedure_type_id'] ?? null);
            ?>
        }
        window.close();
    </script>
    <?php
    exit();
}

// End Submission.
//////////////////////////////////////////////////////////////////////

// Fetch all active providers for the selector dropdown.
$providerRows = [];
$provRes = sqlStatement(
    "SELECT ppid, name FROM procedure_providers WHERE activity = 1 ORDER BY name, ppid"
);
while ($pr = sqlFetchArray($provRes)) {
    $providerRows[] = $pr;
}

// Fetch the distinct procedure_type_name values for the selected provider.
// This populates the second dropdown. When labid = 0 we still show an
// "Any" option and list all types.
$typeNameRows = [];
if ($labid > 0) {
    $tnRes = sqlStatement(
        "SELECT DISTINCT procedure_type_name
         FROM procedure_type
         WHERE lab_id = ? AND activity = 1 AND procedure_type_name IS NOT NULL AND procedure_type_name != ''
         ORDER BY procedure_type_name",
        [$labid]
    );
} else {
    $tnRes = sqlStatement(
        "SELECT DISTINCT procedure_type_name
         FROM procedure_type
         WHERE activity = 1 AND procedure_type_name IS NOT NULL AND procedure_type_name != ''
         ORDER BY procedure_type_name"
    );
}
while ($tn = sqlFetchArray($tnRes)) {
    $typeNameRows[] = (string)$tn['procedure_type_name'];
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['opener']); ?>
    <title><?php echo xlt('Procedure Picker'); ?></title>

    <style>
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .filter-row label {
            font-weight: 600;
            margin-bottom: 0;
            white-space: nowrap;
        }
        .filter-row select {
            flex: 1 1 180px;
            min-width: 140px;
        }
        #search_term {
            flex: 1 1 200px;
        }
        .results-info {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 0.25rem;
        }
    </style>

    <script>
        // Reload the script with the select procedure type ID.
        function selcode(typeid) {
            <?php
            echo "const params = new URLSearchParams({";
            echo "order: " . js_escape($order) . ", ";
            echo "labid: " . js_escape($labid) . ", ";
            echo "otype: " . js_escape($otype);
            if (isset($_GET['addfav'])) {
                echo ", addfav: " . js_escape($_GET['addfav']);
            }
            if (isset($_GET['formid'])) {
                echo ", formid: " . js_escape($_GET['formid']);
            }
            if (isset($_GET['formseq'])) {
                echo ", formseq: " . js_escape($_GET['formseq']);
            }
            echo ", typeid: typeid";
            echo "});";
            ?>

            location.href = 'find_order_popup.php?' + params.toString();
            return false;
        }

        /**
         * When the provider selector changes, reload the page with the new
         * labid so the procedure_type_name dropdown is refreshed.
         */
        function onProviderChange(sel) {
            const labid = sel.value;
            <?php
            $baseUrl = 'find_order_popup.php?order=' . attr_url($order)
                     . '&otype=' . attr_url($otype);
            if (isset($_GET['formid']))  $baseUrl .= '&formid='  . attr_url($_GET['formid']);
            if (isset($_GET['formseq'])) $baseUrl .= '&formseq=' . attr_url($_GET['formseq']);
            if (isset($_GET['addfav']))  $baseUrl .= '&addfav='  . attr_url($_GET['addfav']);
            echo "const base = " . js_escape($baseUrl) . ";";
            ?>
            location.href = base + '&labid=' + encodeURIComponent(labid);
        }
    </script>
</head>
<body>
<div class="container-fluid pt-2">
    <form method='post' name='theform' action='find_order_popup.php<?php
        echo "?order=" . attr_url($order)
            . "&labid=" . attr_url($labid)
            . "&otype=" . attr_url($otype)
            . "&filter_ptn=" . attr_url($filter_ptn);
        if (isset($_GET['formid']))  echo '&formid='  . attr_url($_GET['formid']);
        if (isset($_GET['formseq'])) echo '&formseq=' . attr_url($_GET['formseq']);
        if (isset($_GET['addfav']))  echo '&addfav='  . attr_url($_GET['addfav']);
    ?>'>

        <input type="hidden" name='isfav' value='<?php echo attr($_REQUEST['ordLookup'] ?? ''); ?>' />

        <!-- Row 1: Provider selector -->
        <div class="filter-row">
            <label for="sel_labid"><?php echo xlt('Provider'); ?>:</label>
            <select id="sel_labid" name="sel_labid" class="form-control form-control-sm"
                    onchange="onProviderChange(this)">
                <option value="0"><?php echo xlt('-- All providers --'); ?></option>
                <?php foreach ($providerRows as $pr): ?>
                    <option value="<?php echo attr($pr['ppid']); ?>"
                        <?php echo ((int)$pr['ppid'] === $labid) ? 'selected' : ''; ?>>
                        <?php echo text($pr['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Row 2: Procedure type name filter -->
        <div class="filter-row">
            <label for="filter_ptn"><?php echo xlt('Type'); ?>:</label>
            <select id="filter_ptn" name="filter_ptn" class="form-control form-control-sm">
                <option value=""><?php echo xlt('-- All types --'); ?></option>
                <?php foreach ($typeNameRows as $tn): ?>
                    <option value="<?php echo attr($tn); ?>"
                        <?php echo ($tn === $filter_ptn) ? 'selected' : ''; ?>>
                        <?php echo text($tn); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Row 3: Text search -->
        <div class="filter-row">
            <label for="search_term"><?php echo xlt('Search'); ?>:</label>
            <input class="form-control form-control-sm" id='search_term' name='search_term'
                   value='<?php echo attr($_REQUEST['search_term'] ?? ''); ?>'
                   title='<?php echo xla('Any part of the desired code or its description'); ?>'
                   placeholder="<?php echo xla('Code or description') ?>&hellip;"/>
            <span class="input-group-append d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm" name='bn_search' value="true">
                    <?php echo xlt('Search'); ?>
                </button>
                <?php if (!isset($_REQUEST['addfav'])): ?>
                <button type="submit" class="btn btn-secondary btn-sm" name='bn_grpsearch' value="true">
                    <?php echo xlt('Favorites'); ?>
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-danger btn-sm" onclick="selcode(0)">
                    <?php echo xlt('Erase'); ?>
                </button>
            </span>
        </div>

        <?php if (!empty($_REQUEST['bn_search']) || !empty($_REQUEST['bn_grpsearch'])): ?>
            <?php
            $ord = isset($_REQUEST['bn_search']) ? 'ord' : 'fgp';
            $sub = '';
            if ($ord === 'ord') {
                $sub = "OR pt.procedure_type LIKE 'pro'";
            }
            $search_term = '%' . trim((string)($_REQUEST['search_term'] ?? '')) . '%';

            $whereClauses = ["pt.activity = 1"];
            $params = [];

            // 1. Order mode: 'ord' (or 'pro') for search, 'fgp' for favorites
            $whereClauses[] = "(pt.procedure_type LIKE ? $sub)";
            $params[] = $ord;

            // 2. Filter by provider (lab_id) — required when a provider is selected
            if ($labid > 0) {
                $whereClauses[] = "pt.lab_id = ?";
                $params[] = $labid;
            }

            // 3. Filter by procedure_type_name (chosen by the user in the popup selector)
            if ($filter_ptn !== '' && $ord === 'ord') {
                if ($filter_ptn === 'imaging') {
                    // Show the DIAGNOSTIC IMAGING subtree (same as original otype logic)
                    $imageRoot = sqlQuery(
                        "SELECT procedure_type_id FROM procedure_type " .
                        "WHERE parent = 0 AND procedure_type = 'grp' AND name = ? AND activity = 1 LIMIT 1",
                        ['DIAGNOSTIC IMAGING']
                    );
                    $imageRootId = (int) ($imageRoot['procedure_type_id'] ?? 0);
                    if ($imageRootId > 0) {
                        $whereClauses[] = "(pt.parent IN (SELECT procedure_type_id FROM procedure_type WHERE parent = ? AND activity = 1) OR pt.procedure_type_name = 'imaging')";
                        $params[] = $imageRootId;
                    } else {
                        $whereClauses[] = "(pt.procedure_type_name = 'imaging')";
                    }
                } else {
                    $whereClauses[] = "(pt.procedure_type_name = ?)";
                    $params[] = $filter_ptn;
                }
            }

            // 4. Text search (empty term '%%' returns all rows matching the filters above)
            $whereClauses[] = "(pt.procedure_code LIKE ? OR pt.name LIKE ?)";
            $params[] = $search_term;
            $params[] = $search_term;

            $whereSql = implode(" AND ", $whereClauses);
            $query = "SELECT pt.procedure_type_id, pt.procedure_code, pt.procedure_type, pt.name, pt.lab_id, pt.procedure_type_name " .
                "FROM procedure_type pt WHERE $whereSql " .
                "ORDER BY pt.seq, pt.procedure_code";
            $res = sqlStatement($query, $params);

            $rows = [];
            while ($row = sqlFetchArray($res)) {
                $rows[] = $row;
            }
            ?>
            <p class="results-info">
                <?php echo text(count($rows)); ?> <?php echo xlt('results'); ?>
                <?php if ($labid > 0): ?>
                    &mdash; <?php
                    $pname = '';
                    foreach ($providerRows as $pr) {
                        if ((int)$pr['ppid'] === $labid) { $pname = $pr['name']; break; }
                    }
                    echo xlt('Provider') . ': ' . text($pname);
                ?>
                <?php endif; ?>
                <?php if ($filter_ptn !== ''): ?>
                    &mdash; <?php echo xlt('Type') . ': ' . text($filter_ptn); ?>
                <?php endif; ?>
            </p>
            <div class="table-responsive">
                <table class="table table-striped table-sm table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th><?php echo xlt('Type'); ?></th>
                            <th><?php echo xlt('Code'); ?></th>
                            <th><?php echo xlt('Description'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $itertypeid = $row['procedure_type_id'];
                        $itertype   = strtoupper((string) $row['procedure_type']);
                        $itercode   = $row['procedure_code'];
                        $itertext   = trim((string) $row['name']);
                        $anchor     = "<a href='' onclick='return selcode(" . attr_js($itertypeid) . ")'>";
                    ?>
                        <tr>
                            <td><?php echo $anchor . text($itertype); ?></a></td>
                            <td><?php echo $anchor . text($itercode); ?></a></td>
                            <td><?php echo $anchor . text($itertext); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="3" class="text-center text-muted">
                            <?php echo xlt('No results found. Try broadening your filters.'); ?>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </form>
</div>
</body>
</html>
