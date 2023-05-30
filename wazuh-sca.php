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


if(!isset($_GET['policy_id'])) {


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


$values = array();
$elemArr = array();
if($valeurSelect !== null){
  $hostname = $hostFilter[$valeurSelect];
  $dbResult = $pearDB->query("SELECT o.host_macro_name, o.host_macro_value from host h, on_demand_macro_host o where h.host_id=o.host_host_id and o.host_macro_name='\$_HOSTWAZUHAGENTID$' and h.host_name='".$hostname."'");

  while($host = $dbResult->fetch()){
    $agentid = $host["host_macro_value"];
  }

  [$values, $status_code] = get_sca($wazuh_url, $token, $agentid);
  if($status_code!=200){
    echo '<div class="error">' . _('Error when requesting Wazuh API. Verify Wazuh configuration.') . '</div>';
    exit();
  }
  $style = "one";
  for ($j = 0; $j < count($values); $j++) {
    $elemArr[$j] = array(
      "MenuClass" => "list_" . $style,
      "RowMenu_name" => $values[$j]['name'],
      "RowMenu_invalid" => $values[$j]['invalid'],
      "RowMenu_pass" => $values[$j]['pass'],
      "RowMenu_fail" => $values[$j]['fail'],
      "RowMenu_score" => $values[$j]['score'],
      "RowMenu_total" => $values[$j]['total_checks'],
      "RowMenu_description" => $values[$j]['description'],
      "RowMenu_scan" => $values[$j]['end_scan'],
      "RowMenu_policy_id" => $values[$j]['policy_id'],
      "RowMenu_agent_id" => $agentid,
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

$tpl->assign("headerMenu_name", _("Policy"));
$tpl->assign("headerMenu_description", _("Description"));
$tpl->assign("headerMenu_invalid", _("Invalid"));
$tpl->assign("headerMenu_pass", _("Pass"));
$tpl->assign("headerMenu_fail", _("Fail"));
$tpl->assign("headerMenu_score", _("Score"));
$tpl->assign("headerMenu_total", _("Total"));
$tpl->assign("headerMenu_scan", _("End scan"));
$tpl->assign("headerMenu_policy_id", _("Policy ID"));


$tpl->assign('form', $renderer->toArray());
$tpl->display("wazuh-sca.ihtml");

}else{
  $policyId = $_GET['policy_id'];
  $agentId = $_GET['agent_id'];

  $form = new HTML_QuickFormCustom('form', 'post', "?p=".$p."&policy_id=".$policyId."&agent_id=".$agentId);
  $tpl = new Smarty();
  $tpl = initSmartyTpl($path, $tpl);
  $renderer = new HTML_QuickForm_Renderer_ArraySmarty($tpl);

  $pageSize = 20;
  $curPage = 1;

  if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pageSizeIndex = $_POST["page"];
    $resultSizeIndex = $_POST["resultFilter"];
  }

  $resultFilter = array("all","failed","passed","not applicable");
  $resultDefault = array($resultFilter[0] => -1);
  if ($resultSizeIndex!==null) {
    $resultDefault = $resultSizeIndex == -1 ? array($resultFilter[0] => $resultSizeIndex) : array($resultFilter[$resultSizeIndex] => $resultSizeIndex);
  }

  $attrMapresultStatus = array(
    'defaultDataset' => $resultDefault
  );
  $form->addElement('select2', "resultFilter", _("Result"), $resultFilter, $attrMapresultStatus);

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

  $values = array();  
  $elemArr = array();

  [$values, $status_code] = get_sca_policy($wazuh_url, $token, $agentId, $policyId, key($resultDefault));
  if($status_code!=200){
    echo '<div class="error">' . _('Error when requesting Wazuh API. Verify Wazuh configuration.') . '</div>';
    exit();
  }

  $style = "one";
  for ($j = 0; $j < count($values); $j++) {
    switch (strtolower($values[$j]['result'])) {
      case 'passed':
        $badge = "service_ok";
        break;
      case 'failed':
        $badge = "service_critical";
        break;
      case 'not applicable':
        $badge = "service_unknown";
        break;
      
      default:
        $badge = "service_unknown";
        break;
    }
    $elemArr[$j] = array(
      "MenuClass" => "list_" . $style,
      "RowMenu_title" => $values[$j]['title'],
      "RowMenu_remediation" => $values[$j]['remediation'],
      "RowMenu_command" => $values[$j]['command'],
      "RowMenu_rationale" => $values[$j]['rationale'],
      "RowMenu_description" => $values[$j]['description'],
      "RowMenu_result" => $values[$j]['result'],
      "RowMenu_file" => $values[$j]['file'],
      "RowMenu_badge" => $badge,
    );
  
    $style != "two"
      ? $style = "two"
      : $style = "one";
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

  $tpl->assign("headerMenu_title", _("Title"));
  $tpl->assign("headerMenu_description", _("Description"));
  $tpl->assign("headerMenu_remediation", _("Remediation"));
  $tpl->assign("headerMenu_command", _("Command"));
  $tpl->assign("headerMenu_rationale", _("Rationale"));
  $tpl->assign("headerMenu_file", _("File"));
  $tpl->assign("headerMenu_result", _("Result"));

  $tpl->assign('form', $renderer->toArray());
  $tpl->display("wazuh-sca-policy.ihtml");
}

?>