<?php
/**
 * wp-admin: the Polls list columns, the Questions meta box and the save
 * rules. Plain wp-admin styling.
 */

defined( 'ABSPATH' ) || exit;

class TBTPL_Admin {

	const NONCE  = 'tbtpl_questions_nonce';
	const ACTION = 'tbtpl_save_questions';
	const FIELD  = 'tbtpl_questions_text';

	/** @var bool Set when this request's save was refused. */
	private static $refused = false;

	public static function register() {
		add_action( 'add_meta_boxes_' . TBTPL_Polls::POST_TYPE, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . TBTPL_Polls::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'manage_' . TBTPL_Polls::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . TBTPL_Polls::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/* ---------- Edit screen ---------- */

	public static function meta_box() {
		add_meta_box( 'tbtpl-questions', __( 'Questions', 'tbt-poll' ), array( __CLASS__, 'render_box' ), TBTPL_Polls::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * @param WP_Post $post Poll.
	 */
	public static function render_box( $post ) {
		wp_nonce_field( self::ACTION, self::NONCE );

		$questions = TBTPL_Polls::questions( $post->ID );
		$text      = TBTPL_Format::to_text( $questions );
		$voters    = TBTPL_DB::voters( $post->ID );

		if ( $voters > 0 ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of votes. */
						_n(
							'This poll has %d vote. You can correct the wording, but not add, remove or move questions or answers.',
							'This poll has %d votes. You can correct the wording, but not add, remove or move questions or answers.',
							$voters,
							'tbt-poll'
						),
						$voters
					)
				)
			);
		}
		?>
		<p class="description">
			<?php esc_html_e( 'One question per line, then its answers on lines starting A. B. C. … (up to F). 1–5 questions, 2–6 answers each.', 'tbt-poll' ); ?>
		</p>
		<textarea name="<?php echo esc_attr( self::FIELD ); ?>" rows="18" class="large-text code" spellcheck="true"><?php echo esc_textarea( $text ); ?></textarea>
		<?php
		if ( 'publish' === $post->post_status && $questions ) {
			printf(
				'<p>%s <code style="user-select:all">%s</code></p>',
				esc_html__( 'Shortcode:', 'tbt-poll' ),
				esc_html( TBTPL_Polls::api_shortcode( $post->ID ) )
			);
		}
	}

	/**
	 * @param int     $post_id Poll.
	 * @param WP_Post $post    Poll.
	 */
	public static function save( $post_id, $post ) {
		// Only the meta box submits this field; tbt_poll_create() saves meta itself.
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( TBTPL_Polls::CAP ) ) {
			return;
		}

		$text      = isset( $_POST[ self::FIELD ] ) ? (string) wp_unslash( $_POST[ self::FIELD ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed, then every string goes through sanitize_text_field().
		$questions = TBTPL_Polls::clean( TBTPL_Format::parse( $text ) );
		// parse() marks answers before any question with a null question;
		// clean() turns that into '', so validate the raw parse for it.
		$valid = TBTPL_Format::validate( TBTPL_Format::parse( $text ) );
		if ( true === $valid ) {
			$valid = TBTPL_Format::validate( $questions );
		}

		if ( true !== $valid ) {
			self::refuse( $valid[0] );
			return;
		}

		$old = TBTPL_Polls::questions( $post_id );
		if ( TBTPL_DB::has_votes( $post_id ) && ! TBTPL_Format::same_shape( $old, $questions ) ) {
			self::refuse( __( 'This poll already has votes, so its questions and answers can’t be added, removed or moved. Only the wording can change.', 'tbt-poll' ) );
			return;
		}

		TBTPL_Polls::save_questions( $post_id, $questions );
	}

	/**
	 * Keep the old meta and show the problem on the next screen.
	 *
	 * @param string $message Plain text.
	 */
	private static function refuse( $message ) {
		self::$refused = true;
		set_transient( 'tbtpl_notice_' . get_current_user_id(), $message, 60 );
	}

	/**
	 * Drop WordPress's "Post updated." when the questions were refused.
	 *
	 * @param string $location Redirect URL.
	 * @param int    $post_id  Post ID.
	 * @return string
	 */
	public static function redirect( $location, $post_id ) {
		if ( self::$refused && TBTPL_Polls::POST_TYPE === get_post_type( $post_id ) ) {
			$location = remove_query_arg( 'message', $location );
		}
		return $location;
	}

	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || TBTPL_Polls::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$key     = 'tbtpl_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( ! $message ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( /* translators: %s: the problem. */ __( 'The questions were not saved. %s', 'tbt-poll' ), $message ) )
		);
	}

	/* ---------- List ---------- */

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'              => $columns['cb'] ?? '<input type="checkbox" />',
			'title'           => __( 'Title', 'tbt-poll' ),
			'tbtpl_questions' => __( 'Questions', 'tbt-poll' ),
			'tbtpl_votes'     => __( 'Votes', 'tbt-poll' ),
			'tbtpl_shortcode' => __( 'Shortcode', 'tbt-poll' ),
			'date'            => __( 'Date', 'tbt-poll' ),
		);
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Poll.
	 */
	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'tbtpl_questions':
				$label = TBTPL_Format::shape_label( TBTPL_Polls::questions( $post_id ) );
				echo esc_html( '' !== $label ? $label : '—' );
				break;
			case 'tbtpl_votes':
				echo esc_html( number_format_i18n( TBTPL_DB::voters( $post_id ) ) );
				break;
			case 'tbtpl_shortcode':
				printf( '<code style="user-select:all">%s</code>', esc_html( TBTPL_Polls::api_shortcode( $post_id ) ) );
				break;
		}
	}
}
