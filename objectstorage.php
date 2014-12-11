<?php
/*
Plugin Name: IBM Object Storage
Description: Automatically copies media uploads to IBM Object Storage
Author: IBM
Author URI: http://ibm.com/jstart
Plugin URI: https://github.com/ibmjstart/wp-bluemix-objectstorage
Version: 0.2
Network: True
License: GPLv3

// Copyright (c) 2014 IBM. All rights reserved.
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************
//
// Forked from the Amazon S3 plugin (http://wordpress.org/extend/plugins/amazon-s3-and-cloudfront/)
// and modified to use the Object Storage service on IBM Bluemix
*/

function swift_init( ) {
    global $swift;
    require_once 'classes/swift.php';
    $swift = new Swift( __FILE__ );

    if(!get_option('object_storage')){
      //If this isn't in the database, create all of the default values we need.
      $swift->swift_create_bucket('WordPress');
      $options = array(
        'bucket' => 'WordPress',
        'expires' => '1',
        'object-prefix' => 'wp-content/uploads/',
        'copy-to-swift' => '1',
        'serve-from-swift' => '1',
        'remove-local-file' => '1'
      );
      update_option('object_storage', $options);
    }}

add_action( 'init', 'swift_init' );

function hide_objectstorage_deactivate($hook){
  if($hook == 'plugins.php'){
    wp_enqueue_script( 'hide-deactivation', plugins_url('hide-deactivation.js', __FILE__), 'jquery');
  }
}

add_action('admin_enqueue_scripts', 'hide_objectstorage_deactivate');
