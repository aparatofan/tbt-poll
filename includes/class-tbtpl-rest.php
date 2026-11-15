<?php
/**
 * POST /wp-json/tbt-poll/v1/polls/{id}/vote
 *
 * The user is always the session (get_current_user_id()), never the
 * request. The vote is final: the votes table's unique key refuses a
 * second ballot. Reporting to Notes and asking Points fail open.
 */

defined( 'ABSPATH' ) || exit;

class TBTPL_Rest {

	const NS = 'tbt-poll/v1';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'nocache_all' ), 10, 3 );
	}

	/**
	 * No-cache headers on every answer under this namespace, including the
	 * ones WordPress itself produces (401, an invalid nonce).
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function nocache_all( $response, $server, $request ) {
		if ( $response instanceof WP_HTTP_Response && $request instanceof WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/' . self::NS . '/' ) ) {
			foreach ( wp_get_nocache_headers() as $name => $value ) {
				if ( $value ) {
					$response->header( $name, $value );
				}
			}
		}
		return $response;
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/polls/(?P<id>\d+)/vote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'vote' ),
				'permission_callback' => array( __CLASS__, 'can_vote' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Logged in. Cookie auth only counts with a valid wp_rest nonce, so a
	 * request without one arrives as user 0 and is refused here.
	 *
	 * @return true|WP_Error
	 */
	public static function can_vote() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error( 'tbtpl_login', __( 'Log in to vote.', 'tbt-poll' ), array( 'status' => 401 ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function vote( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$poll    = TBTPL_Polls::published( (int) $request['id'] );
		if ( ! $poll ) {
			return self::error( 'tbtpl_not_found', __( 'That poll does not exist.', 'tbt-poll' ), 404 );
		}

		$questions = TBTPL_Polls::questions( $poll->ID );
		$answers   = self::ballot_from( $request->get_param( 'answers' ), $questions );
		if ( null === $answers ) {
			return self::error( 'tbtpl_bad_ballot', __( 'Answer every question once.', 'tbt-poll' ), 400 );
		}

		$post_id = self::lesson_id( $request->get_param( 'post_id' ) );
		$here    = $post_id ? get_permalink( $post_id ) : home_url( '/' );

		if ( null !== TBTPL_DB::ballot( $poll->ID, $user_id ) ) {
			return self::already_voted( $poll, $user_id, $post_id, $here );
		}

		$stored = TBTPL_DB::insert_ballot( $poll->ID, $user_id, $answers );
		if ( is_wp_error( $stored ) ) {
			if ( 'tbtpl_already_voted' === $stored->get_error_code() ) {
				return self::already_voted( $poll, $user_id, $post_id, $here );
			}
			return self::error( $stored->get_error_code(), $stored->get_error_message(), 500 );
		}

		$activity_id = self::report_to_notes( $poll->ID, $user_id, $post_id );
		$points      = self::points_for( $activity_id );

		$data           = TBTPL_Shortcode::data( $poll, $user_id, $post_id, $here );
		$data['points'] = $points;

		return self::respond( $data, 200 );
	}

	/**
	 * Exactly one in-range integer per question, or null.
	 *
	 * @param mixed $raw       Request value.
	 * @param array $questions Poll questions.
	 * @return int[]|null
	 */
	private static function ballot_from( $raw, array $questions ) {
		if ( ! is_array( $raw ) || ! $questions || count( $raw ) !== count( $questions ) || array_values( $raw ) !== $raw ) {
			return null;
		}
		$answers = array();
		foreach ( $raw as $i => $value ) {
			if ( is_int( $value ) ) {
				$answer = $value;
			} elseif ( is_string( $value ) && preg_match( '/^\d{1,2}$/', $value ) ) {
				$answer = (int) $value;
			} else {
				return null;
			}
			if ( $answer < 0 || $answer >= count( $questions[ $i ]['a'] ) ) {
				return null;
			}
			$answers[ $i ] = $answer;
		}
		return $answers;
	}

	/**
	 * Informational only: a published post, otherwise 0.
	 *
	 * @param mixed $raw Request value.
	 * @return int
	 */
	private static function lesson_id( $raw ) {
		$post_id = is_numeric( $raw ) ? absint( $raw ) : 0;
		if ( ! $post_id ) {
			return 0;
		}
		$post = get_post( $post_id );
		return ( $post && 'publish' === $post->post_status ) ? $post_id : 0;
	}

	/**
	 * Record the vote in the Notes activity log (Notes 1.22.0+), as Learning
	 * History does. Fails open.
	 *
	 * @param int $poll_id Poll.
	 * @param int $user_id Session user.
	 * @param int $post_id Lesson or 0.
	 * @return int Activity row ID, 0 when nothing was recorded.
	 */
	private static function report_to_notes( $poll_id, $user_id, $post_id ) {
		if ( ! function_exists( 'tbt_notes_record_activity' ) ) {
			return 0;
		}
		try {
			$result = tbt_notes_record_activity(
				array(
					'user_id'      => $user_id,
					'tool'         => 'poll',
					'object_ref'   => (string) $poll_id,
					'object_title' => html_entity_decode( get_the_title( $poll_id ), ENT_QUOTES, 'UTF-8' ),
					'post_id'      => $post_id,
				)
			);

			// A teacher or admin in no class is deliberately not recorded by
			// Notes; anything else is worth a log line.
			if ( is_wp_error( $result ) ) {
				if ( 'tbt_notes_no_class' !== $result->get_error_code() ) {
					error_log( 'TBT Poll: vote not reported to TBT Notes: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return 0;
			}
			return (int) $result;
		} catch ( \Throwable $e ) {
			error_log( 'TBT Poll: vote not reported to TBT Notes: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return 0;
		}
	}

	/**
	 * What the vote earned, from Points 0.1.3's helper when present.
	 *
	 * @param int $activity_id Notes row ID or 0.
	 * @return int|null
	 */
	private static function points_for( $activity_id ) {
		if ( $activity_id <= 0 || ! function_exists( 'tbtp_points_for_activity' ) ) {
			return null;
		}
		try {
			return (int) tbtp_points_for_activity( $activity_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/* ---------- Responses ---------- */

	/**
	 * 409 with the current results, so the page can show them.
	 *
	 * @param WP_Post      $poll    Poll.
	 * @param int          $user_id Session user.
	 * @param int          $post_id Lesson or 0.
	 * @param string|false $here    Return URL.
	 * @return WP_REST_Response
	 */
	private static function already_voted( $poll, $user_id, $post_id, $here ) {
		return self::error(
			'tbtpl_already_voted',
			__( 'You have already voted in this poll.', 'tbt-poll' ),
			409,
			array( 'poll' => TBTPL_Shortcode::data( $poll, $user_id, $post_id, $here ) )
		);
	}

	/**
	 * An error in the WP_Error JSON shape, with no-cache headers.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param array  $extra   Extra keys for `data`.
	 * @return WP_REST_Response
	 */
	private static function error( $code, $message, $status, array $extra = array() ) {
		return self::respond(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array_merge( array( 'status' => $status ), $extra ),
			),
			$status
		);
	}

	/**
	 * @param array $data   Body.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function respond( array $data, $status ) {
		$response = new WP_REST_Response( $data, $status );
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}
}
