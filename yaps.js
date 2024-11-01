/**
 * Scripting for the "YAPS Password Generator" button
 * @package WordPress
 * @subpackage Yet Another Push Service
 * @author Lars Bergelt
 * @author URI: http://www.lbergelt.de
 */

jQuery( function ( $ ) {
  var rand = function() {
      return Math.random().toString(36).substr(2);
  };

  var token = function() {
      return rand() + rand();
  };
  
  $('#passkey').after( '<button id="yaps_password_generator" class="button-primary" value="Generate Key" role="button">Generate Key</button><br />' );

  $('#yaps_password_generator').on( 'click', function () {
	  $('#passkey').val(token());
  });
});