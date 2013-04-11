<?php

class Filer {

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
   * @return              bool      FALSE on failure
   */
  public function add($filepath, $items, $callback, $filemode = 'a', $read = FALSE) {
    $fmstrlen = strlen($filemode);
    if (!in_array(substr($filemode, 0, 1), array('r', 'w', 'a', 'x', 'c')) || !in_array(substr($filemode, 1, 1), array('+', '')) || $fmstrlen > 2 || $fmstrlen == 0) {
      watchdog('filer', 'Invalid filemode given');
      return FALSE;
    }
    if ($read && $fmstrlen == 1 && in_array($filemode, array('a', 'w', 'x', 'c'))) {
      $filemode .= '+';
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
        'id' => $this->id,
        'status' => $status,
        'read' => $read,
        'fmode' => $filemode,
        'filepath' => $filepath,
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
      return $result->fetchAllAssoc('frid');
    }
  }

  /**
   * Internal filer function!
   * Sets filetask to finished.
   *
   * @param   $frid   Int   FilerFileId.
   * @private
   */
  public function finish($frid) {
    $row = $this->getFiles($frid);
    if (!empty($row)) {
      db_delete('filer')->isNotNull('finished')->condition('file', $row['file'])->execute();
    }
    db_update('filer')->fields(array('finished' => time()))->condition('frid', $frid)->condition('id', $this->id)->execute();
  }

  /**
   * Internal filer function!
   * Deletes one or more rows from the filer table.
   *
   * @param   $frid   Int   FilerFileId.
   * @private
   */
  public function deleteRow($frid) {
    db_delete('filer')->condition('frid', $frid)->condition('id', $this->id)->execute();
  }
}
