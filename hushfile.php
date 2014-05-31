<?php
$config = json_decode(file_get_contents('config.json'));

function get_fileid() {
	global $config; // to access $config variable from inside of the function
	$fileid = bin2hex(openssl_random_pseudo_bytes(15));
	if (file_exists($config->data_path.$fileid)) {
		//somehow this ID already exists, call recursively until we found an unused ID
		//todo: set a recursion limit to avoid infinite looping
		$fileid = get_fileid();
	};
	return $fileid;
};

// function to get the number of chunks and total size of a given file
function get_upload_info($path) {
	$handle = opendir($path);
	$totalsize = 0;
	while (false !== ($entry = readdir($handle))) {
		if(substr($entry,0,10) == "cryptofile.") {
			$totalsize = $totalsize + filesize($entry);
			$chunkcount++;
		};
	};
	closedir($handle);
	$finished = file_exists($path."/uploadpassword");
	return array(
		"chunkcount" => $chunkcount, 
		"totalsize" => $totalsize, 
		"finished" => $finished
	);
};

if($_SERVER["REQUEST_URI"] == "/api/upload") {
	// THIS IS A FILE UPLOAD, ONLY POST ACCEPTED
	if($_SERVER['REQUEST_METHOD'] != "POST") {
		header("Status: 405 Method Not Allowed");
		die(json_encode(array("status" => "Invalid upload request, only POST allowed", "fileid" => "")));
	};
	
	// check if $_REQUEST['chunknumber'] is numeric
	if(!is_numeric($_REQUEST['chunknumber'])) {
		header("Status: 400 Bad Request");
		die(json_encode(array("status" => "invalid upload request, chunknumber must be numeric", "fileid" => "")));
	};
	
	if(isset($_REQUEST['cryptofile']) && isset($_REQUEST['metadata']) && isset($_REQUEST['chunknumber']) && isset($_REQUEST['finishupload'])) {
		// This is the first chunk of this file, not a continuation of an existing upload
		
		// get a new unique ID for this file
		$fileid = get_fileid();
		$cryptofile = $config->data_path.$fileid."/cryptofile." . $_REQUEST['chunknumber'];
		$metadatafile = $config->data_path.$fileid."/metadata.dat";
		$serverdatafile = $config->data_path.$fileid."/serverdata.json";
		
		// create folder for this file
		mkdir($config->data_path.$fileid);
		
		
		// write metadata file
		$fh = fopen($metadatafile, 'w') or die(json_encode(array("status" => "unable to write metadatafile", "fileid" => "")));
		fwrite($fh, $_REQUEST['metadata']);
		fclose($fh);

		// find client IP
        if($config->trust_x_forwarded_for && array_key_exists('X-Forwarded-For',$_SERVER)) {
            $clientip = $_SERVER['X-Forwarded-For'];
        } else {
            $clientip = $_SERVER['REMOTE_ADDR'];
        };

		// write serverdata file
		$fh = fopen($serverdatafile, 'w') or die(json_encode(array("status" => "unable to write serverdatafile", "fileid" => "")));
		
        trust_x_forwarded_for
		if(array_key_exists('X-Forwarded-For',$_SERVER)) {
			$clientip = $_SERVER['X-Forwarded-For'];
		} else {
			$clientip = $_SERVER['REMOTE_ADDR'];
		}
		
        if(array_key_exists('deletepassword',$_REQUEST)) {
            $json = json_encode(array(
                "deletepassword" => $_REQUEST['deletepassword'],
                "clientips" => $clientip
            ));
        } else {
            $json = json_encode(array(
                "clientips" => $clientip
            ));
        };
        fwrite($fh, $json);
		fclose($fh);

		// check if upload is to be finished
		if ($_REQUEST['finishupload']) {
			$finished = true;
			$uploadpassword = null;
		} else {
			// upload is not finished, generate uploadpassword so the user can continue the upload
			$uploadpassword = bin2hex(openssl_random_pseudo_bytes(40));
			$finished = false;
			// write the uploadpassword to a file
			$fh = fopen($serverdatafile, 'w') or die(json_encode(array("status" => "unable to write uploadpassword file", "fileid" => "")));
			fwrite($fh, $uploadpassword);
			fclose($fh);
		};
		
		// send email
		if ($config->admin->send_email === true) {
			$to = "{$config->admin->name} <{$config->admin->email}>";
			$subject = "new filepart uploaded to " . $_SERVER["SERVER_NAME"];
			$message = "new filepart uploaded to " . $_SERVER["SERVER_NAME"] . ": https://" . $_SERVER["SERVER_NAME"] . "/" . $fileid . "\n";
			if($finished) {
				$message += "upload is finished, 1 chunk";
			} else {
				$message += "upload is not finished, 1 chunk so far";
			};
			$from = $config->email_sender;
			$headers = "From:" . $from;
			mail($to,$subject,$message,$headers);
		};
		
		// encode and return json reply
		die(json_encode(array(
			"status" => "ok", 
			"fileid" => $fileid, 
			"chunks" => 1, 
			"totalsize" => strlen($_REQUEST['cryptofile']), 
			"finished" => $finished, 
			"uploadpassword" => $uploadpassword
		)));
	} elseif(isset($_REQUEST['cryptofile']) && isset($_REQUEST['chunknumber']) && isset($_REQUEST['finishupload']) && isset($_REQUEST['fileid']) && isset($_REQUEST['uploadpassword'])) {
		// this is a continuation of an existing upload
		
		// check if fileid is valid
		if (!file_exists($config->data_path.$params['fileid'])) {
			header("Status: 404 Not Found");
			die(json_encode(array("fileid" => $params['fileid'], "exists" => false)));
		};
		
		// check that the upload is not finished
		if(!file_exists($config->data_path.$fileid."/uploadpassword")) {
			header("Status: 412 Precondition Failed");
			die(json_encode(array(
				"fileid" => $params['fileid'], 
				"status" => "File upload is finished, no further uploads possible"
			)));
		};
		
		// check if the uploadpassword is correct
		$fh = fopen($config->data_path.$fileid."/uploadpassword", 'r');
		$uploadpassword = fread($fh, filesize($file));
		fclose($fh);
		if($uploadpassword != $_REQUEST['uploadpassword']) {
			header("Status: 403 Forbidden");
			die(json_encode(array("fileid" => $params['fileid'], "status" => "Incorrect uploadpassword")));
		};
		
		// write encrypted file part
		$cryptofile = $config->data_path.$fileid."/cryptofile." . $_REQUEST['chunknumber'];
		$fh = fopen($cryptofile, 'w') or die(json_encode(array("status" => "unable to write cryptofile", "fileid" => "")));
		fwrite($fh, $_REQUEST['cryptofile']);
		fclose($fh);
		
		// find out if this ip should be added to serverdata
		$file = $config->data_path.$fileid."/serverdata.json";
		$fh = fopen($file, 'r');
		$serverdata = fread($fh, filesize($file));
		fclose($fh);
		$serverdata = json_decode($serverdata,true);
		$alreadyadded = false;
		foreach($serverdata['clientips'] as $ip) {
			if($_SERVER['REMOTE_ADDR'] == $ip) {
				$alreadyadded = true;
				break;
			};
		};
		if(!$alreadyadded) {
			$serverdata['clientips'][] = $ip;
			//write serverdata.json again
			$fh = fopen($serverdatafile, 'w') or die(json_encode(array("status" => "unable to write serverdatafile", "fileid" => "")));
			$json = json_encode($serverdata);
			fwrite($fh, $json);
			fclose($fh);
		};
		
		// find the total size of all chunks uploaded so far, and the number of chunks
		$chunkinfo = get_upload_info($config->data_path.$fileid);
		
		
		// send email
		if ($config->admin->send_email === true) {
			$to = "{$config->admin->name} <{$config->admin->email}>";
			$subject = "new filepart uploaded to " . $_SERVER["SERVER_NAME"];
			$message = "new filepart uploaded to " . $_SERVER["SERVER_NAME"] . ": https://" . $_SERVER["SERVER_NAME"] . "/" . $fileid . "\n";
			if($finished) {
				$message += "upload is finished, " . $chunkinfo['chunkcount'] . " chunks - " . $chunkinfo['totalsize'] . " bytes total";
			} else {
				$message += "upload is not finished, " . $chunkinfo['chunkcount'] . " chunks  - " . $chunkinfo['totalsize'] . " bytes total";
			};
			$from = $config->email_sender;
			$headers = "From:" . $from;
			mail($to,$subject,$message,$headers);
		};
		
		// encode and return json reply
		die(json_encode(array("status" => "ok", "fileid" => $fileid, "chunks" => $chunkcount, "totalsize" => $totalsize, "finished" => $finished, "uploadpassword" => $uploadpassword)));
	} else {
		header("Status: 400 Bad Request");
		die(json_encode(array("status" => "invalid upload request, error", "fileid" => "")));
	};
} else {
	// parse URL
	$url = parse_url($_SERVER['REQUEST_URI']);
	$vars = explode("&",$url['query']);
	foreach($vars as $element) {
		if(strpos($element,"=") === false) {
			$params[$element] = null;
		} else {
			$key = substr($element,0,strpos($element,"="));
			$params[$key] = substr($element,strpos($element,"=")+1);
		};
	};

    // handle API endpoints that do not require a valid fileid
	switch($url['path']) {
		case "/api/serverinfo":
            // return serverinfo json
            die(json_encode(array(
                "server_operator_email" => $config->admin->email,
                "max_retention_hours" => $config->max_retention_hours,
                "max_filesize" => $config->max_filesize_bytes,
                "max_chunksize" => $config->max_chunksize_bytes
            )));
		break;
	};
    
	// all remaining API endpoints require a valid fileid, 
    // so check if fileid is in the params
	if(isset($params['fileid'])) {
		//check if fileid exists and is valid
		if (!file_exists($config->data_path.$params['fileid'])) {
			header("Status: 404 Not Found");
			die(json_encode(array("fileid" => $params['fileid'], "exists" => false)));
		};
	} else {
		header("Status: 400 Bad Request");
		die(json_encode(array("status" => "missing fileid")));
	};

	switch($url['path']) {
		case "/api/exists":
			// fileid is valid if we got this far, find out if the upload is finished
			$uploadinfo = get_upload_info($config->data_path.$fileid);
			die(json_encode(array(
				"fileid" => $params['fileid'], 
				"exists" => true, 
				"chunks" => $uploadinfo['chunkcount'], 
				"totalsize" => $uploadinfo['totalsize'], 
				"finished" => $uploadinfo['finished']
			)));
		break;
		
		case "/api/file":
			//download cryptofile.N file
			$file = $config->data_path.$params['fileid']."/cryptofile." . $params['chunknumber'];
			header("Content-Length: " . filesize($file));
			header("Content-Type: text/plain");
			flush();
			$fp = fopen($file, "r");
			while (!feof($fp)) {
				echo fread($fp, 65536);
				flush(); // for large downloads
			}
			fclose($fp);
			exit();
		break;
		
		case "/api/metadata":
			//download metadata.dat file
			$file = $config->data_path.$params['fileid']."/metadata.dat";
			header("Content-Length: " . filesize($file));
			header("Content-Type: text/plain");
			flush();
			readfile($file);
			exit();
		break;
		
		case "/api/delete":
			//get deletepassword from serverdata.json
			$file = $config->data_path.$params['fileid']."/serverdata.json";
			$fh = fopen($file, 'r');
			$serverdata = fread($fh, filesize($file));
			fclose($fh);
			$serverdata = json_decode($serverdata,true);
			
			//check if passwords match
			if($params['deletepassword'] == $serverdata['deletepassword']) {
				//password valid! delete stuff
				unlink($config->data_path.$params['fileid']."/serverdata.json");
				unlink($config->data_path.$params['fileid']."/metadata.dat");
				array_map('unlink', glob($config->data_path.$params['fileid']."/cryptofile.*"));
				rmdir($config->data_path.$params['fileid']);
				die(json_encode(array("fileid" => $params['fileid'], "deleted" => true)));
			} else {
				//incorrect password
				header("Status: 401 Unauthorized");
				die(json_encode(array("fileid" => $params['fileid'], "deleted" => false)));
			};
		break;
		
		case "/api/ip":
			//return the ip(s) that uploaded this file
			$file = $config->data_path.$params['fileid']."/serverdata.json";
			$fh = fopen($file, 'r');
			$serverdata = fread($fh, filesize($file));
			fclose($fh);
			$serverdata = json_decode($serverdata,true);
			die(json_encode(array("fileid" => $params['fileid'], "uploadip" => $serverdata['clientips'])));
		break;
		
		default:
			// invalid command, show error page
			header("Status: 400 Bad Request");
			die(json_encode(array("fileid" => $params['fileid'], "status" => "bad request")));
		break;
	};
};
?>
