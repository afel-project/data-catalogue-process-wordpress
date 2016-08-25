<?php
/**
 * @package Data Cat Process
 */
/*
Plugin Name: Data Cat Process
Plugin URI: http://mksmart.org
Description: Create keys for users, and create new datasets
Version: 0.0.2 - 03-11-2014
Author: mdaquin
Author URI: http://mdaquin.net
License: -
*/

// for couchdb code reuse from ecapi plugin
require_once( str_replace("/data-cat-process/", "/", plugin_dir_path( __FILE__ )) . 'ecapi/inc/ecapiconfigform/couchdb.class.php');

$config = array(
   "ecapiuri" => "http://localhost:8081/jit/",
   "ecapikey" => ""
);

// send message when called directly
if ( !function_exists( 'add_action' ) ) {
	echo 'This is a plugin... what do you want?';
	exit;
}

define('DCP_LOG', true);
function dcp_log(){
	if(DCP_LOG === true){
		error_log("[DCP] " . implode(' ', func_get_args()));
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
    if (!endsWith($_SERVER['REQUEST_URI'], newuserdataset)){
	return;
    }
    $result = array("version" => 0.1);

    if (isset($_POST['username'])){
	$username = $_POST['username'];
	$result->username=$username;
    } else {
	$result['error'] = "Please provide a username";
	wp_send_json($result);
	return;
    }
    if (isset($_POST['password'])){
	$password = $_POST['password'];
    } else {
	$result['error'] = "Please provide a password";
	wp_send_json($result);
	return;
    }
    if (isset($_POST['type'])){
	$type = $_POST['type'];
    } else {
	$result['error'] = "Please provide a type of dataset";
	wp_send_json($result);
	result;
    }
    if (isset($_POST['description'])){
	$description = $_POST['description'];
    } else {
	$result['error'] = "Please provide a description for the dataset";
	wp_send_json($result);
	result;
    }
    if (isset($_POST['ecapiconf'])){
	$ecapiconf = $_POST['ecapiconf'];
    } else {
	$result['error'] = "Please provide a configuration for the Data API.";
	wp_send_json($result);
	result;
    }

    // check user credentials 
    $user = get_user_by( 'login', $username );
    if ( $user && wp_check_password( $password, $user->data->user_pass, $user->ID) ) {
	$result['key']= getUserKey($username);
	$result['dataset'] = getDatasetId($type, $username);
	if (!createDatasetEntry($type, $username, $user->ID, $description, $result['dataset'])){
	    $result['error'] = "could not create dataset entry - maybe it already exists";
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
    }
    wp_send_json($result);
}

add_action( 'template_redirect', 'dcp_login_and_create_dataset' );

function getUserKey($username){
    $salt1 = "I don't know what to do";
    $salt2 = "about this really";
    return md5($salt1.$username.$salt2);
}

function getDatasetId($type, $username){
    $salt1 = "rumble in the jungle";
    $salt2 = "the party is on";
    $salt3 = "but the giraff will be sad";
    return md5($salt1.$type.$salt2.$username.$salt3);
}

function createDatasetEntry($type, $username, $userid, $description, $datasetid) {
    $post_id = -1;
    $author_id = $userid;
    // $slug = sanitize_title($type).'-'.$username;
    $slug = $datasetid;
    $title = $type.' for '.$username;
    // TODO: check if the slug already exists
    // and do nothing if it does...
    if( null == get_page_by_title( $title ) ) {
	$post_id = wp_insert_post(
	    array(
		'comment_status'=>'closed',
		'ping_status'=>'closed',
		'post_author'=>$author_id,
		'post_name'=>$slug,
		'post_title'=>$title,
		'post_content'=>$description,
		'post_status'=>'publish',
		'post_type'=>'mksdc-datasets' // of course that means that mksdc needs to be installed
		)
	    );
    } else {
	return false;	
    } 
    return true;
} 

function endsWith($haystack, $needle) {
    return $needle === "" || 
	(($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
}
