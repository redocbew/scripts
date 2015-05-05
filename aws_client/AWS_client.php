<?php

/**
 * Report.Factory_list
 * @package Marmot
 * @author Dan 'Redocbew' Fogle
 */

require_once('/var/www/awsphp2/vendor/autoload.php');
require_once('/var/opt/marmot/marmotdb/include/Marmot/marmot_db.php');

use Aws\Common\Enum\Region;
use Aws\S3\Enum\CannedAcl;
use Aws\S3\Exception\S3Exception;
use Guzzle\Http\EntityBody;
use Aws\Iam\IamClient;

define('AWS_S3'        , 'S3');
define('AWS_CLOUDFRONT', 'CLOUDFRONT');

define('AWS_KEY_CIPHER'          , 'rijndael-256');
define('AWS_PRIVATE_KEY'         , 'welcome to security theater.  your super secret private key goes here.');
define('AWS_KEY_LIFETIME'        , 60*60*24*7*6);
define('AWS_KEY_ROTATE_SECONDARY', 60*60*24);
define('AWS_POLICY_LIFETIME'     , 60*60*24);
define('AWS_KEY_ROTATE_SECONDARY', 35);
define('AWS_KEY_MAXIMUM'         , 2);

define('UTF32_BIG_ENDIAN_BOM'   , chr(0x00) . chr(0x00) . chr(0xFE) . chr(0xFF));
define('UTF32_LITTLE_ENDIAN_BOM', chr(0xFF) . chr(0xFE) . chr(0x00) . chr(0x00));
define('UTF16_BIG_ENDIAN_BOM'   , chr(0xFE) . chr(0xFF));
define('UTF16_LITTLE_ENDIAN_BOM', chr(0xFF) . chr(0xFE));
define('UTF8_BOM'               , chr(0xEF) . chr(0xBB) . chr(0xBF));

class AWS_client {
  private $iam = null;
  private $sts = null;
  private $db  = null;

  private $worker_access_key_id     = null;
  private $worker_security_token    = null;
  private $worker_service           = null;
  private $worker_username          = null;
  private $worker_secret_access_key = null;

  private $policy_conditions = array();
  private $policy_duration   = 0;


  /**
   * Initialize class, create the appropriate service worker object, and rotate
   * AWS access keys if necessary.
   * @param string $service  The AWS service that this client will be using
   * @param string $username The username of the AWS user performing the work done by this client
   */
  public function __construct($service, $username) {
    if(empty($service) || empty($username)) {
      return null;
    }

    $this->db = new MarmotDB(MarmotDB::WEBAPPS);

    $worker                = null;
    $this->worker_service  = strtoupper($service);
    $this->worker_username = pg_escape_string($username);

    $keys = $this->db->runQuery("SELECT id, secret, updated FROM aws_keys WHERE username = '{$this->worker_username}' AND is_primary = 1 ORDER BY updated")->fetch();

    // create STS and IAM instances with the user identified by $username
    $config = array(
      'key'               => $this->decrypt_key($keys->id),
      'secret'            => $this->decrypt_key($keys->secret),
      'region'            => Region::US_EAST_1,
      'validation'        => false,
      'credentials.cache' => true,
    );

    // disabling temporary credentials until I figure out how to create presigned
    // URLs using them
    $this->iam = Aws\Iam\IamClient::factory($config);
    $worker_config = $config;

    switch($this->worker_service) {
      case AWS_S3         : $worker = Aws\S3\S3Client::factory($worker_config); break;
      case AWS_CLOUDFRONT : $worker = Aws\CloudFront\CloudFrontClient::factory($worker_config); break;
    }

    if(!$worker) {
      return null;
    }

    $this->worker_access_key_id     = $worker_config['key'];
    $this->worker_security_token    = $worker_config['token'];
    $this->worker_secret_access_key = $worker_config['secret'];

    $this->rotate_keys($keys->id, $keys->updated);
    return $worker;
  }


  /**
   * Get the temporary access key ID created for this client
   */
  protected function get_worker_access_key_id() {
    return $this->worker_access_key_id;
  }


  protected function get_worker_security_token() {
    return $this->worker_security_token;
  }


  protected function clear_policy_conditions() {
    $this->policy_conditions = array();
  }


  protected function get_policy_conditions_string() {
    if(empty($this->policy_conditions)) {
      return null;
    }

    foreach($this->policy_conditions as $condition) {
      if(count($condition) == 3) {
        $condition_list[] .= sprintf('["%s", "%s", "%s"]', $condition[2], $condition[0], $condition[1]);
      } else {
        $condition_list[] .= sprintf('{"%s": "%s"}', $condition[0], $condition[1]);
      }
    }

    return implode(",\n\n", $condition_list);
  }


  /**
   * Set a policy condition
   * @param string $match_op The match operator for this condition: 'starts-with', 'eq', etc.
   * @param string $field    The fieldname of the policy condition being added
   * @param string $match    The expected value or pattern to match against for this policy condition
   */
  protected function set_policy_condition($field, $match, $match_op = '') {
    $condition = array($field, $match);
    if(!empty($match_op)) {
      $condition[] = $match_op;
    }

    $this->policy_conditions[$field] = $condition;
  }


  /**
   * Get a policy duration
   */
  protected function get_policy_duration() {
    return $this->policy_duration;
  }


  /**
   * Set a policy duration
   * @param integer $duration The duration of this policy in seconds
   */
  protected function set_policy_duration($duration = 0) {
    $this->policy_duration = $duration;
  }


  /**
   * Get a signed base64 encoded UTF-8 string for cases where the API calls for
   * a "signature".
   * @param string $string_to_sign A string to sign
   */
  protected function get_signed_string($string_to_sign) {
    $encoding = $this->get_encoding($string_to_sign);
    if($encoding != 'UTF-8') {
      $string_to_sign = iconv($encoding, 'UTF-8', $string_to_sign);
    }

    return base64_encode(hash_hmac('sha1', base64_encode($string_to_sign), $this->worker_secret_access_key, true));
  }


  /**
   * Base64 encode a UTF-8 string to make it safe for transport
   * @param string $string_to_encode A string to encode
   */
  protected function get_encoded_string($string_to_encode) {
    $encoding = $this->get_encoding($string_to_encode);
    if($encoding != 'UTF-8') {
      $string_to_encode = iconv($encoding, 'UTF-8', $string_to_encode);
    }

    return base64_encode($string_to_encode);
  }


  /**
   *
   */
  private function get_encoding($string_to_check) {
        // Attempt to detect character encoding
    $encoding = mb_detect_encoding($xml, 'UTF-8', true);

    // mb_detect_encoding() sometimes fails miserably, so attempt to determine
    // character encoding by inspecting the byte order marker of the file
    if(empty($encoding)) {
      $first2 = substr($xml, 0, 2);
      $first3 = substr($xml, 0, 3);
      $first4 = substr($xml, 0, 3);

      if($first3 == UTF8_BOM){
        $encoding = 'UTF-8';
      } elseif($first4 == UTF32_BIG_ENDIAN_BOM){
        $encoding = 'UTF-32BE';
      } elseif($first4 == UTF32_LITTLE_ENDIAN_BOM) {
        $encoding = 'UTF-32LE';
      } else if($first2 == UTF16_BIG_ENDIAN_BOM) {
        $encoding = 'UTF-16BE';
      } else if($first2 == UTF16_LITTLE_ENDIAN_BOM) {
        $encoding = 'UTF-16LE';
      }
    }

    return $encoding;
  }


  /**
   * Rotates the access keys used for making API calls to AWS.
   * @param string  $access_key_id An access key ID for the AWS user used by this client
   * @param integer $key_updated   The timestamp at which the keys being rotated were last updated
   */
  private function rotate_keys($access_key_id, $key_updated) {
    $args = array('UserName' => $this->worker_username);
    $keys = $this->iam->listAccessKeys($args);
    $keys = count($keys['AccessKeyMetadata']);

    // check expiration date of keys
    if(time() - $key_updated > AWS_KEY_LIFETIME && $keys < AWS_KEY_MAXIMUM) {
      // create a new key and set it as primary for the next client, then
      // set the current key as secondary and update its timestamp
      $key                      = $this->iam->createAccessKey($args);
      $cipher_access_key_id     = pg_escape_string($this->encrypt_key($key['AccessKey']['AccessKeyId']));
      $cipher_secret_access_key = pg_escape_string($this->encrypt_key($key['AccessKey']['SecretAccessKey']));
      $date_created             = strtotime($key['AccessKey']['CreateDate']);

      $this->db->runQuery(
        "INSERT INTO aws_keys (id, updated, secret, username, is_primary)
        VALUES ('{$cipher_access_key_id}', {$date_created}, '{$cipher_secret_access_key}', '{$this->worker_username}', 1)"
      );

      $date_made_secondary = time();
      $this->db->runQuery("UPDATE aws_keys SET is_primary = 0, updated = {$date_made_secondary} WHERE id = '{$access_key_id}'");
    }

    if($keys < AWS_KEY_MAXIMUM) {
      return;
    }

    // Lazily rotate out secondary keys after a grace period defined in
    // AWS_KEY_ROTATE_SECONDARY.  Broadcasting the creation of new keys to all
    // active instances would remove the need for this, but that requires the
    // creation of a standalone auth server with sync routines for all the
    // clients... overkill for this project.
    $key = $this->db->runQuery('SELECT id FROM aws_keys WHERE extract(epoch from now())::integer - updated > '.AWS_KEY_ROTATE_SECONDARY.' AND is_primary != 1')->fetch();
    if(!empty($key->id)) {
      $args = array('UserName' => $this->worker_username, 'AccessKeyId' => $this->decrypt_key($key->id), 'Status' => 'Inactive');
      $this->iam->updateAccessKey($args);
      unset($args['Status']);
      $this->iam->deleteAccessKey($args);
      $this->db->runQuery("DELETE FROM aws_keys WHERE id = '{$key->id}'");
    }
  }


  /**
   * Encrypt a string of plaintext
   */
  private function encrypt_key($plain_key) {
    $list = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890!@#$%^&*()_+-={}|\\:"<>?/.,;\'');
    $list_length = count($list);

    $salt = '';
    while(strlen($salt) < 16) {
      $salt .= $list[mt_rand(0, $list_length)];
    }

    // initalize mcrypt
    $td  = mcrypt_module_open(AWS_KEY_CIPHER, '', 'ofb', '');
    $iv  = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_DEV_URANDOM);
    $key = substr(sha1($salt.S3_CLIENT_PRIVATE_KEY, true), 0, mcrypt_enc_get_key_size($td));

    //encrypt data and close module
    mcrypt_generic_init($td, $key, $iv);
    $cipher_key = $iv.$salt.mcrypt_generic($td, $plain_key);
    mcrypt_generic_deinit($td);
    mcrypt_module_close($td);

    //base64 encode the key to prevent binary characters from breaking things
    return base64_encode($cipher_key);
  }


  /**
   *  Decrypt a string of ciphertext
   *  @param string $base64 A string of encoded text
   *  @return string A string of plaintext
   */
  private function decrypt_key($base64) {
    if(empty($base64)) {
      return '';
    }

    $td         = mcrypt_module_open(AWS_KEY_CIPHER, '', 'ofb', '');
    $iv_size    = mcrypt_enc_get_iv_size($td);
    $ciphertext = base64_decode($base64);
    $iv         = substr($ciphertext, 0, $iv_size);
    $ciphertext = substr($ciphertext, $iv_size);
    $salt       = substr($ciphertext, 0, 16);
    $key        = substr(sha1($salt.S3_CLIENT_PRIVATE_KEY, true), 0, mcrypt_enc_get_key_size($td));
    $ciphertext = substr($ciphertext, 16);

    if(strlen($iv) != $iv_size || empty($ciphertext)) {
      return '';
    }

    // decrypt data and close module
    mcrypt_generic_init($td, $key, $iv);
    $plaintext = mdecrypt_generic($td, $ciphertext);
    mcrypt_generic_deinit($td);
    mcrypt_module_close($td);
    return $plaintext;
  }
}