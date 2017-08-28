<?php

/**
 * Report to show difference between accounts in WHMCS and Cpanel server.
 * This report get all active CPanel servers in WHMCS servers list and compare
 * account status in Cpanel with WHMCS product status.
 *
 * Will report following mismatch in account statuses :
 * 1. username not found in Cpanel(probably deleted in Cpanel)
 * 2. Account in Cpanel is active but in WHMCS has different status than active(Suspend, Fraud ...)
 * 3. Account in Cpanel is not active but active in WHMCS
 * 4. Owner(resseler) was deleted and some of her clients is active.
 *
 * If account exists in whmcs than values in columns "Domain" and "Username" will be a valid
 * links to WHMCS configure product page.
 *
 *
 * @copyright Madeit Inc.
 * @license http://www.gnu.org/copyleft/gpl.html GNU GENERAL PUBLIC LICENSE v3
 * @version 0.1.2
 * @link http://madeit.com/
 */

if (!defined("WHMCS"))
        die("This file cannot be accessed directly");

require_once(ROOTDIR . "/includes/cpanel/xmlapi.php");

$reportdata["title"] = "MadeIT.com cPanel and WHMCS accounting mapping reporter";
$reportdata["description"] = "Cpanel Whmcs Comparator";

if (empty($_POST)) {
    $show_cpanel_suspend_active_whmcs = 'on';
    $show_users_which_not_have_cpanell_account = 'on';
}

$reportdata["headertext"] = '<form method="post" action="'.$PHP_SELF.'?report='.$report.'">
<p align="left">
<input type="checkbox" name="show_cpanel_suspend_active_whmcs" '.($show_cpanel_suspend_active_whmcs?'checked="checked"':'').' />
Show suspened users in CPanel but active in WHMCS
<br>
<input type="checkbox" name="show_users_which_not_have_cpanell_account" '.($show_users_which_not_have_cpanell_account?'checked="checked"':'').' />
Show users which does not have account in CPanel server(probably deleted in Cpanel)
</p>
<p align="center">
<input type="submit" value="Generate Report">
</p>
</form>';

function get_whmcs_user_for($server)
{
    $query_result = select_query(
        'tblhosting',
        'id, userid, orderid, domainstatus, username, domain',
        array('server' => $server['id'])
    );
    $result = array();
    while ($row = mysql_fetch_assoc($query_result))
    {
        $result[] = $row;
    }
    return $result;
}

function server_xmlapi($server) {
    $xmlapi = new xmlapi($server['ipaddress']);
    try {
        if ($server['accesshash']) {
            $xmlapi->hash_auth($server['username'], $server['accesshash']);
        } else {
            $xmlapi->password_auth($server['username'], $server['password']);
        }
    } catch (Exception $e) {
        $reportdata["headertext"] = $reportdata["headertext"]
            + "Could not authorize to ".$server['name'].' '.$server['ipaddress'].".
            Please check your settings. Error message".$e->getMessage()."<br/>";
    }
    $xmlapi->set_output('array');
    return $xmlapi;
}

function get_active_cpanel_servers()
{
    $query_result = select_query(
        'tblservers',
        '*',
        array('type' => 'cpanel', 'disabled' => 0)
    );
    $result = array();
    while ($row = mysql_fetch_assoc($query_result))
    {
        $row['accesshash'] = preg_replace("'(\r|\n)'", "", $row['accesshash']);
        $row['xmlapi'] = server_xmlapi($row);
        $result[] =$row;
    }
    return $result;
}


function cpanel_resellers($server) {
    if ( ! isset($server['xmlapi']))
        $xmlapi = server_xmlapi($row);
    else
        $xmlapi = $server['xmlapi'];
    $resellers = $xmlapi->listresellers();
    $result = array();
    foreach($resellers['reseller'] as $reseller_name)
    {
        $stats = $xmlapi->resellerstats($reseller_name);
        if ( $stats['result']['status'] === '0' )
            continue;
        $result[$reseller_name] = $stats['result'];
    }
    return $result;
}

function cpanel_users($server)
{
    if ( ! isset($server['xmlapi']))
        $xmlapi = server_xmlapi($row);
    else
        $xmlapi = $server['xmlapi'];
    $users = $xmlapi->listaccts();
    if ( $users['status'] == 0 )
        return array();
    $result = array();
    foreach($users['acct'] as $cuser)
    {
        $result[$cuser['user']][$cuser['domain']] = $cuser;
    }
    return $result;
}

function user_link($hosting)
{
    global $print;
    if ($print)
        return $hosting['username']?:"UNKNOWN";
    $url = 'clientsservices.php?userid='
        .$hosting['userid']
        .'&id='.$hosting['id'];
    return '<a href="'.$url.'"  target="_blank">'
        .($hosting['username']?:"UNKNOWN")
        .'</a> ';
}

function product_link($hosting)
{
    global $print;
    if ($print)
        return $hosting['domain'];
    $url = 'clientsservices.php?userid='
        .$hosting['userid']
        .'&id='.$hosting['id'];
    return '<a href="'.$url.'"  target="_blank">'
        .$hosting['domain']
        .'</a> ';
}

function is_assoc(array $array)
{
        // Keys of the array
        $keys = array_keys($array);

        // If the array keys of the keys match the keys, then the array must
        // not be associative (e.g. the keys array looked like {0:0, 1:1...}).
        return array_keys($keys) !== $keys;
}

function check_reseller_subaccounts($reseller_data, $message, $reason) {
    $result = array();
    if (!is_array($reseller_data['accts'])) {
        return $result;
    }
    if (is_assoc($reseller_data['accts'])) {
        return $result;
    }

    foreach($reseller_data['accts'] as $acct) {
        if ($acct['deleted'] === '1' || $acct['suspended'] === '1')
            continue;
        $result[] =array(
            $acct['domain'],
            $acct['user'],
            sprintf($message, $acct['user'], $acct['domain']),
            $reason
        );
    }
    return $result;
}

function check_users($server) {
    global $show_cpanel_suspend_active_whmcs,
            $show_users_which_not_have_cpanell_account;

    try {
    $cusers = cpanel_users($server);
    } catch(Exception $e) {
      return array(
          array(
                "**<b>Unable to get account list. Error message: ".$e->getMessage().'</b>'
          )
      );
    }
    $whmcs_users = get_whmcs_user_for($server);

    try {
        $resselers = cpanel_resellers($server);
    } catch(Exception $e) {
        return array(
            array(
                "**<b>Unable to get resseler list. Error message: ".$e->getMessage().'</b>'
            )
        );
    }
    $result = array();
    foreach($whmcs_users as $whmcs_user) {
        $whmcs_user_active = $whmcs_user['domainstatus'] === 'Active';
        $reseller = NULL;
        if (array_key_exists($whmcs_user['username'], $resselers))
            $reseller = $resselers[$whmcs_user['username']];

        if ( ! array_key_exists($whmcs_user['username'], $cusers)){
          if ($whmcs_user_active
              && $show_users_which_not_have_cpanell_account === 'on' ) {
              $result[] = array(
                  product_link($whmcs_user),
                  user_link($whmcs_user),
                  'Could not find account in cPanel server(maybe it was deleted) which is active in WHMCS',
                  'WHMCS status:'.$whmcs_user['domainstatus']
              );

          }
          if ($reseller) {
                $msg = 'WHMCS account '.user_link($whmcs_user).'  not found in cPanel was reseller for client %s, %s which is still active.';
                $reason = 'Reseller account is '.$whmcs_user['domainstatus'].' in WHMCS  ';
                $result = array_merge(
                    check_reseller_subaccounts($reseller, $msg, $reason),
                    $result
                );
          }
          continue;
        }
        $cuser = $cusers[$whmcs_user['username']];
        if ( ! array_key_exists($whmcs_user['domain'], $cuser) )
             continue;

        $account = $cuser[$whmcs_user['domain']];
        $caccount_active = $account['suspended'] === '0';
        unset($cusers[$whmcs_user['username']][$whmcs_user['domain']]);
        if ( $show_cpanel_suspend_active_whmcs === 'on'
            && (! $caccount_active && $whmcs_user_active)) {
            $result[] = array(
                product_link($whmcs_user),
                user_link($whmcs_user),
                'Account is active in WHMCS but suspended on cPanel server',
                'Cpanel : '.$account['suspendreason']
            );
            if ($reseller) {
                $msg = 'Resseler account '.user_link($whmcs_user).' susspened in cPanel but his client %s, %s still active.';
                $reason = 'Reseller account suspended for '.$account['suspendreason'];
                $result = array_merge(
                    check_reseller_subaccounts($reseller, $msg, $reason),
                    $result
                );
            }
            continue;
        }
        if ( $caccount_active && ! $whmcs_user_active) {
            $result[] = array(
                product_link($whmcs_user),
                user_link($whmcs_user),
                'Account is '.strtolower($whmcs_user['domainstatus']).' in WHMCS but still active on cPanel server',
                'WHMCS status:'.$whmcs_user['domainstatus']
            );
            if ($reseller) {
                $msg = 'Resseler account '.user_link($whmcs_user).' is '.strtolower($whmcs_user['domainstatus'])
                    .' in whmcs but his client %s, %s still active.';
                $reason = 'Reseller account is '.$whmcs_user['domainstatus'].' in WHMCS  ';
                $result = array_merge(
                    check_reseller_subaccounts($reseller, $msg, $reason),
                    $result
                );
            }
            continue;
        }
        if ((!$caccount_active && ! $whmcs_user_active) && $reseller ) {
                $msg = 'Resseler account '.user_link($whmcs_user).' is '.strtolower($whmcs_user['domainstatus'])
                    .' in whmcs but his client %s, %s still active.';
                $reason = 'Reseller account is '.$whmcs_user['domainstatus'].' in WHMCS  ';
                $result = array_merge(
                    check_reseller_subaccounts($reseller, $msg, $reason),
                    $result
                );
        }
    }

    return $result;
}


$reportdata["tableheadings"] = array("Domain", "Username", "Comparison mismatch label", "Reason");
$tablevalues = array();
foreach(get_active_cpanel_servers() as $cpanel_server)
{
    $group =  'Cpanel server '.$cpanel_server['hostname'];
    $server_user_diff = check_users($cpanel_server);
    if ($server_user_diff) {
        $tablevalues[] = array("**<b>$group</b>");
        $tablevalues = array_merge($tablevalues, $server_user_diff);
    }

}
if ($tablevalues) {
    $reportdata["tablevalues"] = $tablevalues;
}
