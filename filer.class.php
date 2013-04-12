<?php

class Filer {

  /**
   * @const String  File extension for temporary files.
   */
  CONST TEMP_EXT = '.tmp';

  /**
   * @var   String  Filer identifier.
   */
  private $name;

  /**
   * Fetches all Filer names.
   *
   * @param   $finished     Bool    Include name even if all tasks within that filer are completed.
   * @param   $nonQueued    Bool    Include names of non-queued tasks.
   * @return                Array
   */
  public static function getNames($finished = FALSE, $nonQueued = FALSE) {
    $q = db_select('filer', 'f')->distinct()->fields('f', array('name'));
    if (!$finished)
      $q->isNull('f.finished');
    if (!$nonQueued)
      $q->condition('f.queued', 1);
    return array_keys($q->execute()->fetchAllAssoc('name', PDO::FETCH_ASSOC));
  }

  /**
   * Synchronize all Filer rows.
   *
   * @see Filer::sync().
   */
  public static function synchronize() {
    foreach (self::getNames(TRUE) as $name) {
      $filer = new Filer($name);
      $filer->sync();
    }
  }

  /**
   * @param $name   String          Filer identifier.
   * @throws        ErrorException  If name is a non-empty string.
   */
  public function __construct($name) {
    if (!is_string($name) || empty($name)) {
      throw new ErrorException('Filer::__construct($name) requires a non-empty string as first param.');
    }
    $this->name = $name;
    $this->sync();
  }

  /**
   * @param   $path     String    Path of the file we want to write to, can be a wrapper.
   * @param   $options  Array     Optional: array indexed as follows:
   *                              - items   Array   Each item is passed to hook_filer_FILER_NAME_cron($item, $content, $fh, $status).
   *                              - append  Bool    Whether to append or overwrite the contents of $path on each callback.
   *                              -                 e.g. CSV: append => FALSE, JSON: append => TRUE.
   *                              - read    Bool    Whether to pass the contents of the file to hook_filer_FILER_NAME_cron($item, $content, $fh, $status).
   * @param   $enqueue  Bool      If TRUE DrupalQueue will take care of calling hook_filer_FILER_NAME_cron().
   *                              - Otherwise manual calling of Filer::run() is required to fill our file.
   *                              - @Note: if FALSE $options will be ignored.
   * @return            Bool|Int  frid on success, FALSE on failure.
   */
  public function add($path, $options, $enqueue = TRUE) {
    if (($enqueue && (empty($options['items']) || !is_array($options['items']))) || empty($path)) {
      watchdog('filer', 'Invalid arguments passed to Filer::add().');
      return FALSE;
    }

    $options += array('items' => array(), 'append' => TRUE, 'read' => TRUE);

    if (!$frid = $this->addRow($path, $enqueue))
      return FALSE;

    if ($enqueue) {
      $q = DrupalQueue::get(FILER_CRON . $this->name);
      $item_count = count($options['items']);
      $i = 0;
      foreach ($options['items'] as $item) {
        $i++;
        $q->createItem(array(
          'name' => $this->name,
          'frid' => $frid,
          'status' => $i == $item_count ? 'last' : ($i == 1 ? 'first' : ''),
          'read' => $options['read'],
          'append' => $options['append'],
          'item' => $item
        ));
      }
    }
    return $frid;
  }

  /**
   * Delete a file from both db and the filesystem.
   *
   * @param   $frid   Int   The frid.
   * @return          Bool  TRUE if the file was deleted from the filesystem or did not exist, FALSE otherwise.
   */
  public function delete($frid) {
    $row = $this->files($frid);
    if (isset($row['file'])) {
      $ext = empty($row['finished']) ? self::TEMP_EXT : '';
      if (!file_exists($row['file'] . $ext) || unlink($row['file'] . $ext)) {
        $this->deleteRow($frid);
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Get a list of all files, or the file specified by $frid
   *
   * @param   $frid   Int     The frid.
   * @return          mixed   If frid was specified: single row array, otherwise an array of row arrays.
   */
  public function files($frid = NULL) {
    $qry = db_select('filer', 'f');
    $qry->fields('f', array('frid', 'name', 'file', 'finished', 'queued'));
    $qry->condition('f.name', $this->name);
    if (is_numeric($frid)) {
      $qry->condition('f.frid', $frid);
    }
    $qry = $qry->execute();
    if (!is_null($frid)) {
      $results = $qry->fetchAssoc();
    }
    else {
      $results = $qry->fetchAllAssoc('frid', PDO::FETCH_ASSOC);
    }

    return $results;
  }

  /**
   * Synchronize the rows of the current Filer (delete db records if file does not exist in the filesystem or we have an orphaned temporary file).
   */
  public function sync() {
    $files = $this->files();
    $queued_items = $this->numberOfItems();
    if (empty($files) && !empty($queued_items)) {
      $this->deleteQueue();
    }
    foreach ($files as $file) {
      if (empty($queued_items) && empty($file['finished']) && !empty($file['queued'])) {
        $this->delete($file['frid']);
      }
      elseif (!empty($file['finished']) && !file_exists($file['file'])) {
        $this->delete($file['frid']);
      }
    }
  }

  /**
   * @return  Int   An alias for DrupalQueueInterface::numberOfItems();
   */
  public function numberOfItems() {
    return DrupalQueue::get(FILER_CRON . $this->name)->numberOfItems();
  }

  /**
   * @return  void  An alias for DrupalQueueInterface::deleteQueue();
   */
  public function deleteQueue() {
    DrupalQueue::get(FILER_CRON . $this->name)->deleteQueue();
  }

  /**
   * Manual runner. If a task was added (Filer::add()) with param $enqueue = FALSE, use this method to invoke hook_filer_FILER_NAME_cron().
   * Calling this for a queued task will do nothing.
   *
   * @param   $frid   Int     The frid. We like frid.
   * @param   $item   mixed   Single item to pass to hook_filer_FILER_NAME_cron($item, ...).
   * @param   $append Bool    Whether to append or overwrite the file. @see Filer::add().
   * @param   $read   Bool    Whether to pass the contents of the file to hook_filer_FILER_NAME_cron($item, $content, ...). @see Filer::add().
   * @return          Bool    FALSE on failure.
   */
  public function run($frid, $item, $append = TRUE, $read = FALSE) {
    return $this->write($frid, $item, $append, $read, TRUE, TRUE);
  }

  /**
   * Cron runner (internal).
   *
   * @private
   */
  public function _run($frid, $item, $append = TRUE, $read = FALSE, $status) {
    $this->write($frid, $item, $append, $read, $status === 'last', FALSE);
  }

  /**
   * TODO: write me.
   *
   * @param $frid
   * @param $item
   * @param $append
   * @param $read
   * @param $finish
   * @param $manual
   * @return bool
   */
  private function write($frid, $item, $append, $read, $finish, $manual) {
    $hook = 'filer_' . $this->name . '_cron';
    $modules = module_implements($hook);
    if (empty($modules)) {
      watchdog('filer', 'No modules implement hook_%hook', array('%hook' => $hook));
      return FALSE;
    }
    $filer_row = $this->files($frid);
    dsm($filer_row['queued']);
    dsm($manual);
    dsm($filer_row);
    if (empty($filer_row) || (!empty($filer_row['queued']) && $manual))
      return FALSE;
    $fn = $filer_row['file'] . ($manual ? '' : self::TEMP_EXT);
    $dir = dirname($fn);
    if (!file_prepare_directory($dir, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS)) {
      watchdog('filer', 'Could not prepare directory (%path)', array('%path' => $dir));
      return FALSE;
    }
    $content = '';
    if ($read && file_exists($fn)) {
      $content = file_get_contents($fn);
    }
    if ($fh = fopen($fn, $append ? 'a' : 'w')) {
      foreach ($modules as $module) {
        $return = module_invoke($module, $hook, $item, $content, $fh, $finish);
        if (is_string($return) && fwrite($fh, $return) === FALSE) {
          watchdog('filer', 'could not write to %file', array('%file' => $fn));
        }
      }
      fclose($fh);
    }
    else {
      watchdog('filer', 'could not open file: %file', array('%file' => $fn));
      if ($finish)
        $this->sync();
    }
    if ($finish)
      $this->finish($frid, !$manual);
    return TRUE;
  }

  /**
   * Finishes the file: rename temporary file to permanent file if necessary
   * and merge identical rows into 1 (given they're all finished and their path is the same).
   *
   * @param   $frid   Int   The frid.
   * @param   $temp   Bool  If TRUE rename file.tmp to file.
   */
  private function finish($frid, $temp = TRUE) {
    $row = $this->files($frid);
    if ($temp && !rename($row['file'] . self::TEMP_EXT, $row['file'])) {
      watchdog('filer', 'could not rename %file to %nfile', array('%file' => $row['file'] . self::TEMP_EXT, '%nfile' => $row['file']));
      return;
    }
    if (!empty($row)) {
      db_delete('filer')->isNotNull('finished')->condition('file', $row['file'])->execute();
    }
    db_update('filer')->fields(array('finished' => time()))->condition('frid', $frid)->condition('name', $this->name)->execute();
  }

  /**
   * Adds a row to the filer table.
   *
   * @param   $path     String      The file path/wrapper.
   * @param   $queued   Bool        Mark this task as queued.
   * @return            Bool|Int    frid on success, FALSE on failure.
   */
  private function addRow($path, $queued) {
    $insert = array('name' => $this->name, 'file' => $path, 'queued' => (int)$queued);
    $frid = db_insert('filer')->fields($insert)->execute();
    if (is_null($frid)) {
      watchdog('filer', 'Could not insert row into filer table');
      return FALSE;
    }
    return $frid;
  }

  /**
   * Deletes a row from the filer table.
   *
   * @param   $frid   Int   The frid.
   */
  private function deleteRow($frid) {
    db_delete('filer')->condition('frid', $frid)->condition('name', $this->name)->execute();
  }

}
