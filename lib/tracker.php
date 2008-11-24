<?PHP

/*
  tracker.php
  Classes and functions designed to facilitate communication with BitTorrent trackers using the
  BitTorrent protocol, in order to query them for information.
  This is a component of a BitTorrent tracker/torrent library.
  This library is free to redistribute, but I'd appreciate if you left the credit in if you use it.
  Written by Greg Poole | m4dm4n@gmail.com | http://m4dm4n.homelinux.net:8086
*/

require_once "bt.config.php";
require_once "bencode.reader.php";

define('CACHE_TYPE_ANNOUNCE',   "announce");
define('CACHE_TYPE_SCRAPE',   "scrape");

// Define some storage classes

class AnnounceResult {

  var
    $warning,
    $interval,
    $minInterval,
    $trackerId,
    $seeds,
    $leeches,
    $peers;
}

class ScrapeResult {
  var
    $seeds,
    $downloads,
    $leeches;
}

// Summarise the results of the tracker_scrape_all function, by adding up all of the scrape results
// into a single array of seeds, leeches and successful downloads.
function tracker_scrape_summarise($scrape_results) {

  if(!is_array($scrape_results)) {
    trigger_error("tracker_scrape_summarise error: Expected array as first parameter", E_USER_WARNING);
    return false;
  }
  $summary = new ScrapeResult();
  foreach($scrape_results as $result) {
    if(is_a($result, "ScrapeResult")) {
      $summary->seeds += $result->seeds;
      $summary->leeches += $result->leeches;
      $summary->downloads += $result->downloads;
    }
  }

  return $summary;

}

// Retrieve a full list of trackers available in the case where "announce-list" is specified.
// If "announce-list" is specified, then the default "announce" property will be ignored in favour
// of this. Otherwise, only the results of scraping the tracker specified by the "announce" property
// will be returned.
function tracker_scrape_all($torrent, $timeout = 5, $force_refresh = false) {

  if(!is_a($torrent, "Torrent")) {
    trigger_error("tracker_scrape_all requires \$torrent to be a Torrent instance", E_USER_WARNING);
    return false;
  }

  if($torrent->error) {
    trigger_error("Can't scrape: Torrent is marked as invalid", E_USER_WARNING);
    return false;
  }

  if(!count($torrent->announceList)) {
    return array(tracker_scrape($torrent));
  }

  $scrape_results = array();

  foreach($torrent->announceList as $tier)
    foreach($tier as $tracker)
      $scrape_results[$tracker] = tracker_scrape($torrent, $tracker, $timeout, $force_refresh);

  return $scrape_results;
}

// Retrieve information on the torrent object supplied by querying the tracker's
// announce address. If no tracker announce address is specified, then the default
// announce address will be used from the tracker object.
function tracker_scrape($torrent, $tracker = null, $timeout = 5, $force_refresh = false) {

  if(!is_a($torrent, "Torrent")) {
    trigger_error("tracker_scrape requires \$torrent to be a Torrent instance", E_USER_WARNING);
    return false;
  }

  if($torrent->error) {
    trigger_error("Can't scrape: Torrent is marked as invalid", E_USER_WARNING);
    return false;
  }

  if(is_null($tracker))
    $tracker = $torrent->announce;

  // Try the cache first and see if we've scraped this tracker in regards to this torrent in the
  // past BT_CACHE_DURATION seconds.
  if(!$force_refresh && tracker_cache_exists($torrent, $tracker, CACHE_TYPE_SCRAPE))
    return tracker_cache_get($torrent, $tracker, CACHE_TYPE_SCRAPE);

  $scrape_address = tracker_get_scrape_address($tracker);

  if($scrape_address === false) {
    trigger_error("Failed to scrape tracker {$tracker}", E_USER_WARNING);
    return false;
  }

  if(strpos($scrape_address, "?") !== false)
    $scrape_address .= "&info_hash={$torrent->infoHash}";
  else
    $scrape_address .= "?info_hash={$torrent->infoHash}";

  // Set the timeout before proceeding and reset it when done
  $old_timeout = ini_get('default_socket_timeout');
  ini_set('default_socket_timeout', $timeout);
  $data = @file_get_contents($scrape_address);
  ini_set('default_socket_timeout', $old_timeout);

  // Something is wrong with the address or the HTTP response of the tracker, or the request timed out. It's hard to
  // say but something has clearly gone critically wrong.
  // As an important part of the caching efficiency, I feel that it is good to cache the results regardless, to avoid
  // the need to requery the server.
  if($data === false)
    return tracker_cache_store($torrent, $tracker, false, CACHE_TYPE_SCRAPE);

  $reader = new BEncodeReader();
  $reader->setData($data);
  $trackerInfo = $reader->readNext();

  // A bad tracker response might be bad software, something the library doesn't understand or any number
  // of other weird issues. Regardless, we couldn't read it so we can't proceed.
  if($trackerInfo === false) {
    trigger_error("tracker_scrape error: Tracker returned invalid response to scrape request", E_USER_WARNING);
    return false;
  }

  // The tracker doesn't want to give us information on the torrent we requested. They've given us a response as to why.
  if(isset($trackerInfo['failure reason'])) {
    trigger_error("tracker_scrape error: Scrape failed. Tracker gave the following reason: \"{$trackerInfo['failure reason']}\"", E_USER_WARNING);
    return false;
  }

  // The bencoded dictionary result uses the infohash in raw form, so we need
  // to convert it back from the more commonly used urlencoded form
  $ih_raw = urldecode($torrent->infoHash);

  $result = new ScrapeResult();
  $result->seeds = $trackerInfo['files'][$ih_raw]['complete'];
  $result->downloads = $trackerInfo['files'][$ih_raw]['downloaded'];
  $result->leeches = $trackerInfo['files'][$ih_raw]['incomplete'];

  return tracker_cache_store($torrent, $tracker, $result, CACHE_TYPE_SCRAPE);
}

// Get the address which can be used to scrape a tracker for information on a torrent, based
// on the announce address provided.
function tracker_get_scrape_address($announce) {

  $last_slash = strrpos($announce, "/");

  if($last_slash === false) {
    trigger_error("Tracker address ({$announce}) is invalid", E_USER_WARNING);
    return false;
  }

  $last_part = substr($announce, $last_slash);
  if(strpos($last_part, "announce") === false) {
    trigger_error("Tracker ({$announce}) does not appear to support scrape", E_USER_WARNING);
    return false;
  }

  return substr($announce, 0, $last_slash) . "/" . str_replace($last_part, "announce", "scrape");
}

// Retrieve the full announce (including a peerlist up to $max_peers) from the specified tracker, impersonating Azureus 3.1.1.0.
function tracker_get_announce($torrent, $tracker, $timeout = 5, $max_peers = 200, $force_refresh = false) {

  if(!is_a($torrent, "Torrent")) {
    trigger_error("tracker_get_announce requires \$torrent to be a Torrent instance", E_USER_WARNING);
    return false;
  }

  if($torrent->error) {
    trigger_error("Can't get announce: Torrent is marked as invalid", E_USER_WARNING);
    return false;
  }

  if(is_null($tracker))
    $tracker = $torrent->announce;

  // Try the cache first and see if we've scraped this tracker in regards to this torrent in the
  // past BT_CACHE_DURATION seconds.
  if(!$force_refresh && tracker_cache_exists($torrent, $tracker, CACHE_TYPE_ANNOUNCE))
    return tracker_cache_get($torrent, $tracker, CACHE_TYPE_ANNOUNCE);

  // Settings for impersonation
  $peer_id_prefix = "AZ";
  $peer_id_version = "3110";
  $peer_ua = "Azureus/{$peer_id_version}";

  // Generate a nice peer_id
  $peer_id = uniqid("-{$peer_id_prefix}{$peer_id_version}-");
  if(strlen($peer_id) > 20)
    $peer_id = substr($peer_id, 0, 20);
  else if(strlen($peer_id) < 20)
    $peer_id = str_pad($peer_id, 20, '0', STR_PAD_RIGHT);

  $query_url = array();

  // Generate our request URL
  $query_url[] = "info_hash=" . $torrent->infoHash;
  $query_url[] = "peer_id=" . urlencode($peer_id);
  $query_url[] = "numwant={$max_peers}";
  $query_url[] = "no_peer_id=1";
  $query_url[] = "compact=1";
  $query_url[] = "port=" . rand(6881, 6889);

  $query_url = "{$tracker}?" . implode("&", $query_url);
  $url_parts = parse_url($query_url);

  if(!$handle = fsockopen($url_parts['host'], ($url_parts['port']) ? $url_parts['port'] : 80, $error_number, $error, $timeout)) {
    trigger_error("Failed to connect to {$tracker} to query: {$error}", E_USER_WARNING);
    return false;
  }

  // Fire off an HTTP GET query to the tracker, impersonating our specified client
  fwrite($handle, "GET {$url_parts['path']}?{$url_parts['query']} HTTP/1.0\r\n");
  fwrite($handle, "User-Agent: {$peer_ua}\r\n");
  fwrite($handle, "Host: {$url_parts['host']}\r\n\r\n");

  // Read the first line of the HTTP response, which should be something like "HTTP/1.0 200 OK"
  // if everything's alright. Otherwise, we know something's up.
  if(!feof($handle) && ($line = trim(fgets($handle)))) {
    $response_message = explode(" ", $line);
    if($response_message[1] != 200) {
      fclose($handle);
      trigger_error("Tracker ({$tracker}) returned non-200 response code ({$response_message[1]})", E_USER_WARNING);
      return false;
    }
  } else {
    // No response. This probably won't ever happen, but who knows?
    fclose($handle);
    trigger_error("Tracker ({$tracker}) failed to respond to announce query", E_USER_WARNING);
    return false;
  }

  // Read in the HTTP response headers
  $response_headers = array();
  while(!feof($handle) && ($line = trim(fgets($handle))) != "") {
    $response_headers[] = fgets($handle);
  }

  // We don't actually use the response headers since it's not standard to return any, but this is just
  // a catch-all.
  unset($response_headers);

  // Read the body of the response
  $response = "";
  while(!feof($handle))
    $response .= fgets($handle);

  fclose($handle);

  // Parse the response with a BEncodeReader object
  $reader = new BEncodeReader();
  $reader->data = $response;
  $response_dict = $reader->readNext();

  if($response_dict === false) {
    // Cleanup
    unset($response);
    unset($reader);

    trigger_error("Failed to read bencoded tracker response ({$tracker})", E_USER_WARNING);
    return false;
  }

  // The presence of a "failure reason" key indicates that something went wrong.
  if($response_dict['failure reason']) {
    trigger_error("tracker_get_announce error: Announce failed. Tracker gave the following reason: \"{$response_dict['failure reason']}\"", E_USER_WARNING);
    return false;
  }

  // The peer list should have been returned in "compact" mode so we need to unpack it
  $peer_count = ceil(strlen($response_dict['peers']) / 6);
  $peer_list = array();
  for($i = 0; $i < $peer_count; $i++) {
    $parts = unpack("C4ip/nport", substr($response_dict['peers'], $i * 6, 6));
    $peer_list[] = sprintf("%s.%s.%s.%s:%s",
                $parts['ip1'], $parts['ip2'], $parts['ip3'], $parts['ip4'],
                $parts['port']);
  }
  $response_dict['peers'] = $peer_list;

  $result = new AnnounceResult();
  $result->warning = $response_dict['warning message'];
  $result->interval = $response_dict['interval'];
  $result->minInterval = $response_dict['interval'];
  $result->trackerId = $response_dict['tracker id'];
  $result->seeds = $response_dict['complete'];
  $result->leeches = $response_dict['incomplete'];
  $result->peers = $response_dict['peers'];

  // Cleanup
  unset($response);
  unset($response_dict);
  unset($reader);

  return tracker_cache_store($torrent, $tracker, $result, CACHE_TYPE_ANNOUNCE);

}

// Can be used to determine if a cached version of a particular tracker query exists.
// This method is only for internal use.
function tracker_cache_exists($torrent, $tracker, $type) {

  if(!BT_IMPLICIT_CACHE)
    return false;

  $file = tracker_cache_filename($torrent, $tracker, $type);

  // initial existance check
  $exists = is_file($file);

  // In the interests of not passively abusing the tracker system the library will also take
  // into account information provided on the desired announce interval
  if($exists && $type === CACHE_TYPE_ANNOUNCE) {
    $result = @unserialize(file_get_contents($file));
    if($result && is_a($result, "AnnounceResult")) {
      $interval = is_numeric($result->minInterval) ? $result->minInterval : $result->interval;
      $exists = (time() - filemtime($file) < $interval);
    } else
      $exists = $exists && (time() - filemtime($file) < BT_CACHE_DURATION);
  } else // Check against our own default cache duration
    $exists = $exists && (time() - filemtime($file) < BT_CACHE_DURATION);

  return $exists;
}

// Can be used to retrieve a cached version of a particular tracker query.
// This method is only for internal use.
function tracker_cache_get($torrent, $tracker, $type) {

  if(!BT_IMPLICIT_CACHE)
    return NULL;

  $file = tracker_cache_filename($torrent, $tracker, $type);

  // Retrieve the file or clear the cache depending on if the cache exists and is valid
  if(tracker_cache_exists($torrent, $tracker, $type)) {
    return unserialize(file_get_contents($file));
  } else if(is_file($file))
    unlink($file);

  return NULL;
}

// Used to store the results of a particular tracker query for later retrieval, then return the input
// value for nice neat code.
// This method is only for internal use.
function tracker_cache_store($torrent, $tracker, $return_value, $type) {

  if(!BT_IMPLICIT_CACHE)
    return $return_value;

  $file = tracker_cache_filename($torrent, $tracker, $type);

  // Ensure the directories are avilable and create ones where necessary.
  // Note that the first entry might be blank but it should still work,
  // given the concatentation of $full_path (read the code closely).
  $full_path = "";
  $path = explode("/", str_replace("\\", "/", $file));
  for($i = 0; $i < count($path) - 1; $i++) {
    $full_path .= "{$path[$i]}/";
    if(!@is_dir($full_path))
      @mkdir($full_path);
  }

  if(!@file_put_contents($file, serialize($return_value)))
    trigger_error("Failed to write to cache, check folder settings", E_USER_WARNING);

  return $return_value;

}

function tracker_cache_filename($torrent, $tracker, $type) {

  $tracker_parts = parse_url($tracker);
  $tracker = "{$tracker_parts['scheme']}://{$tracker_parts['host']}:" . (($tracker_parts['port']) ? $tracker_parts['port'] : 80);
  return BT_CACHE_PATH . "/" . urlencode($tracker) . "/{$type}/" . $torrent->infoHash . ".cache";

}
?>