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
    if (!$finished) {
      $q->isNull('f.finished');
    }
    if (!$nonQueued) {
      $q->condition('f.queued', 1);
    }
    if ($uri !== FALSE) {
      $q->condition('f.file', file_stream_wrapper_uri_normalize($uri));
    }
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
    if (!is_string($name) || empty($name)) {
      throw new ErrorException('Filer::__construct($name) requires a non-empty string as first param.');
    }
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
   *                         - items   Array   Each item is passed to hook_filer_FILER_NAME($item, $fh, $info).
   *                         - append  bool    Whether to append or overwrite the contents of $uri on each callback.
   *                         -                 e.g. CSV: append => TRUE, JSON: append => FALSE.
   *                         - read    bool    Whether to pass the contents of the file to hook_filer_FILER_NAME($item, $fh, $info).
   * @param bool   $enqueue If TRUE DrupalQueue will take care of calling hook_filer_FILER_NAME().
   *                         - Otherwise manual calling of Filer::run() is required to fill our file.
   *                         - @Note: if FALSE $options will be ignored.
   * @return bool|int       Filer id on success, FALSE on failure.
   */
  public function add($uri, $options, $enqueue = TRUE) {
    $uri = file_stream_wrapper_uri_normalize($uri);
    $valid_stream_wrappers = stream_get_wrappers();
    $scheme = file_uri_scheme($uri);
    if (!$scheme || !in_array($scheme, $valid_stream_wrappers)) {
      watchdog('filer', 'Invalid uri %uri (stream wrapper not registered: %scheme)', array('%uri' => $uri, '%scheme' => $scheme));
      return FALSE;
    }
    if (($enqueue && (empty($options['items']) || !is_array($options['items']))) || empty($uri)) {
      watchdog('filer', 'Invalid arguments passed to Filer::add().');
      return FALSE;
    }
    $options = (array)$options;
    $options += array('items' => array(), 'append' => TRUE, 'read' => TRUE);

    if (!$frid = $this->addRow($uri, $enqueue)) {
      watchdog('filer', 'Unable to create a new database row in table filer');
      return FALSE;
    }

    if ($enqueue) {
      $q = DrupalQueue::get(FILER_CRON . $this->name);
      $item_count = count($options['items']);
      $i = 0;
      foreach ($options['items'] as $item) {
        $i++;
        $q->createItem(array(
          'name' => $this->name,
          'frid' => $frid,
          'status' => $i == $item_count ? FILER_STATUS_LAST : ($i == 1 ? FILER_STATUS_FIRST : FILER_STATUS_NORMAL),
          'read' => $options['read'],
          'append' => $options['append'],
          'item' => $item,
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
   * @param bool $finishedOnly    Only delete finished files, if FALSE: delete all and clear this Filers queue.
   * @return bool                 FALSE if we were unable to delete any of the files, TRUE otherwise @see Filer::delete().
   */
  public function deleteAll($finishedOnly = TRUE) {
    $success = TRUE;
    if (!$finishedOnly) {
      $this->deleteQueue();
    }
    foreach ($this->files() as $file) {
      var_dump($finishedOnly && !empty($file['finished']), $file['finished']);
      if ($finishedOnly && empty($file['finished'])) {
        continue;
      }
      $success = $this->delete($file['frid']) ? $success : FALSE;
    }
    return $success;
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
   * Manual runner. If a task was added (Filer::add()) with param $enqueue = FALSE, use this method to invoke hook_filer_FILER_NAME().
   * Calling this for a queued task will do nothing.
   *
   * @param int   $frid   The Filer id.
   * @param mixed $item   Single item to pass to hook_filer_FILER_NAME().
   * @param bool  $append Whether to append or overwrite the file. @see Filer::add().
   * @param bool  $read   Whether to pass the contents of the file to hook_filer_FILER_NAME(). @see Filer::add().
   * @param int   $status if FILER_STATUS_FIRST: invoke hook_filer_FILER_NAME_first, if FILER_STATUS_LAST invoke hook_filer_FILER_NAME_last and rename to final file.
   * @return bool         FALSE on failure.
   */
  public function run($frid, $item, $append = TRUE, $read = FALSE, $status = FILER_STATUS_NORMAL) {
    return $this->write($frid, $item, $append, $read, FILER_STATUS_MANUAL | $status);
  }

  /**
   * Cron runner (internal).
   *
   * @private
   */
  public function _run($frid, $item, $append = TRUE, $read = FALSE, $status = FILER_STATUS_NORMAL) {
    $this->write($frid, $item, $append, $read, $status);
  }

  /**
   * Passes the item, content (if requested), filehandle and the queue status to hook_filer_FILER_NAME()
   * (and hook_filer_FILER_NAME_first or hook_filer_FILER_NAME_last depending on $status)
   * and writes the return value (if any) to the file.
   *
   * @param int   $frid   Filer id.
   * @param mixed $item   Single item to pass to hook_filer_FILER_NAME($item, ...).
   * @param bool  $append TRUE: fopen(..., 'a'), FALSE: fopen(..., 'w').
   * @param bool  $read   Read the file prior to writing and pass the contents to the hooks.
   * @param int   $status Status of the current file:
   *                       -  FILER_STATUS_FIRST: first item (hook_filer_FILER_NAME_first will also be invoked),
   *                       -  FILER_STATUS_LAST: last item (hook_filer_FILER_NAME_last will also be invoked
   *                       -                                and hook_filer_FILER_NAME_finished when the filewriting has stopped
   *                       -                                and the file has been renamed),
   *                       -  FILER_STATUS_MANUAL: non-queued or
   *                       -  FILER_STATUS_NORMAL: anything in between.
   * @return bool
   */
  private function write($frid, $item, $append, $read, $status = FILER_STATUS_NORMAL) {
    $hook = 'filer_' . $this->name;
    $modules = module_implements($hook);

    if (empty($modules)) {
      return FALSE;
    }
    $filer_row = $this->files($frid);
    if (empty($filer_row) || (!empty($filer_row['queued']) && $status & FILER_STATUS_MANUAL)) {
      return FALSE;
    }
    $fn = $filer_row['file'] . self::TEMP_EXT;
    $dir = dirname($fn);
    if (!file_prepare_directory($dir, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS)) {
      watchdog('filer', 'Could not prepare directory (%path)', array('%path' => $dir));
      return FALSE;
    }
    $content = $old_content = FALSE;
    if ($read) {
      file_exists($filer_row['file']) && $old_content = file_get_contents($filer_row['file']);
      file_exists($fn) && $content = file_get_contents($fn);
    }
    $info = array(
      'old content' => $old_content,
      'content' => $content,
      'status' => $status,
      'frid' => $frid,
    );

    if ($status & FILER_STATUS_FIRST) {
      $first_hook = 'filer_' . $this->name . '_first';
      $first_modules = module_implements($first_hook);
      $this->invoke($first_modules, $first_hook, $append, $read, $fn, $item, $info);
    }
    $this->invoke($modules, $hook, $append, $read, $fn, $item, $info);
    if ($status & FILER_STATUS_LAST) {
      $last_hook = 'filer_' . $this->name . '_last';
      $last_modules = module_implements($last_hook);
      $this->invoke($last_modules, $last_hook, $append, $read, $fn, $item, $info);
    }

    if ($status & FILER_STATUS_LAST) {
      $this->finish($frid);
      $this->sync();
      $finished_hook = 'filer_' . $this->name . '_finished';
      $finished_modules = module_implements($finished_hook);
      foreach ($finished_modules as $module) {
        module_invoke($module, $finished_hook, $info);
      }
    }
    return TRUE;
  }

  /**
   * Invokes the given hook on the given modules and writes their return value to the file.
   * If $read = TRUE the entire file will be read and updated in $info['content'];
   * We use file_get_contents to avoid any discrepancy caused when a hook manually writes to the file instead of returning a string.
   *
   * @param array  $modules  Array of module names.
   * @param string $hook     Hook to invoke on every module in $modules
   * @param bool   $append   Boolean indicating what mode to open the file in.
   * @param bool   $read     Boolean indicating whether to read the file after every write.
   * @param string $fn       Filename
   * @param mixed  $item     @see Filer::write().
   * @param array  $info     @see Filer::write().
   */
  private function invoke($modules, $hook, $append, $read, $fn, $item, &$info) {
    foreach ($modules as $module) {
      if ($fh = fopen($fn, $append ? 'a' : 'w')) {
        $content = (string)module_invoke($module, $hook, $item, $fh, $info);
        if (!empty($content) && fwrite($fh, $content) === FALSE) {
          watchdog('filer', 'could not write to %file', array('%file' => $fn));
        }
        if ($read) {
          $info['content'] = file_get_contents($fn);
        }
        fclose($fh);
      }
      else {
        watchdog('filer', 'could not open file: %file', array('%file' => $fn));
      }
    }
  }

  /**
   * Finishes the file: rename temporary file to permanent file if necessary
   *                  - and merge identical rows into 1 (given they're all finished and their uri is the same).
   *
   * @param int  $frid  Filer id.
   */
  private function finish($frid) {
    $row = $this->files($frid);
    if (!rename($row['file'] . self::TEMP_EXT, $row['file'])) {
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
   * @param int $frid   Filer id.
   */
  private function deleteRow($frid) {
    db_delete('filer')->condition('frid', $frid)->condition('name', $this->name)->execute();
    $this->files(NULL, TRUE);
  }

}
