<?php

class Filer {

  CONST TEMP_EXT = '.tmp';
  private $id;

  /**
   * @param $id String  Arbitrary string used to differentiate between Filer data.
   * @throws ErrorException when $id is not a non-empty string
   */
  public function __construct($id) {
    if (!is_string($id) || empty($id)) {
      throw new ErrorException('Filer::__construct($id) requires a non-empty string as first param.');
    }
    $this->id = $id;
  }

  /**
   * Add new filer task.
   *
   * @param   $filepath   String    Absolute filepath.
   * @param   $items      Array     Array of items, each item is passed to the given callback function.
   * @param   $callback   String    Reference to a function, called for each item in $items.
   * @param   $filemode   String    @see http://php.net/manual/en/function.fopen.php: mode.
   * @param   $read       bool      Whether to read the file and pass the contents to $callback.
   * @return              bool      FALSE on failure.
   */
  public function add($filepath, $items, $callback, $filemode = 'a', $read = FALSE) {
    if (($filemode = $this->validateFileMode($filemode, $read)) === FALSE) {
      watchdog('filer', 'Invalid filemode given.');
      return FALSE;
    }

    $insert = array('id' => $this->id, 'callback' => $callback, 'file' => $filepath);
    watchdog('', '', $this->id);
    $frid = db_insert('filer')->fields($insert)->execute();
    if (is_null($frid)) {
      watchdog('filer', 'Could not insert row into filer table');
      return FALSE;
    }
    $q = DrupalQueue::get(FILER_CRON);
    $item_count = count($items);
    $i = 0;
    foreach ($items as $item) {
      $i++;
      $status = '';
      if ($i == $item_count) {
        $status = 'last';
      }
      elseif ($i == 1) {
        $status = 'first';
      }
      $q->createItem(array(
        'frid' => $frid,
        'status' => $status,
        'read' => $read,
        'fmode' => $filemode,
        'data' => $item
      ));
    }

    return TRUE;
  }

  /**
   * Unlink file from filesystem and delete from db if successful.
   *
   * @param   $frid   Int   FilerFileId.
   * @return          bool  FALSE on failure.
   */
  public function deleteFile($frid) {
    $row = $this->getFiles($frid);
    if (isset($row['file']) && unlink($row['file'])) {
      $this->deleteRow($frid);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Get all FilerFile rows. Filtering out those not matching given $frid or/and $path.
   *
   * @param   $frid   Int     FilerFileId.
   * @param   $path   String  Absolute path to the file.
   * @return          mixed
   */
  public function getFiles($frid = NULL, $path = NULL) {
    $qry = db_select('filer', 'f');
    $qry->fields('f', array('frid', 'id', 'callback', 'file', 'finished'));
    $qry->condition('f.id', $this->id);
    if (is_numeric($frid)) {
      $qry->condition('f.frid', $frid);
    }
    if (is_string($path)) {
      $qry->condition('f.file', $path);
    }
    $result = $qry->execute();
    if (!is_null($frid)) {
      return $result->fetchAssoc();
    }
    else {
      return $result->fetchAllAssoc('frid', PDO::FETCH_ASSOC);
    }
  }

  /**
   * Internal filer function, do not use directly as the item won't be removed from the queue.
   *
   * @param   $frid     Int
   * @param   $data     Mixed
   * @param   $filemode String
   * @param   $read     bool
   * @param   $status   String
   */
  public function process($frid, $data, $filemode, $read, $status) {
    if (($filemode = $this->validateFileMode($filemode, $read)) === FALSE) {
      watchdog('filer', 'Invalid filemode given.');
      return;
    }
    $filer_row = $this->getFiles($frid);
    if (!isset($filer_row['callback'])) {
      $this->deleteRow($frid);
      watchdog('filer', 'Invalid data in filer table', $filer_row);
      return;
    }
    $callback = $filer_row['callback'];
    $tmp_fn = $filer_row['file'] . Self::TEMP_EXT;
    if (!function_exists($callback)) {
      watchdog('filer', 'callback function %func was not found', array('%func' => $callback));
      return;
    }
    if (!$fh = fopen($tmp_fn, $filemode)) {
      watchdog('filer', 'could not open file: %file', array('%file' => $tmp_fn));
      return;
    }
    $content = '';
    if (!empty($read)) {
      clearstatcache(TRUE);
      if ($filesize = filesize($tmp_fn)) {
        $content = fread($fh, $filesize);
      }
    }
    $return = call_user_func($callback, $data, $content, $fh, $status);
    if (is_string($return) && fwrite($fh, $return) === FALSE) {
      watchdog('filer', 'could not write to %file', array('%file' => $tmp_fn));
    }
    fclose($fh);
    if ($status === 'last') {
      $this->finish($frid);
    }
  }

  /**
   * Sets filetask to finished.
   *
   * @param   $frid   Int   FilerFileId.
   * @private
   */
  private function finish($frid) {
    $row = $this->getFiles($frid);
    if (!rename($row['file'] . Self::TEMP_EXT, $row['file'])) {
      watchdog('filer', 'could not rename %file to %nfile', array('%file' => $row['file'] . TEMP_EXT, '%nfile' => $row['file']));
      return;
    }
    if (!empty($row)) {
      db_delete('filer')->isNotNull('finished')->condition('file', $row['file'])->execute();
    }
    db_update('filer')->fields(array('finished' => time()))->condition('frid', $frid)->condition('id', $this->id)->execute();
  }

  /**
   * Deletes one or more rows from the filer table.
   *
   * @param   $frid   Int   FilerFileId.
   * @private
   */
  private function deleteRow($frid) {
    db_delete('filer')->condition('frid', $frid)->condition('id', $this->id)->execute();
  }

  private function validateFileMode($filemode, $read) {
    $fmstrlen = strlen($filemode);
    if (!in_array(substr($filemode, 0, 1), array('r', 'w', 'a', 'x', 'c')) || !in_array(substr($filemode, 1, 1), array('+', '')) || $fmstrlen > 2 || $fmstrlen == 0) {
      return FALSE;
    }
    if ($read && $fmstrlen == 1 && in_array($filemode, array('a', 'w', 'x', 'c'))) {
      $filemode .= '+';
    }
    return $filemode;
  }
}
