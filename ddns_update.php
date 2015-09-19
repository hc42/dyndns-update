<?php

define ('DB_DB', 'ddns');
define ('DB_TABLE', 'ddns');
define ('DB_HOST', 'localhost');
define ('DB_USER', '<YOUR_USER_NAME>');
define ('DB_PASSWD', '<YOUR_PASSWORD>');
define ('DNSTIMEOUT', '600');
define ('DNSKEY', '<PATH_TO_YOUR_DNS_KEY_FILE>');

/**
 * Perform Dyndns update
 */
final class Ddns_update {

  /**
   * exit with error message
   * @param $msg string message to output
   * @param $code integer http code
   */
  static private function _error_exit ($msg, $code = 400) {
    http_response_code ($code);
    echo $msg;
    exit ();
  }

  /**
   * get an input var from http request
   * @param $key string variable name
   * @param $required boolean error exit if variable not set
   * @return String variable value, null if unset or empty
   */
  static private function _get_var ($key, $required) {
    if (! isset ($_REQUEST[$key])) {
      if ($required)
        static::_error_exit ($key.' is not set');
      return null;
    }
    $var = $_REQUEST[$key];
    $var = filter_var ($var, FILTER_SANITIZE_STRING);
    if ( ! is_string ($var))
      static::_error_exit ($key.' is invalid');
    $var = trim ($var);
    if (empty ($var)) {
      if ($required)
        static::_error_exit ($key.' is empty');
      return null;
    }
    return $var;
  }

  /**
   * check if given value is a valid ip
   * @param $ip string value to be tested
   * @param $v6 boolean false: test ipv4, true: test ipv6
   * @return boolean valid ip
   */
  static private function _validate_ip ($ip, $v6) {
    return is_string ($ip) && (
        (!$v6 && filter_var ($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ||
        ($v6 && filter_var ($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
    );
  }

  /**
   * get the ddnsupdate params
   * @return array of request params
   */
  static private function _get_request_params () {
    $data = array ();
    $keys = array (
      'password' => true,
      'domain'   => true,
      'ip'       => false,
      'ipv4'     => false,
      'ipv6'     => false,
    );

    foreach ($keys as $key => $required)
      $data[$key] = static::_get_var ($key, $required);

    if (is_string ($data['ip'])) {
      if (static::_validate_ip ($data['ip'], false))
        $data['ipv4'] = $data['ip'];
      else if (static::_validate_ip ($data['ip'], true))
        $data['ipv6'] = $data['ip'];
      else if (is_string ($data['ip']))
        static::_error_exit ('given ip is not a valid ip');
    }

    if (is_string ($data['ipv4']) && ! static::_validate_ip ($data['ipv4'], false))
      static::_error_exit ('given ipv4 is not a valid ip');

    if (is_string ($data['ipv6']) && ! static::_validate_ip ($data['ipv6'], true))
      static::_error_exit ('given ipv6 is not a valid ip');

    if ( ! is_string ($data['ipv4']) && ! is_string ($data['ipv6']))
      static::_error_exit ('no ip given');

    return $data;
  }

  /**
   * Open a new db connection
   * @return mysqli connection object
   */
  static private function _connect_to_db () {
    $mysqli = new mysqli (DB_HOST, DB_USER, DB_PASSWD, DB_DB);
    if ($mysqli->connect_error)
      static::_error_exit ('DB connection failed', 500);
    return $mysqli;
  }

  /**
   * Update the ip values in the db if the given login data is correct
   * @param $data array with request data
   * @return boolean if successful updated
   */
  static private function _update_database ($data) {
    $mysqli = static::_connect_to_db ();
    // validate login data
    $query = 'SELECT `password` FROM `'.DB_TABLE.'` WHERE `domain` = \''.$mysqli->escape_string ($data['domain']).'\'';
    $result = $mysqli->query ($query);
    if ( ! $result)
      return FALSE;
    $row = $result->fetch_assoc ();
    if ( ! (isset ($row) && isset ($row['password']) && password_verify ($data['password'], $row['password'])))
      return FALSE;

    // save update
    $ipv4 = is_string ($data['ipv4']) ? ('`ipv4` = \''.$mysqli->escape_string ($data['ipv4']).'\', `lastupdate_ipv4` = NOW()') : '';
    $ipv6 = is_string ($data['ipv6']) ? ('`ipv6` = \''.$mysqli->escape_string ($data['ipv6']).'\', `lastupdate_ipv6` = NOW()') : '';
    $comma = (is_string ($data['ipv4']) && is_string ($data['ipv6'])) ? ', ' : '';

    $query = 'UPDATE `'.DB_TABLE."` SET $ipv4 $comma $ipv6 WHERE `domain` = '".$mysqli->escape_string ($data['domain']).'\'';

    if ( ! $mysqli->query ($query))
      static::_error_exit ('DB update error', 500);
    $ok = $mysqli->affected_rows > 0;
    $mysqli->close ();

    return $ok;
  }

  /**
   * Update the dns server
   * @param $data array with request data
   */
  static private function _apply_update ($data) {
    $_get_update_string = function ($data, $v6) {
      return
      'update delete '.$data['domain'].' '.($v6 ? 'AAAA' : 'A')."\n".
      'update add '.$data['domain'].' '.DNSTIMEOUT.' '.($v6 ? 'AAAA' : 'A').' '.$data[($v6 ? 'ipv6' : 'ipv4')]."\n";
    };

    $ipv4 = is_string ($data['ipv4']) ? $_get_update_string ($data, false) : '';
    $ipv6 = is_string ($data['ipv6']) ? $_get_update_string ($data, true) : '';
    $request = "echo -e \"\n$ipv4 $ipv6 show\nsend\" | /usr/local/bin/nsupdate -k ".DNSKEY;
    passthru ($request);
  }

  /**
   * Setup the database
   */
  static private function _setup () {
    $mysqli = static::_connect_to_db ();

    $query = 'CREATE TABLE IF NOT EXISTS `ddns` ('.
      '`domain` varchar(255) NOT NULL, '.
      '`password` varchar(255) NOT NULL, '.
      '`ipv4` varchar(255) DEFAULT NULL, '.
      '`ipv6` varchar(266) DEFAULT NULL, '.
      '`lastupdate_ipv4` datetime DEFAULT NULL, '.
      '`lastupdate_ipv6` datetime DEFAULT NULL, '.
      'PRIMARY KEY (`domain`))';
    if ( ! $mysqli->query ($query))
      static::_error_exit ('DB error', 500);
  }

  /**
   * execute all steps of the update procedure
   */
  static public function execute () {
    $cmd_options = getopt ('c:d:p:');

    // create command: Generate DB insert query and return (NO DIRECT INSERT HERE!)
    // php -c=create -d=example.com -p='change_me'
    if (array_key_exists ('c', $cmd_options) && $cmd_options['c'] === 'create') {
      if ( ! array_key_exists ('p', $cmd_options) OR ! array_key_exists ('d', $cmd_options)) {
        echo 'required options d for new domain and p for new password';
      }
      $new_hash = password_hash ($cmd_options['p'], PASSWORD_BCRYPT);
      $create_query = 'INSERT INTO `'.DB_TABLE.'` (`domain`, `password`) VALUES (\''.$cmd_options['d'].'\', \''.$new_hash.'\')';
      echo $create_query;
      return;
    }

   // Setup command: try create DB table
   // php -c=setup
   if (array_key_exists ('c', $cmd_options) && $cmd_options['c'] === 'setup') {
      static::_setup ();
      return;
    }

    // otherwise try update
    $data = static::_get_request_params ();
    if (static::_update_database ($data))
      static::_apply_update ($data);
    else
      static::_error_exit ('invalid request', 403);
  }
}

// execute
Ddns_update::execute ();
