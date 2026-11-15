<?php
/**
 * Pure helpers: the paste format, validation, shape and percentages.
 *
 * No WordPress calls, so tests/test-format.php can run it without
 * WordPress. Change the tests with it.
 *
 * A poll's questions are always the structure stored in `_tbtpl_questions`:
 *
 *   [ [ 'q' => 'Question text', 'a' => [ 'Answer', 'Answer', … ] ], … ]
 */

defined( 'ABSPATH' ) || exit;

class TBTPL_Format {

	const MAX_QUESTIONS = 5;
	const MIN_ANSWERS   = 2;
	const MAX_ANSWERS   = 6;
	const LETTERS       = 'ABCDEF';

	/**
	 * Parse the paste format.
	 *
	 * A line that starts with a letter A–F followed by `.` or `)` is an
	 * answer to the question above it; any other non-empty line starts a new
	 * question. Surrounding `**` (Word bold) is removed and blank lines are
	 * ignored. Answers that come before any question are kept under a
	 * question whose `q` is null, so validate() can name the problem.
	 *
	 * @param string $text Pasted text.
	 * @return array[] [ [ 'q' => string|null, 'a' => string[] ], … ]
	 */
	public static function parse( string $text ): array {
		$lines     = preg_split( '/\R/u', $text );
		$questions = array();

		foreach ( (array) $lines as $line ) {
			$line = self::clean_line( (string) $line );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^[A-Fa-f][.)]\s*(.*)$/su', $line, $m ) ) {
				$answer = self::clean_line( $m[1] );
				if ( ! $questions ) {
					$questions[] = array(
						'q' => null,
						'a' => array(),
					);
				}
				$questions[ count( $questions ) - 1 ]['a'][] = $answer;
				continue;
			}

			$questions[] = array(
				'q' => $line,
				'a' => array(),
			);
		}

		return $questions;
	}

	/**
	 * Check a questions structure against the poll's limits.
	 *
	 * @param array $questions Output of parse(), or the same shape from code.
	 * @return true|string[] True, or plain-English problems, first one first.
	 */
	public static function validate( array $questions ) {
		$problems = array();
		$count    = count( $questions );

		if ( 0 === $count ) {
			return array( 'Add at least one question.' );
		}
		if ( $count > self::MAX_QUESTIONS ) {
			$problems[] = 'A poll can have at most ' . self::MAX_QUESTIONS . ' questions.';
		}

		$n = 0;
		foreach ( array_values( $questions ) as $i => $question ) {
			$q = is_array( $question ) && array_key_exists( 'q', $question ) ? $question['q'] : '';
			$a = is_array( $question ) && isset( $question['a'] ) && is_array( $question['a'] ) ? array_values( $question['a'] ) : array();

			if ( null === $q && 0 === $i ) {
				$problems[] = 'An answer comes before the first question.';
				continue;
			}

			$n++;
			if ( ! is_string( $q ) || '' === trim( $q ) ) {
				$problems[] = "Question {$n} has no text.";
			}
			if ( count( $a ) < self::MIN_ANSWERS ) {
				$problems[] = "Question {$n} needs at least " . self::MIN_ANSWERS . ' answers.';
			} elseif ( count( $a ) > self::MAX_ANSWERS ) {
				$problems[] = "Question {$n} can have at most " . self::MAX_ANSWERS . ' answers.';
			}
			foreach ( $a as $j => $answer ) {
				if ( ! is_string( $answer ) || '' === trim( $answer ) ) {
					$letter     = $j < self::MAX_ANSWERS ? self::LETTERS[ $j ] : (string) ( $j + 1 );
					$problems[] = "Question {$n}, answer {$letter} has no text.";
				}
			}
		}

		return $problems ? $problems : true;
	}

	/**
	 * Same number of questions and the same number of answers in each.
	 *
	 * @param array $a Questions.
	 * @param array $b Questions.
	 * @return bool
	 */
	public static function same_shape( array $a, array $b ): bool {
		return self::shape( $a ) === self::shape( $b );
	}

	/**
	 * The number of answers per question, e.g. [4, 4, 4].
	 *
	 * @param array $questions Questions.
	 * @return int[]
	 */
	public static function shape( array $questions ): array {
		$shape = array();
		foreach ( array_values( $questions ) as $question ) {
			$shape[] = is_array( $question ) && isset( $question['a'] ) && is_array( $question['a'] ) ? count( $question['a'] ) : 0;
		}
		return $shape;
	}

	/**
	 * "3 × 4" when every question has the same number of answers,
	 * otherwise "3 questions"; "" for none.
	 *
	 * @param array $questions Questions.
	 * @return string
	 */
	public static function shape_label( array $questions ): string {
		$shape = self::shape( $questions );
		if ( ! $shape ) {
			return '';
		}
		if ( 1 === count( array_unique( $shape ) ) ) {
			return count( $shape ) . ' × ' . $shape[0];
		}
		return count( $shape ) . ' questions';
	}

	/**
	 * The reverse of parse(), for the admin textarea.
	 *
	 * @param array $questions Questions.
	 * @return string
	 */
	public static function to_text( array $questions ): string {
		$blocks = array();
		foreach ( array_values( $questions ) as $question ) {
			$lines = array();
			if ( isset( $question['q'] ) && is_string( $question['q'] ) ) {
				$lines[] = $question['q'];
			}
			$answers = isset( $question['a'] ) && is_array( $question['a'] ) ? array_values( $question['a'] ) : array();
			foreach ( $answers as $j => $answer ) {
				$letter  = $j < self::MAX_ANSWERS ? self::LETTERS[ $j ] : 'F';
				$lines[] = $letter . '. ' . (string) $answer;
			}
			$blocks[] = implode( "\n", $lines );
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Whole percentages that add up to exactly 100 (largest-remainder
	 * rounding; ties go to the earlier answer). All zeros with no votes.
	 *
	 * @param array $counts Votes per answer.
	 * @return int[]
	 */
	public static function percentages( array $counts ): array {
		$counts = array_map( 'intval', array_values( $counts ) );
		$counts = array_map(
			static function ( $n ) {
				return max( 0, $n );
			},
			$counts
		);
		$total = array_sum( $counts );
		if ( $total <= 0 ) {
			return array_fill( 0, count( $counts ), 0 );
		}

		$floors     = array();
		$remainders = array();
		foreach ( $counts as $k => $n ) {
			$exact          = $n * 100 / $total;
			$floors[ $k ]   = (int) floor( $exact );
			$remainders[]   = array( $exact - $floors[ $k ], $k );
		}

		usort(
			$remainders,
			static function ( $x, $y ) {
				if ( $x[0] === $y[0] ) {
					return $x[1] <=> $y[1];
				}
				return $y[0] <=> $x[0];
			}
		);

		$rest = 100 - array_sum( $floors );
		foreach ( $remainders as $r ) {
			if ( $rest <= 0 ) {
				break;
			}
			$floors[ $r[1] ]++;
			$rest--;
		}

		return $floors;
	}

	/**
	 * Decode the stored meta into questions. Anything unreadable is [].
	 *
	 * @param mixed $json The `_tbtpl_questions` value.
	 * @return array[]
	 */
	public static function decode( $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$questions = array();
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$answers = array();
			foreach ( ( isset( $item['a'] ) && is_array( $item['a'] ) ) ? $item['a'] : array() as $answer ) {
				$answers[] = (string) $answer;
			}
			// Keep any additive keys a later release may have stored.
			$item['q'] = isset( $item['q'] ) ? (string) $item['q'] : '';
			$item['a'] = $answers;
			$questions[] = $item;
		}
		return $questions;
	}

	/**
	 * Trim a line and strip surrounding `**`.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	private static function clean_line( string $line ): string {
		$line = trim( str_replace( "\xC2\xA0", ' ', $line ) );
		while ( strlen( $line ) >= 4 && '**' === substr( $line, 0, 2 ) && '**' === substr( $line, -2 ) ) {
			$line = trim( substr( $line, 2, -2 ) );
		}
		// Word sometimes bolds only the start or the end of a line.
		if ( '**' === substr( $line, 0, 2 ) ) {
			$line = ltrim( substr( $line, 2 ) );
		}
		if ( strlen( $line ) >= 2 && '**' === substr( $line, -2 ) ) {
			$line = rtrim( substr( $line, 0, -2 ) );
		}
		return $line;
	}
}
