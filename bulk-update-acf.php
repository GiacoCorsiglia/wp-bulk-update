<?php

/*
Plugin Name: ACF Bulk Update
Plugin URI: https://github.com/GiacoCorsiglia/wp-bulk-update
Description: Plugin to enable bulk metadata updates for Advanced Custom Fields.
Version: 1.0.0-beta1
Author: Giaco Corsiglia
Author URI: https://giacocorsiglia.com
*/

/**
 * Hooks to force ACF to update metadata in bulk.
 */
final class acf_bulk_update {

	/**
	 * The id of the post, term, or user
	 * @var string
	 */
	private $object_id;

	/**
	 * The type of meta data (post|term|user)
	 * @var string
	 */
	private $meta_type;

	/**
	 * Undocumented variable
	 * @var array
	 */
	private $metadata = [];

	/**
	 * ID of the post revision
	 * @var int|null
	 */
	private $revision_post_id;

	/**
	 * Stashed meta data for the revision post
	 * @var mixed[]
	 */
	private $revision_metadata = [];

	/**
	 * Whether we should run the bulk update in after_acf_saves()
	 * @var bool
	 */
	private $should_update_bulk = false;

	public function __construct() {

		add_action( 'acf/save_post', [ $this, 'before_acf_saves' ], 1, 1 );

		add_action( 'acf/save_post', [ $this, 'after_acf_saves' ], 20, 1 );

		add_action( 'save_post', [ $this, 'after_acf_saves_post_revision' ], 20 );

	}

	/**
	 * Just before ACF starts to save something we register a hook to intercept
	 * INSERT/UPDATE calls.
	 * @param string|int $id The id of the object being saved as ACF formats it
	 *                       @see acf_get_post_id_info()
	 * @return void
	 */
	public function before_acf_saves( $id ) {

		$info = acf_get_post_id_info( $id );

		$this->meta_type = $info[ 'type' ];

		if ( $this->meta_type === 'option' ) {
			return;
		}

		$this->should_update_bulk = true;
		$this->object_id = $info[ 'id' ];

		add_filter( "update_{$this->meta_type}_metadata", [ $this, 'catch_metadata_update' ], 10, 5 );

		if ( $this->meta_type === 'post'
			&& post_type_supports( get_post_type( $id ), 'revisions' )
			&& ( $revision = acf_get_post_latest_revision( $id ) )
		) {
			$this->revision_post_id = $revision->ID;
		}

	}

	/**
	 * Short-circuits calls to `update_metadata()` and stashes key => value to
	 * save in bulk later.
	 * @param bool|null $check
	 * @param int|string $object_id
	 * @param string $meta_key
	 * @param mixed $meta_value
	 * @param mixed $prev_value
	 * @return bool|null If false, update_metadata() will exit early and not
	 *                   run any queries.
	 */
	public function catch_metadata_update( $check, $object_id, $meta_key, $meta_value, $prev_value ) {

		// NOTE: no way to check $meta_type here, which means there's potentially a bug

		if ( $object_id === $this->object_id ) {
			$this->metadata[ $meta_key ] = $meta_value;
		}
		elseif ( $object_id === $this->revision_post_id ) {
			$this->revision_metadata[ $meta_key ] = $meta_value;
		}
		else {
			// Only short-circuit calls for the relevant object ids
			return $check;
		}

		// Don't let WordPress actually update anything right now
		return false;

	}

	/**
	 * Runs the bulk meta update using the stashed values after ACF is done
	 * making calls to `update_metadata()`.
	 * @param int|string $id The id of the object being saved as ACF formats it
	 * @return void
	 */
	public function after_acf_saves( $id ) {

		if ( ! $this->should_update_bulk ) {
			return;
		}

		$info = acf_get_post_id_info( $id );
		if ( $info[ 'id' ] !== $this->object_id ) {
			// Not sure how we'd ever get here but just in case.
			return;
		}

		// Have to make sure to save the post data before ACF
		// calls `acf_save_post_revision()`
		update_metadata_bulk( $this->meta_type, [ $this->object_id => $this->metadata ] );

	}

	/**
	 * Runs the bulk meta update for the post revision after ACF calls
	 * `acf_save_post_revision()`
	 * @return void
	 */
	public function after_acf_saves_post_revision() {

		// Stop intercepting metadata updates
		remove_action( "update_{$this->meta_type}_metadata", [ $this, 'catch_metadata_update' ] );

		if ( $this->revision_post_id ) {
			update_metadata_bulk( $this->meta_type, [ $this->revision_post_id => $this->revision_metadata ] );
		}

	}

}

new acf_bulk_update();
