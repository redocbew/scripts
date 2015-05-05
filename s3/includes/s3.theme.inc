<?php


/**
 * Display the(empty) uploader
 * @group Global
 */
function theme_s3_uploader_files() {
  $output .= "<div class='s3_header'>
      <div class='s3_upload_name'>Filename</div>
    </div>";

  $output .= "<div class='s3_uploads' id='s3_active'><span class='s3_empty'>No files selected</span></div>";
  return $output;
}


/**
 * Display a messages block.  The first of a few stubby little theme functions.
 * @param $id string The CSS ID that should be applied to this block
 * @group Global
 */
function theme_s3_message_block($id = 's3_messages') {
  return "<div id='{$id}'></div>";
}


/**
 * Display the textbox for sharing fileposts.
 * @group Global
 */
function theme_s3_share_emails() {
  $text .= "<div class='form-item'>";
  $text .= "<input type='text' class='form-text' value='' size='60' id='s3_share_emails' name='' maxlength='128'>";
  $text .= "<div class='description'>Enter a list of email addresses with which you'd like to share these files.</div>";
  $text .= "</div>";
  return $text;
}


/**
 * Custom theme function for including a hidden form field with an arbitrary id.
 * @param string $id    The id string for this field
 * @param string $value The hidden value its self
 * @group Global
 */
function theme_s3_hidden_field($id, $value) {
  return "<input type='hidden' value='{$value}' id='{$id}' name='{$id}'>";
}


/**
 * Display the plupload wrapper containing browse and upload buttons.  Drupal's
 * form API apparently has no way of creating a non-submit button.
 * @group Global
 */
function theme_s3_plupload_wrapper() {
  $output  = "<div id='plupload_wrapper'>";
  $output .= theme_s3_form_buttons(array('s3_add_files' => 'Add files', 's3_upload' => 'Upload'));
  $output .= "</div>";
  return $output;
}


/**
 * Render a list of objects representing a single "directory" from S3
 * @param array   $object_list  A list of objects collected from S3
 * @param boolean $modify_files Whether or not this filepost allows files to be modified
 * @group Global
 */
function theme_s3_object_list($object_list = array(), $modify_files = false) {
  global $base_url;
  $rows = null;

  $i = 0;
  $delete_image = $base_url.'/'.drupal_get_path('module', 's3').'/images/delete.gif';
  foreach($object_list as $object_name => $object) {
    $row = "<div class='s3_row'>";
    if($object->type == AWS_S3_OBJECT_FILE) {
      $s3_file_class = strpos(AWS_S3_ICON_EXTENSIONS, $object->extension) !== FALSE ? 's3_file s3_'.$object->extension : 's3_file';
      $row .= "<div class='s3_link'>".l($object->name, $object->get_url, array('attributes' => array('class' => $s3_file_class))).'</div>';
    } else {
      $row .= "<div class='s3_link' src='{$object->key_hash}'><span class='s3_folder' src='{$object->get_url}'>".$object->name.'</span></div>';
    }

    $row .= "<div class='s3_modified'>{$object->modified}</div>";
    if($object->type == AWS_S3_OBJECT_FILE) {
      $row .= "<div class='s3_size'>{$object->size}</div>";
    } else {
      $row .= "<div class='s3_size'>&nbsp;</div>";
    }

    if(user_access('delete s3 objects') && $modify_files) {
      $row .= "<div class='s3_delete' src='{$object->delete_url}'><img src='{$delete_image}' title='Delete this object' alt='Delete this object'></div>";
    } else {
      $row .= "<div class='s3_delete'>&nbsp;</div>";
    }

    $row .= '</div>';
    if($object->type == AWS_S3_OBJECT_DIRECTORY) {
      $row .= "<div class='s3_nested s3_hidden' id='{$object->key_hash}'></div>";
    }

    $rows .= $row;
  }

  if(empty($rows)) {
    $rows = "<div class='s3_row'>
      <div class='s3_link'><span class='s3_empty'>No files selected</span></div>
      <div class='s3_size'>&nbsp;</div>
      <div class='s3_modified'>&nbsp;</div>
      <div class='s3_delete'>&nbsp;</div>
    </div>";
  }

  return $rows;
}


/**
 * Display the file browser
 * @param array   $object_list  A list of objects collected from S3
 * @param boolean $modify_files Whether or not this filepost allows files to be modified
 * @group Global
 */
function theme_s3_browser($object_list = array(), $modify_files = false) {
  return "<hr><div id='s3_browser'>".theme_s3_object_list($object_list, $modify_files).'</div><hr>';
}


/**
 * Since the form API seems incapable of creating a non-submit button, lets
 * create our own theme function to make them.
 * @param $buttons array A key/value list of button IDs and names
 * @group Global
 */
function theme_s3_form_buttons($buttons = array()) {
  $output = "<div class='s3_uploader_buttons'>";
  foreach($buttons as $id => $value) {
    $output .= "<input type='button' class='s3_button' value='{$value}' id='{$id}' name='op'>";
  }

  $output .= "</div>";
  return $output;
}


/**
 * A totally tiny and boring theme function for sending a(mostly) plain text email
 * when someone shares a filepost.
 * @param $full_name string The full name of the person who shared this filepost
 * @param $path      string The path to the filepost including security token
 * @group Global
 */
function theme_s3_share_mail($full_name = '', $path = '') {
  global $base_url;

  $site_name = variable_get('site_name', '');
  $body  = "<p>{$full_name} has sent you a file from the {$site_name}.  Click the link below to begin downloading your files.</p>";
  $body .= "<a href='{$path}'>{$path}</a>";

  return $body;
}


/**
 * What will hopefully one day be a swanky HTML email sent when someone shares a filepost.
 * Not included at launch to avoid design-by-programmer weirdness
 * @param $full_name string The full name of the person who shared this filepost
 * @param $path      string The path to the filepost including security token
 * @group Global
 */
function theme_s3_share_html_mail($full_name = '', $path = '') {
  global $base_url;

  $css_rules = "
    body {
      background-color: #e3e3e3;
    }

    #content {
      padding-bottom: 0;
      border-left: 1px solid #c3c3c3;
      border-right: 1px solid #c3c3c3;
    }

    #header, #linkage, #content-inner {
      background-color: #ffffff;
      width: 600px;
      margin-left: auto;
      margin-right: auto;
    }

    #content-inner {
      height: 100%;
    }

    #header {
      padding-bottom: 100px;
    }

    #mdot {
      text-align: right;
    }

    #logo {
      float: left;
      text-align: left;
    }

    #link-div {
      padding-top: 10px;
      width: 255px;
      margin: 0 auto;
    }";

  $base_path = $base_url.'/'.drupal_get_path('module', 's3').'/images';
  $body = "<html>
    <head>
      <style type='text/css'>{$css_rules}</style>
    </head>
    <body>";
  $body .= "<div id='content'>";

  $body .= "<div id='content-inner'>";
  $body .= "<div id='header'>";
  $body .= "<div id='logo'><a href='http://marmot.com'><img src='{$base_path}/email_logo.gif' alt='Marmot for life'></a></div>";
  $body .= "<div id='mdot'><a href='http://marmot.com'><img src='{$base_path}/email_mdot.gif' alt='Marmot for life'></a></div>";
  $body .= "</div>";

  $body .= "<div id='linkage'>";
  $body .= "{$full_name} has sent you a file from {$site_name}.  Click the link below to begin downloading your files.<br>";
  $body .= "<div id='link-div'><a class='link-button' href='{$path}'>Linky button</a></div>";
  $body .= "</div>";
  $body .= "</div>";

  $body .= '</div></body></html>';
  return $body;
}