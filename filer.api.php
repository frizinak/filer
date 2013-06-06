<?php
/**
 * This hook is invoked for every item as passed to Filer:add($uri, $options, $enqueue).
 *
 * @param mixed    $item   One of the items as passed in Filer::add().
 * @param resource $fh     File pointer resource of the fopened file.
 * @param array    $info   Array indexed as follows:
 *                          - content =>  (string) Contents of the file if Filer::add() was called with $options['read'] = TRUE.
 *                          - status  =>  (string) One of the following:
 *                                        - FILER_STATUS_FIRST: first item,
 *                                        - FILER_STATUS_LAST: last item,
 *                                        - FILER_STATUS_MANUAL: not queued,
 *                                        - or an empty string: anything in between.
 *                          - frid   =>   (int)   Id of the current file, can be passed to Filer::files($frid) to retrieve info about this file.
 * @return string         Optional return value will be written to the file. (can also use $fh instead of returning).
 */
function hook_filer_FILER_NAME_cron($item, $fh, $info) {
  fwrite($fh, 'text');
  /* or */
  return 'text';
}

/**
 * Only invoked for the first item in the queue.
 *
 * @see  hook_filer_FILER_NAME_cron
 */
function hook_filer_FILER_NAME_first($item, $fh, $info) {
  fputcsv($fh, array('csv column header 1', 'csv column header 2', 'csv column header 3'));
  /* or */
  return '"csv column header 1","csv column header 2","csv column header 3"' . PHP_EOL;
}

/**
 * Only invoked for the last item in the queue.
 * File has not been renamed yet.
 *
 * @see  hook_filer_FILER_NAME_cron
 */
function hook_filer_FILER_NAME_last($item, $fh, $info) {
  return 'last line before finishing file';
}

/**
 * Invoked when the file was 'finished' (all writing done and renamed from .tmp to final filename)
 *
 * @param array $info   Array indexed as follows:
 *                      - content =>  (string) Contents of the file if Filer::add() was called with $options['read'] = TRUE.
 *                      - status  =>  FILER_STATUS_LAST
 *                      - frid   =>   (int)   Id of the current file, can be passed to Filer::files($frid) to retrieve info about this file.
 */
function hook_filer_FILER_NAME_finished($info) {

}

