<div class="aws-content swift-settings">

<?php
$buckets = $this->swift_get_containers();

if ( is_wp_error( $buckets ) ) :
	?>
	<div class="error">
		<p>
			<?php _e( 'Error retrieving a list of your containers from Swift:', 'swift' ); ?>
			<?php echo $buckets->get_error_message(); ?>
		</p>
	</div>
	<?php
endif;

if ( isset( $_GET['updated'] ) ) {
	?>
	<div class="updated">
		<p>
			<?php _e( 'Settings saved.', 'swift' ); ?>
		</p>
	</div>
	<?php
}
?>

<form method="post">
<input type="hidden" name="action" value="save" />
<?php wp_nonce_field( 'swift-save-settings' ) ?>

<table class="form-table">
<tr valign="top">
	<td>
		<h3><?php _e( 'IBM Object Storage Settings', 'swift' ); ?></h3>

		<select name="bucket" class="bucket">
		<option value="">-- <?php _e( 'Select a Swift Container', 'swift' ); ?> --</option>
		<?php if ( 1 ) foreach ( $buckets as $bucket ): ?>
				<option value="<?= esc_attr( $bucket->name ); ?>" <?= $bucket->name == $this->swift_get_setting( 'bucket' ) ? 'selected="selected"' : ''; ?>><?= esc_html( $bucket->name ); ?></option>
		<?php endforeach;?>
		<option value="new"><?php _e( 'Create a new container...', 'swift' ); ?></option>
		</select><br />

		<input type="checkbox" name="expires" value="1" id="expires" <?php echo $this->swift_get_setting( 'expires' ) ? 'checked="checked" ' : ''; ?> />
		<label for="expires"> <?php printf( __( 'Set a <a href="%s" target="_blank">far future HTTP expiration header</a> for uploaded files <em>(recommended)</em>', 'swift' ), 'http://developer.yahoo.com/performance/rules.html#expires' ); ?></label>
	</td>
</tr>

<tr valign="top">
	<td>
		<label><?php _e( 'Object Path:', 'swift' ); ?></label>&nbsp;&nbsp;
		<input type="text" name="object-prefix" value="<?php echo esc_attr( $this->swift_get_setting( 'object-prefix' ) ); ?>" size="30" />
		<label><?php echo trailingslashit( $this->swift_get_dynamic_prefix() ); ?></label>
	</td>
</tr>

<tr valign="top">
	<td>
		<h3><?php _e( 'Plugin Settings', 'swift' ); ?></h3>

		<!--
			These hidden input values must be on by default. Bluemix's filesystem is ephemeral, so we
			absolutely must copy things over to swift every time. Additionally, remove them from the filesystem
			to avoid the 2GB per instance limit.
		-->
		<input type="hidden" name="copy-to-swift" value="1" id="copy-to-swift"  />

		<input type="hidden" name="serve-from-swift" value="1" id="serve-from-swift" />

		<input type="hidden" name="remove-local-file" value="1" id="remove-local-file" />

		<input type="checkbox" name="hidpi-images" value="1" id="hidpi-images" <?php echo $this->swift_get_setting( 'hidpi-images' ) ? 'checked="checked" ' : ''; ?> />
		<label for="hidpi-images"> <?php _e( 'Copy any HiDPI (@2x) images to Swift (works with WP Retina 2x plugin)', 'swift' ); ?></label>

	</td>
</tr>
<tr valign="top">
	<td>
		<button type="submit" class="button button-primary"><?php _e( 'Save Changes', 'amazon-web-services' ); ?></button>
	</td>
</tr>
</table>

</form>

</div>
