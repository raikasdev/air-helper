<?php
/**
 * Prioritized security related actions.
 *
 * @Author: Timi Wahalahti
 * @Date:   2020-01-10 16:00:16
 * @Last Modified by:   Timi Wahalahti
 * @Last Modified time: 2020-02-11 14:42:34
 *
 * @package air-helper
 */

/**
 *  Stop user enumeraton by ?author=(init) urls.
 *  Idea by Davide Giunchi, from plugin "Stop User Enumeration"
 *
 *  Turn off by using `remove_action( 'init', 'air_helper_stop_user_enumeration' )`
 *
 *  @since  1.7.4
 */
add_action( 'init', 'air_helper_stop_user_enumeration', 10 );
function air_helper_stop_user_enumeration() {
  if ( ! is_admin() && isset( $_SERVER['REQUEST_URI'] ) ) {
    if ( preg_match( '/(wp-comments-post)/', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) === 0 && ! empty( $_REQUEST['author'] ) ) {
      wp_safe_redirect( home_url() );
      exit;
    }
  }
} // end air_helper_stop_user_enumeration

/**
 *  Add honeypot to the login form.
 *
 *  For login to succeed, we require that the field value is exactly
 *  six characters long and has is prefixed with correct three letters.
 *  Prefix cannot be older than 30 minutes. After the prefix, following
 *  three charters can be anything. Store the prefix and generation time
 *  to the options table for later use.
 *
 *  Append the three charters with javascript and hide the field. In case
 *  user has javascript disabled, the label describes what the input is and
 *  what to do with that. This is unlikely to happen, but better safe than
 *  sorry.
 *
 *  @since  1.9.0
 */
add_action( 'login_form', 'air_helper_login_honeypot_form', 99 );
function air_helper_login_honeypot_form() {
  // Generate new prefix to honeypot if it's older than 30 minutes
  $prefix = get_option( 'air_helper_login_honeypot' );
  if ( ! $prefix || $prefix['generated'] < strtotime( '-30 minutes' ) ) {
    $prefix = air_helper_login_honeypot_reset_prefix();
  } ?>

  <p id="air_lh_name_field" class="air_lh_name_field">
    <label for="air_lh_name"><?php echo esc_html( 'Append three letters to this input', 'air-helper' ); ?></label><br />
    <input type="text" name="air_lh_name" id="air_lh_name" class="input" value="<?php echo esc_attr( $prefix['prefix'] ); ?>" size="20" autocomplete="off" />
  </p>

  <script type="text/javascript">
    var text = document.getElementById('air_lh_name');
    text.value += '<?php echo esc_attr( wp_generate_password( 3, false ) ); ?>';
    document.getElementById('air_lh_name_field').style.display = 'none';
  </script>
<?php } // end air_helper_login_honeypot_form

/**
 *  Check if login form honeypot seems legit.
 *
 *  If honeypot fails, write to combined login log and prevent simple histroy from doing its logging.
 *
 *  @since  1.9.0
 *  @param  mixed  $user      if the user is authenticated. WP_Error or null otherwise.
 *  @param  string $username  username or email address.
 *  @param  string $password  user password.
 *  @return mixed             WP_User object if honeypot passed, null otherwise.
 *
 *  phpcs:disable WordPress.Security.NonceVerification.Missing
 */
add_action( 'authenticate', 'air_helper_login_honeypot_check', 29, 3 );
function air_helper_login_honeypot_check( $user, $username, $password ) {
  // field is required
  if ( ! empty( $_POST ) ) {
    if ( isset( $_POST['woocommerce-login-nonce'] ) ) {
      return $user;
    }

    if ( ! isset( $_POST['air_lh_name'] ) ) {
      return null;
    }

    // field cant be empty
    if ( empty( $_POST['air_lh_name'] ) ) {
      air_helper_act_on_login_fail( 'honeypot_empty' );
      return null;
    }

    // value needs to be exactly six charters long
    if ( 6 !== mb_strlen( sanitize_text_field( wp_unslash( $_POST['air_lh_name'] ) ) ) ) {
      air_helper_act_on_login_fail( 'honeypot_lenght' );
      return null;
    }

    // bother database at this point
    $prefix = get_option( 'air_helper_login_honeypot' );

    // prefix is too old
    if ( $prefix['generated'] < strtotime( '-30 minutes' ) ) {
      air_helper_act_on_login_fail( 'honeypot_prefix_old' );
      return null;
    }

    // prefix is not correct
    if ( substr( sanitize_text_field( wp_unslash( $_POST['air_lh_name'] ) ), 0, 3 ) !== $prefix['prefix'] ) {
      air_helper_act_on_login_fail( 'honeypot_prefix_wrong' );
      return null;
    }
  }

  return $user;
} // end air_helper_login_honeypot_check
// phpcs: enable WordPress.Security.NonceVerification.Missing

/**
 *  Reset login form honeypot prefix on call and after succesfull login.
 *
 *  @since  1.9.0
 *  @return array  prexif generation time an prefix itself
 */
add_action( 'wp_login', 'air_helper_login_honeypot_reset_prefix' );
function air_helper_login_honeypot_reset_prefix() {
  $prefix = [
    'generated' => time(),
    'prefix'    => wp_generate_password( 3, false ),
  ];

  update_option( 'air_helper_login_honeypot', $prefix, false );

  return $prefix;
} // end air_helper_login_honeypot_reset_prefix

/**
 *  Unify and modify the login error message to be more general,
 *  so those do not exist any hint what did go wrong.
 *
 *  Turn off by using `remove_action( 'login_errors', 'air_helper_login_errors' )`
 *
 *  @since  1.8.0
 *  @return string  messag to display when login fails
 */
add_filter( 'login_errors', 'air_helper_login_errors' );
function air_helper_login_errors() {
  return __( '<b>Login failed.</b> Please contact your site admin or agency if you continue having problems.', 'air-helper' );
} // end air_helper_login_errors

/**
 *  Maybe catch some simple history login related messages and redirect them
 *  to combined login log instead of simple history databse. If no log message
 *  redirects are wanted at all, disable whole combined log with
 *  `add_filter( 'air_helper_write_to_combined_login_log', '__return_false' )`
 *
 *  Modify which messages will be redirected with "air_helper_simplehistory_message_keys_to_combined_login_log"
 *
 *  @since 2.16.0
 *  @param boolean  $do_log   If the message should be logged. Default true.
 *  @param mixed    $level    The log level. Default "info".
 *  @param string   $message  The log message. Default "".
 *  @param array    $context  The log context. Default empty array.
 *  @return boolean           If the message should be logged. Defaults to $do_log.
 */
add_action( 'simple_history/log/do_log', 'air_helper_maybe_redirect_simplehistory_to_combined_log', 10, 4 );
function air_helper_maybe_redirect_simplehistory_to_combined_log( $do_log, $level, $message, $context ) {
  if ( ! isset( $context['_message_key'] ) ) {
    return $do_log;
  }

  // allow filtering which simple history message keys should be redirected to combined log
  $message_keys_to_combined_log = apply_filters( 'air_helper_simplehistory_message_keys_to_combined_login_log', [
    'user_unknown_login_failed' => true,
  ] );

  // check that this type of message should go to combined log, based on message key existance on array
  if ( ! array_key_exists( $context['_message_key'], $message_keys_to_combined_log ) ) {
    return $do_log;
  }

  // check that this type of message should still go to combined log, based on message key is turned on (true) on array
  if ( ! $message_keys_to_combined_log[ $context['_message_key'] ] ) {
    return $do_log;
  }

  // maybe replace username in messge if exists in context
  // this type is used on "user_unknown_login_failed" message key
  if ( isset( $context['failed_username'] ) && ! empty( $context['failed_username'] ) ) {
    $message = str_replace( '{failed_username}', $context['failed_username'], $message );
  }

  // maybe replace username in messge if exists in context
  // this type is used on "user_login_failed" message key
  if ( isset( $context['login'] ) && ! empty( $context['login'] ) ) {
    $message = str_replace( '{login}', $context['login'], $message );
  }

  // try to write fo logfile
  $wrote = air_helper_write_combined_login_log( $message );

  // if write failed, let simple history do logging
  if ( false === $wrote ) {
    return $do_log;
  }

  // prevent default simple history logging
  return false;
} // end air_helper_maybe_redirect_simplehistory_to_combined_log

/**
 * Act on login failures and prevents simple history from doing its own logging.
 *
 * Currently used only when login honeypot fails for reason or another.
 *
 * If simple history is wanted to work, disable whole combined log with
 *  `add_filter( 'air_helper_write_to_combined_login_log', '__return_false' )`
 *
 * @since  2.16.0
 * @param  string $type Type of the fail
 */
function air_helper_act_on_login_fail( $type ) {
  $messages_by_type = [
    'honeypot_empty'        => 'failed to login (air honeypot empty)',
    'honeypot_lenght'       => 'failed to login (air honeypot lenght)',
    'honeypot_prefix_old'   => 'failed to login (air honeypot old prefix)',
    'honeypot_prefix_wrong' => 'failed to login (air honeypot wrong prefix)',
  ];

  $wrote = air_helper_write_combined_login_log( $messages_by_type[ $type ] );
  if ( true === $wrote ) {
    add_filter( 'simple_history/log/do_log/SimpleUserLogger', '__return_false' );
  }
} // end air_helper_act_on_login_fail

/**
 * Try to write login related messages to combined server log.
 *
 * Turn this off with `add_filter( 'air_helper_write_to_combined_login_log', '__return_false' )`
 *
 * @since  2.16.0
 * @param  string   $message  The log message.
 * @return boolean            Was the write to combined log succesfull.
 */
function air_helper_write_combined_login_log( $message ) {
  if ( ! apply_filters( 'air_helper_write_to_combined_login_log', true ) ) {
    return false;
  }

  $log_file = apply_filters( 'air_helper_combined_login_log_file', '/var/log/wordpress/wp-login.log' );

  // try to create the log file if it does not exist
  if ( ! file_exists( $log_file ) ) {
    touch( $log_file );
  }

  // bail if file creation failed
  if ( ! file_exists( $log_file ) ) {
    return false;
  }

  // bail if file is not writable
  if ( ! is_writable( $log_file ) ) {
    return false;
  }

  // get visitor ip
  if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
    $user_ip = $_SERVER['HTTP_CLIENT_IP'];
  } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
    $user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
  } else {
    $user_ip = $_SERVER['REMOTE_ADDR'];
  }

  // combine the message
  $write = wp_date( 'Y-m-d H:i:s' ) . " client: {$user_ip}";
  $write .= ', ' . mb_strtolower( $message );
  $write .= ', site ' . parse_url( get_home_url() )['host'];

  // write to log
  return file_put_contents( $log_file, $write . "\n", FILE_APPEND );
} // end air_helper_write_combined_login_log
