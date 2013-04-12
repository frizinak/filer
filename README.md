filer
=====

Drupal module to store large amounts of data in files (csv, json, ...)
TODO: ui to manage files.

Not ready for production yet.

Usage example:
```
// Create a file and write to it on cron, appending lines:
$content_types = node_type_get_types();
$nids = array_keys(db_select('node', 'n')
          ->fields('n', array('nid'))
          ->condition('n.type', $content_type_name)
          ->condition('n.status', 1)
          ->execute()
          ->fetchAllAssoc('nid'));

$f = new Filer('nodes');
$f->add('public://nodes-' . time() . '.txt', array('items' => $nids, 'append' => TRUE, 'read' => FALSE));

function hook_filer_nodes_cron($data, $content, $fh, $status) {
  $node = node_load($data);
  fputcsv($fh, node_to_array($node));
}

// Create a file and write to it on cron, overwriting it on each call and passing old contents to our hook_filer_FILER_NAME_cron():
$f = new Filer('wickedJsonNodes');
$f->add('public://json/nodes.json', array('items' => $nids, 'append' => FALSE, 'read' => TRUE));

// Create a non queue task:
$frid = $f->add('public://json/nodes_manual.json', null, FALSE);
foreach($nids as $nid) {
  $f->run($frid, $nid, FALSE, TRUE);
}

function hook_filer_wickedJsonNodes_cron($nid, $content, $fh, $status) {
  $stored = json_decode($content, TRUE);
  $node = node_load($nid);
  $stored[$nid] = node_to_array($node);
  return json_encode($stored);
}

// Get all files and delete them:

$f = new Filer('nodes');
foreach($f->files() as $file){
  $f->delete($file['frid']);
}


```

Sponsored by [wieni](http://wieni.be).
