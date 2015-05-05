<?php

/**
 * Report.Factory_list
 * @package Marmot
 * @author Dan 'Redocbew' Fogle
 */

require_once '/var/www/aws_client/AWS_client.php';

define('AWS_S3_POLICY_LIFETIME', 60*60*24);

define('AWS_S3_OBJECT_KEY_DELIMITER', '/');
define('AWS_S3_OBJECT_ACL'          , 'private');
define('AWS_S3_OBJECT_URL_DURATION' , '+12 hours');
define('AWS_S3_OBJECT_FILE'         , 1);
define('AWS_S3_OBJECT_DIRECTORY'    , 2);
define('AWS_S3_OBJECT_DELETE_MULTI' , 999); // 1000, zero based


/**
 * S3 abstraction layer for a web based S3 client.
 */
class S3_client extends AWS_client {
  private $s3         = null;
  private $last_error = null;


  /**
   *  Create the S3_client worker object
   */
  public function __construct($username) {
    $this->s3 = parent::__construct(AWS_S3, $username);
  }


  /**
   * Assemble the parameters necessary for uploading directly to S3.
   *
   * @param string $bucket      The name of an Amazon S3 bucket
   * @param string $prefix      The "directory" where this upload is targeted
   * @param string $object_name The filename of this upload
   */
  public function get_upload_parameters($bucket, $prefix, $object_name) {
    $object_name = str_replace(array('/', '\n', '\r', '\t', '\0', '\f', '`', '?', '*', '\\', '<', '>', '|', '\"', ':'), '', $object_name);
    $policy_doc = $this->get_upload_policy($bucket, $prefix, $object_name);

    if(empty($policy_doc)) {
      return null;
    }

    $base64_policy = $this->get_encoded_string($policy_doc);
    $signed_policy = $this->get_signed_string($policy_doc);

    return array(
      'key'           => empty($prefix) ? $object_name : $prefix.'/'.$object_name,
      'filename'      => empty($prefix) ? $object_name : $prefix.'/'.$object_name,
      'policy'        => $base64_policy,
      'signature'     => $signed_policy,
      'access_key_id' => $this->get_worker_access_key_id(),
    );
  }


  /**
   * Check if an object exists in S3
   *
   * @param string $bucket The name of an Amazon S3 bucket
   * @param string $key    The name of an Amazon S3 object
   */
  public function object_exists($bucket, $key) {
    return $this->s3->doesObjectExist($bucket, $key);
  }


  /**
   * Delete an object, or a collection of objects from the selected S3 bucket
   * @param integer $object_type The type of object being deleted, single file or directory
   * @param string  $bucket      The name of an Amazon S3 bucket
   * @param string  $key         An S3 object key
   */
  public function delete($object_type, $bucket, $key) {
    $key = rawurldecode($key);
    if($object_type == AWS_S3_OBJECT_DIRECTORY) {
      $is_deleted = $this->delete_directory($bucket, $key);
    } else if($object_type == AWS_S3_OBJECT_FILE) {
      $is_deleted = $this->delete_object($bucket, $key);
    }

    return $is_deleted;
  }


  /**
   * Get the last error that occured while making an API call.
   */
  public function get_last_error() {
    return $this->last_error;
  }


  /**
   * Emulate directory listings by fetching all objects matching a given key prefix
   * @param string $bucket   The name of an Amazon S3 bucket
   * @param string $prefix   A key prefix matching the simulated directory containing the objects to be listed
   * @param string $cdn_root The cname of a CloudFront distribution rooted at this bucket
   */
  public function get_list($bucket, $prefix = '/', $cdn_root = '') {
    $list = array();

    $prefix = rawurldecode($prefix);
    $root = 'http://';
    $args = array('Bucket' => $bucket);

    if(empty($cdn_root)) {
      $root .= $bucket.'.s3.amazonaws.com/';
    } else {
      $root .= substr($cdn_root, -1) != '/' ? $cdn_root.'/' : $cdn_root;
    }

    if(substr($prefix, -1) != '/') {
      $prefix .= '/';
    }

    if($prefix != '/') {
      $args['Prefix'] = $prefix;
    } else {
      $prefix = '';
    }

    try {
      // calling toArray() explicitly will trigger an error if something weird
      // is going on.

      // TODO: Find a way to do this that doesn't load everything into memory
      $object_itr = $this->s3->getIterator('ListObjects', $args)->toArray();
    }catch(Aws\S3\Exception\AccessDeniedException $e) {
      $this->last_error = "Access denied when attempting to list objects.  Verify that this bucket is configured to allow access to the requested objects. ";
      return FALSE;
    }catch(Aws\S3\Exception\S3Exception $e) {
      $this->last_error = 'The following error was encountered when attempting to list objects: '.$e->getMessage();
      return FALSE;
    }

    // collect information about the files returned by ListObjects
    foreach($object_itr as $s3_object) {
      if($s3_object['Key'] != $prefix) {
        // the file browser goes only one level deep in each call, so all we
        // need to care about here is the number of directories in each key,
        // and the string immediately following the prefix

        $regex_prefix = str_replace('/', '\\/', $prefix);
        $object_dirs = explode('/', preg_replace("/^(?:{$regex_prefix})/", '', $s3_object['Key'], 1));
        $object_name = array_shift($object_dirs);

        if(!isset($list[$object_name])) {
          $listing             = null;
          $listing->type       = count($object_dirs) > 0 ? AWS_S3_OBJECT_DIRECTORY : AWS_S3_OBJECT_FILE;
          $listing->key        = $prefix.$object_name; // or at least, the parts of the key that matter here
          $listing->name       = $object_name;
          $listing->modified   = date('m/d/Y', strtotime($s3_object['LastModified']));
          $listing->size       = '';
          $listing->delete_url = $listing->type.'/'.$this->s3->encodeKey($listing->key);

          // key_hash is a cheap trick to avoid annoying problems when keys are
          // used as CSS classes and IDs.  Since the actual key will always point
          // to a filename, we hash the abbreviated key to avoid collisions
          // when listing directories.
          $listing->key_hash = md5($listing->key);

          // Download links go directly to S3 while deletes are handled from the
		  // frontend and passed back through to the API.
          if($listing->type == AWS_S3_OBJECT_FILE) {
              $request = $this->s3->get($root.$s3_object['Key']);
              $listing->get_url   = $this->s3->createPresignedUrl($request, AWS_S3_OBJECT_URL_DURATION);

              $filename           = explode('.', $object_name);
              $listing->extension = strtolower(array_pop($filename));
              $listing->size      = $this->get_formatted_file_size($s3_object['Size']);
          } else {
            $listing->get_url = $this->s3->encodeKey($listing->key);
          }

          $list[$object_name] = $listing;
        }
      }
    }

    return $list;
  }


  /**
   * Encode an S3 key preserving slashes
   * @param string $key An S3 object key
   */
  public function encode_key($key) {
    return $this->s3->encodeKey($key);
  }


  /**
   * Check if a given bucket exists in S3 and whether the user invoking this
   * client has access to it.
   * @param string $bucket The name of an S3 bucket
   */
  public function bucket_exists($bucket) {
    return $this->s3->doesBucketExist($bucket);
  }


  /**
   * Delete all objects matching a given key prefix simulating the delete of a
   * sub-directory.
   * @param string $bucket The name of an Amazon S3 bucket
   * @param string $prefix A key prefix matching the simulated directory
   */
  private function delete_directory($bucket, $prefix) {
    $errors     = array();
    $args       = array('Bucket'     => $bucket, 'Prefix' => $prefix);
    $ops        = array('names_only' => true);

    // getIterator() abstracts away the limit of 1000 per request for ListObjects,
    // but doing one request for each delete would defeat the purose of it.  Store
    // the objects to delete in batches of 1000 before calling deleteObjects
    // on each batch.

    $i = 0;
    // TODO: Better error checking, just in case it wants to scream and die
    foreach($this->s3->getIterator('ListObjects', $args, $ops) as $key) {
      $keys[$i++] = array('Key' => $key);
      if($i >= AWS_S3_OBJECT_DELETE_MULTI) {
        $errors = array_merge($this->delete_multiple_objects($bucket, $keys), $errors);
        $keys   = array();
        $i      = 0;
      }
    }

    if(!empty($keys)) {
      $errors = array_merge($this->delete_multiple_objects($bucket, $keys), $errors);
    }

    if(!empty($errors)) {
      $this->last_error = implode("\n", $errors);
      return false;
    }

    return true;
  }


  /**
   * Helper function when deleting multiple objects from a "directory".
   * @param string $bucket The name of an Amazon S3 bucket
   * @param string $keys   A list of object keys to be deleted
   */
  private function delete_multiple_objects($bucket, $keys) {
    $errors  = array();
    $deleted = $this->s3->deleteObjects(
      array(
        'Bucket'     => $bucket,
        'Objects'    => $keys,
        'ContentMD5' => true
      )
    );

    if(is_array($delete['Errors'])) {
      foreach($deleted['Errors'] as $error) {
        $errors[] = 'S3_Client Error '.$error['code'].' - '.$error['Message'].'. Object '.$error['Key'];
      }
    }

    return $errors;
  }


  /**
   * Delete a single S3 object
   * @param string $bucket The name of an Amazon S3 bucket
   * @param string $key    The name of an Amazon S3 object
   */
  private function delete_object($bucket, $key) {
    if(!$this->s3->doesObjectExist($bucket, $key)) {
      return false;
    }

    try {
      $this->s3->deleteObject(array('Bucket' => $bucket, 'Key' => $key));
    } catch(S3Exception $e) {
      $this->last_error = $e;
      return false;
    }

    return true;
  }


  /**
   * Assemble POST policy document string for uploading directly to S3
   * @param string $bucket      The name of an S3 bucket
   * @param string $prefix      The "directory" where this upload will be placed
   * @param string $object_name The filename of this upload
   */
  private function get_upload_policy($bucket, $prefix, $object_name) {
    $this->set_policy_duration(AWS_S3_POLICY_LIFETIME);

    $expiration = gmdate('Y-n-d\TH:i:s.000\Z', time() + $this->get_policy_duration());
    $key = empty($prefix) ? $object_name : $prefix .'/'.$object_name;

    // Set policy conditions.  With the exception of AWSAccessKeyId, signature,
    // policy and the actual file upload field, these conditions should match
    // the form fields supplied by the uploader otherwise S3 gets very cranky.
    $this->clear_policy_conditions();
    $this->set_policy_condition('acl'                   , AWS_S3_OBJECT_ACL);
    $this->set_policy_condition('bucket'                , $bucket);
    $this->set_policy_condition('key'                   , $key);
    $this->set_policy_condition('success_action_status' , '201');  // make flash happy

    $this->set_policy_condition('$name'    , '' , 'starts-with');
    $this->set_policy_condition('$filename', '' , 'starts-with');  // also make flash happy... the flash runtime for plupload wants a filename param
    $conditions = $this->get_policy_conditions_string();

    if(empty($conditions)) {
      $this->last_error = 'Could not create policy conditions';
      return null;
    }

    $policy_doc = '{ "expiration": "'.$expiration.'", "conditions": ['.$conditions.']}';
    return preg_replace('/\s\s+|\\f|\\n|\\r|\\t|\\v/', '', $policy_doc);
  }


  /**
   * Format a filesize given in bytes into human-readable units.
   * @param integer $filesize The size of an object in bytes
   */
  private function get_formatted_file_size($filesize) {
    if($filesize < 1024) {
      return $filesize.' bytes';
    }

    $units = array('KB', 'MB', 'GB', 'TB');
    foreach($units as $index => $unit) {
      $filesize /= 1024;
      if($filesize < 1024) {
        return $unit == 'KB' ? intval($filesize).$unit : round($filesize, 1).$unit;
      }
    }

    return round($filesize, 1).'TB';
  }
}