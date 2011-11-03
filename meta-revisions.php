<?php
/*
Plugin Name: Meta Revisions
Plugin URI: http://www.voceconnect.com
Description: Keep track of meta revisions
Author: jeffstieler, johnciacia
Version: 1.0
Author URI: http://www.voceconnect.com

Copyright 2011  Voce  (email : john@voceconnect.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

Meta_Revisions::init();

class Meta_Revisions {

	const POST_EDIT_PAGENOW = 'post.php';
	const POST_EDIT_ACTION = 'editpost';
	const REVISION_PAGENOW = 'revision.php';
	const REVISION_DIFF_ACTION = 'diff';
	const REVISION_EDIT_ACTION = 'edit';
	const POSTMETA_TYPE = 'postmeta';
	const TAXONOMY_TYPE = 'taxonomy';
	const POSTMETA_DEFAULT_CALLBACK = 'postmeta_display_callback';
	const TAXONOMY_DEFAULT_CALLBACK = 'taxonomy_display_callback';
	static $is_left_revision_column = true;
	static $tracked_fields = array();

	public static function init() {
		add_action('wp_loaded', array(__CLASS__, 'setup'));
	}

	public static function setup() {
		do_action(__CLASS__ . '_init');
		add_action('check_admin_referer', array(__CLASS__, 'check_admin_referer_action'), 10, 2);
		add_action('pre_post_update', array(__CLASS__, 'modify_post_save_actions'), 1);
		add_filter('_wp_post_revision_fields', array(__CLASS__, 'filter_metadata_revision_fields'));
		add_action('wp_restore_post_revision', array(__CLASS__, 'restore_post_revision_action'), 10, 2);
	}

	/**
	 * Prevent wp_save_post_revision from running, if not on the post edit screen - run the save meta/terms routine
	 */
	public static function modify_post_save_actions($post_id) {
		remove_action('pre_post_update', 'wp_save_post_revision');

		if (!self::is_post_edit_screen()) {
			self::version_post_meta_and_terms($post_id);
		}
	}

	/**
	 * Determines whether or not we are on the Post Edit screen
	 *
	 * @global string $pagenow
	 * @return bool
	 */
	public static function is_post_edit_screen() {
		global $pagenow;
		$action = isset($_POST['action']) ? $_POST['action'] : '';
		return ((self::POST_EDIT_PAGENOW == $pagenow) && (self::POST_EDIT_ACTION == $action));
	}

	/**
	 * Start versioning new post metadata, either post meta or taxonomy for now.
	 *
	 * @param string $fieldname Post meta key or Taxonomy slug
	 * @param string $label Row title for revisions page
	 * @param string $fieldtype "postmeta" or "taxonomy"
	 * @param string $post_type Post type to version this metadata for
	 * @param string|array $callback Diff callback for this metadata, is passed single value or left/right in a diff.
	 */
	public static function track_new_field($fieldname, $label, $fieldtype = self::POSTMETA_TYPE, $post_type = 'post', $callback = false) {

		// verify that this post type exists and supports revisions
		if (!post_type_exists($post_type) || !post_type_supports($post_type, 'revisions')) {
			return;
		}

		// if this is a taxonomy, verify that it is registered for this post type
		if (self::TAXONOMY_TYPE == $fieldtype) {
			$post_type_taxonomies = get_object_taxonomies($post_type);
			if (!in_array($fieldname, $post_type_taxonomies)) {
				return;
			}
		}

		// for recognized fieldtype (postmeta, taxonomy) use a default callback if none specified
		if (!$callback) {
			switch($fieldtype) {
				case self::TAXONOMY_TYPE:
					$callback = array(__CLASS__, self::TAXONOMY_DEFAULT_CALLBACK);
					break;
				case self::POSTMETA_TYPE:
					$callback = array(__CLASS__, self::POSTMETA_DEFAULT_CALLBACK);
					break;
				default:
					return;
			}
		}

		// make sure the display callback is valid
		if (!is_callable($callback)) {
			return;
		}

		if (!isset(self::$tracked_fields[$post_type])) {
			self::$tracked_fields[$post_type] = array();
		}

		if (!isset(self::$tracked_fields[$post_type][$fieldtype])) {
			self::$tracked_fields[$post_type][$fieldtype] = array();
		}

		self::$tracked_fields[$post_type][$fieldtype][$fieldname] = compact('fieldname', 'label', 'post_type', 'callback');
	}

	/**
	 * Whether or not a post type has metadata being versioned.
	 *
	 * @param string $post_type
	 * @param string $fieldtype Optional. Could be "postmeta" or "taxonomy"
	 * @return bool
	 */
	public static function tracking_fields_for_post_type($post_type, $fieldtype = false) {

		if ($fieldtype) {
			return isset(self::$tracked_fields[$post_type][$fieldtype]);
		}

		return isset(self::$tracked_fields[$post_type]);
	}

	/**
	 * Helper, retrieves tracked fields for a given post type and metadata type
	 *
	 * @param string $post_type
	 * @param string $fieldtype
	 * @return array
	 */
	public static function get_tracked_fields($post_type, $fieldtype) {
		$tracked = self::get_tracked_field_info($post_type, $fieldtype);

		if ($tracked) {
			$tracked = array_keys($tracked);
		}

		return $tracked;
	}

	/**
	 * Helper, retrieves tracked field info for given post type and metadata type
	 *
	 * @param string $post_type
	 * @param string $fieldtype
	 * @return array
	 */
	public static function get_tracked_field_info($post_type, $fieldtype) {

		if (isset(self::$tracked_fields[$post_type][$fieldtype])) {
			return self::$tracked_fields[$post_type][$fieldtype];
		}

		return array();
	}

	/**
	 * Run the metadata->revision routine
	 */
	public static function save_post_action($post_ID, $post) {

		if (!self::tracking_fields_for_post_type(get_post_type($post))) {
			return;
		}

		if ($revision_parent = wp_is_post_revision($post)) {
			self::post_metadata_to_revision($revision_parent, $post_ID);
		}

	}

	/**
	 * Run the metadata->revision routine from the Post Edit screen
	 */
	public static function check_admin_referer_action($action, $result) {
		global $pagenow;

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// if this is a post edit, save the revision with current metadata
		if (self::is_post_edit_screen() && $result) {
			$post_id = intval($_POST['post_ID']);
			self::version_post_meta_and_terms($post_id);
		}
	}

	/**
	 * Meat and potatoes of the Meta Versioning plugin, saves post meta and tax terms to a new revision.
	 *
	 * @param int $post_id
	 */
	public static function version_post_meta_and_terms($post_id) {
		$post_type = get_post_type($post_id);
		$post_meta = false;
		$tracked_terms = false;

		if (self::tracking_fields_for_post_type($post_type, self::POSTMETA_TYPE)) {
			$post_meta = self::get_tracked_post_custom($post_id);
		}
		if (self::tracking_fields_for_post_type($post_type, self::TAXONOMY_TYPE)) {
			$tracked_terms = self::get_tracked_terms($post_id);
		}

		$revision_id = wp_save_post_revision($post_id); // This saves the post meta!

		if ($revision_id && $post_meta) {
			self::save_meta_to_revision($post_meta, $revision_id);
		}

		if ($revision_id && $tracked_terms) {
			self::save_taxonomy_terms_to_revision($tracked_terms, $revision_id);
		}
	}

	/**
	 * Copy all tracked post metadata entries to it's last revision
	 *
	 * @param array $post_meta Return of get_tracked_post_custom()
	 * @param int $revision_id
	 */
	public static function save_meta_to_revision($post_meta, $revision_id) {
		foreach ($post_meta as $meta_key => $meta_values) {

			if (!is_array($meta_values)) {
				$meta_values = array($meta_values);
			}

			foreach ($meta_values as $meta_value) {
				// can't call add_post_meta since it won't allow saving to a revision
				add_metadata('post', $revision_id, $meta_key, $meta_value);
			}

		}
	}

	/**
	 * Retrieve all post meta that is being versioned.
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get_tracked_post_custom($post_id) {
		$post_custom = self::get_post_custom($post_id);
		$tracked_postmeta = self::get_tracked_postmeta_fields($post_id);
		foreach ($post_custom as $key => $val) {
			if (!in_array($key, $tracked_postmeta)) {
				unset($post_custom[$key]);
			}
		}
		return $post_custom;
	}

	/**
	 * Retrieve all post meta keys that are being versioned.
	 *
	 * @param string|int $post_type
	 * @return array
	 */
	public static function get_tracked_postmeta_fields($post_type) {
		if (is_numeric($post_type)) {
			$potential_parent = wp_is_post_revision($post_type);
			if ($potential_parent) {
				$post_type = $potential_parent;
			}
			$post_type = get_post_type($post_type);
		}
		return self::get_tracked_fields($post_type, self::POSTMETA_TYPE);
	}

	/**
	 * Retreive all post meta keys/values, handling serialized data
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get_post_custom($post_id) {
		$post_custom = get_post_custom($post_id);
		$post_meta = array();
		foreach ($post_custom as $key => $values) {
			$post_meta[$key] = array();
			foreach ($values as $value) {
				$post_meta[$key][] = maybe_unserialize($value);
			}
		}
		return $post_meta;
	}

	/**
	 * Retreive all post meta keys, filtering out null (no meta)
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get_post_custom_keys($post_id) {
		$post_keys = get_post_custom_keys($post_id);
		if (is_null($post_keys)) {
			return array();
		}
		return $post_keys;
	}

	/**
	 * Retrieve terms in versioned taxonomies
	 *
	 * @param int $post_id
	 * @return array Term slugs grouped by taxonomy.
	 */
	public static function get_tracked_terms($post_id) {

		$post_id_for_type = wp_is_post_revision($post_id);

		if (!$post_id_for_type) {
			$post_id_for_type = $post_id;
		}

		$revision_taxonomies = self::get_tracked_fields(get_post_type($post_id_for_type), self::TAXONOMY_TYPE);
		$revision_terms = wp_get_object_terms($post_id, $revision_taxonomies);
		$grouped_terms = array();

		foreach ($revision_terms as $term) {
			if (!array_key_exists($term->taxonomy, $grouped_terms)) {
				$grouped_terms[$term->taxonomy] = array();
			}
			$grouped_terms[$term->taxonomy][] = $term->slug;
		}
		return $grouped_terms;
	}

	/**
	 * Helper to retrieve a metadata value by post/revision, type, and key.
	 *
	 * @param int $post_id
	 * @param string $field_type
	 * @param string $fieldname
	 * @return mixed
	 */
	public static function get_field_value($post_id, $field_type, $fieldname) {
		if (self::POSTMETA_TYPE == $field_type) {
			$meta = get_post_meta($post_id, $fieldname);
			if ($meta) {
				if (count($meta) == 1) {
					$meta = array_shift($meta);
				}
			} else {
				$meta = '';
			}
			return $meta;
		}
		else if (self::TAXONOMY_TYPE == $field_type) {
			return wp_get_object_terms($post_id, $fieldname);
		}
		return '';
	}

	/**
	 * Copy all taxonomy terms from post to a revision
	 *
	 * @param array $tracked_terms Return of get_tracked_terms()
	 * @param int $revision_id
	 */
	public static function save_taxonomy_terms_to_revision($tracked_terms, $revision_id) {
		foreach ($tracked_terms as $taxonomy => $term_slugs) {
			wp_set_object_terms($revision_id, $term_slugs, $taxonomy);
		}
	}

	/**
	 * Kick off all metadata copying to a revision
	 *
	 * @param int $post_id
	 * @param int $revision_id
	 */
	public static function post_metadata_to_revision($post_id, $revision_id) {
		$post_type = get_post_type($post_id);
		if (self::tracking_fields_for_post_type($post_type, self::POSTMETA_TYPE)) {
			self::save_meta_to_revision($post_id, $revision_id);
		}
		if (self::tracking_fields_for_post_type($post_type, self::TAXONOMY_TYPE)) {
			self::save_taxonomy_terms_to_revision($post_id, $revision_id);
		}
	}

	/**
	 * Hook into revision fields filter to takeover display of view/diff operations
	 *
	 * @global string $pagenow
	 * @global string $action
	 * @global object $left_revision
	 * @global object $right_revision
	 * @global int $revision_id
	 * @param array $revision_fields
	 * @return array
	 */
	public static function filter_metadata_revision_fields($revision_fields) {
		global $pagenow, $action, $post;

		$takeover_actions = array(self::REVISION_DIFF_ACTION, self::REVISION_EDIT_ACTION);
		if ((self::REVISION_PAGENOW == $pagenow) && in_array($action, $takeover_actions)) {
			$post_type = get_post_type($post);
			if (self::tracking_fields_for_post_type($post_type)) {
				self::revision_screen_takeover($revision_fields, $post_type);
			}
			return array();
		}

		return $revision_fields;
	}

	/**
	 * Displays the original revision screen, with additional meta underneath.
	 *
	 * @global string $action
	 * @global object $revision
	 * @global object $left_revision
	 * @global object $right_revision
	 * @global bool $identical
	 * @param array $revision_fields
	 * @param string $post_type
	 */
	public static function revision_screen_takeover($revision_fields, $post_type) {
		global $action, $revision, $left_revision, $right_revision, $identical;
		// straight up stolen from revision.php
		$identical = true;
		foreach ( $revision_fields as $field => $field_title ) :
			if ( 'diff' == $action ) {
				$left_content = apply_filters( "_wp_post_revision_field_$field", $left_revision->$field, $field );
				$right_content = apply_filters( "_wp_post_revision_field_$field", $right_revision->$field, $field );
				if ( !$content = wp_text_diff( $left_content, $right_content ) )
					continue; // There is no difference between left and right
				$identical = false;
			} else {
				add_filter( "_wp_post_revision_field_$field", 'htmlspecialchars' );
				$content = apply_filters( "_wp_post_revision_field_$field", $revision->$field, $field );
			}
			?>

			<tr id="revision-field-<?php echo $field; ?>">
				<th scope="row"><?php echo esc_html( $field_title ); ?></th>
				<td><div class="pre"><?php echo $content; ?></div></td>
			</tr>

			<?php
		endforeach;

		// display versioned metadata
		foreach (self::$tracked_fields[$post_type] as $field_type => $fields):
			foreach ($fields as $fieldname => $field_info):
				$args = array();
				if (self::REVISION_EDIT_ACTION == $action) {
					$args[] = self::get_field_value($revision->ID, $field_type, $fieldname);
				} else if (self::REVISION_DIFF_ACTION) {
					$args[] = self::get_field_value($left_revision->ID, $field_type, $fieldname);
					$args[] = self::get_field_value($right_revision->ID, $field_type, $fieldname);
				}
				$content = call_user_func_array($field_info['callback'], $args);
				if ($content):
					$identical = false;
			?>
				<tr id="revision-field-<?php echo $fieldname; ?>">
					<th scope="row"><?php echo esc_html( $field_info['label'] ); ?></th>
					<td><div class="pre"><?php echo $content; ?></div></td>
				</tr>
			<?php
				endif;
			endforeach;
		endforeach;
	}

	/**
	 * Migrate a revision's post meta and taxonomies to the restored post
	 *
	 * @param int $post_id
	 * @param int $revision_id
	 */
	public static function restore_post_revision_action($post_id, $revision_id) {
		// restore tracked taxonomy terms
		$tracked_terms = self::get_tracked_terms($revision_id);

		foreach ($tracked_terms as $taxonomy => $term_slugs) {
			wp_set_object_terms($post_id, $term_slugs, $taxonomy);
		}

		// restore post meta
		$revision_meta = self::get_tracked_post_custom($revision_id);

		foreach ($revision_meta as $meta_key => $values) {

			delete_post_meta($post_id, $meta_key);

			if (!is_array($values)) {
				$values = array($values);
			}

			foreach ($values as $value) {
				add_post_meta($post_id, $meta_key, $value);
			}

		}
	}

	/**
	 * Displays value for a taxonomy term, or diff of two.
	 *
	 * @param array $left_value
	 * @param array $right_value
	 * @return string Display of the diff.
	 */
	public static function taxonomy_display_callback($left_value, $right_value = null) {
		$left_terms = array();
		foreach ($left_value as $term) {
			$left_terms[] = $term->name;
		}
		$content = implode(', ', $left_terms);

		if (!is_null($right_value)) {
			$right_terms = array();
			foreach ($right_value as $term) {
				$right_terms[] = $term->name;
			}
			$content = wp_text_diff($content, implode(', ', $right_terms));
		}

		return $content;
	}

	/**
	 * Displays value for post meta, or diff of two.
	 *
	 * @param object|array|string $left_value
	 * @param object|array|string $right_value
	 * @return string Display of the diff.
	 */
	public static function postmeta_display_callback($left_value, $right_value = null) {
		$content = self::get_printable($left_value);
		if (!is_null($right_value)) {
			$right_value = self::get_printable($right_value);
			$content = wp_text_diff($content, $right_value);
		}
		return $content;
	}

	/**
	 * Helper, returns 'printable' value of $var.
	 *
	 * @param mixed $var
	 * @return string
	 */
	public static function get_printable($var) {
		return (is_string($var) || is_numeric($var)) ? (string)$var : var_export($var, true);
	}

}


function meta_revisions_track_new_field($fieldname, $label, $fieldtype = Meta_Revisions::POSTMETA_TYPE, $post_type = 'post', $callback = false) {
	return Meta_Revisions::track_new_field($fieldname, $label, $fieldtype, $post_type, $callback);
}

function meta_revisions_track_postmeta_field($fieldname, $label, $post_type = 'post', $callback = false) {
	return Meta_Revisions::track_new_field($fieldname, $label, Meta_Revisions::POSTMETA_TYPE, $post_type, $callback);
}

function meta_revisions_track_taxonomy_field($fieldname, $label, $post_type = 'post', $callback = false) {
	return Meta_Revisions::track_new_field($fieldname, $label, Meta_Revisions::TAXONOMY_TYPE, $post_type, $callback);
}