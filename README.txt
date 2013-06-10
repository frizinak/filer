filer
=====

Drupal module to store large amounts of data in files (csv, json, ...).

Usage examples:
<?php
$nids = array_keys(db_select('node', 'n')
  ->fields('n', array('nid'))
  ->condition('n.type', 'my_content_type')
  ->condition('n.status', 1)
  ->execute()
  ->fetchAllAssoc('nid'));

/**
 * Example 1.
 * Default usage.
 */

// Create a new Filer, this is an object that manages a set of files.
$filer = new Filer('example_one');

$options = array(
  'items' => $nids, // Each one of these items is passed to the hooks one after another
  'append' => TRUE, // Append or overwrite the contents of the file for every item, (e.a: CSV: append => TRUE, JSON: append => FALSE)
  'read' => FALSE, // If TRUE the contents of our file will be passed to our hook (should be false if not used).
);

// Add a task to our Filer specifying the file to write to.
// If third param ($enqueue = TRUE) is FALSE, the task will not be added to the queue and needs to be run manually @see example_three
$filer->add('public://example_one.csv', $options);

/**
 * Implements hook_filer_FILER_NAME_first();
 * Only called for the first item.
 */
function hook_filer_example_one_first($item, $fh, $info) {
  // $info['frid']    The frid of this file.
  // $info['content'] The contents of the file if $options['read'] were TRUE.
  // $info['status']  The status of the cron queue (one of: FILER_STATUS_FIRST, FILER_STATUS_LAST, FILER_STATUS_MANUAL or an empty string)

  // write column headers to csv.
  fputcsv($fh, array('csv column header 1', 'csv column header 2', 'csv column header 3'));
  /* or */
  return '"csv column header 1","csv column header 2","csv column header 3"' . PHP_EOL;
}

/**
 * Implements hook_filer_FILER_NAME();
 * Called on every item (including the first and last)
 */
function hook_filer_example_one($item, $fh, $info) {
  fputcsv($fh, node_to_array(node_load($item)));
  /* or */
  return implode(',', node_to_array(node_load($item))) . PHP_EOL; // For the purposes of this example (for a real csv fputcsv is the way to go).
}

/**
 * Implements hook_filer_FILER_NAME_last();
 * Only called for the last item
 */
function hook_filer_example_one_last($item, $fh, $info) {
  // Newline at end of file.
  return PHP_EOL;
}

/**
 * Implements hook_filer_FILER_NAME_finished();
 * Called after renaming the temporary file.
 * Return value is ignored.
 */
function hook_filer_example_one_finished($info) {
// Send a mail to client to notify about our newly created csv.
}

// Get all files and delete them:
$filer = new Filer('example_one');
foreach ($f->files() as $file) {
  $f->delete($file['frid']);
}
/* or */
$filer = new Filer('example_one');
// If 1st param ($finishedOnly = TRUE) is FALSE, the cron queue will be emptied and unfinished files deleted aswell.
$filer->deleteAll(TRUE);

/**
 * Example 2.
 * Non-queued.
 */

$filer = new Filer('example_two');
$options = array();
// Third param $enqueue is FALSE so $options is ignored and cron / batch will not be calling our hooks and thus not writing to our file.
// File inside private dir => can use Filer permissions to give certain roles access to this file.
$frid = $filer->add('private://example_two.json', $options, FALSE);

// Write to our file and also invoke hook_filer_FILER_NAME_first:
$filer->run($frid, $nids[0], $append = FALSE, $read = TRUE, FILER_STATUS_FIRST);
// Write to our file and don't invoke any hooks (other than hook_filer_FILER_NAME):
$count = count($nids);
for ($i = 1; $i < $count - 1; $i++) {
  $filer->run($frid, $nids[$i], $append = FALSE, $read = TRUE, FILER_STATUS_NORMAL);
}
// Write to our file and also invoke hook_filer_FILER_NAME_last and hook_filer_FILER_NAME_finished
// This step is required to finish the file (rename from example_two.json.tmp to example_two.json):
$filer->run($frid, $nids[$count - 1], $append = FALSE, $read = TRUE, FILER_STATUS_LAST);



/**
 * Implements hook_filer_FILER_NAME_first();
 */
function hook_filer_example_two_first($item, $fh, $info) {
}

/**
 * Implements hook_filer_FILER_NAME();
 * Called on every run.
 */
function hook_filer_example_two($item, $fh, $info) {
  $node = node_load($item);
// Get the stored array of nodes.
  $stored_object = drupal_json_decode($info['content']);
// Update / Insert new node.
  $stored_object[$item] = $node;
// Write to file (not appending).
  return drupal_json_encode($stored_object);
}

/**
 * Implements hook_filer_FILER_NAME_last();
 */
function hook_filer_example_two_last($item, $fh, $info) {
}

/**
 * Implements hook_filer_FILER_NAME_finished();
 */
function hook_filer_example_two_finished($info) {
// file written, move to public dir (for example)
}
?>


Sponsored by [wieni](http://wieni.be).
