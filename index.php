<?php 

	require_once 'ftp/ftp.class.php';

	function customErrorHandler($errno, $errmsg, $filename, $linenum, $vars)
	{
		echo "\r\n".$errno.' * '.$errmsg.' * '.$filename.' * '.$linenum.' * '.$vars;
	}

	function flushoutput()
	{
		$fp = fopen('data.txt', 'a');
		echo "\r\n ** Ending script execution **\r\n\r\n";
		fwrite($fp,ob_get_clean()."\r\n");
		fclose($fp);
	}

	function endscript()
	{
		flushoutput();
		exit(0);
	}

	function remove_tmp_files($dir_name)
	{
		unlink($dir_name.'.zip');
		try {
			deleteDir($dir_name);	
		}
		catch (Exception $ex)
		{
			error_log("Error removing tmp dir: ".$ex->getMessage());
			endscript();
		}
	}
	
	function deleteDir($dirPath) 
	{
		if (! is_dir($dirPath)) {
			throw new InvalidArgumentException('$dirPath must be a directory');
		}
		if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
			$dirPath .= '/';
		}
		$files = glob($dirPath . '*', GLOB_MARK);
		foreach ($files as $file) {
			if (is_dir($file)) {
				deleteDir($file);
			} else {
				unlink($file);
			}
		}
		rmdir($dirPath);
	}
	
	set_error_handler("customErrorHandler");
	// Avoid warnings on our servers
	date_default_timezone_set('America/Argentina/Buenos_Aires');
	ob_start();
	echo "\r\n *** Starting execution at: ".date("Y-m-d h:i:s")." from IP: ".$_SERVER["REMOTE_ADDR"];
	if (!isset($_REQUEST['payload']))
	{
		error_log("PAYLOAD Unset");
		endscript();
	}
	echo "\r\nPayload sent:".$_REQUEST['payload']."\r\n";
	$payload = str_replace('\"','"',$_REQUEST['payload']);
	$payload = str_replace("\'","'",$payload);
	$commitInfo = json_decode($payload);
	if (!$commitInfo)
	{
		echo "\r\nError decoding Payload. Payload decoded as:".$commitInfo;
		error_log("Couldn't decode Payload: ".$payload."\r\n");
		endscript();
	}
	// Upload only when the change gets to master branch
	if (str_replace('refs/heads/', '', $commitInfo->{'ref'})=='master')
	{
		$add = array();
		$update = array();
		$delete = array();
		foreach($commitInfo->{'commits'} as $commit) 
		{
			if(isset($commit->{'added'}) && count($commit->{'added'})) 
			{
				foreach ($commit->{'added'} as $file)
				{
					$add[]=$file;
				}
			}
			if(isset($commit->{'removed'}) && count($commit->{'removed'})) 
			{
				foreach ($commit->{'removed'} as $file)
				{
					$delete[]=$file;
				}
			}
			if(isset($commit->{'modified'}) && count($commit->{'modified'})) 
			{
				foreach ($commit->{'modified'} as $file)
				{
					$update[]=$file;
				}
			}
		}
		if (count($add)||count($update)||count($delete))
		{
			// Save temp zip file
			$ch = curl_init();
			echo "\r\nDownloading from:".str_replace('https://github','https://nodeload.github',$commitInfo->{'repository'}->{'url'}.'/zipball/master');
			$source = str_replace('https://github','https://nodeload.github',$commitInfo->{'repository'}->{'url'}.'/zipball/master');
			curl_setopt($ch, CURLOPT_URL, $source);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			// Strict cert validation not working on my hosting environment :(
			//curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			//curl_setopt($ch, CURLOPT_CAINFO, getcwd() . "/certs/github.crt");
			$data = curl_exec ($ch);
			if ($data==false)
			{
				error_log("CURL Error: ". curl_error($ch));
				curl_close($ch);
				endscript();
			}
			curl_close ($ch);
			$destination = $commitInfo->{'before'}.'.zip';
			$file = fopen($destination, "w+");
			fputs($file, $data);
			fclose($file);
			// Extract zip file
			$zip = new ZipArchive;
			if ($zip->open($commitInfo->{'before'}.'.zip') === TRUE) 
			{
				$zip->extractTo('./'.$commitInfo->{'before'});
				$zip->close();
			} 
			else 
			{
				error_log ('unzip UNsuccesful');
				unlink($commitInfo->{'before'}.'.zip');
				endscript();
			}
			// FTP tasks
			$dir_names = scandir($commitInfo->{'before'});
			$dir_name = $dir_names[2];
			$base_dir = $commitInfo->{'before'}.'/'.$dir_name;
			$params = parse_ini_file("config.ini",true);
			$ftp = new Ftp;
			if ($params != false)
			{
				if (!$params[$commitInfo->{'repository'}->{'name'}]["server"]||!$params[$commitInfo->{'repository'}->{'name'}]["user"]||!$params[$commitInfo->{'repository'}->{'name'}]["pass"]||!$params[$commitInfo->{'repository'}->{'name'}]["initial_folder"])
				{
					error_log("\r\nInvalid FTP Ini settings:\r\nRepository Name:".$commitInfo->{'repository'}->{'name'}."\r\nIni File settings:".var_dump($params[$commitInfo->{'repository'}->{'name'}]));
					remove_tmp_files($commitInfo->{'before'});
					endscript();
				}
				try 
				{
					$ftp->connect($params[$commitInfo->{'repository'}->{'name'}]['server']);
					$ftp->login($params[$commitInfo->{'repository'}->{'name'}]['user'], $params[$commitInfo->{'repository'}->{'name'}]['pass']);
				}
				catch (FtpException $e) 
				{
					echo "\r\nFTP Error: ", $e->getMessage();
				}
				// Upload files to ftp server
				foreach ($add as $file)			
				{
					echo "\r\nAdding file: ".$base_dir.'/'.$file;
					try 
					{
						// If added file is in a subfolder then create folders on ftp server first
						if (strpos($file,'/')!==false)
						{
							echo "\r\nCreating folder: ".$params[$commitInfo->{'repository'}->{'name'}]['initial_folder'].'/'.substr($file,0,strrpos($file,'/'));
							$ftp->mkDirRecursive($params[$commitInfo->{'repository'}->{'name'}]['initial_folder'].'/'.substr($file,0,strrpos($file,'/')));
						}
						$ftp->put($params[$commitInfo->{'repository'}->{'name'}]['initial_folder'].'/'.$file, $base_dir.'/'.$file, FTP_BINARY);
					}
					catch (FtpException $e) 
					{
						echo "\r\nFTP Error: ", $e->getMessage()." - Destination file: ".$params[$commitInfo->{'repository'}->{'name'}]['initial_folder'].'/'.$file." - Source file: ".$base_dir.'/'.$file;
					}
				}
				// Update files on ftp server
				foreach ($update as $file)			
				{
					echo "\r\nUpdating file: ".$base_dir.'/'.$file;
					try 
					{
						$ftp->put($params[$commitInfo->{'repository'}->{'name'}]['initial_folder'].'/'.$file, $base_dir.'/'.$file, FTP_BINARY);
					}
					catch (FtpException $e) 
					{
						echo "\r\nFTP Error: ", $e->getMessage()." - Destination file: ".$params[$commitInfo->{'repository'}->{'name'}]['initial_folder'].'/'.$file." - Source file: ".$base_dir.'/'.$file;
					}
				}
				// Delete files from ftp server
				foreach ($delete as $file)			
				{
					echo "\r\nDeleting file from FTP: ".$params[$commitInfo->{'repository'}->{'name'}]['initial_folder'].'/'.$file;
					try
					{
						$ftp->delete($params[$commitInfo->{'repository'}->{'name'}]['initial_folder'].'/'.$file);
					}
					catch (FtpException $e) 
					{
						echo "\r\nFTP Error: ", $e->getMessage();
					}
				}
				echo "\r\nSuccesfully updated files\r\n";
				remove_tmp_files($commitInfo->{'before'});
				endscript();
			}
		}
	}
?>		