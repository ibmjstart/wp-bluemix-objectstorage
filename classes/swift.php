<?php
require_once ABSPATH.'vendor/autoload.php';
require_once 'swift-plugin-base.php';

class Swift extends Swift_Plugin_Base {
	private $swiftClient, $storageUrl, $uploadHash;

	const SETTINGS_KEY = 'object_storage';

	function __construct( $plugin_file_path ) {
		parent::__construct( $plugin_file_path );

		$this->uploadHash = $this->swift_get_hash();
		add_action( 'admin_menu', array( $this, 'swift_admin_menu' ) );

		$this->plugin_title = __( 'IBM Object Storage', 'swift' );
		$this->plugin_menu_title = __( 'Object Storage', 'swift' );

		add_action( 'wp_ajax_swift-create-bucket', array( $this, 'swift_ajax_create_bucket' ) );

		add_filter( 'wp_get_attachment_url', array( $this, 'wp_get_attachment_url' ), 9, 2 );
		add_filter( 'wp_update_attachment_metadata', array( $this, 'wp_update_attachment_metadata' ), 100, 2 );
		add_filter( 'delete_attachment', array( $this, 'swift_delete_attachment' ), 20 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'wp_calculate_image_srcset' ), 10, 5 );
	}
		private function swift_get_hash(){
			return substr(md5(uniqid(mt_rand(), true)), 0, 4);
		}

		private function swift_get_vcap_variable( $variable ){
			$vcap = getenv("VCAP_SERVICES");
			$data = json_decode($vcap, true);

			return $data[$variable]['0'];	//Get back the vcap variable you asked for.
		}

		function swift_get_setting( $key ) {
			$settings = $this->swift_get_settings();

			// If legacy setting set, migrate settings
			if ( isset( $settings['wp-uploads'] ) && $settings['wp-uploads'] && in_array( $key, array( 'copy-to-swift', 'serve-from-swift' ) ) ) {
				return '1';
			}

			// Default object prefix
			if ( 'object-prefix' == $key && !isset( $settings['object-prefix'] ) ) {
						$uploads = wp_upload_dir();
						$parts = parse_url( $uploads['baseurl'] );
						return substr( $parts['path'], 1 ) . '/';
			}

			if ( 'bucket' == $key && defined( 'swift_BUCKET' ) ) {
				$value = swift_BUCKET;
			}
			else {
				$value = parent::swift_get_setting( $key );
			}

			return apply_filters( 'swift_setting_' . $key, $value );
		}

		function swift_delete_attachment( $post_id ) {
			if ( !$this->swift_plugin_setup() ) {
					return;
			}

			$backup_sizes = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );

			$intermediate_sizes = array();
			foreach ( get_intermediate_image_sizes() as $size ) {
					if ( $intermediate = image_get_intermediate_size( $post_id, $size ) )
							$intermediate_sizes[] = $intermediate;
			}

			if ( !( $swiftObject = $this->swift_get_attachment_info( $post_id ) ) ) {
					return;
			}

			$swift_path = dirname( $swiftObject['key'] );
			$objects = array();

			// remove intermediate and backup images if there are any
			foreach ( $intermediate_sizes as $intermediate ) {
					$objects[] = array(
						'Key' => path_join( $swift_path, $intermediate['file'] )
					);
			}

			if ( is_array( $backup_sizes ) ) {
					foreach ( $backup_sizes as $size ) {
						$objects[] = array(
							'Key' => $swift_path
						);
					}
			}

			// Try removing any @2x images but ignore any errors
			if ( $objects ) {
				$hidpi_images = array();
				foreach ( $objects as $object ) {
					$hidpi_images[] = array(
						'Key' => $this->swift_get_hidpi_file_path( $object['Key'] )
					);
				}

				try {
					foreach($hidpi_images as $image) {
						$this->swift_get_client()
						     ->getContainer($swiftObject['bucket'])
					             ->getObject($image['Key'])
					             ->delete();
					}
				}
				catch ( Exception $e ) {}
			}

			$objects[] = array(
				'Key' => $swiftObject['key']
			);

			try {
				foreach ($objects as $object){
					$this->swift_get_client()
					     ->getContainer($swiftObject['bucket'])
				             ->getObject($object['Key'])
				             ->delete();
				}
			}
			catch ( Exception $e ) {
				error_log( 'Error removing files from Swift: ' . $e->getMessage() );
				return;
			}
	
					delete_post_meta( $post_id, 'swift_info' );
			}

		/*
		* 	When WordPress uploads a file on the local filesystem with the same name as something that has already been uploaded,
		*   the filesystem can automatically mark file 2 as "file_copy." However, when you upload an object to Swift, the object
		*   store does not make this distinction. It might consider the new file an "update," hence it would overwrite the old file.
		*		to resolve this, create a unique hash for each uploaded file, so that if you upload "file.png" twice, each object would have
		*   the following names:
		*				6f4t/file1.png
		*				7dmq/file1.png
		*		This makes each file unique and allows you to handle accidentally overwriting files which could mess with previous posts.
		*/
		function wp_update_attachment_metadata( $data, $post_id ) {
				if ( !$this->swift_get_setting( 'copy-to-swift' ) || !$this->swift_plugin_setup() ) {
						return $data;
				}

				$time = $this->swift_get_attachment_folder_time( $post_id );
				$time = date( 'Y/m', $time );

		$prefix = ltrim( trailingslashit( $this->swift_get_setting( 'object-prefix' ) ), '/' );
				$prefix .= ltrim( trailingslashit( $this->swift_get_dynamic_prefix( $time ) ), '/' );

				if ( $this->swift_get_setting( 'object-versioning' ) ) {
					$prefix .= $this->swift_get_object_version_string( $post_id );
				}

				$type = get_post_mime_type( $post_id );

				$file_path = get_attached_file( $post_id, true );

				$acl = apply_filters( 'wps3_upload_acl', 'public-read', $type, $data, $post_id, $this ); // Old naming convention, will be deprecated soon
				$acl = apply_filters( 'swift_upload_acl', $acl, $data, $post_id );

				//By default, this bucket is initialized to "WordPress" and if a user chooses
				//to not define the bucket to upload files to, Object Storage will continue to use it.
				//A user is welcome to define a bucket if they want.
				$this->swift_create_bucket('WordPress');
				$bucket = $this->swift_get_setting( 'bucket' );

				$file_name = basename( $file_path );

				$args = array(
			'Bucket'     => $bucket,
			'Key'        => $prefix . $this->uploadHash . '/' . $file_name,
			'SourceFile' => $file_path,
			'ACL'        => $acl
				);

				// If far future expiration checked (10 years)
		if ( $this->swift_get_setting( 'expires' ) ) {
			$args['Expires'] = date( 'D, d M Y H:i:s O', time()+315360000 );
		}

				$files_to_remove = array();
				if (file_exists($file_path)) {
						$files_to_remove[] = $file_path;
						try {
								$uploaded = file_get_contents($args['SourceFile'] );
								$newKey = $args['Key'];
								$this->swift_get_client()
								     ->getContainer($args['Bucket'])
								     ->createObject([
								     		'name'    => $newKey,
    										'content' => $uploaded
								     		]);
						}
						catch ( Exception $e ) {
								error_log( 'Error uploading ' . $file_path . ' to Swift: ' . $e->getMessage() );
								return $data;
						}
				}

				delete_post_meta( $post_id, 'swift_info' );

				add_post_meta( $post_id, 'swift_info', array(
					'bucket' => $bucket,
					'key' => $prefix . $this->uploadHash . '/' . $file_name
				) );

		$additional_images = array();

				if ( isset( $data['thumb'] ) && $data['thumb'] ) {
			$path = str_replace( $file_name, $data['thumb'], $file_path );
					$additional_images[] = array(
				'Key'        => $prefix . $this->uploadHash . '/' . $data['thumb'],
				'SourceFile' => $path
					);
					$files_to_remove[] = $path;
				}
				elseif ( !empty( $data['sizes'] ) ) {
					foreach ( $data['sizes'] as $size ) {
				$path = str_replace( $file_name, $size['file'], $file_path );
						$additional_images[] = array(
					'Key'        => $prefix . $this->uploadHash . '/' . $size['file'],
					'SourceFile' => $path
						);
						$files_to_remove[] = $path;
						}
				}

				// Because we're just looking at the filesystem for files with @2x
				// this should work with most HiDPI plugins
				if ( $this->swift_get_setting( 'hidpi-images' ) ) {
					$hidpi_images = array();

					foreach ( $additional_images as $image ) {
						$hidpi_path = $this->swift_get_hidpi_file_path( $image['SourceFile'] );
						if ( file_exists( $hidpi_path ) ) {
							$hidpi_images[] = array(
						'Key'        => $this->swift_get_hidpi_file_path( $image['Key'] ),
						'SourceFile' => $hidpi_path
							);
							$files_to_remove[] = $hidpi_path;
						}
					}

			$additional_images = array_merge( $additional_images, $hidpi_images );
		}

				foreach ( $additional_images as $image ) {
			try {
				$args = array_merge( $args, $image );

				$uploaded = file_get_contents($args['SourceFile'] );
				$newKey = $args['Key'];
		  		$this->swift_get_client()
				     ->getContainer($args['Bucket'])
				     ->createObject([
				     		'name'    => $newKey,
    						'content' => $uploaded
				     		]);

			}
			catch ( Exception $e ) {
				error_log( 'Error uploading ' . $args['SourceFile'] . ' to Swift: ' . $e->getMessage() );
			}
				}

				if ( $this->swift_get_setting( 'remove-local-file' ) ) {
					$this->swift_remove_local_files( $files_to_remove );
				}

				return $data;
		}

		function swift_remove_local_files( $file_paths ) {
			foreach ( $file_paths as $path ) {
				if ( !@unlink( $path ) ) {
					error_log( 'Error removing local file ' . $path );
				}
			}
		}

		function swift_get_hidpi_file_path( $orig_path ) {
			$hidpi_suffix = apply_filters( 'swift_hidpi_suffix', '@2x' );
			$pathinfo = pathinfo( $orig_path );

			// return $pathinfo['dirname'] . '/' . $pathinfo['filename'] . $hidpi_suffix . '.' . $pathinfo['extension'];
			return $pathinfo['dirname'] . '/' . $pathinfo['filename'] . $hidpi_suffix;

		}

		function swift_get_object_version_string( $post_id ) {
		if ( get_option( 'uploads_use_yearmonth_folders' ) ) {
			$date_format = 'dHis';
		}
		else {
			$date_format = 'YmdHis';
		}

		$time = $this->swift_get_attachment_folder_time( $post_id );

		$object_version = date( $date_format, $time ) . '/';
		$object_version = apply_filters( 'swift_get_object_version_string', $object_version );

		return $object_version;
		}

		// Media files attached to a post use the post's date
		// to determine the folder path they are placed in
		function swift_get_attachment_folder_time( $post_id ) {
		$time = current_time( 'timestamp' );

				if ( !( $attach = get_post( $post_id ) ) ) {
					return $time;
				}

				if ( !$attach->post_parent ) {
					return $time;
				}

		if ( !( $post = get_post( $attach->post_parent ) ) ) {
			return $time;
		}

		if ( substr( $post->post_date_gmt, 0, 4 ) > 0 ) {
			return strtotime( $post->post_date_gmt . ' +0000' );
		}

				return $time;
		}

	function wp_get_attachment_url( $url, $post_id ) {
		$new_url = $this->swift_get_attachment_url( $post_id );
		if ( false === $new_url ) {
			return $url;
		}

		$new_url = apply_filters( 'wps3_get_attachment_url', $new_url, $post_id, $this ); // Old naming convention, will be deprecated soon
		$new_url = apply_filters( 'swift_wp_get_attachment_url', $new_url, $post_id );

		return $new_url;
	}

	function swift_get_attachment_info( $post_id ) {
		return get_post_meta( $post_id, 'swift_info', true );
	}

	function swift_plugin_setup() {
		return (bool) $this->swift_get_setting( 'bucket' ) && !is_wp_error( $this->swift_get_client() );
	}

	/**
	* Generate a link to download a file from Softlayer Swift using query string
	* authentication.
	*
	* @param mixed $post_id Post ID of the attachment or null to use the loop
	* @param int $expires Seconds for the link to live
	*/
	function swift_get_secure_attachment_url( $post_id, $expires = 900, $size = null ) {
		return $this->swift_get_attachment_url( $post_id, $expires, $size = null );
	}

	function swift_get_attachment_url( $post_id, $expires = null, $size = null ) {
		if ( !$this->swift_get_setting( 'serve-from-swift' ) || !( $swiftObject = $this->swift_get_attachment_info( $post_id ) ) ) {
			return false;
		}
		$domain_bucket = $swiftObject['bucket'];

		if($size) {
				$meta = get_post_meta($post_id, '_wp_attachment_metadata', TRUE);
				if(isset($meta['sizes'][$size]['file'])) {
						$swiftObject['key'] = dirname($swiftObject['key']) . '/' . $meta['sizes'][$size]['file'];
				}
		}

		//Add in a random hash to the object name if a collision exists. Messy, but should work
		// and be random enough. The name of the URL isn't important because there is a short
		// permalink on WordPress and it's the best way to automatically handle collisions.
		// Hash cannot be added at the end of the url due to file extension, so put it at the
		// beginning of the object name.

		$url = $this->getObjectUrl($swiftObject['bucket'], $swiftObject['key']);

		if ( !is_null( $expires ) ) {
			try {
				$expires = time() + $expires;
					$secure_url = $this->getObjectUrl($swiftObject['bucket'], $swiftObject['key'] );
					$url .= substr( $secure_url, strpos( $secure_url, '?' ) );
			}
			catch ( Exception $e ) {
				return new WP_Error( 'exception', $e->getMessage() );
			}
		}

		return apply_filters( 'swift_get_attachment_url', $url, $swiftObject, $post_id, $expires );
	}
	
	function getObjectUrl($myBucket, $myKey) {
		$swift = $this->swift_get_vcap_variable('Object-Storage');
		$creds = $swift['credentials'];
		$auth_url = $creds['auth_url'] . '/v3'; //keystone v3
		$region = $creds['region'];
		$userId = $creds['userId'];
		$password = $creds['password'];
		$projectId = $creds['projectId'];
		
		$objectUrl = $this->swift_get_client()
                    ->getContainer($myBucket)
                    ->getObject($myKey)
                    ->getPublicUri();
		$url = (string)$objectUrl;
		
		//$url = 'https://dal.objectstorage.open.softlayer.com/v1/AUTH_' . $projectId . '/' . $myBucket . '/' . $myKey;
		return $url;
	}

	function swift_verify_ajax_request() {
		if ( !is_admin() || !wp_verify_nonce( $_POST['_nonce'], $_POST['action'] ) ) {
			wp_die( __( 'Cheatin&#8217; eh?', 'swift' ) );
		}

		if ( !current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'swift' ) );
		}
	}

	function swift_ajax_create_bucket() {
		$this->swift_verify_ajax_request();

		if ( !isset( $_POST['bucket_name'] ) || !$_POST['bucket_name'] ) {
			wp_die( __( 'No bucket name provided.', 'swift' ) );
		}

		$result = $this->swift_create_bucket( $_POST['bucket_name'] );
		if ( is_wp_error( $result ) ) {
			$out = array( 'error' => $result->get_error_message() );
		}
		else {
			$out = array( 'success' => '1', '_nonce' => wp_create_nonce( 'swift-create-bucket' ) );
		}

		echo json_encode( $out );
		exit;
	}

	function swift_create_bucket( $bucket_name ) {
		try {
				$this->swift_get_client()->createContainer(['name' => $bucket_name, 'readAccess' => '.r:*']);
		}
		catch ( Exception $e ) {
			return new WP_Error( 'exception', $e->getMessage() );
		}

		return true;
	}

	function swift_admin_menu( ) {
		$hook_suffix = add_menu_page( $this->plugin_title, $this->plugin_menu_title, 'manage_options', $this->plugin_slug, array( $this, 'swift_render_page' ) );
		add_action( 'load-' . $hook_suffix , array( $this, 'swift_plugin_load' ) );
	}

	function swift_get_client() {

		$swift = $this->swift_get_vcap_variable('Object-Storage');
		$creds = $swift['credentials'];
		$auth_url = $creds['auth_url'] . '/v3'; //keystone v3
		$region = $creds['region'];
		$userId = $creds['userId'];
		$password = $creds['password'];
		$projectId = $creds['projectId'];

		if(is_null($this->swiftClient)){
			$this->swiftClient = new OpenStack\OpenStack([
						    'authUrl' => $auth_url,
						    'region'  => $region,
						    'user'    => [
						        'id'       => $userId,
						        'password' => $password
						    ],
						    'scope'   => [
						    	'project' => [
						    		'id' => $projectId
						    	]
						    ]
						]);
		}
		
		return $this->swiftClient->objectStoreV1();
	}

	function swift_get_containers() {
		try {
			$containers = $this->swift_get_client()->listContainers();
		}
		catch ( Exception $e ) {
			return new WP_Error( 'exception', $e->getMessage() );
		}

		return $containers;
	}

	function swift_plugin_load() {
		$src = plugins_url( 'assets/css/styles.css', $this->plugin_file_path );
		wp_enqueue_style( 'swift-styles', $src, array(), $this->get_installed_version() );

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		$src = plugins_url( 'assets/js/script' . $suffix . '.js', $this->plugin_file_path );
		wp_enqueue_script( 'swift-script', $src, array( 'jquery' ), $this->get_installed_version(), true );

		wp_localize_script( 'swift-script', 'swift_i18n', array(
			'create_bucket_prompt'  => __( 'Bucket Name:', 'swift' ),
			'create_bucket_error'	=> __( 'Error creating bucket: ', 'swift' ),
			'create_bucket_nonce'	=> wp_create_nonce( 'swift-create-bucket' )
		) );

		$this->swift_handle_post_request();
	}

	function swift_handle_post_request() {
		if ( empty( $_POST['action'] ) || 'save' != $_POST['action'] ) {
			return;
		}

		if ( empty( $_POST['_wpnonce'] ) || !wp_verify_nonce( $_POST['_wpnonce'], 'swift-save-settings' ) ) {
			die( __( "Cheatin' eh?", 'amazon-web-services' ) );
		}

		$this->set_settings( array() );

		$post_vars = array( 'bucket', 'expires', 'permissions', 'object-prefix', 'copy-to-swift', 'serve-from-swift', 'remove-local-file', 'hidpi-images', 'object-versioning' );
		foreach ( $post_vars as $var ) {
			if ( !isset( $_POST[$var] ) ) {
				continue;
			}

			$this->set_setting( $var, $_POST[$var] );
		}

		//Need these to be always on to work on Bluemix. We don't want users to uncheck them.
		$default_vars = array('copy-to-swift', 'serve-from-swift', 'remove-local-file');

		foreach ($default_vars as $var){
			$this->set_setting( $var, $_POST[$var]);
		}

		$this->save_settings();

		wp_redirect( 'admin.php?page=' . $this->plugin_slug . '&updated=1' );
		exit;
	}

	function swift_render_page() {
		$this->swift_render_view( 'settings' );
	}

	function swift_get_dynamic_prefix( $time = null ) {
				$uploads = wp_upload_dir( $time );
				return str_replace( $this->swift_get_base_upload_path(), '', $uploads['path'] );
	}

	// Without the multisite subdirectory
	function swift_get_base_upload_path() {
		if ( defined( 'UPLOADS' ) && ! ( is_multisite() && get_site_option( 'ms_files_rewriting' ) ) ) {
			return ABSPATH . UPLOADS;
		}

		$upload_path = trim( get_option( 'upload_path' ) );

		if ( empty( $upload_path ) || 'wp-content/uploads' == $upload_path ) {
			return WP_CONTENT_DIR . '/uploads';
		} elseif ( 0 !== strpos( $upload_path, ABSPATH ) ) {
			// $dir is absolute, $upload_path is (maybe) relative to ABSPATH
			return path_join( ABSPATH, $upload_path );
		} else {
			return $upload_path;
		}
	}
	
	/**
	 * Replace local URLs with object storage ones for srcset image sources
	 *
	 * @param array  $sources
	 * @param array  $size_array
	 * @param string $image_src
	 * @param array  $image_meta
	 * @param int    $attachment_id
	 *
	 * @return array
	 */
	public function wp_calculate_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		foreach ( $sources as $width => $source ) {
			$size   = $this->find_image_size_from_width( $image_meta['sizes'], $width );
			$OS_url = $this->swift_get_attachment_url( $attachment_id, null, $size );
			if ( false === $OS_url || is_wp_error( $OS_url ) ) {
				continue;
			}
			$sources[ $width ]['url'] = $OS_url;
		}
		return $sources;
	}
	
	/**
	 * Helper function to find size name from width
	 *
	 * @param array  $sizes
	 * @param string $width
	 *
	 * @return null|string
	 */
	protected function find_image_size_from_width( $sizes, $width ) {
		foreach ( $sizes as $name => $size ) {
			if ( $width === $size['width'] ) {
				return $name;
			}
		}
		return null;
	}

}
