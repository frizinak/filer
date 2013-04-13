<?php

/**
 * Confirmation form before deleting a FilerFile.
 */
function filer_delete_file_form($form, $form_state, $filer, $frid = NULL) {
  $dest = isset($_GET['destination']) ? $_GET['destination'] : FILER_PAGE_URL;
  if (!user_access('delete ' . $filer->name)) {
    drupal_access_denied();
    drupal_exit();
  }
  if ($filer instanceof Filer && isset($filer->files[$frid]['file'])) {
    return confirm_form(NULL, t('Are you sure you wish to delete %path?', array('%path' => $filer->files[$frid]['file'])), $dest);
  }
  drupal_set_message(t('File was not found.'), 'warning');
  drupal_goto($dest);
}

/**
 * Implements hook_submit().
 */
function filer_delete_file_form_submit($form, $form_state) {
  $dest = isset($_GET['destination']) ? $_GET['destination'] : FILER_PAGE_URL;
  $filer = isset($form_state['build_info']['args'][0]) ? $form_state['build_info']['args'][0] : NULL;
  $frid = isset($form_state['build_info']['args'][1]) ? $form_state['build_info']['args'][1] : NULL;
  if (!empty($frid) && $filer instanceof Filer) {
    $filer->delete($frid);
  }
  drupal_goto($dest);
}

/**
 * Confirmation form before clearing a Filer queue.
 */
function filer_clear_queue_form($form, $form_state, $filer) {
  $dest = isset($_GET['destination']) ? $_GET['destination'] : FILER_PAGE_URL;
  if (!user_access('clear ' . $filer->name . ' queue')) {
    drupal_access_denied();
    drupal_exit();
  }
  if ($filer instanceof Filer) {
    return confirm_form(NULL, t('Are you sure you wish to clear the %name queue?', array('%name' => $filer->name)), $dest);
  }
  drupal_set_message(t('Filer was not found.'), 'warning');
  drupal_goto($dest);
}

/**
 * Implements hook_submit().
 */
function filer_clear_queue_form_submit($form, $form_state) {
  $dest = isset($_GET['destination']) ? $_GET['destination'] : FILER_PAGE_URL;
  $filer = isset($form_state['build_info']['args'][0]) ? $form_state['build_info']['args'][0] : NULL;
  if ($filer instanceof Filer) {
    $filer->deleteQueue();
  }
  drupal_goto($dest);
}
