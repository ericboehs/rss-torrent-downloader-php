<?php
define('MAX_INTEGER_LENGTH', 12);

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
?>