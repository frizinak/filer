<?php

class Filer {

  /**
   * @const string  File extension for temporary files.
   */
  CONST TEMP_EXT = '.tmp';

  /**
   * @var   string  Filer identifier.
   */
  private $name;

  /**
   * Fetches all Filer names.
   *
   * @param bool        $finished   Include name even if all tasks within that filer are completed.
   * @param bool        $nonQueued  Include names of non-queued tasks.
   * @param string|bool $uri        Only return names of tasks that have a specific $uri, FALSE to ignore.
   * @return Array
   */
  public static function getNames($finished = FALSE, $nonQueued = FALSE, $uri = FALSE) {
    $q = db_select('filer', 'f')->distinct()->fields('f', array('name'));
    if (!$finished)
      $q->isNull('f.finished');
    if (!$nonQueued)
      $q->condition('f.queued', 1);
    if ($uri !== FALSE)
      $q->condition('f.file', file_stream_wrapper_uri_normalize($uri));
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
   * @param string $name  Filer name.
   *
   * @throws ErrorException if $name is a non-empty string.
   */
  public function __construct($name) {
    if (!is_string($name) || empty($name))
      throw new ErrorException('Filer::__construct($name) requires a non-empty string as first param.');
    $this->name = $name;
    $this->sync();
  }

  public function __get($property) {
    switch ($property) {
      case 'name':
        return $this->name;
      case 'files':
        return $this->files();
    }
    $trace = debug_backtrace();
    trigger_error('Undefined property: Filer::$' . $property . ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'], E_USER_NOTICE);
    return NULL;
  }

  public function __isset($property) {
    switch ($property) {
      case 'name':
      case 'files':
        return TRUE;
    }
    return FALSE;
  }

  /**
   * @param string $uri     Stream wrapper URI
   * @param array  $options Optional: array indexed as follows:
   *                         - items   Array   Each item is passed to hook_filer_FILER_NAME_cron($item, $content, $fh, $status).
   *                         - append  bool    Whether to append or overwrite the contents of $uri on each callback.
   *                         -                 e.g. CSV: append => TRUE, JSON: append => FALSE.
   *                         - read    bool    Whether to pass the contents of the file to hook_filer_FILER_NAME_cron($item, $content, $fh, $status).
   * @param bool   $enqueue If TRUE DrupalQueue will take care of calling hook_filer_FILER_NAME_cron().
   *                         - Otherwise manual calling of Filer::run() is required to fill our file.
   *                         - @Note: if FALSE $options will be ignored.
   * @return bool|int       Filer id on success, FALSE on failure.
   */
  public function add($uri, $options, $enqueue = TRUE) {
    $uri = file_stream_wrapper_uri_normalize($uri);
    if (($enqueue && (empty($options['items']) || !is_array($options['items']))) || empty($uri)) {
      watchdog('filer', 'Invalid arguments passed to Filer::add().');
      return FALSE;
    }
    $options = (array)$options;
    $options += array('items' => array(), 'append' => TRUE, 'read' => TRUE);

    if (!$frid = $this->addRow($uri, $enqueue))
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
   * @param int $frid   The Filer id.
   * @return bool       TRUE if the file was deleted from the filesystem or did not exist, FALSE otherwise.
   */
  public function delete($frid) {
    $row = $this->files($frid);
    if (isset($row['file'])) {
      $ext = empty($row['finished']) ? self::TEMP_EXT : '';
      if (!file_exists($row['file'] . $ext) || drupal_unlink($row['file'] . $ext)) {
        $this->deleteRow($frid);
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Get a list of all files, or the file specified by $frid
   *
   * @param int  $frid    The Filer id.
   * @param bool $reset   Reset the drupal_static cache.
   * @return mixed        If $frid was specified: single db-row array, otherwise an array of db-row arrays.
   */
  public function files($frid = NULL, $reset = FALSE) {
    if (!isset($frid)) {
      $results =& drupal_static($this->name . __FUNCTION__);
    }
    if (!isset($results) || $reset) {
      $qry = db_select('filer', 'f')
        ->fields('f', array('frid', 'name', 'file', 'finished', 'queued'))
        ->condition('f.name', $this->name);
      if (isset($frid)) {
        return $qry->condition('f.frid', $frid)->execute()->fetchAssoc();
      }
      else {
        $results = $qry->execute()->fetchAllAssoc('frid', PDO::FETCH_ASSOC);
      }
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
   * An alias for DrupalQueueInterface::numberOfItems().
   *
   * @return int  Amount of items in the queue.
   */
  public function numberOfItems() {
    return DrupalQueue::get(FILER_CRON . $this->name)->numberOfItems();
  }

  /**
   * An alias for DrupalQueueInterface::deleteQueue().
   * Removes all remaining items in the queue.
   */
  public function deleteQueue() {
    DrupalQueue::get(FILER_CRON . $this->name)->deleteQueue();
  }

  /**
   * Manual runner. If a task was added (Filer::add()) with param $enqueue = FALSE, use this method to invoke hook_filer_FILER_NAME_cron().
   * Calling this for a queued task will do nothing.
   *
   * @param int   $frid   The Filer id.
   * @param mixed $item   Single item to pass to hook_filer_FILER_NAME_cron($item, ...).
   * @param bool  $append Whether to append or overwrite the file. @see Filer::add().
   * @param bool  $read   Whether to pass the contents of the file to hook_filer_FILER_NAME_cron($item, $content, ...). @see Filer::add().
   * @return bool         FALSE on failure.
   */
  public function run($frid, $item, $append = TRUE, $read = FALSE) {
    return $this->write($frid, $item, $append, $read, TRUE, FALSE);
  }

  /**
   * Cron runner (internal).
   *
   * @private
   */
  public function _run($frid, $item, $status, $append = TRUE, $read = FALSE) {
    $this->write($frid, $item, $append, $read, $status === 'last', TRUE);
  }

  /**
   * Passes the item, content (if requested), filehandle and the queue status to hook_filer_FILER_NAME_cron()
   * and writes the return value (if any) to the file.
   *
   * @param int   $frid   The frid.
   * @param mixed $item   Single item to pass to hook_filer_FILER_NAME_cron($item, ...).
   * @param bool  $append TRUE: fopen(..., 'a'), FALSE: fopen(..., 'w').
   * @param bool  $read   Read the file prior to writing and pass the contents to the hooks.
   * @param bool  $finish Indicates Whether this is the last item in the queue.
   * @param bool  $cron   Indicates whether we are called from cron or manually.
   * @return bool
   */
  private function write($frid, $item, $append, $read, $finish, $cron) {
    $hook = 'filer_' . $this->name . '_cron';
    $finished_hook = 'filer_' . $this->name . '_finished';
    $modules = module_implements($hook);
    if (empty($modules)) {
      watchdog('filer', 'No modules implement hook_%hook', array('%hook' => $hook));
      return FALSE;
    }
    $filer_row = $this->files($frid);
    if (empty($filer_row) || (!empty($filer_row['queued']) && !$cron))
      return FALSE;
    $fn = $filer_row['file'] . ($cron ? self::TEMP_EXT : '');
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
        if ($finish) {
          $return = module_invoke($module, $finished_hook, $this, $frid, $filer_row);
        }
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
      $this->finish($frid, $cron);
    return TRUE;
  }

  /**
   * Finishes the file: rename temporary file to permanent file if necessary
   *                  - and merge identical rows into 1 (given they're all finished and their uri is the same).
   *
   * @param int  $frid  The frid.
   * @param bool $temp  If TRUE rename file.tmp to file.
   */
  private function finish($frid, $temp = TRUE) {
    $row = $this->files($frid);
    if ($temp && !rename($row['file'] . self::TEMP_EXT, $row['file'])) {
      watchdog('filer', 'could not rename %file to %nfile', array('%file' => $row['file'] . self::TEMP_EXT, '%nfile' => $row['file']));
      return;
    }
    if (!empty($row)) {
      db_delete('filer')->isNotNull('finished')->condition('file', $row['file'])->condition('frid', $frid, '!=')->execute();
    }
    db_update('filer')->fields(array('finished' => time()))->condition('frid', $frid)->condition('name', $this->name)->execute();
    $this->files(NULL, TRUE);
  }

  /**
   * Adds a row to the filer table.
   *
   * @param string $uri     Stream wrapper uri.
   * @param bool   $queued  Mark this task as queued.
   * @return bool|int       Filer id on success, FALSE on failure.
   */
  private function addRow($uri, $queued) {
    $insert = array('name' => $this->name, 'file' => $uri, 'queued' => (int)$queued);
    $frid = db_insert('filer')->fields($insert)->execute();
    $this->files(NULL, TRUE);
    if (is_null($frid)) {
      watchdog('filer', 'Could not insert row into filer table');
      return FALSE;
    }
    return $frid;
  }

  /**
   * Deletes a row from the filer table.
   *
   * @param int $frid   The frid.
   */
  private function deleteRow($frid) {
    db_delete('filer')->condition('frid', $frid)->condition('name', $this->name)->execute();
    $this->files(NULL, TRUE);
  }

}
