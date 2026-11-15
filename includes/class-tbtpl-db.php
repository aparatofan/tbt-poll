<?php
/**
 * The votes table: install, upgrade, reads and writes.
 *
 * One row per answered question. The unique key (poll_id, user_id,
 * question) is what makes a vote final: a second ballot fails on insert,
 * whatever the browser does.
 */

defined( 'ABSPATH' ) || exit;

class TBTPL_DB {

	const OPTION = 'tbtpl_db_version';

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tbtpl_votes';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  poll_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  question tinyint(3) unsigned NOT NULL,
  answer tinyint(3) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY poll_user_question (poll_id,user_id,question),
  KEY poll_question_answer (poll_id,question,answer)
) {$charset};";

		dbDelta( $sql );
		update_option( self::OPTION, TBTPL_DB_VERSION, false );
	}

	/**
	 * Install when the stored schema version differs from the code's.
	 */
	public static function maybe_upgrade() {
		if ( (string) get_option( self::OPTION ) !== (string) TBTPL_DB_VERSION ) {
			self::install();
		}
	}

	/* ---------- Reads ---------- */

	/**
	 * Votes per answer.
	 *
	 * @param int $poll_id Poll.
	 * @return array [ question => [ answer => n ] ]
	 */
	public static function counts( $poll_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT question, answer, COUNT(*) AS n FROM {$table} WHERE poll_id = %d GROUP BY question, answer", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $poll_id
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['question'] ][ (int) $row['answer'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Number of students who voted.
	 *
	 * @param int $poll_id Poll.
	 * @return int
	 */
	public static function voters( $poll_id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE poll_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $poll_id
			)
		);
	}

	/**
	 * A user's answers.
	 *
	 * @param int $poll_id Poll.
	 * @param int $user_id User.
	 * @return array|null [ question => answer ], or null when they have not voted.
	 */
	public static function ballot( $poll_id, $user_id ) {
		global $wpdb;
		if ( (int) $user_id <= 0 ) {
			return null;
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT question, answer FROM {$table} WHERE poll_id = %d AND user_id = %d ORDER BY question", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $poll_id,
				(int) $user_id
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return null;
		}
		$ballot = array();
		foreach ( $rows as $row ) {
			$ballot[ (int) $row['question'] ] = (int) $row['answer'];
		}
		return $ballot;
	}

	/**
	 * @param int $poll_id Poll.
	 * @return bool
	 */
	public static function has_votes( $poll_id ) {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE poll_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $poll_id
			)
		);
	}

	/* ---------- Writes ---------- */

	/**
	 * Store a whole ballot in one transaction.
	 *
	 * @param int   $poll_id Poll.
	 * @param int   $user_id User (the session user).
	 * @param array $answers [ question => answer ], 0-based.
	 * @return true|WP_Error tbtpl_already_voted on a duplicate key.
	 */
	public static function insert_ballot( $poll_id, $user_id, array $answers ) {
		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// A duplicate key is an expected outcome here, not a page error.
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( 'START TRANSACTION' );

		foreach ( $answers as $question => $answer ) {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (poll_id, user_id, question, answer, created_at) VALUES (%d, %d, %d, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $poll_id,
					(int) $user_id,
					(int) $question,
					(int) $answer,
					$now
				)
			);
			if ( false === $ok ) {
				$error = (string) $wpdb->last_error;
				$wpdb->query( 'ROLLBACK' );
				$wpdb->suppress_errors( $suppress );
				if ( false !== stripos( $error, 'Duplicate' ) ) {
					return new WP_Error( 'tbtpl_already_voted', __( 'You have already voted in this poll.', 'tbt-poll' ), array( 'status' => 409 ) );
				}
				return new WP_Error( 'tbtpl_write_failed', __( 'Your vote could not be saved.', 'tbt-poll' ), array( 'status' => 500 ) );
			}
		}

		$wpdb->query( 'COMMIT' );
		$wpdb->suppress_errors( $suppress );
		return true;
	}

	/**
	 * Remove every vote for a poll (on permanent deletion only).
	 *
	 * @param int $poll_id Poll.
	 */
	public static function delete_for_poll( $poll_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'poll_id' => (int) $poll_id ), array( '%d' ) );
	}
}
