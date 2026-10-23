<?php
define('NOCSRFCHECK', 1);

$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

// Rechteprüfung
if (!$user->rights->produit->lire) {
    accessforbidden();
}

// Automatischer Eintrag ins linke Menü (unter Produkte), falls noch nicht vorhanden
$sql_check_menu = "SELECT rowid FROM " . MAIN_DB_PREFIX . "menu WHERE url LIKE '%massedit/index.php%'";
$res_check_menu = $db->query($sql_check_menu);
if ($res_check_menu && $db->num_rows($res_check_menu) == 0) {
    $sql_parent = "SELECT rowid FROM " . MAIN_DB_PREFIX . "menu WHERE mainmenu = 'products' AND type = 'left' AND (fk_menu = 0 OR fk_menu IS NULL) LIMIT 1";
    $res_parent = $db->query($sql_parent);
    $fk_menu_id = 0;
    
    if ($res_parent && $obj_parent = $db->fetch_object($res_parent)) {
        $fk_menu_id = (int)$obj_parent->rowid;
    }

    $sql_insert_menu = "INSERT INTO " . MAIN_DB_PREFIX . "menu (fk_menu, type, titre, mainmenu, leftmenu, url, langs, position, enabled, perms, target, entity) 
                   VALUES (" . $fk_menu_id . ", 'left', 'Massenbearbeitung', 'products', 'massedit', '/custom/massedit/index.php', '', 150, '1', '\$user->rights->produit->lire', '', 0)";
    @$db->query($sql_insert_menu);
}

$action = GETPOST('action', 'aZ09');
$search_ref = GETPOST('search_ref', 'alpha');
$search_label = GETPOST('search_label', 'alpha');
$search_warehouse_id = GETPOST('search_warehouse_id', 'int');

// Sortierung & Paginierung
$sortfield = GETPOST('sortfield', 'aZ09') ? GETPOST('sortfield', 'aZ09') : 'p.ref';
$sortorder = GETPOST('sortorder', 'aZ09') ? GETPOST('sortorder', 'aZ09') : 'ASC';
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : (empty($conf->liste_limit) ? 25 : $conf->liste_limit);
$page = GETPOST('page', 'int');
if (empty($page) || $page == -1) { $page = 0; }
$offset = $limit * $page;

// Erlaubte Felder für Sortierung gegen SQL-Injection absichern
$allowed_sortfields = array(
    'p.ref' => 'p.ref',
    'p.label' => 'p.label',
    'p.price' => 'p.price',
    'ef.onlineshop' => 'ef.onlineshop'
);
if (!array_key_exists($sortfield, $allowed_sortfields)) {
    $sortfield = 'p.ref';
}

// Lager absolut fehlertolerant ermitteln (SELECT *)
$warehouses_list = array();
$sql_wh_list = "SELECT * FROM " . MAIN_DB_PREFIX . "entrepot ORDER BY rowid ASC";
$res_wh_list = $db->query($sql_wh_list);
if ($res_wh_list) {
    while ($obj_wh_item = $db->fetch_object($res_wh_list)) {
        $wh_id = $obj_wh_item->rowid;
        $wh_ref = isset($obj_wh_item->ref) ? $obj_wh_item->ref : 'Lager ' . $wh_id;
        
        $wh_name = '';
        if (!empty($obj_wh_item->label)) {
            $wh_name = $obj_wh_item->label;
        } elseif (!empty($obj_wh_item->libelle)) {
            $wh_name = $obj_wh_item->libelle;
        } else {
            $wh_name = $wh_ref;
        }
        
        $warehouses_list[$wh_id] = $wh_ref . ' (' . $wh_name . ')';
    }
}

// Wenn kein Lager ausgewählt wurde, das erste verfügbare Lager als Standard vorauswählen
if (empty($search_warehouse_id) && !empty($warehouses_list)) {
    reset($warehouses_list);
    $search_warehouse_id = key($warehouses_list);
}

// Extrafields laden
$extrafields = new ExtraFields($db);
$extralabels = $extrafields->fetch_name_optionals_label('product');

/*
 * Speichern-Logik (nur bei action == 'update_all')
 */
if ($action == 'update_all') {
    $products_data = GETPOST('prod', 'array');

    if (!empty($products_data) && is_array($products_data)) {
        $db->begin();
        $error = 0;
        $error_messages = array();

        foreach ($products_data as $id => $fields) {
            $productstatic = new Product($db);
            if ($productstatic->fetch($id) > 0) {
                // 1. Bezeichnung & Preis setzen
                $productstatic->label = $fields['label'];
                $productstatic->price = price2num($fields['price']);
                $productstatic->price_base_type = 'HT';
                
                if (!isset($productstatic->type)) {
                    $productstatic->type = Product::TYPE_PRODUCT;
                }

                // 2. Extrafield "onlineshop" setzen (2 für Ja, 1 für Nein)
                $productstatic->fetch_optionals();
                $onlineshop_val = isset($fields['onlineshop']) ? 2 : 1; 
                $productstatic->array_options['options_onlineshop'] = $onlineshop_val;

                // Update des Hauptprodukts
                $res_update = $productstatic->update($productstatic->id, $user);
                
                if ($res_update >= 0) {
                    $productstatic->insertExtraFields();
                } else {
                    $error++;
                    $err_details = !empty($productstatic->errors) ? implode(', ', $productstatic->errors) : $productstatic->error;
                    $error_messages[] = "Fehler beim Update von Ref '" . $productstatic->ref . "': " . $err_details;
                }

                // 3. Lagerbestand für das ausgewählte Lager anpassen
                if (!empty($conf->stock->enabled) && isset($fields['stock_target']) && $fields['stock_target'] !== '') {
                    $target_stock = price2num($fields['stock_target']);
                    
                    if ($search_warehouse_id > 0) {
                        $sql_check_ps = "SELECT rowid, reel FROM " . MAIN_DB_PREFIX . "product_stock WHERE fk_product = " . (int)$id . " AND fk_entrepot = " . (int)$search_warehouse_id;
                        $res_check_ps = $db->query($sql_check_ps);
                        
                        if ($res_check_ps && $db->num_rows($res_check_ps) > 0) {
                            $obj_ps = $db->fetch_object($res_check_ps);
                            if ($obj_ps->reel != $target_stock) {
                                $sql_up_ps = "UPDATE " . MAIN_DB_PREFIX . "product_stock SET reel = " . (float)$target_stock . " WHERE rowid = " . (int)$obj_ps->rowid;
                                $res_up_ps = $db->query($sql_up_ps);
                                if (!$res_up_ps) {
                                    $error++;
                                    $error_messages[] = "Lager-Update fehlgeschlagen für Ref '" . $productstatic->ref . "'";
                                }
                            }
                        } else {
                            $sql_ins_ps = "INSERT INTO " . MAIN_DB_PREFIX . "product_stock (fk_product, fk_entrepot, reel) VALUES (" . (int)$id . ", " . (int)$search_warehouse_id . ", " . (float)$target_stock . ")";
                            $res_ins_ps = $db->query($sql_ins_ps);
                            if (!$res_ins_ps) {
                                $error++;
                                $error_messages[] = "Lager-Erstellung fehlgeschlagen für Ref '" . $productstatic->ref . "'";
                            }
                        }
                    } else {
                        $error++;
                        $error_messages[] = "Kein Lager ausgewählt für Bestandsanpassung von Ref '" . $productstatic->ref . "'";
                    }
                }

                // 4. Kategorie zuweisen (Hinzufügen)
                if (isset($fields['categ_id']) && (int)$fields['categ_id'] > 0) {
                    $new_categ_id = (int)$fields['categ_id'];
                    $categ = new Categorie($db);
                    if ($categ->fetch($new_categ_id) > 0) {
                        $res_cat = $categ->add_type($productstatic, 'product');
                        if ($res_cat < 0 && $res_cat != -2) {
                            $error++;
                            $error_messages[] = "Kategoriezuweisung fehlgeschlagen für Ref '" . $productstatic->ref . "'";
                        }
                    }
                }

                // 5. Kategorie entfernen
                if (isset($fields['remove_categ_id']) && (int)$fields['remove_categ_id'] > 0) {
                    $remove_categ_id = (int)$fields['remove_categ_id'];
                    $categ_rem = new Categorie($db);
                    if ($categ_rem->fetch($remove_categ_id) > 0) {
                        $res_rem = $categ_rem->del_type($productstatic, 'product');
                        if ($res_rem < 0) {
                            $error++;
                            $error_messages[] = "Kategorieentfernung fehlgeschlagen für Ref '" . $productstatic->ref . "'";
                        }
                    }
                }
            }
        }

        if (!$error) {
            $db->commit();
            setEventMessages("Änderungen erfolgreich gespeichert.", null, 'mesgs');
        } else {
            $db->rollback();
            $msg = "Fehler beim Speichern:<br>" . implode("<br>", $error_messages);
            setEventMessages($msg, null, 'errors');
        }
    }
}

// Kategorien laden
$categ_static = new Categorie($db);
$categories_list = $categ_static->get_full_arbo('product');

// CSS: Linke Spalte komplett ausblenden für volle Seitenbreite
$morejs = array();
$morecss = array();
$morehead = '<style type="text/css">
    div#id-left, td.vmenu, .side-nav, .left-sidebar { display: none !important; }
    div#id-right, .fiche, .main-content, div.fichecenter { width: 100% !important; margin-left: 0 !important; padding-left: 0 !important; max-width: 100% !important; }
</style>';

llxHeader($morehead, 'Produkt-Massenbearbeitung', '', '', 0, 0, $morejs, $morecss, '', 1);

print load_fiche_titre('Massenbearbeitung von Produkten', '', 'title_products');

// SQL-Filter
$sql_where = " FROM " . MAIN_DB_PREFIX . "product as p 
               LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields as ef ON p.rowid = ef.fk_object
               WHERE p.entity IN (" . getEntity('product') . ")";

if (!empty($search_ref)) {
    $sql_where .= " AND p.ref LIKE '%" . $db->escape($search_ref) . "%'";
}
if (!empty($search_label)) {
    $sql_where .= " AND p.label LIKE '%" . $db->escape($search_label) . "%'";
}

// Gesamtzahl für Pagination
$sql_count = "SELECT COUNT(p.rowid) as total" . $sql_where;
$resql_count = $db->query($sql_count);
$nbtotalofrecords = 0;
if ($resql_count) {
    $obj_count = $db->fetch_object($resql_count);
    $nbtotalofrecords = $obj_count->total;
}

// URL-Parameter für Paginierungs-Links sichern (inkl. Limit & gewähltes Lager)
$param = '&limit=' . urlencode($limit) . '&search_warehouse_id=' . urlencode($search_warehouse_id);
if (!empty($search_ref)) $param .= '&search_ref=' . urlencode($search_ref);
if (!empty($search_label)) $param .= '&search_label=' . urlencode($search_label);

// 1. SUCH- & FILTER-LEISTE (inkl. Lager-Auswahl)
print '<form method="GET" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="page" value="0">';
print '<div class="div-table-responsive-no-min" style="margin-bottom: 15px; background: #f8f9fa; padding: 12px 15px; border: 1px solid #e0e0e0; border-radius: 4px;">';
print '<div style="display: flex; flex-wrap: wrap; align-items: center; gap: 20px; justify-content: space-between;">';

print '<div style="display: flex; align-items: center; gap: 10px;">';
print '<strong>Lager:</strong> ';
print '<select name="search_warehouse_id" class="flat" onchange="this.form.submit();">';
if (!empty($warehouses_list)) {
    foreach ($warehouses_list as $wh_id => $wh_label) {
        print '<option value="' . $wh_id . '"' . ($search_warehouse_id == $wh_id ? ' selected' : '') . '>' . dol_escape_htmltag($wh_label) . '</option>';
    }
} else {
    print '<option value="0">Keine Lager gefunden</option>';
}
print '</select>';
print '</div>';

print '<div style="display: flex; align-items: center; gap: 10px;">';
print '<strong>Referenz:</strong> <input type="text" class="flat width150" name="search_ref" value="' . dol_escape_htmltag($search_ref) . '" placeholder="z. B. ART-100">';
print '</div>';

print '<div style="display: flex; align-items: center; gap: 10px;">';
print '<strong>Bezeichnung:</strong> <input type="text" class="flat width200" name="search_label" value="' . dol_escape_htmltag($search_label) . '" placeholder="Suchbegriff...">';
print '</div>';

print '<div style="display: flex; align-items: center; gap: 10px;">';
print '<strong>Pro Seite:</strong> ';
print '<select name="limit" class="flat" onchange="this.form.submit();">';
foreach (array(10, 25, 50, 100, 250) as $val) {
    print '<option value="' . $val . '"' . ($limit == $val ? ' selected' : '') . '>' . $val . '</option>';
}
print '</select>';
print '</div>';

print '<div>';
print '<input type="submit" class="button" value="Filtern"> ';
if (!empty($search_ref) || !empty($search_label)) {
    print '<a href="' . $_SERVER["PHP_SELF"] . '" class="button button-reset">Zurücksetzen</a>';
}
print '</div>';

print '</div>';
print '</div>';
print '</form>';

// Standard-Dolibarr-Blätterleiste
print_barre_liste('Produkte', $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $nbtotalofrecords, $nbtotalofrecords, 'title_products', 0, '', '', $limit);

// 2. BEARBEITUNGS- & SPEICHER-FORMULAR (POST)
$action_url = $_SERVER["PHP_SELF"] . '?page=' . $page . '&sortfield=' . urlencode($sortfield) . '&sortorder=' . urlencode($sortorder) . $param;

print '<form method="POST" action="' . $action_url . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update_all">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';

// Sortierbare Spaltenköpfe
print_liste_field_titre('Referenz', $_SERVER["PHP_SELF"], 'p.ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Bezeichnung', $_SERVER["PHP_SELF"], 'p.label', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Verkaufspreis (Netto)', $_SERVER["PHP_SELF"], 'p.price', '', $param, '', $sortfield, $sortorder);
print '<th>Aktueller Lagerbestand</th>';
print '<th>Neuer Lagerbestand</th>';
print_liste_field_titre('Online-Shop', $_SERVER["PHP_SELF"], 'ef.onlineshop', '', $param, 'style="text-align: center;"', $sortfield, $sortorder);
print '<th>Kategorie verwalten (Hinzufügen / Entfernen)</th>';

print '</tr>';

// Daten abfragen mit Sortierung & Limit (inklusive Live-Bestand aus dem gewählten Lager)
$sql = "SELECT p.rowid, p.ref, p.label, p.price, ef.onlineshop, ps.reel as stock_reel" . $sql_where;
if ($search_warehouse_id > 0) {
    $sql = str_replace("FROM " . MAIN_DB_PREFIX . "product as p", "FROM " . MAIN_DB_PREFIX . "product as p LEFT JOIN " . MAIN_DB_PREFIX . "product_stock as ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = " . (int)$search_warehouse_id, $sql);
}
$sql .= " ORDER BY " . $sortfield . " " . $sortorder;
$sql .= " LIMIT " . (int)$offset . ", " . (int)$limit;

$resql = $db->query($sql);
if ($resql) {
    $num = $db->num_rows($resql);
    $i = 0;
    while ($i < $num) {
        $obj = $db->fetch_object($resql);
        
        $warehouse_stock = isset($obj->stock_reel) ? $obj->stock_reel : 0;

        // Aktuelle Kategorien des Produkts abfragen
        $categs_assigned = array();
        $sql_cat = "SELECT c.rowid, c.label FROM " . MAIN_DB_PREFIX . "categorie as c ";
        $sql_cat .= " INNER JOIN " . MAIN_DB_PREFIX . "categorie_product as cp ON cp.fk_categorie = c.rowid ";
        $sql_cat .= " WHERE cp.fk_product = " . (int)$obj->rowid;
        $resql_cat = $db->query($sql_cat);
        if ($resql_cat) {
            while ($obj_cat = $db->fetch_object($resql_cat)) {
                $categs_assigned[] = array('id' => $obj_cat->rowid, 'label' => dol_escape_htmltag($obj_cat->label));
            }
        }
        
        print '<tr class="oddeven">';
        print '<td><a href="' . DOL_URL_ROOT . '/product/card.php?id=' . $obj->rowid . '" target="_blank">' . dol_escape_htmltag($obj->ref) . '</a></td>';
        print '<td><input type="text" class="flat minwidth200" name="prod[' . $obj->rowid . '][label]" value="' . dol_escape_htmltag($obj->label) . '"></td>';
        print '<td><input type="text" class="flat width80" name="prod[' . $obj->rowid . '][price]" value="' . price($obj->price) . '"> €</td>';
        print '<td>' . $warehouse_stock . '</td>';
        print '<td><input type="text" class="flat width80" name="prod[' . $obj->rowid . '][stock_target]" value="' . $warehouse_stock . '"></td>';
        
        $checked = (!empty($obj->onlineshop) && (string)$obj->onlineshop === '2') ? ' checked' : '';
        print '<td style="text-align: center;"><input type="checkbox" name="prod[' . $obj->rowid . '][onlineshop]" value="2"' . $checked . '></td>';

        // Kategorie-Verwaltung
        print '<td>';
        
        print '<div style="margin-bottom: 5px;">';
        if (!empty($categs_assigned)) {
            foreach ($categs_assigned as $cat_item) {
                print '<span class="badge badge-status4 badge-status" style="margin-right: 3px; display: inline-block; margin-bottom: 2px;">' . $cat_item['label'] . '</span>';
            }
        } else {
            print '<span class="opacitymedium" style="font-size: 0.9em;">Keine Kategorien</span>';
        }
        print '</div>';

        print '<div style="margin-bottom: 3px;">';
        print '<select name="prod[' . $obj->rowid . '][categ_id]" class="flat" style="width: 100%; max-width: 250px;">';
        print '<option value="0">+ Kategorie hinzufügen...</option>';
        if (!empty($categories_list) && is_array($categories_list)) {
            foreach ($categories_list as $cat) {
                print '<option value="' . $cat['id'] . '">' . dol_escape_htmltag($cat['fulllabel']) . '</option>';
            }
        }
        print '</select>';
        print '</div>';

        if (!empty($categs_assigned)) {
            print '<div>';
            print '<select name="prod[' . $obj->rowid . '][remove_categ_id]" class="flat" style="width: 100%; max-width: 250px; color: #c00;">';
            print '<option value="0">- Kategorie entfernen...</option>';
            foreach ($categs_assigned as $cat_item) {
                print '<option value="' . $cat_item['id'] . '">' . $cat_item['label'] . '</option>';
            }
            print '</select>';
            print '</div>';
        }

        print '</td>';

        print '</tr>';
        
        $i++;
    }
}

print '</table>';

print '<div class="tabsAction">';
print '<input type="submit" class="button button-save" value="Alle Änderungen speichern (diese Seite)">';
print '</div>';

print '</form>';

llxFooter();
$db->close();