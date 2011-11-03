=== Meta Revisions ===
Contributors: jeffstieler, johnciacia
Tags: revision, post, meta
Requires at least: 3.2
Tested up to: 3.2
Stable tag: 1.0

Keep track of meta revisions

== Description ==

This plugin allows you to track revisions to postmeta and taxonomy fields. To take advantage of the provided functionality, you must use the `Meta_Revisions_init` hook and call the appropriate helper functions.

= Example =
`function add_meta_versioning() {
	// Track changes to the 'category' taxonomy
	meta_revisions_track_taxonomy_field( 'category', 'Categories' );
	// Track changes to the 'post_tag' taxonomy
	meta_revisions_track_taxonomy_field( 'post_tag', 'Tags' );
	// Track changes to the 'due_date' post meta (due_date is the meta-key)
	meta_revisions_track_postmeta_field( 'due_date', 'Due Date' );
}
add_action( 'Meta_Revisions_init', 'add_meta_versioning' );`

== Installation ==

1. Unzip the archive and put the meta-revisions folder into your plugins folder (/wp-content/plugins/).
1. Activate the plugin from the Plugins menu.