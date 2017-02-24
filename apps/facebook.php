<?php
// Prevent direct calls
if( !function_exists('add_action') ) die( "This is WordPress plugin code." );

/**
 * Assigns a Facebook user token to an AFEL user, either with a given set of
 * credentials (supplied as POSTdata) or from the currently active WP session.
 *
 * This method supports Ajax calls, therefore dies after sending the JSON response.
 */
function fb_bind_account() {
	$response = array();
	// Handle invalid HTTP method.
	if( $_SERVER ['REQUEST_METHOD'] !== 'POST' ) {
		status_header( 405 );
		$response['status'] = "FAILED";
		$response['error'] = $_SERVER ['REQUEST_METHOD'] 
			. " method not allowed. You can only send POST requests to this endpoint.";
		$response['http_status'] = 405;
		wp_send_json_error( $response );
		return $response;
	}
	// A Facebook token must be set (we are assuming it's not an expired one)
	if ( empty( $_POST["fb-token"]) ) {
		$error .= "<p>Please ensure you have signed into Facebook and authorised this app.</p>";
	} else {	
		$token = $_POST["fb-token"];
		$user = validate_credentials( 
			empty($_POST["username"]) ? NULL : $_POST["username"], 
			empty($_POST["password"]) ? NULL : $_POST["password"]
		);
		if( !$user )
			$error .= "<p>Please provide proper AFEL Platform credentials if requested, or be logged into it.</p>";
		else {
			/*
			 * TODO make the configuration
			 */
			$conf_obj = array(
				'type/global:id/day' => array(
					'localise' => "function localise(stype, authority, uidcat, uid) {var theday,nextday;if (uid == 'today') {theday = new Date();nextday = new Date();}else{var aday = uid.split('-');theday = new Date(aday[2], aday[1] - 1, aday[0]); nextday = new Date(aday[2], aday[1] - 1, aday[0]);} nextday.setDate(nextday.getDate() + 1);theday.setHours(0,0,0,0);nextday.setHours(0,0,0,0); return 'FILTER ( ?t >= \\\"'+theday.toISOString()+'\\\"^^<http://www.w3.org/2001/XMLSchema#dateTime> && ?t < \\\"'+nextday.toISOString() + '\\\"^^<http://www.w3.org/2001/XMLSchema#dateTime>'+')';}",
					'query_text' => "SELECT DISTINCT ?p1 ?o1 ?p2 ?o2 WHERE { GRAPH <[GRAPH]> { ?o1 a <http://rdfs.org/sioc/ns#Post> . ?o1 <http://purl.org/dc/terms/created> ?t . [LURI] . BIND (uri(\\\"http://data.afel-project.org/acbh/onto/resources\\\") AS ?p1) . ?o1 ?p2 ?o2}} ORDER BY ?t"
				)
			);
			$created = dcp_login_and_create_dataset( 
				"AFEL User Facebook Activity", 
				"Facebook activity data from user {$user->user_login}.", 
				json_encode($conf_obj),
				$user->ID );
			$response = $created; // copy now, alter eventually
			if (! isset( $created['key'] ) || ! isset( $created['dataset'] )) {
				$error = "Issue setting up dataset with AFEL Data Platform";
			} elseif( 409 == $created['http_status'] ) { 
				// Manage HTTP Conflict to reuse dataset
				fb_update_token ( $user->user_login, $token, $created['dataset'], $created['key'], $created['ecapi'] );
				unset( $response['error'] );
				$response['http_status'] = 200;
				fb_run_extractor( $user->user_login );
			} elseif( isset ($created['error']) ) {
				$error = "Error connecting to AFEL Platform - " . $created['error'];
			} else {
				fb_update_token ( $user->user_login, $token, $created['dataset'], $created['key'], $created['ecapi'] );
				$response['http_status'] = 201;
				fb_run_extractor( $user->user_login );
			}
		}
	}	
	if( empty($error) ) $response['status'] = 'OK';
	else {
		$response['status'] = 'FAILED';
		$response['error'] = $error;
	}
	wp_send_json( $response, $response['http_status'] );
}

/**
 * The part I like the least.
 */
function fb_run_extractor( $username = NULL ) {
	$old_path = getcwd();
	chdir( '/data/extractors/' );
	$cmd = './update_facebook_data';
	if( !empty($username) ) $cmd .= " $username";
	$output = shell_exec( $cmd );
	chdir( $old_path );
	return $output;
}

/**
 * Updates a Facebook user binding on file.
 * TODO: do not hardcode file path.
 */
function fb_update_token($afel_username, $fb_token, $afel_dataset, $afel_key, $ecapi) {
	// clear old registrations for this user
	// (OAuth tokens have most likely expired)
	$path = $_SERVER['DOCUMENT_ROOT'] . '/apps/facebook/' . 'registered';
	$row = "$afel_username $fb_token $afel_dataset $afel_key $ecapi\n";
	$remove = $afel_username;
	$lines = file ( $path, FILE_IGNORE_NEW_LINES | LOCK_EX );
	foreach ( $lines as $key => $line ) {
		if (0 === strpos ( $line, $remove ))
			unset ( $lines [$key] );
	}
	// and add the new registration
	$lines [] = $row;
	$data = implode ( "\n", array_values ( $lines ) );
	$file = fopen ( $path, 'w' );
	fwrite ( $file, $data );
	fclose ( $file );
	// file_put_contents($path, $row, FILE_APPEND | LOCK_EX);
}

// Hooks
add_action('wp_ajax_afel_register_facebook', 'fb_bind_account');
// Cannot do it if not authenticated
// add_action('wp_ajax_nopriv_afel_register_facebook', 'bind_account_facebook');