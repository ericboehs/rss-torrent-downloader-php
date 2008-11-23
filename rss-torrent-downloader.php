<?php

/*
TODO:
-Do checks to make sure download completed
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
    if(!isset($latestSeason)) $latestSeason = $season;
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
    if(strlen($season) < 2)
      $seasonPadded = "0".$season;
    if(strlen($episode) < 2)
      $episodePadded = "0".$episode;
    if($seasonPadded <= $lastDownloadSeason && $episodePadded <= $lastDownloadEpisode) $endwhile = false;
    if($season == $latestSeason && $endwhile == true){
      $result[$entryNumber]['url'] = $url;
      $result[$entryNumber]['showName'] = $showName;
      if(strlen($season) < 2)
        $result[$entryNumber]['season'] = "0".$season;
      if(strlen($episode) < 2)
        $result[$entryNumber]['episode'] = "0".$episode;
      $result[$entryNumber]['showTitle'] = $showTitle;
    }
    $entryNumber++;
  }
  return $result;
}

function checkForUpdate($rssURL, $lastDownloadSeason, $lastDownloadEpisode, $downloadLocation){
  $episodesToDownload = parseXML(fetchURLContents($rssURL), $lastDownloadSeason, $lastDownloadEpisode);
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

$lastDownloadFileContents = file($lastDownloadFile); //Get the Last Downloaded season/episode into an array
$showsToGetFileContents = file($showsToGetFile); //Get the show feeds into an array

foreach($lastDownloadFileContents as $lastDownloadThisFeed){ //Loop through the last files downloaded for each feed and seperate the season and episode into an array
  $lastDownloadThisFeedArray[] = explode(" ", $lastDownloadThisFeed);
}

foreach($showsToGetFileContents as $feed => $feedURL){ //Loop through each feed and check/update each feed
  $lastDownloadFileThisFeed = checkForUpdate($feedURL, $lastDownloadThisFeedArray[$feed][0], $lastDownloadThisFeedArray[$feed][1], $downloadLocation);
  if(!$lastDownloadFileThisFeed) $lastDownloadFileContentsNew .= $lastDownloadFileContents[$feed];
  else $lastDownloadFileContentsNew .= $lastDownloadFileThisFeed['season']." ".$lastDownloadFileThisFeed['episode']."\n";
}

file_put_contents($lastDownloadFile, $lastDownloadFileContentsNew); //Update the lastDownload.txt file with the latest season/episode that you just downloaded.

?>
