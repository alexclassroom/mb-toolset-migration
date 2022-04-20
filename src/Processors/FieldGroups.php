<?php
namespace MetaBox\TS\Processors;

use WP_Query;
use MetaBox\Support\Data;
use MBBParser\Parsers\MetaBox;

class FieldGroups extends Base {
	private $post_id;
	private $settings = [];
	private $fields   = [];

	protected function get_items() {
		// Process all field groups at once.
		if ( $_SESSION['processed'] ) {
			return [];
		}

		$query = new WP_Query( [
			'post_type'              =>  [ 'wp-types-group', 'wp-types-term-group', 'wp-types-user-group' ],
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		return $query->posts;
	}

	protected function migrate_item() {
		$this->post_id  = null;
		$this->settings = [];
		$this->fields   = [];
		$this->create_post();
		$this->migrate_settings();
		$this->migrate_fields();
		$this->save_id();

		$data = [
			'post_name'  => $this->item->post_name,
			'post_title' => $this->item->post_title,
			'fields'     => $this->fields,
			'settings'   => $this->settings,
		];

		$parser = new MetaBox( $data );
		$parser->parse();
		update_post_meta( $this->post_id, 'meta_box', $parser->get_settings() );

		//$this->disable_post();
		//$this->delete_post();
	}

	private function create_post() {
		$data = [
			'post_title'        => $this->item->post_title,
			'post_type'         => 'meta-box',
			'post_status'       => $this->item->post_status,
			'post_name'         => "ts_{$this->item->post_name}",
			'post_content'      => $this->item->post_content,
			'post_date'         => $this->item->post_date,
			'post_date_gmt'     => $this->item->post_date_gmt,
			'post_modified'     => $this->item->post_modified,
			'post_modified_gmt' => $this->item->post_modified_gmt,
		];

		$post_id = get_post_meta( $this->item->ID, 'meta_box_id', true );
		if ( $post_id ) {
			$this->post_id = $data['ID'] = $post_id;
			wp_update_post( $data );
		} else {
			$this->post_id = wp_insert_post( $data );
		}

		update_post_meta( $this->item->ID, 'meta_box_id', $this->post_id );
	}

	private function disable_post() {
		$data = [
			'ID'          => $this->item->ID,
			'post_status' => 'draft',
		];
		wp_update_post( $data );
	}

	private function delete_post() {
		wp_delete_post( $this->item->ID );
	}

	private function migrate_settings() {

		$this->migrate_location();

		update_post_meta( $this->post_id, 'settings', $this->settings );
	}

	private function migrate_location() {
		$object_type    = null;
		$all_post_types = [];
		$all_taxonomies = [];

		if ( $this->item->post_type === 'wp-types-group' ) {
			$object_type = 'post';
		} elseif ( $this->item->post_type === 'wp-types-user-group' ) {
			$object_type = 'user';
		}elseif ( $this->item->post_type === 'wp-types-term-group' ) {
			$object_type = 'term';
		}

		if ( $object_type === 'post' ) {
			$post_type  = get_post_meta( $this->item->ID, '_wp_types_group_post_types', true );
			$post_type  = ( $post_type === 'all' ) ? 'all' : array_filter( explode( ",", $post_type ) );
			$post_types = Data::get_post_types();
			foreach( $post_types as $key => $values ) {
				$all_post_types[] = $key;
			}
			$post_types = ( $post_type === 'all' ) ? $all_post_types : array_filter( $post_type, function( $value ) use ( $all_post_types ) {
				return in_array( $value, $all_post_types );
			});
		}

		if ( $object_type === 'term' ) {
			$taxonomy   = get_post_meta( $this->item->ID, '_wp_types_associated_taxonomy', false );
			$taxonomies = Data::get_taxonomies();
			foreach( $taxonomies as $key => $values ) {
				$all_taxonomies[] = $key;
			}
			$taxonomies = empty ( $taxonomy ) ? $all_taxonomies : array_filter( $taxonomy, function( $value ) use ( $all_taxonomies ) {
				return in_array( $value, $all_taxonomies );
			});
		}

		$this->settings['object_type'] = $object_type;

		if ( $object_type === 'post' ) {
			$this->settings['post_types'] = $post_types;
		} elseif ( $object_type === 'term' ) {
			$this->settings['taxonomies'] = $taxonomies;
		} elseif ( $object_type === 'user' ) {
			$user = get_post_meta( $this->item->ID, '_wp_types_group_showfor', true );
			if ( $user === 'all' ) {
				return;
			}
			$label    = [];
			$user     = array_filter( explode( ",", $user ) );
			$wp_roles = new \WP_Roles();
            $users    = $wp_roles->get_names();
			foreach ( $user as $value ) {
				foreach ( $users as $key_user => $value_user ) {
					if ( $value == $key_user ) {
						$label[] = $value_user;
					}
				}
			}
			$id = uniqid();
            $this->settings['include_exclude'] = [
            	'type'     => 'include',
            	'relation' => 'OR',
            	'rules'    => [
            		$id => [
            			'id'    => $id,
            			'name'  => 'user_role',
            			'value' => $user,
            			'label' => $label,
            		]

            	]
            ];
		}

	}

	private function migrate_fields() {

		$fields = new FieldGroups\Fields( $this->item->ID );
		$this->fields = $fields->migrate_fields();

		update_post_meta( $this->post_id, 'fields', $this->fields );
	}

	private function save_id() {
		$object_type = $this->settings['object_type'];

		if ( empty( $_SESSION['field_groups'] ) ) {
			$_SESSION['field_groups'] = [];
		}
		if ( empty( $_SESSION['field_groups'][ $object_type ] ) ) {
			$_SESSION['field_groups'][ $object_type ] = [];
		}
		$_SESSION['field_groups'][ $object_type ][] = $this->item->ID;
	}
}
