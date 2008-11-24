<?php
define('MAX_INTEGER_LENGTH', 12);

class BEncodeReader {
  var $data,
    $pointer = 0,
    $data_length = NULL;
  function BEncodeReader($filename = null) {
    if(is_null($filename))
      return;
    if(($data = @file_get_contents($filename)) === false) {
      trigger_error("Could not create BEncodeReader for {$filename}: failed to read file", E_USER_WARNING);
      return;
    }
    $this->setData($data);
  }
  function setData($data) {
    $this->data_length = strlen($data);
    $this->data = $data;
  }
  function readNext() {
    // This is a hack for legacy use, since I just added the setData method and some parts of
    // the code haven't switched over yet. It's reliable, its just not very nice :p
    if(is_null($this->data_length))
      $this->data_length = strlen($this->data);
    while($this->pointer < $this->data_length) {
      switch($this->data[$this->pointer++]) {
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
  function readNextDictionary() {

    $dictionary = array();
    while($this->data[$this->pointer] != 'e') {
      $key = $this->readNextString();
      if($key !== false) {
        // Special hack just for torrent files (how nice)
        if($key == "info")
          $info_start = $this->pointer;
        $dictionary[$key] = $this->readNext();
        // We need a sha1 hash of the info bit of the file for use with trackers, so grab that now
        if($key == "info") {
          $dictionary['info_hash'] = urlencode(pack("H*", sha1(substr($this->data, $info_start, $this->pointer - $info_start))));
          unset($info_start);
        }
      } else // Error in reading a key
        return false;
    }
    $this->pointer++;
    return $dictionary;
  }
  function readNextList() {
    $list = array();
    while($this->data[$this->pointer] != 'e') {
      $next = $this->readNext();
      if($next === false)
        return false;
      $list[] = $next;
    }
    $this->pointer++;
    return $list;
  }

  function readNextString() {

    $colon = strpos($this->data, ":", $this->pointer);

    if($colon === false || ($colon - $this->pointer) > MAX_INTEGER_LENGTH)
      return false;

    $length = substr($this->data, $this->pointer, $colon - $this->pointer);
    $this->pointer = $colon + 1;
    $str = substr($this->data, $this->pointer, $length);
    $this->pointer += $length;

    return $str;

  }

  function readNextInteger() {
    $end = strpos($this->data, "e", $this->pointer);

    if($end === false || ($end - $this->pointer) > MAX_INTEGER_LENGTH)
      return false;
    $int = intval(substr($this->data, $this->pointer, $end - $this->pointer));
    $this->pointer = $end + 1;
    return $int;
  }
}
?>
