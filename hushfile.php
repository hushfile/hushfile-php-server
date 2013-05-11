<?php
$datapath = "/usr/local/www/filedata/";

function get_uniqid() {
	$fileid = uniqid();
	if (file_exists($datapath.$fileid)) {
		//somehow this ID already exists, call recursively
		$fileid = get_uniqid();
	};
	return $fileid;
}

if($_SERVER["REQUEST_URI"] == "/api/upload") {
	// THIS IS A FILE UPLOAD, ONLY POST ACCEPTED
	if(isset($_REQUEST['cryptofile']) && isset($_REQUEST['metadata']) && isset($_REQUEST['deletepassword'])) {
		// first get a new unique ID for this file
		$fileid = get_uniqid();
		$cryptofile = $datapath.$fileid."/cryptofile.dat";
		$metadatafile = $datapath.$fileid."/metadata.dat";
		$serverdatafile = $datapath.$fileid."/serverdata.json";
		
		// create folder for this file
		mkdir($datapath.$fileid);

		// write encrypted file
		$fh = fopen($cryptofile, 'w') or die(json_encode(array("status" => "unable to write cryptofile", "fileid" => "")));
		fwrite($fh, $_REQUEST['cryptofile']);
		fclose($fh);

		// write metadata file
		$fh = fopen($metadatafile, 'w') or die(json_encode(array("status" => "unable to write metadatafile", "fileid" => "")));
		fwrite($fh, $_REQUEST['metadata']);
		fclose($fh);

		// write serverdata file
		$fh = fopen($serverdatafile, 'w') or die(json_encode(array("status" => "unable to write serverdatafile", "fileid" => "")));
		$json = json_encode(array(
			"deletepassword" => $_REQUEST['deletepassword'],
			"clientip" => $_SERVER['REMOTE_ADDR']
		));
		fwrite($fh, $json);
		fclose($fh);

		// send email
		$to = "thomas@gibfest.dk";
		$subject = "new file uploaded to hushfile.it";
		$message = "new file uploaded to hushfile.it: http://hushfile.it/" . $fileid;
		$from = "upload@hushfile.it";
		$headers = "From:" . $from;
		mail($to,$subject,$message,$headers);
		
		// encode json reply
		echo json_encode(array("status" => "ok", "fileid" => $fileid));
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
		if (!file_exists($datapath.$fileid)) {
			header("Status: 404 Not Found");
			echo json_encode(array("fileid" => $fileid, "exists" => false));
		};
	} else {
		header("Status: 400 Bad Request");
		echo json_encode(array("status" => "missing fileid"));
	};

	switch($url['path']) {
		case "/api/exists":
			// fileid is valid if we got this far
			echo json_encode(array("fileid" => $fileid, "exists" => true));
		break;
		
		case "/api/file":
			//download cryptofile.dat file
			$file = $datapath.$fileid."/cryptofile.dat";
			header("Content-Length: " . filesize($file));
			header("Content-Type: text/plain");
			flush();
			$fp = fopen($file, "r");
			while (!feof($fp)) {
				echo fread($fp, 65536);
				flush(); // for large downloads
			} 
			fclose($fp);
		break;
		
		case "/api/metadata":
			//download metadata.dat file
			$file = $datapath.$fileid."/metadata.dat";
			header("Content-Length: " . filesize($file));
			header("Content-Type: text/plain");
			flush();
			readfile($file);
		break;
		
		case "/api/delete":
			//get deletepassword from serverdata.json
			$file = $datapath.$fileid."/serverdata.json";
			$fh = fopen($file, 'r');
			$serverdata = fread($fh, filesize($file));
			fclose($fh);
			$serverdata = json_decode($serverdata,true);
			
			//check if passwords match
			if($params['deletepassword'] == $serverdata['deletepassword']) {
				//password valid! delete stuff
				unlink($datapath.$fileid."/serverdata.json");
				unlink($datapath.$fileid."/metadata.dat");
				unlink($datapath.$fileid."/cryptofile.dat");
				rmdir($datapath.$fileid);
				echo json_encode(array("fileid" => $fileid, "deleted" => true));
			} else {
				//incorrect password
				header("Status: 401 Unauthorized");
				echo json_encode(array("fileid" => $fileid, "deleted" => false));
			};
		break;
		
		case "/api/ip":
			//return the ip that uploaded this file
			$file = $datapath.$fileid."/serverdata.json";
			$fh = fopen($file, 'r');
			$serverdata = fread($fh, filesize($file));
			fclose($fh);
			$serverdata = json_decode($serverdata,true);
			echo json_encode(array("fileid" => $fileid, "uploadip" => $serverdata['clientip']));
		break;
		
		default:
			// invalid command, show error page
			header("Status: 400 Bad Request");
			echo json_encode(array("fileid" => $fileid, "status" => "bad request"));
		break;
	};
};
?>
