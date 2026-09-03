<?php
/**
 * Elementor 4.3 global-classes stand-ins.
 *
 * The door's `touches_for()` asks Elementor which pages a class DELETION
 * would rewrite (Ruling P32), through exactly the two classes Elementor's own
 * `get_posts_affected_by_deletion()` and `translate_delete()` use. Neither can
 * be loaded here, so both are modelled — guarded by `class_exists` so a real
 * Elementor (should one ever be autoloadable in a future suite) always wins.
 *
 * Both read from globals `sa_reset_state()` clears, so a test that says
 * nothing about classes gets an index that answers nothing — which is what
 * every pre-existing door test expects.
 *
 * @package Aura_Worker\Tests
 */

namespace Elementor\Modules\GlobalClasses {

	if ( ! class_exists( '\Elementor\Modules\GlobalClasses\Global_Classes_Relations' ) ) {
		/**
		 * The class → posts reverse index.
		 */
		class Global_Classes_Relations {

			/**
			 * @param string $style_id Global class id.
			 * @return int[]
			 * @throws \RuntimeException When the test asked for a broken index.
			 */
			public function get_posts_by_style( $style_id ) {
				if ( ! empty( $GLOBALS['_sa_class_relations_throw'] ) ) {
					throw new \RuntimeException( 'elementor relations exploded' );
				}
				$map = isset( $GLOBALS['_sa_class_relations'] ) ? (array) $GLOBALS['_sa_class_relations'] : array();
				return isset( $map[ (string) $style_id ] ) ? (array) $map[ (string) $style_id ] : array();
			}
		}
	}

	if ( ! class_exists( '\Elementor\Modules\GlobalClasses\Global_Classes_Repository' ) ) {
		/**
		 * The kit's global-class repository — only `all_labels()` is modelled.
		 */
		class Global_Classes_Repository {

			/**
			 * @param mixed $kit Kit; ignored here.
			 * @return self
			 */
			public static function make( $kit = null ) {
				return new self();
			}

			/**
			 * @return array id => label, as Elementor's `all_labels()` returns.
			 */
			public function all_labels() {
				return isset( $GLOBALS['_sa_class_labels'] ) ? (array) $GLOBALS['_sa_class_labels'] : array();
			}
		}
	}
}
