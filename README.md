## Credit ##
This plugin has been forked from the Amazon S3 plugin (http://wordpress.org/extend/plugins/amazon-s3-and-cloudfront/) and modified to use IBM Object Storage instead of S3.

### Media Storage on IBM Object Storage ###
Contributors: aahamilt, clement360, alewitt  
Tags: uploads, Openstack, swift, mirror, admin, media, remote, storage  
Requires at least: 3.5  
Tested up to: 4.4  
License: GPLv3  
Copies files to IBM Object Storage on Bluemix as they are uploaded to the Media Library.

## Description ##

This plugin automatically copies images, videos, documents, and any other media added through WordPress' media uploader to Softlayer's implementation of [Openstack Swift](http://www.openstack.org/software/openstack-storage/). It then automatically replaces the URL to each media file with their respective Softlayer URL. Image thumbnails are also copied to Swift and delivered through Swift.

Uploading files *directly* to your Swift account is not currently supported by this plugin. They are uploaded to your application first, then copied to Swift. However, once they have been uploaded to Swift, they will be removed from the application. Files are served over https.

* This plugin has been written for the Openstack Swift API using [php-opencloud/openstack](https://github.com/php-opencloud/openstack), but was originally a fork of the [Amazon S3 and Cloudfront](https://wordpress.org/plugins/amazon-s3-and-cloudfront/) plugin written by Brad Touesnard. It is designed to work with Bluemix, and will not work outside of the Bluemix
environment without effort by the developer as it depends on services provided by Bluemix.

## Installation ##

This plugin is built in to the WordPress boilerplate on Bluemix. It should require no effort on your half to work properly.

You can access the settings page on the Object Storage option selection in the admin settings panel.

## Uninstallation ##

This plugin is designed to NOT be disabled. Disabling the plugin means that files will be stored on the applications local filesystem. Restarting your app could result in losing all of the files you currently have saved that aren't uploaded to Object Storage. However, if you wish to disable this plugin, then remove it from wp-content/plugins/wp-bluemx-objectstorage.


## Changelog ##

#### 0.4 - 2015-11-30 ####
* changed how wordpress authenticates with ibm object storage. IBM object storage transitioned from v1 to v3, and at that time changed how they authenticate to match how the open source standard authenticates. This update follows that change.

#### 0.3 - 2015-2-11 ####
* added wordpress dependency to ensure wordpress installed before objectstorage

#### 0.2 - 2014-11-04 ####
* Added a default container to upload images to. Object Storage will now automatically upload media files to "WordPress" until changed to a new container.
* Renamed plugin and folder structure
* Removed unnecessary zendservice library files from the plugin itself - install them from Composer. Soon, the modified openstack library will also be removed and installed via a package manager.
* Bug fixes

#### 0.1 - 2014-09-16 ####
* Forked from [Amazon S3 and Cloudfront](https://wordpress.org/plugins/amazon-s3-and-cloudfront/)
* Work with Openstack Swift and Bluemix
