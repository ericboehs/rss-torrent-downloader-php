<?php

/*
TODO:
-Make a "favorites" zip section
-Add a cron job for creating those favorites
-Make it XHTML valid
-Fix it to die gracefully if curse isn't providing the xml correctly
-Updated blah, but blah was missing
-Use the server's tmp directory
-return stuff in arrays
-Improve session messaging system
*/
class ParseXML{
  function GetChildren($vals, &$i) {
    $children = array(); // Contains node data
    if (isset($vals[$i]['value'])){
      $children['VALUE'] = $vals[$i]['value'];
    }
    while (++$i < count($vals)){
      switch ($vals[$i]['type']){
        case 'cdata':
        if (isset($children['VALUE'])){
          $children['VALUE'] .= $vals[$i]['value'];
        } else {
          $children['VALUE'] = $vals[$i]['value'];
        }
        break;

        case 'complete':
        if (isset($vals[$i]['attributes'])) {
          $children[$vals[$i]['tag']][]['ATTRIBUTES'] = $vals[$i]['attributes'];
          $index = count($children[$vals[$i]['tag']])-1;

          if (isset($vals[$i]['value'])){
            $children[$vals[$i]['tag']][$index]['VALUE'] = $vals[$i]['value'];
          } else {
            $children[$vals[$i]['tag']][$index]['VALUE'] = '';
          }
        } else {
          if (isset($vals[$i]['value'])){
            $children[$vals[$i]['tag']][]['VALUE'] = $vals[$i]['value'];
          } else {
            $children[$vals[$i]['tag']][]['VALUE'] = '';
          }
        }
        break;

        case 'open':
        if (isset($vals[$i]['attributes'])) {
          $children[$vals[$i]['tag']][]['ATTRIBUTES'] = $vals[$i]['attributes'];
          $index = count($children[$vals[$i]['tag']])-1;
          $children[$vals[$i]['tag']][$index] = array_merge($children[$vals[$i]['tag']][$index],$this->GetChildren($vals, $i));
        } else {
          $children[$vals[$i]['tag']][] = $this->GetChildren($vals, $i);
        }
        break;

        case 'close':
        return $children;
      }
    }
  }

  function GetXMLTree($xmlloc){
    $data = $xmlloc;
    $parser = xml_parser_create('ISO-8859-1');
    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
    xml_parse_into_struct($parser, $data, $vals, $index);
    xml_parser_free($parser);

    $tree = array();
    $i = 0;

    if (isset($vals[$i]['attributes'])) {
      $tree[$vals[$i]['tag']][]['ATTRIBUTES'] = $vals[$i]['attributes'];
      $index = count($tree[$vals[$i]['tag']])-1;
      $tree[$vals[$i]['tag']][$index] =  array_merge($tree[$vals[$i]['tag']][$index], $this->GetChildren($vals, $i));
    } else {
      $tree[$vals[$i]['tag']][] = $this->GetChildren($vals, $i);
    }
    return $tree;
  }
}

function getCurseSessionID(){
  /*This function is used to store the Session ID, I got from Curse using a packet sniffer and their
  original curse client.  I've been using this ID for a while and it hasn't expired.  Here's to hoping
  it never will.*/
	return "VNIBQDUBUCEUPATP";
}

function fork($shellCmd){
  $shellCmd = addslashes($shellCmd);
	exec("nice sh -c \"$shellCmd\" > /dev/null 2>&1 &");
}

function fetchAddonXML($curseAddonID){
  /*This will get the XML file that is associated with the curseAddonID.  It contains everything we need
  including the URL to the addon and the addon's zip files.  Eventually I'd like to make this return the
  contents of the file in XML format, rather than a filename of where it's stored.*/
  //This registers the $baseURL and $debug variables as global variables so that they can be used inside
  //(or outside) this function.  If I don't make them global, then only what I return is accessable.
  global $baseURL, $debug;
  $curseSessionID = getCurseSessionID();
  //This is what actually get's the xml file and saves it.
  $ch = curl_init('http://addonservice.curse.com/AddOnService.asmx/GetAddOn?pAddOnId='.$curseAddonID.'&pSession='.$curseSessionID);
  if (!$ch)	die( "Cannot allocate a new PHP-CURL handle" );
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
  $xml = curl_exec($ch);
  curl_close($ch); //Free up resources that curl was using
  $xml = substr($xml, 1);
  return $xml;
}

function parseXML($data){
  $ParseXML = new ParseXML();
  $xmlarray = $ParseXML->GetXMLTree($data);
  $latestFile = count($xmlarray['PSYN'][0]['PROJECT'][0]['FILES'][0]['FILE'])-1;
  $addonName = $xmlarray['PSYN'][0]['PROJECT'][0]['NAME'][0]['VALUE'];
  $addonURL = $xmlarray['PSYN'][0]['PROJECT'][0]['URL'][0]['VALUE'];
  $currentDownloadID = $xmlarray['PSYN'][0]['PROJECT'][0]['FILES'][0]['FILE'][$latestFile]['ATTRIBUTES']['ID'];
  $currentVersion = $xmlarray['PSYN'][0]['PROJECT'][0]['FILES'][0]['FILE'][$latestFile]['NAME'][0]['VALUE'];
  $zipURL = $xmlarray['PSYN'][0]['PROJECT'][0]['FILES'][0]['FILE'][$latestFile]['URL'][0]['VALUE'];
  $result = array($addonName, $addonURL, $currentDownloadID, $currentVersion, $zipURL);
  return $result;
}

function getContentLength($url){
  /*Checks the content length of a file.  Used for ajax progress updates.*/
  $contentLength = shell_exec('curl -s -I '.$url.' | grep Content-Length');
  $contentLength = explode(" ", $contentLength);
  $contentLength = $contentLength[1];
  return $contentLength;
}

function getIdFromURL($url){
  /*Grabs the ID from the "Install via Curse Client" link using a bookmarklet.*/
  global $message;
  $pieces = explode("=", $url);
  if(count($pieces)!=2 && substr($url,0,4) != "psyn"){
		$message .= "Invalid URL passed.";
		return false;
  }
  return trim($pieces[1]);
}

function checkForUpdateCompletion($curseAddonID){
  /*Checks to see if the "InProgress" file exists - used for ajax queries*/
  global $baseURL;
	if(file_exists($baseURL.$curseAddonID.'InProgress')) return false;
	return true;
}

function checkDownloadProgress($addonName){
  global $baseURL;
  $sizeInBytes = shell_exec('ls -l '.$baseURL.'cachedZips/'.$addonName.'.zip | awk \'{print $5}\'');
  return $sizeInBytes;
}

function addonExists($curseAddonID){
	global $debug, $baseURL;
	require($baseURL.'config.php');
	$query = "SELECT id from amz_addonsList WHERE curseAddonID=".$curseAddonID;
	$result = mysql_query($query);
	if(mysql_num_rows($result)) return true;
	return false;
}

function getVersionsFromZip($userZipLocation, $userExtractLocation){
  global $debug, $baseURL;
  $zipFilename = "AddonPack-".date('Ymd-His');
  if(file_exists($zipFilename)){
    $zipFilename .= "-".rand().".zip";
  }else{
    $zipFilename .= ".zip";
  }
  shell_exec('unzip "'.$userZipLocation.'" -d "'.$userExtractLocation.'"');
  unlink($userZipLocation);
  $directoryListing = scandir($userExtractLocation.'/versions');
  foreach($directoryListing as $thisFilename){
    if($thisFilename != "." || $thisFilename != ".."){
      $pieces = explode(".", $thisFilename);
      if($pieces[0]){
        $addonNames[] = $pieces[0];
        $addonHashes[] = file_get_contents($userExtractLocation.'/versions/'.$thisFilename);
      }
    }
  }
  $i=0;
  foreach($addonNames as $thisAddonName){
    if($addonHashes[$i] != file_get_contents($baseURL.'cachedZips/'.$thisAddonName.'.dir/versions/'.$thisAddonName.'.md5')){
      $updated = true;
      shell_exec('cd "'.$baseURL.'" && cd "cachedZips/'.$thisAddonName.'.dir" && zip -r "../../customZips/'.$zipFilename.'" * && cd ../..');
    }
    $i++;
  }
  shell_exec('rm -rf "'.$userExtractLocation.'"');
  if($updated) return 'customZips/'.$zipFilename;
  return false;
}

function md5Addon($addonName){
  global $debug, $baseURL;
  $md5Hash = md5_file($baseURL.'cachedZips/'.$addonName.'.zip');
  $md5Location = $baseURL.'cachedZips/'.$addonName.'.dir/versions/'.$addonName.'.md5';
  $md5Directory = $baseURL.'cachedZips/'.$addonName.'.dir/versions/';
  if(!file_exists($md5Directory)) mkdir($md5Directory);
  //die('Dir: '.$md5Directory . ' | Loc: ' . $md5Location . ' | Hash: ' . $md5Hash);
  file_put_contents($md5Location, $md5Hash);
  return;
}

function getDateTime(){
  $lastDownloadDateTime = trim(date('Y-m-d H:i:s'));
  $lastDownloadDateTimeHuman = trim(date('M j, Y \a\t g:i a'));
  return array($lastDownloadDateTime, $lastDownloadDateTimeHuman);
}

function updateAddon($curseAddonID){
  global $debug, $baseURL, $currentDateTime, $addonName, $ourAddonName, $zipURL, $addonURL, $currentVersion, $currentDownloadID;
  require('config.php');
  $addonInfo = parseXML(fetchAddonXML($curseAddonID));
  $addonName = $addonInfo[0];
  $addonURL = $addonInfo[1];
  $currentDownloadID = $addonInfo[2];
  $currentVersion = $addonInfo[3];
  $zipURL = $addonInfo[4];
  $currentDateTime = getDateTime();

  $query = "SELECT lastDownloadID from amz_addonsList WHERE curseAddonID=".$curseAddonID;
  $result = mysql_query($query);
  while($row = mysql_fetch_array($result, MYSQL_ASSOC)){
    $lastDownloadID = trim($row['lastDownloadID']);
  }
  $query = "UPDATE amz_addonsList SET addonName='$addonName', addonURL='$addonURL', version='$currentVersion', lastUpdateDateTime='$currentDateTime[0]', lastUpdateDateTimeHuman='$currentDateTime[1]' WHERE curseAddonID=$curseAddonID";
  if($debug){ echo $query."<br />"; }
  $updatesult = mysql_query($query);
  if($debug && !$updateResult) die('Invalid query: ' . mysql_error());
  if($currentDownloadID != $lastDownloadID) $updatedNeeded = true;
  if(!file_exists($baseURL.'cachedZips/'.$addonName.'.zip')) $updatedNeeded = true;
  if(!file_exists($baseURL.'cachedZips/'.$addonName.'.dir') && !$updatedNeeded) fork('unzip -d "'.$baseURL.'cachedZips/'.$addonName.'.dir" "'.$baseURL.'cachedZips/'.$addonName.'.zip"');
  if(!file_exists($baseURL.'cachedZips/'.$addonName.'.dir/versions/'.$addonName.'.md5') && !$updatedNeeded) md5Addon($addonName);

  if(!$updatedNeeded) return false;

  $_SESSION['addonName'] = $addonName;
  $_SESSION['curseAddonID'] = $curseAddonID;
  $_SESSION['addonSize'] = getContentLength($zipURL);
  touch($baseURL.$curseAddonID."InProgress");
  fork('wget -O "'.$baseURL.'cachedZips/'.$addonName.'.zip" '.$zipURL.' && rm '.$baseURL.$curseAddonID.'InProgress && rm -rf "'.$baseURL.'cachedZips/'.$addonName.'.dir"; unzip -d "'.$baseURL.'cachedZips/'.$addonName.'.dir" "'.$baseURL.'cachedZips/'.$addonName.'.zip" && md5 "'.$baseURL.'cachedZips/'.$addonName.'.zip" -out "'.$baseURL.'cachedZips/'.$addonName.'.dir/md5checksum.txt"');
  if(addonExists($curseAddonID)){
  	$query = "UPDATE amz_addonsList SET addonName='$addonName', version='$currentVersion', addonURL='$addonURL', lastDownloadID=$currentDownloadID, lastDownloadDateTime='$currentDateTime[0]', lastDownloadDateTimeHuman='$currentDateTime[1]', lastUpdateDateTime='$currentDateTime[0]', lastUpdateDateTimeHuman='$currentDateTime[1]' WHERE curseAddonID=$curseAddonID";
  }else{
    $query = "INSERT INTO amz_addonsList (curseAddonID, addonName, version, addonURL, lastDownloadID, lastDownloadDateTime, lastDownloadDateTimeHuman, lastUpdateDateTime, lastUpdateDateTimeHuman) VALUES ($curseAddonID, '$addonName', '$currentVersion', '$addonURL', $currentDownloadID, '$currentDateTime[0]', '$currentDateTime[1]', '$currentDateTime[0]', '$currentDateTime[1]')";
  }
  $result = mysql_query($query);
  if($debug && !$result) die('Invalid query: ' . mysql_error());
  return true;
}

function deleteAddon($curseAddonID){
	global $debug, $baseURL, $addonName, $message;
	if($curseAddonID == null || !is_numeric($curseAddonID)){
		$message .= "Addon ID must contain numbers only.  Please select update from the list below.";
		return false;
	}
	require('config.php');
	$query = "SELECT addonName from amz_addonsList WHERE curseAddonID=".$curseAddonID;
	$result = mysql_query($query);
	if(!result) return false;
	while($row = mysql_fetch_array($result, MYSQL_ASSOC)){
	  $addonName = trim($row['addonName']);

	}
	if(file_exists($baseURL.'cachedZips/'.$addonName.'.dir')){
	  shell_exec('rm -rf "'.$baseURL.'cachedZips/'.$addonName.'.dir"');
	}
	if(file_exists($baseURL.'cachedZips/'.$addonName.'.zip')){
	  shell_exec('rm -rf "'.$baseURL.'cachedZips/'.$addonName.'.zip"');
	}
	$query = "DELETE FROM amz_addonsList WHERE curseAddonID=".$curseAddonID;
	$deleteResult = mysql_query($query);
	if(!$deleteResult) return false;
	return true;
}

function oldParseXML($xml){
  if(!isset($xml)) return false;
  global $debug, $lastDownloadDateTime, $lastDownloadDateTimeHuman, $addonName, $zipURL, $addonURL, $currentVersion, $currentDownloadID;
  //$line = explode("<name>",$xml);
  ///// YOU WERE HERE
  //die(print_r($line));
  $addonName = addslashes(trim(shell_exec('cat '.$xml.' | tr -s \'><\' \'\n\' | grep -a -m 1 -A 1 name | tail -1')));
  $zipURL = trim(shell_exec('cat '.$xml.' | tr -s \'><\' \'\n\' | grep -a -B 2 date | grep zip | tail -1'));
  $addonURL = trim(shell_exec('cat '.$xml.' | tr -s \'><\' \'\n\' | grep -a -m 1 aspx'));
  $currentVersion = trim(shell_exec('cat '.$xml.' | tr -s \'><\' \'\n\' | grep -a -A 2 \'file id\' | tail -1'));
  $currentDownloadID = trim(shell_exec('cat '.$xml.' | tr -s \'><\' \'\n\' | grep -a \'file id\' | tail -1 | awk -F\'"\' \'{print $2}\''));
  unlink($xml);
  return true;
}

?>
