<?php

/*
TODO:
-Do checks to make sure download completed
*/
define('MAX_INTEGER_LENGTH', 12);

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

class BEncodeReader{
  var $data,
    $pointer = 0,
    $data_length = NULL;

  function BEncodeReader($data = null){
    if(is_null($data))
      return;
    /*if(($data = @file_get_contents($filename)) === false){
      trigger_error("Could not create BEncodeReader for {$filename}: failed to read file", E_USER_WARNING);
      return;
    }*/
    $this->setData($data);
  }

  function setData($data){
    $this->data_length = strlen($data);
    $this->data = $data;
  }

  function readNext(){
    // This is a hack for legacy use, since I just added the setData method and some parts of
    // the code haven't switched over yet. It's reliable, its just not very nice
    if(is_null($this->data_length))
      $this->data_length = strlen($this->data);
    while($this->pointer < $this->data_length){
      switch($this->data[$this->pointer++]){
        case 'l':
          return $this->readNextList();
        case 'd':
          return $this->readNextDictionary();
        case 'i':
          return $this->readNextInteger();
        default:
          $this->pointer--;
          return $this->readNextString();
      }
    }
  }

  function readNextDictionary(){
    $dictionary = array();
    while($this->data[$this->pointer] != 'e'){
      $key = $this->readNextString();
      if($key !== false){ // Special hack just for torrent files (how nice)
        if($key == "info")
          $info_start = $this->pointer;
        $dictionary[$key] = $this->readNext();
        // We need a sha1 hash of the info bit of the file for use with trackers, so grab that now
        if($key == "info"){
          $dictionary['info_hash'] = urlencode(pack("H*", sha1(substr($this->data, $info_start, $this->pointer - $info_start))));
          unset($info_start);
        }
      }else // Error in reading a key
        return false;
    }
    $this->pointer++;
    return $dictionary;
  }

  function readNextList(){
    $list = array();
    while($this->data[$this->pointer] != 'e'){
      $next = $this->readNext();
      if($next === false)
        return false;
      $list[] = $next;
    }
    $this->pointer++;
    return $list;
  }

  function readNextString(){
    $colon = strpos($this->data, ":", $this->pointer);
    if($colon === false || ($colon - $this->pointer) > MAX_INTEGER_LENGTH)
      return false;
    $length = substr($this->data, $this->pointer, $colon - $this->pointer);
    $this->pointer = $colon + 1;
    $str = substr($this->data, $this->pointer, $length);
    $this->pointer += $length;
    return $str;
  }

  function readNextInteger(){
    $end = strpos($this->data, "e", $this->pointer);

    if($end === false || ($end - $this->pointer) > MAX_INTEGER_LENGTH)
      return false;
    $int = intval(substr($this->data, $this->pointer, $end - $this->pointer));
    $this->pointer = $end + 1;
    return $int;
  }
}

class Torrent{
  var
    $announce,
    $announceList,
    $createdBy,
    $creationDate,
    $encoding,
    $name,
    $length,
    $files,
    $pieceLength,
    $pieces,
    $comment,
    $private,
    $md5sum,
    $filename,
    $infoHash,
    $totalSize,
    $modifiedBy,
    $error = false;

  function Torrent($filename){
    // Keep this info for reference later
    $this->filename = $filename;

    // The entire contents of a torrent file should form into a dictionary object, which will be used to get all our info.
    $reader = new BEncodeReader($filename);
    $torrentInfo = $reader->readNext();

    // In the case of an invalid torrent file the result of the readNext call will be "false".
    if($torrentInfo === false){
      $this->error = true;
      trigger_error("The torrent file is invalid", E_USER_WARNING);
    }

    // Based on the information we've read in, we can now set up the contents of this class
    $this->announce = $torrentInfo['announce'];
    $this->announceList = $torrentInfo['announce-list'];
    $this->createdBy = $torrentInfo['created by'];
    $this->creationDate = $torrentInfo['creation date'];
    $this->comment = $torrentInfo['comment'];
    $this->modifiedBy = $torrentInfo['modified-by'];
    $this->pieceLength = $torrentInfo['info']['piece length'];
    $this->pieces = $torrentInfo['info']['pieces'];
    $this->private = ($torrentInfo['info']['private'] == 1);
    $this->name = $torrentInfo['info']['name'];
    $this->encoding = $torrentInfo['encoding'];
    $this->infoHash = $torrentInfo['info_hash'];

    // Files gets a bit tricky. If it isn't defined then this is a single file torrent, which has only the info
    // about one file. Otherwise we have a list of files and path info for each.
    if(!isset($torrentInfo['info']['files'])){
      $this->length = $torrentInfo['info']['length'];
      $this->md5sum = isset($torrentInfo['info']['md5sum']) ? $torrentInfo['info']['md5sum'] : null;
      $this->totalSize = $this->length;
    }else{
      $this->files = array();
      $this->totalSize = 0;
      foreach($torrentInfo['info']['files'] as $key => $fileInfo){
        $torrentFile = new TorrentFile();
        $torrentFile->md5sum = isset($fileInfo['md5sum']) ? $fileInfo['md5sum'] : null;
        $torrentFile->length = $fileInfo['length'];
        $torrentFile->name = implode("/",$fileInfo['path']);
        $this->files[$key] = $torrentFile;
        $this->totalSize += $torrentFile->length;
      }
    }
    unset($torrentInfo);
  }
}

class TorrentFile{
  var $md5sum, $name, $length; // Class representing a file within a torrent
}

function fetchURLContents($url){
  $ch = curl_init($url);
  if (!$ch)	die( "Cannot allocate a new PHP-CURL handle" );
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
  $data = curl_exec($ch);
  curl_close($ch);
  return $data;
}

function parseXML($data, $lastDownloadSeason = NULL, $lastDownloadEpisode = NULL){
  $ParseXML = new ParseXML(); //Init the ParseXML class
  $xmlarray = $ParseXML->GetXMLTree($data); //Parse the XML passed through to $data
  $result = false;

  $xmlURL = $xmlarray['RSS'][0]['CHANNEL'][0]['LINK'][0]['VALUE']; //Get the URL from the RSS file
  $parsedURL = parse_url($xmlURL);
  parse_str($parsedURL['query'],$parsedQuery);
  $showName = $parsedQuery['show_name'];
  $epguide = fetchURLContents("http://epguides.com/".str_replace (" ", "", $showName)."/");
  $epguide = explode("\n",$epguide);

  $itemsInFeed = count($xmlarray['RSS'][0]['CHANNEL'][0]['ITEM']);

  $entryNumber = 0;
  $endwhile = true;
  while($entryNumber < $itemsInFeed && $endwhile){
    $url = $xmlarray['RSS'][0]['CHANNEL'][0]['ITEM'][$entryNumber]['ENCLOSURE'][0]['ATTRIBUTES']['URL'];
    $pubDate = $xmlarray['RSS'][0]['CHANNEL'][0]['ITEM'][$entryNumber]['PUBDATE'][0]['VALUE'];
    $description = explode(';', $xmlarray['RSS'][0]['CHANNEL'][0]['ITEM'][$entryNumber]['DESCRIPTION'][0]['VALUE']);
    $showTitle = explode(': ', $description[1]);
    $showTitle = $showTitle[1];
    $season = explode(': ', $description[2]);
    $season = $season[1];
    if(!isset($lastDownloadSeason) || !is_numeric($lastDownloadSeason) || !is_numeric($lastDownloadEpisode)){
      $lastDownloadSeason = $season;
      $lastDownloadEpisode = "00";
    }
    $episode = explode(': ', $description[3]);
    $episode = $episode[1];
    if(strlen($episode) < 2){
      $episodeSpaced = " ".$episode;
    }else{
      $episodeSpaced = $episode;
    }
    if($showTitle === NULL || $showTitle = "n/a"){
      $showTitle = array_keys(preg_grep("/$season-$episodeSpaced/i",$epguide));
      $showTitle = $epguide[$showTitle[0]];
      $showTitle = explode(">",$showTitle);
      $showTitle = explode("<",$showTitle[1]);
      $showTitle = $showTitle[0];
    }
    $seasonPadded = $season;
    $episodePadded = $episode;
    if(strlen($season) < 2)
      $seasonPadded = "0".$season;
    if(strlen($episode) < 2)
      $episodePadded = "0".$episode;
    if($seasonPadded <= $lastDownloadSeason && $episodePadded <= $lastDownloadEpisode) $endwhile = false;
    if($season == $lastDownloadSeason && $endwhile == true){
      $result[$entryNumber]['url'] = $url;
      $result[$entryNumber]['showName'] = $showName;
      $result[$entryNumber]['season'] = $season;
      $result[$entryNumber]['episode'] = $episode;
      if(strlen($season) < 2)
        $result[$entryNumber]['season'] = "0".$season;
      if(strlen($episode) < 2)
        $result[$entryNumber]['episode'] = "0".$episode;
      $result[$entryNumber]['showTitle'] = $showTitle;
    }

    //echo $showName." - ".$season.$episode." - ".$showTitle."\n";
    $entryNumber++;
  }
  //print_r($xmlarray);
  return $result;
}

function checkForUpdate($episodesToDownload, $downloadLocation){
  if(!$episodesToDownload) return false;
  else {
    foreach($episodesToDownload as $episodeToDownload){
        touch($downloadLocation.$episodeToDownload['showName'].' - S'.$episodeToDownload['season'].'E'.$episodeToDownload['episode'].' - '.$episodeToDownload['showTitle'].".torrent");
        $ch = curl_init($episodeToDownload['url']);
        $fp = fopen($downloadLocation.$episodeToDownload['showName'].' - S'.$episodeToDownload['season'].'E'.$episodeToDownload['episode'].' - '.$episodeToDownload['showTitle'].".torrent", "w");
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    }
    return $episodesToDownload[0];
  }
}

$showsToGetFile = 'feeds.txt';
$lastDownloadFile = 'lastDownload.txt';
$downloadLocation = '/Users/ericboehs/Downloads/';

$lastDownloadFileContents = file($lastDownloadFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); //Get the Last Downloaded season/episode into an array
$showsToGetFileContents = file($showsToGetFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); //Get the show feeds into an array

foreach($lastDownloadFileContents as $lastDownloadThisFeed){ //Loop through the last files downloaded for each feed and seperate the season and episode into an array
  $lastDownloadThisFeedArray[] = explode(" ", $lastDownloadThisFeed);
}

foreach($showsToGetFileContents as $feed => $feedURL){ //Loop through each feed and check/update each feed
  $lastDownloadFileThisFeed = checkForUpdate(parseXML(fetchURLContents($feedURL), $lastDownloadThisFeedArray[$feed][0], $lastDownloadThisFeedArray[$feed][1]), $downloadLocation);
  if(!$lastDownloadFileThisFeed){
    $lastDownloadFileContentsNew .= $lastDownloadFileContents[$feed]."\n";
  }else{
    if(trim($lastDownloadFileContents[$feed]) == ""){
      $lastDownloadFileContentsNew .= $lastDownloadFileThisFeed['season']." ".$lastDownloadFileThisFeed['episode']."\n";
    }else{
      $lastDownloadFileContentsNew .= $lastDownloadFileThisFeed['season']." ".$lastDownloadFileThisFeed['episode']."\n";
    }
  }
}

file_put_contents($lastDownloadFile, $lastDownloadFileContentsNew); //Update the lastDownload.txt file with the latest season/episode that you just downloaded.

?>