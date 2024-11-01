<?php
/*
  Plugin Name: Yet Another Push Service
  Plugin URI: http://marcel.kussin.net/development-yaps/
  Description: This plugin makes integration in Push-Apps simple and easy.
  Version: 0.1.22
  Author: Marcel Kussin & Lars Bergelt
  Author URI: http://marcel.kussin.net
  License: GPL2
 */

require_once 'connectors/ios.php';

global $jal_db_version;
$jal_db_version = "0.1.22";

/**
 * Thanks to David Gwyerand his "Plugin Options Starter Kit"
 * 
 * @author David Gwyer
 * @global type $wp_version
 */
function requires_wordpress_version() {
    global $wp_version;
    $plugin = plugin_basename(__FILE__);
    $plugin_data = get_plugin_data(__FILE__, false);

    if (version_compare($wp_version, "3.3", "<")) {
	if (is_plugin_active($plugin)) {
	    deactivate_plugins($plugin);
	    wp_die("'" . $plugin_data['Name'] . "' requires WordPress 3.3 or higher, and has been deactivated! Please upgrade WordPress and try again.<br /><br />Back to <a href='" . admin_url() . "'>WordPress admin</a>.");
	}
    }
}

add_action('admin_init', 'requires_wordpress_version');

/**
 * Installs the Table
 * @global type $wpdb
 */
function jal_install() {
    global $wpdb;
    global $jal_db_version;

    $table_name = $wpdb->prefix . "yaps_devices";

    $sql = "CREATE TABLE {$table_name} (
`id` INT( 11 ) NOT NULL AUTO_INCREMENT,
`deviceId` VARCHAR( 255 ) NOT NULL ,
`batchCount` INT( 11 ) NOT NULL ,
`type` VARCHAR( 255 ) NOT NULL ,
`certType` VARCHAR( 255 ) NOT NULL DEFAULT 'production',
`insertTime` DATETIME NOT NULL ,
`updateTime` DATETIME NOT NULL,
 UNIQUE KEY id (id)
);";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta($sql);

    add_option("jal_db_version", $jal_db_version);
}

register_activation_hook(__FILE__, 'jal_install');

function myplugin_update_db_check() {
    global $jal_db_version;
    if (get_site_option('jal_db_version') != $jal_db_version) {
	jal_install();
    }
}

add_action('plugins_loaded', 'myplugin_update_db_check');

/**
 * Delete Options
 */
function posk_delete_plugin() {
    global $wpdb;
    $options = get_option('yaps_options');

    // delete mysql-table
    $table_name = $wpdb->prefix . "yaps_devices";
    $sql = 'DROP TABLE `'.$table_name.'`';
    $wpdb->query($sql);
    
    // delete iOS certs
    @unlink($options['plugin_ios_prod_cert']);
    @unlink($options['plugin_ios_dev_cert']);
    
    // delete options
    delete_option('yaps_options');
}

register_uninstall_hook(__FILE__, 'yaps_delete_plugin');

/**
 * Delete Options
 */
function yaps_deactivate_plugin() {
    global $wpdb;
    $options = get_option('yaps_options');
    
    // delete mysql-table
    $table_name = $wpdb->prefix . "yaps_devices";
    $sql = 'DROP TABLE `'.$table_name.'`';
    $wpdb->query($sql);
    
    // delete iOS certs
    @unlink($options['plugin_ios_prod_cert']);
    @unlink($options['plugin_ios_dev_cert']);
    
    // delete options
    delete_option('yaps_options');
}
register_deactivation_hook( __FILE__, 'yaps_deactivate_plugin' );

/**
 *  LET THE MAGIC BEGIN
 */

/**
 * YAPS INIT
 */
function yaps_init() {
    global $wpdb;
   
    $wpdb->hide_errors();

    $options = get_option('yaps_options');
    
    $_allowed_keys = array('type', 'deviceId', 'badgeCount', 'development', 'passkey');

    $table_name = $wpdb->prefix . "yaps_devices";
    
    if (isset($_REQUEST['yaps-api'])) {
	$yapsApiParameters = $_REQUEST['yaps-api']; // GeT & POST

	$apiParameters = array();
	foreach ($yapsApiParameters as $key => $value) {
	    // xss filter
	    if (in_array($key, $_allowed_keys))
		$apiParameters[$key] = $value;
	}

	$return = "";
	if (count($apiParameters) > 0 && $apiParameters['passkey'] == $options['passkey'] && ($apiParameters['type'] == 'ios' || $apiParameters['type'] == 'android') && strlen($apiParameters['deviceId']) > 0) {
	    $certtype = ($apiParameters['development']=='development') ? 'development' : 'production';
	    
	    $row = $wpdb->get_row('SELECT * FROM ' . $table_name . ' WHERE deviceId="' . $apiParameters['deviceId'] . '"', OBJECT, 0);

	    $error = "";
	    if ($row == null) {

		$wpdb->insert(
			$table_name, array('deviceId' => $apiParameters['deviceId'],
		    'batchCount' => (isset($apiParameters['badgeCount'])) ? (int)$apiParameters['badgeCount'] : 0,
		    'type' => $apiParameters['type'],
		    'certType' => $certtype,
		    'insertTime' => current_time('mysql'),
		    'updateTime' => current_time('mysql')));
		$error = $wpdb->last_error;
	    } else {
		// update entry and set batchCount = 0
		$wpdb->update(
			$table_name, array(
		    'batchCount' => 0,
		    'certType' => $certtype,
		    'updateTime' => current_time('mysql')
			), array('deviceId' => $apiParameters['deviceId'])
		);
		$error = $wpdb->last_error;
	    }
	    if ($error == "") {
		$return = json_encode(array('return' => 1));
	    } else {
		$return = json_encode(array('return' => -11, 'message' => 'Database problems!'));
	    }
	} else {
	    $return = json_encode(array('return' => -1, 'message' => 'Please, More Informations!'));
	}

	if ($return != "") {
	    print $return;
	    die();
	}
    }
}

add_action('init', 'yaps_init');

/**
 * 
 * 
 * @param type $post_ID
 * @return type
 */
function yapsSendPush($post_ID, $future_publish = false) {
    global $wpdb;
    
    
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
	return;
    
    $table_name = $wpdb->prefix . "yaps_devices";

    $options = get_option('yaps_options');
    
    $queried_post = get_post($post_ID);

    if (!$queried_post) {

	$queried_post = get_post(intval($post_ID));
    }
    
    $ios_connector = new iOS();
    $ios_connector->init();
    if($options['plugin_ios_prod_cert']){
		$ios_connector->setCert($options['plugin_ios_prod_cert']);
    }
	if($option['plugin_ios_prod_cert_password']) {
		$ios_connector->setCertPassword($option['plugin_ios_prod_cert_password']);
	}
    $ios_connector->setSound($options['plugin_soundfile']);
    
    $ios_dev_connector = new iOS();
    $ios_dev_connector->init();
    if($options['plugin_ios_dev_cert']){
		$ios_dev_connector->setCert($options['plugin_ios_dev_cert']);
    }
	if($option['plugin_ios_dev_cert_password']) {
		$ios_connector->setCertPassword($option['plugin_ios_dev_cert_password']);
	}
    $ios_dev_connector->isDevelopmentServer(true);
    $ios_dev_connector->setSound($options['plugin_soundfile']);
    
    // todo get deviceId's
    // todo update batchcount
    
    // $options['push_text']
    
    if ($options['push_onupdate'] == "on" || ($future_publish === false && ($queried_post->post_date == $queried_post->post_modified)) || $future_publish === true) {

	// todo: load all devices
	$devices = $wpdb->get_results(	    "SELECT deviceId, batchCount, type, certType
					    FROM $table_name
					    "
				    );
	foreach ($devices as $device) {
	    if($device->type=='ios' && $device->certType == 'production') {
		$ios_connector->addDevice(array($device->deviceId, ++$device->batchCount));
	    }
	    if($device->type=='ios' && $device->certType == 'development') {
		$ios_dev_connector->addDevice(array($device->deviceId, ++$device->batchCount));
	    }
	}
	
	$sendPostID = null;
	if($options['push_postid'] == 'on') $sendPostID = $post_ID;
	
	if($options['plugin_ios_prod_active'] == 'on') {
		
	    if($ios_connector->sendMessage(_prepare_push_text($options['push_text'], $queried_post), $sendPostID)){
		// all ok
		}	    
	}
	
	if($options['plugin_ios_dev_active'] == 'on') {
		// removed 
	    if($ios_dev_connector->sendMessage(_prepare_push_text($options['push_text'], $queried_post), $sendPostID)){
		// all ok
	    }
	    
	}
	
	// only one time
	
	    $wpdb->query( 
		    "
			    UPDATE $table_name
			    SET batchCount=batchCount+1
			    "
		);
	
    }
}

add_action('publish_post', 'yapsSendPush');

function yapsSendPushFutureWrapper($id) {
    $options = get_option('yaps_options');
    if($options['push_onupdate'] != "on")
	yapsSendPush($id, true);
}
add_action('publish_future_post', 'yapsSendPushFutureWrapper');

// ------------------------------------------------------------
// ------------------------------------------------------------
// ----------------- Settings Page ----------------------------
// ------------------------------------------------------------
// ------------------------------------------------------------
function yaps_add_options_page() {
    add_options_page('YAPS Options', 'YAPS Options page', 'manage_options', __FILE__, 'yaps_render_option_page');
}

add_action('admin_menu', 'yaps_add_options_page');

function page_init() {
	
    register_setting('plugin_options', 'yaps_options', 'plugin_options_validate');
    add_settings_section('main_section', 'Main Settings', 'section_text_fn', __FILE__);
    add_settings_field('passkey', 'API-Password', 'setting_passkey_fn', __FILE__, 'main_section');
    add_settings_field('plugin_pushmessage', 'Push Message', 'setting_pushmessage_fn', __FILE__, 'main_section');
    add_settings_field('plugin_push_onupdate', 'Send Push Message When Updating Post', 'setting_push_onupdate_fn', __FILE__, 'main_section');
	add_settings_field('plugin_push_onpostid', 'Send Push Message with Post ID', 'setting_push_onpostid_fn', __FILE__, 'main_section');
	add_settings_field('plugin_push_soundfile', 'Push Sound Filename', 'setting_push_soundfile_fn', __FILE__, 'main_section');
    
    add_settings_section('ios_section', 'iOS Settings', 'ios_section_text_fn', __FILE__);
    add_settings_field('plugin_ios_prod_active', 'Activate Production Certificate', 'setting_ios_prod_active_fn', __FILE__, 'ios_section');
    add_settings_field('plugin_ios_prod_cert', 'Upload Production Certificate', 'setting_ios_prod_upload_fn', __FILE__, 'ios_section');
    add_settings_field('plugin_ios_prod_cert_password', 'Certificate Password', 'setting_ios_prod_password_fn', __FILE__, 'ios_section');
    add_settings_field('plugin_ios_dev_active', 'Activate Development Certificate', 'setting_ios_dev_active_fn', __FILE__, 'ios_section');
    add_settings_field('plugin_ios_dev_cert', 'Upload Development Certificate', 'setting_ios_dev_upload_fn', __FILE__, 'ios_section');
    add_settings_field('plugin_ios_dev_cert_password', 'Certificate Password', 'setting_ios_dev_password_fn', __FILE__, 'ios_section');
    
    add_settings_section('android_section', 'Android Settings', 'android_section_text_fn', __FILE__);
    add_settings_field('plugin_android_key', 'Google API Key', 'setting_android_key_fn', __FILE__, 'android_section');
}
add_action('admin_init', 'page_init');


function plugin_options_validate($input) {
    $options = get_option('yaps_options');
    // Check our textbox option field contains no HTML tags - if so strip them out
    $input['push_onupdate'] = wp_filter_nohtml_kses($input['push_onupdate']);
    $input['push_postid'] = wp_filter_nohtml_kses($input['push_postid']);
    
    $uploadedfile = $_FILES['plugin_ios_prod_cert'];
    if($uploadedfile) {
	$upload_overrides = array( 'test_form' => false, 'mimes' => array('pem' => 'application/octet-stream') );
	$movefile = wp_handle_upload( $uploadedfile, $upload_overrides );
	if ( $movefile ) {
	    $input['plugin_ios_prod_cert'] = $movefile['file'];
	} else {
	    // error
	}

    } else {
	$input['plugin_ios_prod_cert'] = $options['plugin_ios_prod_cert'];
    }
    
    if(isset($input['delete_ios_prod_certificate'])) {
	unset($input['delete_ios_prod_certificate']);
	// delete
	
	if(@unlink($options['plugin_ios_prod_cert'])){
	    unset($input['plugin_ios_prod_cert']);
	    delete_option( 'yaps_options[plugin_ios_prod_cert]' );
	}
	
    }
    
    // -------------
    
    $uploadedfile2 = $_FILES['plugin_ios_dev_cert'];
    if($uploadedfile2) {
	$upload_overrides2 = array( 'test_form' => false, 'mimes' => array('pem' => 'application/octet-stream') );
	$movefile2 = wp_handle_upload( $uploadedfile2, $upload_overrides2 );
	if ( $movefile2 ) {
	    $input['plugin_ios_dev_cert'] = $movefile2['file'];
	} else {
	    // error
	}

    } else {
	$input['plugin_ios_dev_cert'] = $options['plugin_ios_dev_cert'];
    }
    
    if(isset($input['delete_ios_dev_certificate'])) {
	unset($input['delete_ios_dev_certificate']);
	// delete
	
	if(@unlink($options['plugin_ios_dev_cert'])){
	    unset($input['plugin_ios_dev_cert']);
	    delete_option( 'yaps_options[plugin_ios_dev_cert]' );
	}
	
    }
    
    return $input; // return validated input
}

// Section HTML, displayed before the first option
function  section_text_fn() {
	echo '<p>Basic settings below:</p>';
}

function setting_passkey_fn() {
    global $jal_db_version;
	
    $options = get_option('yaps_options');
	echo "<input id='passkey' name='yaps_options[passkey]' type='text' value='".$options['passkey']."' />";
	wp_enqueue_script( 'yaps-password-generator', plugins_url( 'yaps.js', __FILE__ ), array( 'jquery' ), $jal_db_version, true );
}

// CHECKBOX - Name: plugin_options[chkbox1]
function setting_push_onupdate_fn() {
	$options = get_option('yaps_options');
	if($options['push_onupdate']) { $checked = ' checked="checked" '; }
	echo "<input ".$checked." id='plugin_push_onupdate' name='yaps_options[push_onupdate]' type='checkbox' />";
}

function setting_push_onpostid_fn() {
	$options = get_option('yaps_options');
	if($options['push_postid']) { $checked = ' checked="checked" '; }
	echo "<input ".$checked." id='plugin_push_onpostid' name='yaps_options[push_postid]' type='checkbox' />";
}

// TEXTAREA - Name: plugin_options[text_area]
function setting_pushmessage_fn() {
	$options = get_option('yaps_options');
	echo "<textarea id='plugin_pushmessage' name='yaps_options[push_text]' rows='7' cols='50' type='textarea'>{$options['push_text']}</textarea>";
	echo "<br /><small>Placeholder: %%title%%, %%createdate%%, %%updatedate%%</small>";
}

function setting_push_soundfile_fn() {
	$options = get_option('yaps_options');
	if ($options['plugin_soundfile']) 
		$soundFileValue = $options['plugin_soundfile'];
	else
		$soundFileValue = "default";
	echo "<input id='plugin_soundfile' name='yaps_options[plugin_soundfile]' type='text' value='".$soundFileValue."' />";
	echo "<br /><small>Standard: default</small>";
}

// ------------

function ios_section_text_fn() {
    echo '<p>iOS settings below:</p>';
}

function setting_ios_prod_active_fn() {
    $options = get_option('yaps_options');
    if($options['plugin_ios_prod_active']) { $checked = ' checked="checked" '; }
    echo "<input ".$checked." id='plugin_ios_prod_active' name='yaps_options[plugin_ios_prod_active]' type='checkbox' />";
}

function setting_ios_prod_upload_fn() {
    $options = get_option('yaps_options');
    if ($options['plugin_ios_prod_cert']) {
	echo "<input id='delete_ios_prod_certificate' name='yaps_options[delete_ios_prod_certificate]' type='checkbox' value='1' /> delete ".basename($options['plugin_ios_prod_cert']);
    } else {
	echo "<input id='plugin_ios_prod_cert' type='file' name='plugin_ios_prod_cert' />";
    }
}

function setting_ios_prod_password_fn() {
    $options = get_option('yaps_options');
    echo "<input id='plugin_ios_prod_cert_password' name='yaps_options[plugin_ios_prod_cert_password]' size='40' type='text' value='{$options['plugin_ios_prod_cert_password']}' />";
}

function setting_ios_dev_active_fn() {
    $options = get_option('yaps_options');
    if($options['plugin_ios_dev_active']) { $checked = ' checked="checked" '; }
    echo "<input ".$checked." id='plugin_ios_dev_active' name='yaps_options[plugin_ios_dev_active]' type='checkbox' />";
}

function setting_ios_dev_upload_fn() {
    $options = get_option('yaps_options');
    if ($options['plugin_ios_dev_cert']) {
	echo "<input id='delete_ios_dev_certificate' name='yaps_options[delete_ios_dev_certificate]' type='checkbox' value='1' /> delete ".basename($options['plugin_ios_dev_cert']);
    } else {
	echo "<input id='plugin_ios_dev_cert' type='file' name='plugin_ios_dev_cert' />";
    }
}

function setting_ios_dev_password_fn() {
    $options = get_option('yaps_options');
    echo "<input id='plugin_ios_dev_cert_password' name='yaps_options[plugin_ios_dev_cert_password]' size='40' type='text' value='{$options['plugin_ios_dev_cert_password']}' />";
}

// ---------------

function android_section_text_fn() {
    echo '<p>Android settings below:</p>';
}

function setting_android_key_fn() {
    $options = get_option('yaps_options');
    //echo "<input id='plugin_android_key' name='yaps_options[android_key]' size='40' type='text' value='{$options['android_key']}' />";
    echo "<input id='plugin_android_key' name='yaps_options[android_key]' size='40' type='text' value='coming soon' disabled />";
}

// DROP-DOWN-BOX - Name: plugin_options[dropdown1]
function  setting_dropdown_fn() {
	$options = get_option('yaps_options');
	$items = array("Red", "Green", "Blue", "Orange", "White", "Violet", "Yellow");
	echo "<select id='drop_down1' name='yaps_options[dropdown1]'>";
	foreach($items as $item) {
		$selected = ($options['dropdown1']==$item) ? 'selected="selected"' : '';
		echo "<option value='$item' $selected>$item</option>";
	}
	echo "</select>";
}

// TEXTBOX - Name: plugin_options[text_string]
function setting_string_fn() {
	$options = get_option('yaps_options');
	echo "<input id='plugin_text_string' name='yaps_options[text_string]' size='40' type='text' value='{$options['text_string']}' />";
}

// PASSWORD-TEXTBOX - Name: plugin_options[pass_string]
function setting_pass_fn() {
	$options = get_option('yaps_options');
	echo "<input id='plugin_text_pass' name='yaps_options[pass_string]' size='40' type='password' value='{$options['pass_string']}' />";
}



// CHECKBOX - Name: plugin_options[chkbox2]
function setting_chk2_fn() {
	$options = get_option('yaps_options');
	if($options['chkbox2']) { $checked = ' checked="checked" '; }
	echo "<input ".$checked." id='plugin_chk2' name='yaps_options[chkbox2]' type='checkbox' />";
}

// RADIO-BUTTON - Name: plugin_options[option_set1]
function setting_radio_fn() {
	$options = get_option('yaps_options');
	$items = array("Square", "Triangle", "Circle");
	foreach($items as $item) {
		$checked = ($options['option_set1']==$item) ? ' checked="checked" ' : '';
		echo "<label><input ".$checked." value='$item' name='yaps_options[option_set1]' type='radio' /> $item</label><br />";
	}
}

function yaps_render_option_page() {
    ?>
        <div class="wrap">
    <?php screen_icon(); ?>
            <h2>Settings › YAPS</h2>			
            <form action="options.php" method="post" enctype="multipart/form-data">
    <?php settings_fields('plugin_options'); ?>
    <?php do_settings_sections(__FILE__); ?>
    		<p class="submit">
    			<input name="Submit" type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>" />
    		</p>
    		</form>
        </div>
    <?php
}

// --------------------
// HELPER
// --------------------

function _seq_remove_slashes_($string_ = '') { 
   $orig = $string_; 
   $stripped = stripslashes($orig); 
   if ($orig != $stripped) { 
       $escaped = addslashes($stripped); 
       if ($orig == $escaped) { 
           $sec_value = stripslashes($escaped); 
       } else { 
           $sec_value = $orig; 
       } 
   } else { 
       $sec_value = $orig; 
   } 
   return $sec_value; 
}

/**
 * 
 * @param type $cert
 * @param type $deviceToken
 * @param type $message
 * @param type $batchcount
 * @param type $sound
 * @param type $development_mode
 * @return boolean
 */
function _send_ios_push($cert, $deviceToken, $message = '', $batchcount, $sound = 'chime', $development_mode = false) {
    
     if(!is_array($deviceToken)) {
	$deviceToken = array($deviceToken);
    }
    
    if(!is_array($batchcount)) {
	$batchcount = array($batchcount);
    }

    // Open Connection
    $ctx = stream_context_create();
    //stream_context_set_option($ctx, 'ssl', 'local_cert', 'apns-pub.pem');
    stream_context_set_option($ctx, 'ssl', 'local_cert', $cert);
    if($development_mode) {
	$fp = stream_socket_client('ssl://gateway.sandbox.push.apple.com:2195', $err, $errstr, 60, STREAM_CLIENT_CONNECT, $ctx);
    } else {
	$fp = stream_socket_client('ssl://gateway.push.apple.com:2195', $err, $errstr, 60, STREAM_CLIENT_CONNECT, $ctx);
    }

    if (!$fp) {
	return FALSE;
    }
    
    foreach($deviceToken as $key => $token) {
	$body = array();
	$body['aps'] = array('alert' => $message);
	$body['aps']['badge'] = (isset($batchcount[$key])) ? $batchcount[$key] : 1;
	$body['aps']['sound'] = $sound;

	$body['tags'] = array('entryId' => 123);

	$payload = json_encode($body);
	$msg = chr(0) . pack("n", 32) . pack('H*', str_replace(' ', '', $token)) . pack("n", strlen($payload)) . $payload;

	// Send Message
	fwrite($fp, $msg);
    }
    
    fclose($fp);
    
    return TRUE;
}

function _prepare_push_text($text, $post)
{
    $text = str_replace('%%title%%', $post->post_title, $text);
    $text = str_replace('%%createdate%%', $post->post_date, $text);
    $text = str_replace('%%updatedate%%', $post->post_modified, $text);
    
    // todo: %%batchCount%%
    //$text = str_replace('%%batchCount%%', '', $text);
    
    return $text;
}