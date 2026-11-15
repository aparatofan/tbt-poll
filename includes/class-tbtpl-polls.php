<?php
/**
 * The tbt_poll post type, its one meta key and the code behind the public
 * PHP API (tbt_poll_parse / tbt_poll_create / tbt_poll_shortcode).
 *
 * `_tbtpl_questions` holds the questions as JSON:
 *   [{"q":"…","a":["…","…","…","…"]}, …]
 * It is a fixed data contract: new keys additive only, never renamed.
 */

defined( 'ABSPATH' ) || exit;

class TBTPL_Polls {

	const POST_TYPE = 'tbt_poll';
	const META      = '_tbtpl_questions';
	const CAP       = 'manage_options';
	const DOMAINS   = array( 'gi', 'pd', 'le' );

	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'before_delete' ) );
	}

	public static function register_post_type() {
		$cap = self::CAP;

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Polls', 'tbt-poll' ),
					'singular_name'      => __( 'Poll', 'tbt-poll' ),
					'menu_name'          => __( 'Polls', 'tbt-poll' ),
					'all_items'          => __( 'Polls', 'tbt-poll' ),
					'add_new'            => __( 'Add New Poll', 'tbt-poll' ),
					'add_new_item'       => __( 'Add New Poll', 'tbt-poll' ),
					'edit_item'          => __( 'Edit Poll', 'tbt-poll' ),
					'new_item'           => __( 'New Poll', 'tbt-poll' ),
					'search_items'       => __( 'Search Polls', 'tbt-poll' ),
					'not_found'          => __( 'No polls found.', 'tbt-poll' ),
					'not_found_in_trash' => __( 'No polls found in Trash.', 'tbt-poll' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => defined( 'TBT_HUB_SLUG' ) ? TBT_HUB_SLUG : true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_icon'           => 'dashicons-chart-bar',
				'supports'            => array( 'title' ),
				// Every capability maps to manage_options: administrators only.
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'edit_published_posts'   => $cap,
					'edit_private_posts'     => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'delete_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'delete_private_posts'   => $cap,
					'create_posts'           => $cap,
				),
			)
		);
	}

	/**
	 * Permanent deletion removes the votes; trashing keeps them.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public static function before_delete( $post_id ) {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			TBTPL_DB::delete_for_poll( (int) $post_id );
		}
	}

	/* ---------- Reading ---------- */

	/**
	 * A published poll with at least one question, or null.
	 *
	 * @param int $poll_id Poll ID.
	 * @return WP_Post|null
	 */
	public static function published( $poll_id ) {
		$post = $poll_id > 0 ? get_post( (int) $poll_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		return self::questions( $post->ID ) ? $post : null;
	}

	/**
	 * @param int $poll_id Poll ID.
	 * @return array[]
	 */
	public static function questions( $poll_id ) {
		return TBTPL_Format::decode( get_post_meta( (int) $poll_id, self::META, true ) );
	}

	/**
	 * Store questions. update_post_meta() unslashes, so slash first.
	 *
	 * @param int   $poll_id   Poll ID.
	 * @param array $questions Validated questions.
	 */
	public static function save_questions( $poll_id, array $questions ) {
		$json = wp_json_encode( self::clean( $questions ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		update_post_meta( (int) $poll_id, self::META, wp_slash( $json ) );
	}

	/**
	 * Sanitise every string. Keeps any additive keys on a question.
	 *
	 * @param array $questions Questions.
	 * @return array[]
	 */
	public static function clean( array $questions ) {
		$out = array();
		foreach ( array_values( $questions ) as $question ) {
			$question = is_array( $question ) ? $question : array();
			$answers  = array();
			foreach ( ( isset( $question['a'] ) && is_array( $question['a'] ) ) ? array_values( $question['a'] ) : array() as $answer ) {
				$answers[] = sanitize_text_field( (string) $answer );
			}
			$question['q'] = isset( $question['q'] ) && is_string( $question['q'] ) ? sanitize_text_field( $question['q'] ) : '';
			$question['a'] = $answers;
			$out[]         = $question;
		}
		return $out;
	}

	/**
	 * @param string $domain Raw domain attribute.
	 * @return string 'gi', 'pd', 'le' or ''.
	 */
	public static function domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		return in_array( $domain, self::DOMAINS, true ) ? $domain : '';
	}

	/* ---------- Public API (wrapped in tbt-poll.php) ---------- */

	/**
	 * @param string $text Paste format.
	 * @return array|WP_Error
	 */
	public static function api_parse( $text ) {
		$questions = TBTPL_Format::parse( (string) $text );
		$valid     = TBTPL_Format::validate( $questions );
		if ( true !== $valid ) {
			return new WP_Error( 'tbtpl_invalid', $valid[0] );
		}
		return $questions;
	}

	/**
	 * @param string $title     Lesson title.
	 * @param array  $questions Questions.
	 * @return int|WP_Error
	 */
	public static function api_create( $title, array $questions ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new WP_Error( 'tbtpl_forbidden', __( 'Only administrators can create polls.', 'tbt-poll' ) );
		}

		$questions = self::clean( $questions );
		$valid     = TBTPL_Format::validate( $questions );
		if ( true !== $valid ) {
			return new WP_Error( 'tbtpl_invalid', $valid[0] );
		}

		$title = sanitize_text_field( (string) $title );
		if ( '' === $title ) {
			return new WP_Error( 'tbtpl_invalid', __( 'Give the poll a title.', 'tbt-poll' ) );
		}

		$poll_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $poll_id ) ) {
			return $poll_id;
		}

		self::save_questions( (int) $poll_id, $questions );
		return (int) $poll_id;
	}

	/**
	 * @param int    $poll_id Poll ID.
	 * @param string $domain  gi, pd, le or ''.
	 * @return string
	 */
	public static function api_shortcode( $poll_id, $domain = '' ) {
		$domain = self::domain( $domain );
		return sprintf( '[tbt_poll id="%d"%s]', absint( $poll_id ), '' !== $domain ? ' domain="' . $domain . '"' : '' );
	}
}
