<?php
/**
 * @package Data Cat Process
 */
/*
Plugin Name: Data Cataloguing Process
Plugin URI: https://github.com/afel-project/data-catalogue-process-wordpress
Description: Service for generating datasets for the Entity-Centric API and generating user keys for accessing them. Also supports the creation of learning dashboards for users.
Version: 0.2.0 - 16-02-2017
Author: mdaquin
Author URI: http://mdaquin.net
License: -
*/

// Prevent direct calls
if( !function_exists('add_action') ) die( "This is a plugin, there's nothing for you here." );
register_activation_hook( __FILE__, 'dcp_activate' );
// for couchdb code reuse from ecapi plugin
require_once( str_replace("/data-cat-process/", "/", plugin_dir_path( __FILE__ )) . 'ecapi/inc/ecapiconfigform/couchdb.class.php');

define('DCP_VERSION', "0.1.1");
define('DCP_LOG', true);
function dcp_log(){
	if(DCP_LOG === true){
		error_log("[DCP] " . implode(' ', func_get_args()));
	}
}

/*
 * TODO This should be configurable from WordPress!
 */
$config = array(
   "ecapiuri" => "http://localhost:8081/jit/",
   "ecapikey" => ""
);

/**
 * Check for dependencies.
 */
function dcp_activate(){
	$deps = array( 'ecapi', 'mks-data-cataloguing' );
	$unsatisfied = array();
	foreach( $deps as $dep )
		if( !is_plugin_active("{$dep}/{$dep}.php") ) $unsatisfied []= $dep;
	if( !empty($unsatisfied) && current_user_can('activate_plugins') ) {
		$msg = 'Plugin <strong>Data Cataloguing Process</strong> (' . plugin_basename( __FILE__ ) . ')'
			. ' requires the following WordPress plugins to be installed and activated first:<ul>';
		foreach( $unsatisfied as $dep ) $msg .= '<li>'.$dep.'</li>';
		$msg .= '</ul><p><a href="' . admin_url( 'plugins.php' ) . '">&laquo; Return to Plugins</a>';
		wp_die($msg);
	}
}

function dcp_admin_init() {
     register_setting('ecapi_options', 'dcp_options', 'dcp_options_validate');
     add_settings_section('dcp_perm', 'Update and Creation of Datasets', 'dcp_perm_text', 'ecapi-settings');
     add_settings_field('ecapi_su_key', 'Entity-centric API Super User Key', 'dcp_setting_su_key', 'ecapi-settings', 'dcp_perm');
     add_settings_field('ecapi_sparql_query', 'SPARQL query endpoint', 'dcp_setting_sparql_query', 'ecapi-settings', 'dcp_perm');
     add_settings_field('ecapi_sparql_update', 'SPARQL update endpoint', 'dcp_setting_sparql_update', 'ecapi-settings', 'dcp_perm');
}

function dcp_handle_registration() {
    $resp = array( "version" => DCP_VERSION);
	switch( $_SERVER['REQUEST_METHOD'] ) {
		case 'GET':
			// some other function creates the content
			break;
		case 'POST':
			// XXX there is a hardcoded path here which needs to be matched 
			// by a page slug on WordPress!
			// TODO handle this condition somewhere else
			if( basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === "newuserdataset" ) {
			    $result = array( "version" => DCP_VERSION );
   				$missing = [];
				// check that the query includes what we need
				if( empty($_POST['type']) ) $missing []= "a type of dataset";
				if( empty($_POST['description']) ) $missing []= "a description for the dataset";
				if( empty($_POST['ecapiconf']) ) $missing []= "a configuration for the Data API";
				handle_invalid_registration( $missing, $result );
				$rez = dcp_login_and_create_dataset( $_POST['type'], $_POST['description'], $_POST['ecapiconf'] );
				wp_send_json( $rez, $rez['http_status'] );
			}
			break;
		// default:
		//	wp_send_json( $resp, 405 );
	}
}

/**
 * If no user_id is provided, the function will expect 
 * username and password in POSTdata.
 *
 * TODO: get rid of credential checks in POSTdata. 
 * In fact, change this function to pass it the WP User object
 */
function dcp_login_and_create_dataset( $ds_type, $ds_description, $ecapi_conf, $user_id = 0 ) {
    $result = array( "version" => DCP_VERSION );
    $missing = [];
	$status = 200;	
	// Check that we have enough information to identify the user
	if( isset($user_id) && $user_id > 0 ) {
		$user = get_user_by( 'id', $user_id );
		if( !$user ) {
			$status = 401;
			$missing []= "your browser must be logged into the AFEL platform"
				. " (unless username and password are provided instead)";
		}
	} else {
		if( empty($_POST['username']) ) $missing []= "your AFEL username";
		if( empty($_POST['password']) ) $missing []= "your non-empty AFEL password";
		if( !empty($missing) ) {
			$status = 400;
			$missing []= "Alternatively, make sure to be logged into the AFEL platform from your browser.";
		}
		$user = get_user_by( 'login', $_POST['username'] );
		if( !$user || !wp_check_password( $_POST['password'], $user->data->user_pass, $user->ID) ) {
			$status = 401;
			$missing []= "_valid_ credentials for the AFEL data platform";
		}
	}
	handle_invalid_registration( $missing, $result, $status );
	
	// From this point on, everything seems alright regarding credentials.
	$username = $user->user_login;
	$result['username'] = $username;
	
	// Set the following immediately, so clients can interpret 
	// a Conflict as a hint to reuse an existing dataset.
	$result['key'] = computeUserKey($username);
	$result['dataset'] = computeDatasetId($ds_type, $username);
	if( !createDatasetEntry($ds_type, $username, $user->ID, $result['dataset'], $ds_description) ){
		$result['error'] = "could not create dataset entry - maybe it already exists";
		$status = 409;
	} else {
	   // declare the dataset in ECAPI
		$options = get_option('ecapi_options');
		$ecapiroot = str_replace("entity", "dataset", $options['ecapi_url']);
		$options = get_option('dcp_options');
		$sukey = $options['ecapi_su_key'];
		$sparqlq = $options['ecapi_sparql_query'];
		$result['ecapi'] = $ecapiroot;		
		$ch = curl_init($ecapiroot.$result['dataset'].'?key='.$sukey);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
		$response = curl_exec($ch);
		$info = curl_getinfo($ch);
		if ($info['http_code'] !== 200){
			$result["error"] = "could not create ecapi dataset ".
			$result['dataset'].": ".$info['http_code'];
		}
		curl_close($ch);               
		// GRANT write and read to user key
		$ch = curl_init($ecapiroot.$result['dataset'].'/grant/?key='.$sukey);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);             
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch,CURLOPT_POST, 2);
		curl_setopt($ch,CURLOPT_POSTFIELDS, "right=write&ukey=".$result['key']);
		$response = curl_exec($ch);
		$info = curl_getinfo($ch);
		if ($info['http_code'] !== 200){
			$result["error"] = "could not grant access to ecapi dataset ".
			$result['dataset'].": ".$info['http_code'];
		}
		curl_close($ch);

		// update the couchdb config for ECAPI
		$opts = get_option('ecapi_options');
		$dbms = new couchdb(
			$opts['ecapi_config_db_url'], $opts['ecapi_config_db_name'], 
			$opts['ecapi_config_db_username'], $opts['ecapi_config_db_password']
		);
		$json = $dbms->getDoc($result['dataset']);
		$is_change = empty($json['data']->error);
		if( $is_change ) {
			$json = $json['data'];
			$_rev = $json->_rev;
		} else {
			$json = new stdClass();
			$_rev = NULL;
		}
		$json->type = "provider-spec";
		$json->{"mks:cache-lifetime"} = intval($cachetime);
		// TODO: probably how you make the link...
		// $json->{'catalogue-uuid'};
		$json->{'http://rdfs.org/ns/void#sparqlEndpoint'} = $sparqlq;
		// Changed because URNs don't like slashes
		$json->{'mks:graph'} = 'urn:dataset:'.$result['dataset'].':graph';
		
		if( is_array($ecapi_conf) )
			$conf_obj = $ecapi_conf;
		elseif( is_object($ecapi_conf) )
			$conf_obj = (array) $ecapi_conf;
		else
			$conf_obj = json_decode(str_replace('\"','"', str_replace('\\\"', '\"', $ecapi_conf)));
		foreach( $conf_obj as $typename => &$rules ) {
			foreach( $rules as $rulename => &$rule )
				$rule = str_replace('[GRAPH]', $json->{'mks:graph'}, $rule);
		}
		
		//$replaced = str_replace('\"','"', str_replace('\\\"', '\"', str_replace('[GRAPH]', $json->{'mks:graph'}, $ecapi_conf)));
		
		//$result['rules'] = $replaced;
		//$json->{'mks:types'} = json_decode(str_replace('\"','"', str_replace('\\\"', '\"', str_replace('[GRAPH]', $json->{'mks:graph'}, $ecapi_conf))));
		$json->{'mks:types'} = $conf_obj;
		$response = $dbms->saveDoc( $result['dataset'], $json );
		if( !empty($response['data']->error) )
			$result['error'] = "failed to configure dataset ".$result['dataset'].' - '.$response['data']->error.': '.$response['data']->reason;
		// TODO: Connect the catalogue dataset to ECAPI	
	}
	$result['http_status'] = $status;
    // wp_send_json( $result, $status ); // it also dies after sending.
    return $result;
}

function dcp_options_validate($input) {
    $options                        = get_option('dcp_options');
    $options['ecapi_su_key']        = trim($input['ecapi_su_key']);
    $options['ecapi_sparql_query']  = trim($input['ecapi_sparql_query']);
    $options['ecapi_sparql_update'] = trim($input['ecapi_sparql_update']);
    return $options;
}

function dcp_perm_text(){
    print '<p>Set up the catalogue permissions and endpoint to enable the creation of new datasets in ECAPI.</p>';
}

function dcp_setting_sparql_update() {
    $options = get_option('dcp_options');
    print "<input id=\"ecapi_sparql_update\" name=\"dcp_options[ecapi_sparql_update]\" size=\"48\" type=\"text\" value=\"{$options['ecapi_sparql_update']}\"/>";
}

function dcp_setting_sparql_query() {
    $options = get_option('dcp_options');
    print "<input id=\"ecapi_sparql_query\" name=\"dcp_options[ecapi_sparql_query]\" size=\"48\" type=\"text\" value=\"{$options['ecapi_sparql_query']}\"/>";
}

function dcp_setting_su_key() {
    $options = get_option('dcp_options');
    print "<input id=\"ecapi_su_key\" name=\"dcp_options[ecapi_su_key]\" size=\"48\" type=\"text\" value=\"{$options['ecapi_su_key']}\"/>";
}

function computeUserKey($username){
    $salt1 = "I don't know what to do";
    $salt2 = "about this really";
    return md5($salt1.$username.$salt2);
}

function computeDatasetId($type, $username){
    $salt1 = "rumble in the jungle";
    $salt2 = "the party is on";
    $salt3 = "but the giraff will be sad";
    return md5($salt1.$type.$salt2.$username.$salt3);
}

function createDatasetEntry($type, $username, $userid, $datasetid, $description, $force = FALSE) {
    $post_id = -1;
    $author_id = $userid;
    // $slug = sanitize_title($type).'-'.$username;
    $slug = $datasetid;
    $title = "$type for $username";
    $query = array(
    	'name'        => $slug,
    	'post_type'   => 'mksdc-datasets',
    	'numberposts' => 1
    );
    $found = get_posts($query);
    // Do nothing if title or dataset ID exists as WP slug, unless forced to insert.
    if( ($found || get_page_by_title($title) != NULL ) && !$force ) {
    	// print $found[0]->ID;
    	return FALSE;
    }
	$post_id = wp_insert_post( array(
		'comment_status' => 'closed',
		'ping_status'    => 'closed',
		'post_author'    => $author_id,
		'post_name'      => $slug,
		'post_title'     => $title,
		'post_content'   => $description,
		'post_status'    => 'publish',
		'post_type'      => 'mksdc-datasets' // of course that means that mksdc needs to be installed
	)); 
    return TRUE;
}

function handle_invalid_registration( $missing, $response_obj, $http_status = 400 ) {
	if( empty($missing) ) return;
	$response_obj['error'] = "Please provide the following:";
	foreach( $missing as $mi )
		$response_obj['error'] .= " * $mi\n";
	wp_send_json( $response_obj, $http_status );
	die();
}

/**
 * If username is null, look for a currently logged user
 */
function validate_credentials( $username = NULL, $password = NULL ) {
	if( !empty($username) && !empty($password) ) {
		$user = get_user_by( 'login', $username );
		if( $user && wp_check_password( $password, $user->data->user_pass, $user->ID) )
			return $user;
		else return FALSE;
	} elseif( is_user_logged_in() ) {
		$user_id = get_current_user_id();
		if( isset($user_id) && $user_id > 0 )
			return get_user_by( 'id', $user_id );
		else return FALSE;
	}
	return FALSE;
}

// Hooks
add_action( 'admin_init', 'dcp_admin_init' );
add_action( 'template_redirect', 'dcp_handle_registration' );

//================================================================================
// Dashboard-specific code
//================================================================================

function createDashboard( $atts ){
    $current_user = wp_get_current_user();
    $u = $current_user->user_login;
    $key = computeUserKey($u);
    $data = getDailyActivityData($key, "today");
    ob_start();
    include 'pages/user_dashboard.phtml';
    $html = ob_get_contents();
	ob_end_clean();
    return $html;
}

function dcp_add_scripts(){
	wp_register_style('afel_dashboard', plugins_url('/css/style.css', __FILE__));
	wp_enqueue_style('afel_dashboard');
    wp_register_script( 'canvasjs', 'http://canvasjs.com/assets/script/canvasjs.min.js' );
    wp_enqueue_script ( 'canvasjs' );
    wp_register_script( 'afeldb-script', plugins_url( '/afelDashboard.js', __FILE__ ) );
    wp_enqueue_script ( 'afeldb-script' );
}

function getDailyActivityData($k, $d){
	// FIXME hardcoded URI here
    $process = curl_init("http://data.afel-project.eu/api/entity/day/".$d);
	// curl_setopt($process, CURLOPT_HTTPHEADER, array('Content-Type: application/xml', $additionalHeaders));
	// curl_setopt($process, CURLOPT_HEADER, 1);
    curl_setopt($process, CURLOPT_USERPWD, $k . ":");
	// curl_setopt($process, CURLOPT_TIMEOUT, 30);
	// curl_setopt($process, CURLOPT_POST, 1);
	// curl_setopt($process, CURLOPT_POSTFIELDS, $payloadName);
    curl_setopt($process, CURLOPT_RETURNTRANSFER, TRUE);
    $return = curl_exec($process);
    curl_close($process);
    return $return;
}

// Hooks
add_action( 'wp_enqueue_scripts', 'dcp_add_scripts' );
add_shortcode( 'afel_dashboard', 'createDashboard' );

//================================================================================
// Additional extractor-specific scripts
//================================================================================

include_once 'apps/facebook.php';