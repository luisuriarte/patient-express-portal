<?php

/**
 * Script to pick a procedure order type from the compendium.
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
$labid = (int) ($_REQUEST['labid'] ?? null);
// Order type (imaging, laboratory_test, procedure, etc.) sent from the form.
// When it is 'imaging', the search only shows the DIAGNOSTIC IMAGING subtree.
$otype = trim((string) ($_REQUEST['otype'] ?? ''));

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

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['opener']); ?>
    <title><?php echo xlt('Procedure Picker'); ?></title>

    <script>
        // AI-generated code start (GitHub Copilot) - Refactored to use URLSearchParams
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
        // AI-generated code end
    </script>
</head>
<body>
<div class="container">
    <div class="row">
        <form class="form-inline" method='post' name='theform' action='find_order_popup.php<?php echo "?order=" . attr_url($order) . "&labid=" . attr_url($labid) . "&otype=" . attr_url($otype);
        if (isset($_GET['formid'])) {
            echo '&formid=' . attr_url($_GET['formid']);
        }

        if (isset($_GET['formseq'])) {
            echo '&formseq=' . attr_url($_GET['formseq']);
        }
        if (isset($_GET['addfav'])) {
            echo '&addfav=' . attr_url($_GET['addfav']);
        }
        ?>'>
        <div class="col-sm-12">
                <div class="input-group">
                <input type="hidden" name='isfav' value='<?php echo attr($_REQUEST['ordLookup'] ?? ''); ?>' />
                <input class="form-control" id='search_term' name='search_term' value='<?php echo attr($_REQUEST['search_term'] ?? ''); ?>' title='<?php echo xla('Any part of the desired code or its description'); ?>' placeholder="<?php echo xla('Search for') ?>&hellip;"/>
                <span class="input-group-append">
                    <button type="submit" class="btn btn-primary btn-search" name='bn_search' value="true"><?php echo xlt('Search'); ?></button>
                        <?php if (!isset($_REQUEST['addfav'])) { ?>
                        <button type="submit" class="btn btn-primary btn-search" name='bn_grpsearch' value="true"><?php echo xlt('Favorites'); ?></button>
                        <?php } ?>
                    <button type="button" class="btn btn-danger btn-delete" onclick="selcode(0)"><?php echo xlt('Erase'); ?></button>
                    </span>
            </div>
        </div>
        <?php if (!empty($_REQUEST['bn_search']) || !empty($_REQUEST['bn_grpsearch'])) { ?>
            <div class="table-responsive mt-3">
                <table class="table table-striped table-sm">
                    <thead>
                    <th><?php echo xlt('Type'); ?></th>
                    <th><?php echo xlt('Code'); ?></th>
                    <th><?php echo xlt('Description'); ?></th>
                    </thead>
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

                    // 2. Filter by "Enviado a:" (lab_id)
                    if ($labid > 0) {
                        $whereClauses[] = "pt.lab_id = ?";
                        $params[] = $labid;
                    }

                    // 3. Filter by "Tipo de procedimiento" (procedure_type_name)
                    if ($otype !== '' && $ord === 'ord') {
                        if ($otype === 'imaging') {
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
                            $whereClauses[] = "(pt.procedure_type_name = ? OR pt.procedure_type_name IS NULL OR pt.procedure_type_name = '')";
                            $params[] = $otype;
                        }
                    }

                    // 4. Search term (code or description) - empty search term '%%' returns all rows matching the filters above
                    $whereClauses[] = "(pt.procedure_code LIKE ? OR pt.name LIKE ?)";
                    $params[] = $search_term;
                    $params[] = $search_term;

                    $whereSql = implode(" AND ", $whereClauses);
                    $query = "SELECT pt.procedure_type_id, pt.procedure_code, pt.procedure_type, pt.name, pt.lab_id " .
                        "FROM procedure_type pt WHERE $whereSql " .
                        "ORDER BY pt.seq, pt.procedure_code";
                    $res = sqlStatement($query, $params);

                    while ($row = sqlFetchArray($res)) {
                        $itertypeid = $row['procedure_type_id'];
                        $itertype = strtoupper((string) $row['procedure_type']);
                        $itercode = $row['procedure_code'];
                        $itertext = trim((string) $row['name']);
                        $anchor = "<a href='' onclick='return selcode(" . attr_js($itertypeid) . ")'>";
                        echo " <tr>";
                        echo "  <td>$anchor" . text($itertype) . "</a></td>\n";
                        echo "  <td>$anchor" . text($itercode) . "</a></td>\n";
                        echo "  <td>$anchor" . text($itertext) . "</a></td>\n";
                        echo " </tr>";
                    } ?>
                </table>
            </div>
        <?php } ?>

    </form>
</div>
</body>
</html>
