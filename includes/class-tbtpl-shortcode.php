<?php
/**
 * [tbt_poll id="123" domain="gi"] and its assets.
 *
 * The server works out the state and embeds the poll's data as JSON on the
 * root element; assets/js/poll.js draws every state from it (one renderer).
 * The vote route answers with the same shape, from data().
 *
 * The design tokens are TBT Hub's, under the shared handle 'tbt-tokens';
 * without Hub the vendored copy is registered under that same handle (the
 * TBT Results pattern).
 */

defined( 'ABSPATH' ) || exit;

class TBTPL_Shortcode {

	const TAG = 'tbt_poll';

	public static function register() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		// After Hub registers 'tbt-tokens' at priority 5.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'early_styles' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_nocache' ) );
		add_action( 'wp_ajax_tbtpl_nonce', array( __CLASS__, 'fresh_nonce' ) );
	}

	/* ---------- Assets ---------- */

	private static function register_assets() {
		if ( ! wp_style_is( 'tbt-tokens', 'registered' ) ) {
			wp_register_style( 'tbt-tokens', TBTPL_URL . 'assets/css/tbt-tokens.css', array(), self::file_version( 'assets/css/tbt-tokens.css' ) );
		}
		if ( ! wp_style_is( 'tbtpl-fonts', 'registered' ) ) {
			wp_register_style( 'tbtpl-fonts', TBTPL_URL . 'assets/css/fonts.css', array(), TBTPL_VERSION );
		}
		if ( ! wp_style_is( 'tbtpl-poll', 'registered' ) ) {
			wp_register_style( 'tbtpl-poll', TBTPL_URL . 'assets/css/poll.css', array( 'tbt-tokens', 'tbtpl-fonts' ), TBTPL_VERSION );
		}
		if ( ! wp_script_is( 'tbtpl-poll', 'registered' ) ) {
			wp_register_script( 'tbtpl-poll', TBTPL_URL . 'assets/js/poll.js', array(), TBTPL_VERSION, true );
		}
	}

	/**
	 * Styles in the head on a page holding the shortcode, so nothing
	 * flashes unstyled. The script is added by the shortcode itself.
	 */
	public static function early_styles() {
		if ( self::page_has_poll() ) {
			self::register_assets();
			wp_enqueue_style( 'tbtpl-poll' );
		}
	}

	/**
	 * A per-visitor page (state and nonce are embedded): keep it out of
	 * every cache, exactly as TBT Results does.
	 */
	public static function maybe_nocache() {
		if ( ! self::page_has_poll() || headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * A fresh REST nonce for a page left open until its nonce expired.
	 * Logged-in users only (wp_ajax_, not wp_ajax_nopriv_).
	 */
	public static function fresh_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * @return bool
	 */
	private static function page_has_poll() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( (string) $post->post_content, self::TAG );
	}

	/**
	 * Cache buster for the vendored token file: its modification time.
	 *
	 * @param string $relative Path relative to the plugin folder.
	 * @return string
	 */
	private static function file_version( $relative ) {
		$mtime = @filemtime( TBTPL_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $mtime ? TBTPL_VERSION . '.' . $mtime : TBTPL_VERSION;
	}

	/* ---------- Shortcode ---------- */

	/**
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts    = shortcode_atts(
			array(
				'id'     => 0,
				'domain' => '',
			),
			$atts,
			self::TAG
		);
		$poll_id = absint( $atts['id'] );
		$poll    = TBTPL_Polls::published( $poll_id );

		if ( ! $poll ) {
			if ( current_user_can( TBTPL_Polls::CAP ) ) {
				return '<p class="tbtpl-notice">' . esc_html( sprintf( /* translators: %d: poll ID. */ __( 'Poll %d not found.', 'tbt-poll' ), $poll_id ) ) . '</p>';
			}
			return '';
		}

		self::register_assets();
		wp_enqueue_style( 'tbtpl-poll' );
		wp_enqueue_script( 'tbtpl-poll' );

		$post_id = (int) get_the_ID();
		$data    = self::data( $poll, get_current_user_id(), $post_id, get_permalink( $post_id ) );
		$domain  = TBTPL_Polls::domain( $atts['domain'] );

		return sprintf(
			'<div class="tbtpl"%1$s data-tbtpl="%2$s" aria-live="polite"><noscript><p class="tbtpl-muted">%3$s</p></noscript></div>',
			'' !== $domain ? ' data-domain="' . esc_attr( $domain ) . '"' : '',
			esc_attr( wp_json_encode( $data ) ),
			esc_html__( 'The poll needs JavaScript. Turn it on and reload the page.', 'tbt-poll' )
		);
	}

	/**
	 * The data every state is drawn from; also the vote route's response.
	 *
	 * @param WP_Post      $poll    Published poll.
	 * @param int          $user_id Session user, 0 for a visitor.
	 * @param int          $post_id The lesson, 0 when unknown.
	 * @param string|false $here    URL to come back to after logging in.
	 * @return array
	 */
	public static function data( $poll, $user_id, $post_id, $here = '' ) {
		$questions = TBTPL_Polls::questions( $poll->ID );
		$raw       = TBTPL_DB::counts( $poll->ID );
		$ballot    = $user_id > 0 ? TBTPL_DB::ballot( $poll->ID, $user_id ) : null;

		$counts  = array();
		$percent = array();
		$mine    = null;
		foreach ( $questions as $i => $question ) {
			$row = array();
			foreach ( $question['a'] as $j => $unused ) {
				$row[] = (int) ( $raw[ $i ][ $j ] ?? 0 );
			}
			$counts[]  = $row;
			$percent[] = TBTPL_Format::percentages( $row );
		}
		if ( null !== $ballot ) {
			$mine = array();
			foreach ( $questions as $i => $unused ) {
				$mine[] = isset( $ballot[ $i ] ) ? (int) $ballot[ $i ] : null;
			}
		}

		if ( $user_id <= 0 ) {
			$state = 'guest';
		} elseif ( null !== $mine ) {
			$state = 'results';
		} else {
			$state = 'vote';
		}

		$public = array();
		foreach ( $questions as $question ) {
			$public[] = array(
				'q' => $question['q'],
				'a' => $question['a'],
			);
		}

		return array(
			'id'        => (int) $poll->ID,
			'post_id'   => (int) $post_id,
			'state'     => $state,
			'questions' => $public,
			'counts'    => $counts,
			'percent'   => $percent,
			'voters'    => TBTPL_DB::voters( $poll->ID ),
			'mine'      => $mine,
			'login_url' => wp_login_url( $here ? $here : '' ),
			'rest'      => esc_url_raw( rest_url( TBTPL_Rest::NS . '/' ) ),
			'nonce'     => $user_id > 0 ? wp_create_nonce( 'wp_rest' ) : '',
			'ajax'      => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
		);
	}
}
