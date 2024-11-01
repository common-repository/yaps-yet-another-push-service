<?php
require_once 'iConnector.php';

class iOS implements iConnector {
    
    private $devices = null;
    private $message = null;
    private $logs;
    private $cert = null;
    private $cert_password = null;
    private $sound = null;
    private $server = null;
    
    public function getLogs() {
	return $this->logs;
    }

    public function addDevice($str) {
	$this->logs[] = 'Add Device "'.print_r($str, true).'"';
	if(is_array($str)) {
	    $this->devices[]=$str;
	} else {
	    $this->devices[]=array($str, 1);
	}
	
    }

    public function init() {
	$this->devices = array();
	$this->message = '';
	$this->logs = array();
	$this->sound = 'default';
	$this->isDevelopmentServer(false);
    }
    
    public function isDevelopmentServer($development = false) {
	if($development) {
	    $this->server = 'ssl://gateway.sandbox.push.apple.com:2195';
	} else {
	    $this->server = 'ssl://gateway.push.apple.com:2195';
	}
    }
    
    public function setCert($path) {
	if(file_exists($path)) {
	    $this->logs[] = 'Added Certificate "'.$path.'"';
	    $this->cert = $path;
	} else {
	    $this->logs[] = 'Certificate doesnt exists';
	}
	
    }
	
	public function setCertPassword($password) {
		$this->cert_password = $password;
	}

	public function setSound($soundname) {
		$this->sound = $soundname;
	}

    public function sendMessage($message, $postId = null) {
	if($this->cert === null) {
	    $this->logs[] = 'No Certificate!';
	    return FALSE;
	}
	
	
	
	$this->logs[] = 'Got message "'.$message.'"';
	// Open Connection
	$ctx = stream_context_create();
	//stream_context_set_option($ctx, 'ssl', 'local_cert', 'apns-pub.pem');
	stream_context_set_option($ctx, 'ssl', 'local_cert', $this->cert);
	
	if($this->cert_password !== null) {
	    stream_context_set_option($ctx, 'ssl', 'passphrase', $this->cert_password);
	}

	$fp = stream_socket_client($this->server, $err, $errstr, 60, STREAM_CLIENT_CONNECT, $ctx);


	if (!$fp) {
	    $this->logs[] = 'Connection to Apple Server failed with Error: "'.$errstr.'"';
	    return FALSE;
	} else {
	    $this->logs[] = 'Connection to Apple Server created';
	}
	foreach($this->devices as $device) {
	    $body = $this->_createBody($message, ((isset($device[1])) ? $device[1] : 1), $this->sound, $postId);
	    $payload = json_encode($body);
	    
	    $payloadLength = strlen($payload);
	    if($payloadLength>=255) {
		 $message = substr($message, 0, strlen($message)-($payloadLength-255)-4);
		 $message .= " ...";
		$body = $this->_createBody($message, ((isset($device[1])) ? $device[1] : 1), $this->sound, $postId);
		 $payload = json_encode($body);
	    }
	    
	    
	    
	    $token = $device[0];
	    $msg = chr(0) . pack("n", 32) . pack('H*', str_replace(' ', '', $token)) . pack("n", strlen($payload)) . $payload;

	    //var_dump($msg);
	    // Send Message
	    $return = fwrite($fp, $msg);
	    
	    if(!$return) {
		$this->logs[] = 'Message send failed';
	    } else {
		$this->logs[]='Message sent!';
	    }
	}

	fclose($fp);
	$this->logs[] = 'All Message sent';
    }
    
    private function _createBody($message, $badge, $sound, $postid = null) {
	$body = array();
	$body['aps'] = array();
	$body['aps']['alert'] = $message;
	$body['aps']['badge'] = $badge;
	$body['aps']['sound'] = $sound;
	if($postid !== null)
	    $body['postId'] = $postid;
	
	return $body;
    }
}