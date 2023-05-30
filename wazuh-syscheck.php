<?php

if (!isset($centreon)) {
    exit();
}

require_once('requests.php');

$path = './modules/centreon-wazuh/';

/* Read module options */
$query = 'SELECT `key`, `value` FROM `options` '
    . 'WHERE `key` IN '
    . '("centreon_wazuh_manager_user", "centreon_wazuh_manager_password", "centreon_wazuh_manager_url")';
try {
    $res = $pearDB->query($query);
} catch (\PDOException $e) {
    echo '<div class="error">' . _('Error when getting Centreon-Wazuh module options') . '</div>';
    exit();
}

while ($row = $res->fetch()) {
    if ($row['key'] == 'centreon_wazuh_manager_user') {
        $wazuh_user_login = $row['value'];
    } elseif ($row['key'] == 'centreon_wazuh_manager_password') {
        $wazuh_user_mdp = $row['value'];
    } elseif ($row['key'] == 'centreon_wazuh_manager_url') {
        $wazuh_url = $row['value'];
    }
}

[$token, $status_code] = authentication($wazuh_user_login, $wazuh_user_mdp, $wazuh_url);
if($status_code!=200){
  echo '<div class="error">' . _('Error when requesting Wazuh API. Verify Wazuh configuration.') . '</div>';
  exit();
}


// Traiter la réponse
$valeurSelect = null;
$dbResult = $pearDB->query("SELECT host_name, host_id, host_register from host where host_register='1' and host_id in ( select host_host_id from on_demand_macro_host where host_macro_name = \"\$_HOSTWAZUHAGENTID$\")");
$totalRows = $dbResult->rowCount();

$form = new HTML_QuickFormCustom('form', 'post', "?p=".$p);
$tpl = new Smarty();
$tpl = initSmartyTpl($path, $tpl);
$renderer = new HTML_QuickForm_Renderer_ArraySmarty($tpl);

$hostFilter = array();
$attrMapStatus = null;

$pageSize = 20;
$curPage = 1;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $valeurSelect = $_POST["host"];
  $pageSizeIndex = $_POST["page"];
  $typeSizeIndex = $_POST["typeFilter"];
}


$i = 1;
while ($group = $dbResult->fetch()) {  
  $hostFilter[$i] = $group['host_name'];
  if($i===$totalRows){
    $statusDefault = '';
    $attrMapStatus = null;
    if ($valeurSelect!==null) {
      $statusDefault = array($hostFilter[$valeurSelect] => $valeurSelect);
    }
    $attrMapStatus = array(
        'defaultDataset' => $statusDefault
    );
    $form->addElement('select2', "host", _("Search"), $hostFilter, $attrMapStatus);
  }
  $i++;
}

$nbElementFilter = array(10,20,30,40,50,60,70,80,90,100);
$pageDefault = array($nbElementFilter[1] => 1);
if ($pageSizeIndex!==null) {
  $pageDefault = $pageSizeIndex == -1 ? array($nbElementFilter[0] => $pageSizeIndex) : array($nbElementFilter[$pageSizeIndex] => $pageSizeIndex);
  $pageSize = $pageSizeIndex == -1 ? $nbElementFilter[0] : $nbElementFilter[$pageSizeIndex];
}

$attrMapElementStatus = array(
  'defaultDataset' => $pageDefault
);
$form->addElement('select2', "page", _("Page"), $nbElementFilter, $attrMapElementStatus);

$typeFilter = array("all","file","registry_key","registry_value");
$typeDefault = array($typeFilter[0] => -1);
if ($typeSizeIndex!==null) {
  $typeDefault = $typeSizeIndex == -1 ? array($typeFilter[0] => $typeSizeIndex) : array($typeFilter[$typeSizeIndex] => $typeSizeIndex);
}

$attrMaptypeStatus = array(
  'defaultDataset' => $typeDefault
);
$form->addElement('select2', "typeFilter", _("type"), $typeFilter, $attrMaptypeStatus);

$values = array();
$elemArr = array();
if($valeurSelect !== null){
  $hostname = $hostFilter[$valeurSelect];
  $dbResult = $pearDB->query("SELECT o.host_macro_name, o.host_macro_value from host h, on_demand_macro_host o where h.host_id=o.host_host_id and o.host_macro_name='\$_HOSTWAZUHAGENTID$' and h.host_name='".$hostname."'");

  while($host = $dbResult->fetch()){
    $agentid = $host["host_macro_value"];
  }

  [$values, $status_code] = get_syscheck($wazuh_url, $token, $agentid, key($typeDefault));
  if($status_code!=200){
    echo '<div class="error">' . _('Error when requesting Wazuh API. Verify Wazuh configuration.') . '</div>';
    exit();
  }
  $style = "one";
  for ($j = 0; $j < count($values); $j++) {
    $elemArr[$j] = array(
      "MenuClass" => "list_" . $style,
      "RowMenu_file" => $values[$j]['file'],
      "RowMenu_type" => $values[$j]['type'],
      "RowMenu_size" => $values[$j]['size'],
      "RowMenu_gname" => $values[$j]['gname'],
      "RowMenu_changes" => $values[$j]['changes'],
      "RowMenu_uname" => $values[$j]['uname'],
      "RowMenu_perm" => $values[$j]['perm'],
      "RowMenu_date" => $values[$j]['date'],
    );
  
    $style != "two"
      ? $style = "two"
      : $style = "one";
  }
}

$elemArrLength = count($elemArr);


$attrBtnSuccess = array(
  "class" => "btc bt_success",
);
$form->addElement('submit', 'SearchB', _("Search"), $attrBtnSuccess);
$form->accept($renderer);
$tpl->assign("elemArr", $elemArr);
$tpl->assign("elemArrLength", $elemArrLength);
$tpl->assign("pageSize", $pageSize);
$tpl->assign("curPage", $curPage);

$tpl->assign("headerMenu_file", _("File"));
$tpl->assign("headerMenu_type", _("Type"));
$tpl->assign("headerMenu_size", _("Size (bytes)"));
$tpl->assign("headerMenu_gname", _("Group"));
$tpl->assign("headerMenu_changes", _("Changes"));
$tpl->assign("headerMenu_uname", _("Owner"));
$tpl->assign("headerMenu_perm", _("Perm"));
$tpl->assign("headerMenu_date", _("Date"));


$tpl->assign('form', $renderer->toArray());
$tpl->display("wazuh-syscheck.ihtml");

?>