filer
=====

Drupal module to store large amounts of data in files (csv, json, ...)

Not ready for production yet.

Usage example:
```
// Create a file and write to it on cron:

$content_types = node_type_get_types();
$nids = array_keys(db_select('node', 'n')
          ->fields('n', array('nid'))
          ->condition('n.type', $content_type_name)
          ->condition('n.status', 1)
          ->execute()
          ->fetchAllAssoc('nid'));

$files = new Filer('nodes');
$f->add(drupal_realpath('public:///nodes-' . time() . '.txt'), $nids, 'callback');

function callback($data, $content, $fh, $status) {
  $node = node_load($data);
  $row = (array)my_custom_extract_field_values_from_node($node);
  fputcsv($fh, $row);
}

/* OR */

function callback($data, $content, $fh, $status) {
  $node = node_load($data);
  return $node->title . "\n";
}

// Get all files and delete them:

$f = new Filer('nodes');
foreach($f->getFiles() as $file){
  $f->deleteFile($file->frid);
}


```

Thanks to [wieni](http://wieni.be).
