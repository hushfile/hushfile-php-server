<?php
$config = json_decode(file_get_contents('config.json'));

function get_uniqid() {
	$randombytes = openssl_random_pseudo_bytes(15);
	$fileid = bin2hex($randombytes);
	if (file_exists($config->data_path.$fileid)) {
		//somehow this ID already exists, call recursively until we found an unused ID
		//todo: set a recursion limit to avoid infinite looping
		$fileid = get_uniqid();
	};
	return $fileid;
}

if($_SERVER["REQUEST_URI"] == "/api/upload") {
	// THIS IS A FILE UPLOAD, ONLY POST ACCEPTED
	if($_SERVER['REQUEST_METHOD'] != "POST") {
		header("Status: 405 Method Not Allowed");
		die(json_encode(array("status" => "Invalid upload request, only POST allowed", "fileid" => "")));
	};
	
	if(isset($_REQUEST['cryptofile']) && isset($_REQUEST['metadata']) && isset($_REQUEST['deletepassword'])) {
		// first get a new unique ID for this file
		$fileid = get_uniqid();
		$cryptofile = $config->data_path.$fileid."/cryptofile.dat";
		$metadatafile = $config->data_path.$fileid."/metadata.dat";
		$serverdatafile = $config->data_path.$fileid."/serverdata.json";
		
		// create folder for this file
		mkdir($config->data_path.$fileid);

		// write encrypted file
		$fh = fopen($cryptofile, 'w') or die(json_encode(array("status" => "unable to write cryptofile", "fileid" => "")));
		fwrite($fh, $_REQUEST['cryptofile']);
		fclose($fh);

		// write metadata file
		$fh = fopen($metadatafile, 'w') or die(json_encode(array("status" => "unable to write metadatafile", "fileid" => "")));
		fwrite($fh, $_REQUEST['metadata']);
		fclose($fh);

		// find client IP
		if(array_key_exists('X-Forwarded-For',$_SERVER)) {
			$clientip = $_SERVER['X-Forwarded-For'];
		} else {
			$clientip = $_SERVER['REMOTE_ADDR'];
		}

		// write serverdata file
		$fh = fopen($serverdatafile, 'w') or die(json_encode(array("status" => "unable to write serverdatafile", "fileid" => "")));
		$json = json_encode(array(
			"deletepassword" => $_REQUEST['deletepassword'],
			"clientip" => $clientip
		));
		fwrite($fh, $json);
		fclose($fh);

		// send email
		if ($config->admin->send_email === true) {
			$to = "{$config->admin->name} <{$config->admin->email}>";
			$subject = "new file uploaded to " . $_SERVER["SERVER_NAME"];
			$message = "new file uploaded to " . $_SERVER["SERVER_NAME"] . ": https://" . $_SERVER["SERVER_NAME"] . "/" . $fileid;
			$from = $config->email_sender;
			$headers = "From:" . $from;
			mail($to,$subject,$message,$headers);
		}
		
		// encode json reply
		die(json_encode(array("status" => "ok", "fileid" => $fileid)));
	} else {
		header("Status: 400 Bad Request");
		die(json_encode(array("status" => "invalid upload request, error", "fileid" => "")));
	}
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
	
	// check if fileid is in the params
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
			// fileid is valid if we got this far, no need to check again
			die(json_encode(array("fileid" => $params['fileid'], "exists" => true)));
		break;
		
		case "/api/file":
			//download cryptofile.dat file
			$file = $config->data_path.$params['fileid']."/cryptofile.dat";
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
				unlink($config->data_path.$params['fileid']."/cryptofile.dat");
				rmdir($config->data_path.$params['fileid']);
				die(json_encode(array("fileid" => $params['fileid'], "deleted" => true)));
			} else {
				//incorrect password
				header("Status: 401 Unauthorized");
				die(json_encode(array("fileid" => $params['fileid'], "deleted" => false)));
			};
		break;
		
		case "/api/ip":
			//return the ip that uploaded this file
			$file = $config->data_path.$params['fileid']."/serverdata.json";
			$fh = fopen($file, 'r');
			$serverdata = fread($fh, filesize($file));
			fclose($fh);
			$serverdata = json_decode($serverdata,true);
			die(json_encode(array("fileid" => $params['fileid'], "uploadip" => $serverdata['clientip'])));
		break;
		
		default:
			// invalid command, show error page
			header("Status: 400 Bad Request");
			die(json_encode(array("fileid" => $params['fileid'], "status" => "bad request")));
		break;
	};
};
?>
