<?php
/*
Plugin Name: Ldap_Login
Version: auto
Description: Allow piwigo authentication along an ldap
Plugin URI: http://piwigo.org/ext/extension_view.php?eid=650
Author: 22decembre
Author URI: http://www.22decembre.eu
*/
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

// +-----------------------------------------------------------------------+
// | Define plugin constants                                               |
// +-----------------------------------------------------------------------+
define('LDAP_LOGIN_ID',      basename(dirname(__FILE__)));
define('LDAP_LOGIN_PATH' ,   PHPWG_PLUGINS_PATH . LDAP_LOGIN_ID . '/');
define('LDAP_LOGIN_ADMIN',   get_root_url() . 'admin.php?page=plugin-' . LDAP_LOGIN_ID);
define('LDAP_LOGIN_VERSION', '1.2');

include_once(LDAP_LOGIN_PATH.'/class.ldap.php');

// +-----------------------------------------------------------------------+
// | Event handlers                                                        |
// +-----------------------------------------------------------------------+

add_event_handler('init', 'ld_init');

add_event_handler('try_log_user','login', 0, 4);

add_event_handler('get_admin_plugin_menu_links', array(&$ldap, 'ldap_admin_menu'));

// +-----------------------------------------------------------------------+
// | Admin menu loading                                                    |
// +-----------------------------------------------------------------------+

$ldap = new Ldap();
$ldap->load_config();
set_plugin_data($plugin['id'], $ldap);
unset($ldap);

// +-----------------------------------------------------------------------+
// | functions                                                             |
// +-----------------------------------------------------------------------+

function random_password( $length = 8 ) {
	$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+;:,.?";
	$password = substr( str_shuffle( $chars ), 0, $length );
	return $password;
}

function ld_init(){
	load_language('plugin.lang', LDAP_LOGIN_PATH);
}


function login($success, $username, $password, $remember_me){

	global $conf;
	
	$obj = new Ldap();
	$obj->load_config();
	$obj->ldap_conn() or die("Unable to connect LDAP server : ".$ldap->getErrorString());

	$user_dn = $obj->ldap_search_dn($username);	// retrieve the userdn

	// If we have userdn, attempt to login an check user's group access
	if (!($user_dn && $obj->ldap_bind_as($user_dn,$password) &&
		$obj->check_ldap_group_membership($user_dn, $username))) {
		trigger_notify('login_failure', stripslashes($username));
		return false; // wrong user/password or no group access
	}

	// search user in piwigo database
	$query = 'SELECT '.$conf['user_fields']['id'].' AS id FROM '.USERS_TABLE.' WHERE '.$conf['user_fields']['username'].' = \''.pwg_db_real_escape_string($username).'\' ;';

	$row = pwg_db_fetch_assoc(pwg_query($query));

	// if query is not empty, it means everything is ok and we can continue, auth is done !
  	if (!empty($row['id'])) {
  		log_user($row['id'], $remember_me);
  		trigger_notify('login_success', stripslashes($username));
  		return true;
  	}
  	
  	// if query is empty but ldap auth is done we can create a piwigo user if it's said so !
  	else {
		// this is where we check we are allowed to create new users upon that.
		if ($obj->config['allow_newusers']) {
			
			// retrieve LDAP e-mail address and create a new user
			$mail = $obj->ldap_get_email($user_dn);
			$new_id = register_user($username,random_password(8),$mail);

			// Login user
			log_user($new_id, False);
			trigger_notify('login_success', stripslashes($username));

			// in case the e-mail address is empty, redirect to profile page
			if($mail==NULL) {
				redirect('profile.php');
			}

			return true;
		}
		// else : this is the normal behavior ! user is not created.
		else {
		trigger_notify('login_failure', stripslashes($username));
		return false;
		}
  	}
}

?>
