<?php
/**
 * @package Data Cat Process
 */
/*
Plugin Name: Data Cataloguing Process
Plugin URI: https://github.com/afel-project/data-catalogue-process-wordpress
Description: Service for generating datasets for the Entity-Centric API and generating user keys for accessing them. Also supports the creation of learning dashboards for users.
Version: 0.1.1 - 24-01-2017
Author: mdaquin
Author URI: http://mdaquin.net
License: -
*/

// Prevent direct calls
if( !function_exists('add_action') ) die( "This is a plugin, there's nothing for you here." );
register_activation_hook( __FILE__, 'dcp_activate' );
// for couchdb code reuse from ecapi plugin
require_once( str_replace("/data-cat-process/", "/", plugin_dir_path( __FILE__ )) . 'ecapi/inc/ecapiconfigform/couchdb.class.php');

define('DCP_LOG', true);
function dcp_log(){
	if(DCP_LOG === true){
		error_log("[DCP] " . implode(' ', func_get_args()));
	}
}

/*
 * This should be configurable from WordPress!
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

function dcp_add_scripts(){
//	wp_register_script('mksdc-js', plugins_url('/js/mksdc.js', __FILE__), array('jquery'));
//	wp_register_style('mksdc-style', plugins_url('/css/mksdc.css', __FILE__));
//	wp_enqueue_script('mksdc-js');
//	wp_enqueue_style('mksdc-style');
}

function dcp_init() {
	 dcp_add_scripts();		
}
add_action('init', 'dcp_init');

function dcp_admin_init() {
     register_setting('ecapi_options', 'dcp_options', 'dcp_options_validate');
     add_settings_section('dcp_perm', 'Update and Creation of Datasets', 'dcp_perm_text', 'ecapi-settings');
     add_settings_field('ecapi_su_key', 'Entity-centric API Super User Key', 'dcp_setting_su_key', 'ecapi-settings', 'dcp_perm');
     add_settings_field('ecapi_sparql_query', 'SPARQL query endpoint', 'dcp_setting_sparql_query', 'ecapi-settings', 'dcp_perm');
     add_settings_field('ecapi_sparql_update', 'SPARQL update endpoint', 'dcp_setting_sparql_update', 'ecapi-settings', 'dcp_perm');
}
add_action('admin_init', 'dcp_admin_init');

function dcp_perm_text(){
  print '<p>Set up the catalogue permissions and endpoint to enable the creation of new datasets in ECAPI.</p>';
}

function dcp_setting_su_key() {
    $options = get_option('dcp_options');
    print "<input id=\"ecapi_su_key\" name=\"dcp_options[ecapi_su_key]\" size=\"48\" type=\"text\" value=\"{$options['ecapi_su_key']}\"/>";
}

function dcp_setting_sparql_update() {
    $options = get_option('dcp_options');
    print "<input id=\"ecapi_sparql_update\" name=\"dcp_options[ecapi_sparql_update]\" size=\"48\" type=\"text\" value=\"{$options['ecapi_sparql_update']}\"/>";
}

function dcp_setting_sparql_query() {
    $options = get_option('dcp_options');
    print "<input id=\"ecapi_sparql_query\" name=\"dcp_options[ecapi_sparql_query]\" size=\"48\" type=\"text\" value=\"{$options['ecapi_sparql_query']}\"/>";
}


function dcp_options_validate($input) {
    $options                        = get_option('dcp_options');
    $options['ecapi_su_key']        = trim($input['ecapi_su_key']);
    $options['ecapi_sparql_query'] = trim($input['ecapi_sparql_query']);
    $options['ecapi_sparql_update'] = trim($input['ecapi_sparql_update']);
    return $options;
}

function dcp_login_and_create_dataset(){
    // check that the query includes what we want
    // Why does this even work?!?
    if (!endsWith($_SERVER['REQUEST_URI'], newuserdataset)) return;
    
    $result = array( "version" => "0.1" );
	$errstr = ""; $status = 200;
	if( empty($_POST['username']) ) $errstr .= " * a username";
	if( empty($_POST['password']) ) $errstr .= " * a non-empty password";
	if( empty($_POST['type']) ) $errstr .= " * a type of dataset";
	if( empty($_POST['description']) ) $errstr .= " * a description for the dataset";
	if( empty($_POST['ecapiconf']) ) $errstr .= " * a configuration for the Data API";
	if( strlen($errstr) > 0 ) {
		$result['error'] = "Please provide the following:" . $errstr;
		wp_send_json( $result, 400 );
		return;
	}
	$username = $_POST['username'];
	$result->username = $username;
	$password = $_POST['password'];
	$type = $_POST['type'];
	$description = $_POST['description'];
	$ecapiconf = $_POST['ecapiconf'];

    // check user credentials 
    $user = get_user_by( 'login', $username );
    if( $user && wp_check_password( $password, $user->data->user_pass, $user->ID) ) {
	$result['key'] = computeUserKey($username);
	$result['dataset'] = computeDatasetId($type, $username);
	
	if( !createDatasetEntry($type, $username, $user->ID, $result['dataset'], $description) ){
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
		if ($info['http_code']!==200){
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
		if ($info['http_code']!==200){
	                $result["error"] = "could not grant access to ecapi dataset ".
			$result['dataset'].": ".$info['http_code'];
		}               
		curl_close($ch);

		// update the couchdb config for ECAPI
		$opts = get_option('ecapi_options');
	       $dbms = new couchdb(
		   $opts['ecapi_config_db_url'], $opts['ecapi_config_db_name'], 
		   $opts['ecapi_config_db_username'],$opts['ecapi_config_db_password']
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
	       $json->{'mks:graph'} = 'urn:dataset/'.$result['dataset'].'/graph';
	    $json->{'mks:types'} = json_decode(str_replace('\"','"', str_replace('\\\"', '\"', str_replace('[GRAPH]', $json->{'mks:graph'}, $ecapiconf))));
//	       $result['error'] = str_replace('\"','"', str_replace('\\\"', '\"', str_replace('[GRAPH]', $json->{'mks:graph'}, $ecapiconf)))." -".print_r($json->{'mks:types'}, TRUE)."-";
	       // types...
	       $response = $dbms->saveDoc( $result['dataset'], $json );
//	       $result['error'] = str_replace('\\"', '"', str_replace('[GRAPH]', $json->{'mks:graph'}, $ecapiconf)); // print_r($json, TRUE);
	       if( !empty($response['data']->error) ) {
		   $result['error'] = "failed to configure dataset ".$result['dataset'].' - '.$response['data']->error.': '.$response['data']->reason;
	       }
 		// TODO: Connect the catalogue dataset to ECAPI	
	}
    }
    else {
		$result['error'] = "invalid username or password";
		$status = 401;
    }
    wp_send_json( $result, $status );
}

add_action( 'template_redirect', 'dcp_login_and_create_dataset' );

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

function endsWith($haystack, $needle) {
    return $needle === "" || 
	(($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
}

function getDailyActivityData($k, $d){
    $process = curl_init("http://data.afel-project.eu/api/entity/day/".$d);
//    curl_setopt($process, CURLOPT_HTTPHEADER, array('Content-Type: application/xml', $additionalHeaders));
//    curl_setopt($process, CURLOPT_HEADER, 1);
    curl_setopt($process, CURLOPT_USERPWD, $k . ":");
//    curl_setopt($process, CURLOPT_TIMEOUT, 30);
//    curl_setopt($process, CURLOPT_POST, 1);
//    curl_setopt($process, CURLOPT_POSTFIELDS, $payloadName);
    curl_setopt($process, CURLOPT_RETURNTRANSFER, TRUE);
    $return = curl_exec($process);
    curl_close($process);
    return $return;
}


function createDashboard( $atts ){
    $current_user = wp_get_current_user();
    $u = $current_user->user_login;
    $key = computeUserKey($u);
    $data = getDailyActivityData($key, "today");
    return '<div id="afelcharts" style="width: 100%;"></div><script>var data = '.$data.'; afelDisplayDailyData(data, "today");</script>';
}
add_shortcode( 'afel_dashboard', 'createDashboard' );


function afel_scripts_add()
{
    wp_register_script( 'canvasjs', 'http://canvasjs.com/assets/script/canvasjs.min.js' );
    wp_enqueue_script( 'canvasjs' );
    wp_register_script( 'afeldb-script', plugins_url( '/afelDashboard.js', __FILE__ ) );
    wp_enqueue_script( 'afeldb-script' );
}
add_action( 'wp_enqueue_scripts', 'afel_scripts_add' );
