<?php
/*
Plugin Name: IBM Object Storage
Description: Automatically copies media uploads to IBM Object Storage for remote storage. This plugin is designed to NOT be disabled. Disabling it could make you lose all of your data.
As such, selecting deactivate will not deactivate the plugin; if you would like to do so please remove it from your application and repush.
Author: Austin Hamilton
Author URI: http://www-01.ibm.com/software/ebusiness/jstart/
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
// Forked from an Amazon S3 plugin (http://wordpress.org/extend/plugins/amazon-s3-and-cloudfront/)
// by Brad Touesnard and written to use Softlayer/OpenStack Swift object storage instead of S3.
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
