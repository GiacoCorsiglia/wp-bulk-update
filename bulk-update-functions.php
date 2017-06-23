<?php

/*
Plugin Name: Bulk Update Function
Plugin URI: https://github.com/GiacoCorsiglia/wp-bulk-update
Description: Bulk update functionality for (term|post|user)meta
Version: 1.0.0-beta1
Author: Giaco Corsiglia
Author URI: https://giacocorsiglia.com
*/

/**
 * Bulk metadata update/insert
 * @see: https://core.trac.wordpress.org/ticket/34848
 * @see: https://core.trac.wordpress.org/attachment/ticket/34848/meta-bulk.php
 * @global $wpdb
 * @param string $meta_type One of 'term', 'post', 'user'
 * @param array $metadatas Array of the form:
 *                         $object_id => [ $meta_key => $meta_value ]
 *                         where each $meta_value must be serializable.
 * @return bool True on success, false on failure
 */
function update_metadata_bulk( string $meta_type, array $metadatas ): bool {
	global $wpdb;

	// Beginning of this function looks a lot like WordPress' `update_metadata()`

	if ( ! $meta_type || ! $metadatas ) {
		return false;
	}

	$table = _get_meta_table( $meta_type );
	if ( ! $table ) {
		return false;
	}

	$object_id_column = sanitize_key( $meta_type . '_id' );
	$meta_id_column = 'user' == $meta_type ? 'umeta_id' : 'meta_id';

	// Don't waste queries for empty data
	$metadatas = array_filter( $metadatas );

	if ( ! $metadatas ) {
		// No data was passed at all
		return true;
	}

	// Validate object ids and make sure the array keys in metadatas are absints
	$passed_metadatas = $metadatas;
	$metadatas = [];
	foreach ( $passed_metadatas as $object_id => $metadata ) {
		$object_id = absint( $object_id );

		if ( ! $object_id ) {
			// We were passed an invalid object_id
			return false;
		}

		$metadatas[ $object_id ] = $metadata;
	}

	// Get all current metadata values on these objects, including the meta_ids
	// This really doesn't support multiple metadata entries for the same key
	$old_metadatas_query = $wpdb->get_results( $wpdb->prepare(
		"SELECT $meta_id_column, $object_id_column, meta_key, meta_value
		FROM $table
		WHERE $object_id_column IN (%s)
		",
		implode( ',', array_keys( $metadatas ) )
	) );
	$old_metadatas = [];
	foreach ( $old_metadatas_query as $old_meta ) {
		$object_id = $old_meta->$object_id_column;

		if ( ! isset( $old_metadatas[ $object_id ] ) ) {
			$old_metadatas[ $object_id ] = [];
		}

		$old_metadatas[ $object_id ][ $old_meta->meta_key ] = [
			'id' => $old_meta->$meta_id_column,
			'value' => $old_meta->meta_value,
		];
	}

	// Sanitize input
	// Also, we won't re-insert values that haven't changed
	$sanitized_metadatas = [];
	foreach ( $metadatas as $object_id => $metadata ) {
		$sanitized_metadatas[ $object_id ] = [];

		foreach ( $metadata as $key => $value ) {
			$key = wp_unslash( $key );
			$value = wp_unslash( $value );
			$value = sanitize_meta( $key, $value, $meta_type );
			$value = maybe_serialize( $value );

			$old_value = $old_metadatas[ $object_id ][ $key ][ 'value' ] ?? '';
			if ( (string) $value === $old_value ) {
				// No reason to even send the UPDATE to the db if the value is
				// identical.  Note this also catches empty values for new keys, which
				// fine---we don't need to INSERT those either.
				continue;
			}

			$sanitized_metadatas[ $object_id ][ $key ] = $value;
		}
	}

	$sanitized_metadatas = array_filter( $sanitized_metadatas );
	if ( ! $sanitized_metadatas ) {
		// Don't need to run the query if nothing at all has changed
		return true;
	}

	$sql = "INSERT INTO $table ($meta_id_column, $object_id_column, meta_key, meta_value) VALUES ";
	$rows = [];
	foreach ( $sanitized_metadatas as $object_id => $sanitized_metadata ) {
		foreach ( $sanitized_metadata as $key => $value ) {
			// Need to have the correct meta_id here because that's the primary key
			// in meta tables, so need it for `ON DUPLICATE KEY UPDATE to work
			// correctly.
			$meta_id = $old_metadatas[ $object_id ][ $key ][ 'id' ] ?? 0;
			$rows[] = $wpdb->prepare( "(%d, %d, '%s', '%s')", $meta_id, $object_id, $key, $value );
		}
	}
	$sql .= implode( ',', $rows );
	$sql .= " ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)";

	// Send the query
	$wpdb->query( $sql );

	// Clear the cache for the objects whose meta we actually changed/added
	$changed_object_ids = array_keys( $sanitized_metadatas );
	foreach ( $changed_object_ids as $object_id ) {
		wp_cache_delete( $object_id, $meta_type . '_meta' );
	}

	return true;

}
