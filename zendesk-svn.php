#!/usr/bin/php
<?php
/*

This PHP script is a simple integration with ZenDesk to post status updates to tickets by
simply including certain keywords in the commit message along with a '#123', where '123'
represents the ticket ID to update. If you include more than one 'phrase', it will post 
updates to those tickets as well.

== Adding your post-commit hook ==
This would be your 'post-commit' script in the hooks folder of your repository. Make sure
to make it executable (e.g. `chmod +x post-commit`) after adding it.

	REPOS="$1"
	REV="$2"

	/path/to/php /path/to/script/zendesk-svn.php "$REV"

== Configuring this script ==

There are some variables below that you will need to configure below.

*/

// Load configuration file
include 'config.inc.php';

// Get code revision
if (isset($argv[1]))
	$r = (int) $argv[1];
else
	die('No revision specified.');

// Run a system command to get the revision XML
ob_start();
$command = sprintf('svn log -r%d %s --xml', $r, SVN_REPO_PATH);
passthru($command, $result);
$revision_contents=ob_get_contents();
ob_end_clean();

// Verify that returned XML contains an entry
if (strpos($revision_contents, 'logentry') > 0):
	
	// Converto XML object for easier parsing
	$revision = new SimpleXMLElement($revision_contents);
	
	// Extract information from the response XML
	$user =           $revision->logentry->author;
	$commit_message = $revision->logentry->msg;

else:
	die('No revision information for r' . $r);

endif;



// Parse the message for a ticket #. If not present, ignore completely.
$lookup = (int) preg_match_all('^(fixes|addresses|fixed|addressed) \#[0-9]+^', strtolower($commit_message), $action_statements);

// If no commit actions were detected, close the script
if (empty($action_statements[0]))
	die('No action statements found.');

// This array will be used later to loop through messages
$updates = array();

// Look through action statements in a string
foreach ($action_statements[0] as $k => $action_statement):

	// Extract the specific ticket ID from the action
	preg_match('^\#[0-9]+^', $action_statement, $ticket_ids);
	$updates[$k]['ticket_id'] = str_replace('#', '', $ticket_ids[0]);

	// Extract the action from the message
	preg_match('^(fixes|addresses|fixed|addressed)^', $action_statement, $actions);
	$updates[$k]['ticket_status'] = $ticket_statuses[$actions[0]];

	// Username
	$updates[$k]['from_user'] = (string) $user . '@' . EMAIL_ADDRESS_DOMAIN;
	
	// Message
	$updates[$k]['message_body'] = '[r' . $r . '] ' . (string) $commit_message;
	
	// Whether to update the ticket publicly
	$updates[$k]['is_public'] = 'false';
	
	// Build the XML message for the update
	$updates[$k]['xml'] = sprintf(
		'<ticket><comment><is-public>%s</is-public><value>%s</value></comment><status>%d</status></ticket>',
			$updates[$k]['is_public'],
			htmlentities($updates[$k]['message_body']),
			(int) $updates[$k]['ticket_status']
	);

endforeach;

// Loop through updates
foreach ($updates as $k => $update):

	// URL of ticket to update
	$url = HELPDESK_URL . '/' . $update['ticket_id'] . '.xml';

	// cURL parameters
	$soap_do = curl_init();
	curl_setopt($soap_do, CURLOPT_URL,            $url );   
	curl_setopt($soap_do, CURLOPT_CONNECTTIMEOUT, 10); 
	curl_setopt($soap_do, CURLOPT_TIMEOUT,        10); 
	curl_setopt($soap_do, CURLOPT_RETURNTRANSFER, true );
	curl_setopt($soap_do, CURLOPT_SSL_VERIFYPEER, false);  
	curl_setopt($soap_do, CURLOPT_SSL_VERIFYHOST, false); 
	curl_setopt($soap_do, CURLOPT_POST,           true ); 
	curl_setopt($soap_do, CURLOPT_POSTFIELDS,     $update['xml']); 
	curl_setopt($soap_do, CURLOPT_HTTPHEADER,     array('Content-Type: text/xml; charset=utf-8', 'Content-Length: '.strlen($update['xml']), 'X-On-Behalf-Of: ' . $update['from_user'] )); 
	curl_setopt($soap_do, CURLOPT_USERPWD, POST_USER . ":" . POST_PASSWORD);

	$result = curl_exec($soap_do);
	$err = curl_error($soap_do);

	// Do something with result (optionally)
	mail('stephenyeargin@localhost.local', 'Comment submitted for ticket #' . $update['ticket_id'], $update['xml'] . "\n\n" . $result . "\n\n" . $err);

endforeach;
