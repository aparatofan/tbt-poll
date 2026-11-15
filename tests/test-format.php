<?php
/**
 * Standalone tests for TBTPL_Format: parse, validate, same_shape, to_text
 * and percentages. WordPress is not loaded.
 *
 * Run: php tests/test-format.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/class-tbtpl-format.php';

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: {$label}\n";
	}
}
function same( $actual, $expected, $label ) {
	ok( $actual === $expected, $label . ( $actual === $expected ? '' : "\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) ) );
}
function first_problem( $questions ) {
	$r = TBTPL_Format::validate( $questions );
	return true === $r ? true : $r[0];
}

/* ---------- parse() ---------- */

$stress = <<<TXT
After watching this video, which insight about stress do you find most surprising?
A. That believing stress is harmful can be more dangerous than the stress itself.
B. That viewing your stress response as helpful changes how your body actually reacts.
C. That oxytocin is released as part of the stress response, not only when we hug someone.
D. That caring for others can completely cancel out the harmful effects of stress.
Which of Kelly McGonigal’s ideas do you think would help you the most?
A. Treating a pounding heart as a sign that the body is preparing me for a challenge.
B. Reaching out to others when I feel under pressure instead of bottling things up.
C. Helping someone else when I’m stressed rather than focusing only on myself.
D. Chasing meaning in life instead of trying to avoid every difficult situation.
Which takeaway from this video are you most likely to apply at work?
A. Reminding myself that my stress response is helping me rise to a challenge.
B. Talking to a colleague when I feel overwhelmed instead of hiding the problem.
C. Offering help to a teammate when I notice they are struggling under pressure.
D. Choosing a meaningful project even if it brings more stress than an easier one.
TXT;

$p = TBTPL_Format::parse( $stress );
same( TBTPL_Format::shape( $p ), array( 4, 4, 4 ), 'Stress lesson parses to 3 × 4' );
same( $p[1]['q'], 'Which of Kelly McGonigal’s ideas do you think would help you the most?', 'question text kept, curly apostrophe intact' );
same( $p[0]['a'][0], 'That believing stress is harmful can be more dangerous than the stress itself.', 'answer text without its letter' );
same( TBTPL_Format::validate( $p ), true, 'Stress lesson is valid' );
same( TBTPL_Format::shape_label( $p ), '3 × 4', 'shape label' );

$p = TBTPL_Format::parse( "**Bold question?**\n**A. One**\nB. **Two**\n**C) Three" );
same( $p[0]['q'], 'Bold question?', '**bold** question line' );
same( $p[0]['a'], array( 'One', 'Two', 'Three' ), '**bold** answer lines' );

$p = TBTPL_Format::parse( "Q?\nA) one\nB) two" );
same( $p[0]['a'], array( 'one', 'two' ), 'A) as well as A.' );

$p = TBTPL_Format::parse( "Q?\na. one\nb) two\nc.three" );
same( $p[0]['a'], array( 'one', 'two', 'three' ), 'lowercase letters, missing space' );

$p = TBTPL_Format::parse( "\n\nQ1?\n\nA. one\n\n\nB. two\n\nQ2?\nA. x\nB. y\n\n" );
same( TBTPL_Format::shape( $p ), array( 2, 2 ), 'blank lines ignored' );

$p = TBTPL_Format::parse( "Q1?\r\nA. one\r\nB. two\r\nQ2?\r\nA. x\r\nB. y\r\n" );
same( TBTPL_Format::shape( $p ), array( 2, 2 ), 'Windows line endings' );
same( $p[0]['a'][1], 'two', 'no stray \\r' );

$p = TBTPL_Format::parse( "A. orphan\nQ?\nA. one\nB. two" );
same( $p[0]['q'], null, 'answer before any question is kept under a null question' );
same( first_problem( $p ), 'An answer comes before the first question.', 'answer before any question is reported' );

$p = TBTPL_Format::parse( "Q?\nA. one\nB. two\nG. seven" );
same( count( $p ), 2, 'G. is not an answer letter, so it starts a question' );

same( TBTPL_Format::parse( '' ), array(), 'empty text' );

/* ---------- validate() ---------- */

$q = static function ( $n_answers, $text = 'Q?' ) {
	return array(
		'q' => $text,
		'a' => array_slice( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' ), 0, $n_answers ),
	);
};

same( first_problem( array() ), 'Add at least one question.', 'no questions' );
same( first_problem( array_fill( 0, 6, $q( 4 ) ) ), 'A poll can have at most 5 questions.', 'six questions' );
same( first_problem( array_fill( 0, 5, $q( 4 ) ) ), true, 'five questions is fine' );
same( first_problem( array( $q( 4 ), $q( 1 ) ) ), 'Question 2 needs at least 2 answers.', 'one answer' );
same( first_problem( array( $q( 4 ), $q( 7 ) ) ), 'Question 2 can have at most 6 answers.', 'seven answers' );
same( first_problem( array( $q( 2 ), $q( 6 ) ) ), true, 'two and six answers are fine' );
same( first_problem( array( $q( 4 ), $q( 4 ), $q( 4, '  ' ) ) ), 'Question 3 has no text.', 'empty question text' );
same( first_problem( TBTPL_Format::parse( "A. orphan\nB. orphan" ) ), 'An answer comes before the first question.', 'answers only' );
same( first_problem( array( array( 'q' => 'Q?', 'a' => array( 'x', ' ' ) ) ) ), 'Question 1, answer B has no text.', 'empty answer text' );
same( first_problem( TBTPL_Format::parse( "Q1?\nA. a\nB. b\nQ2?\nA. a\nB. b\nC. c" ) ), true, 'mixed answer counts are valid' );

/* ---------- same_shape() ---------- */

$base = TBTPL_Format::parse( $stress );
$copy = $base;
same( TBTPL_Format::same_shape( $base, $copy ), true, 'equal' );

$fewer = $base;
array_pop( $fewer[1]['a'] );
same( TBTPL_Format::same_shape( $base, $fewer ), false, 'one fewer answer' );

$more = $base;
$more[] = $q( 4 );
same( TBTPL_Format::same_shape( $base, $more ), false, 'one more question' );

$reworded = $base;
$reworded[0]['q']    = 'A completely different question?';
$reworded[2]['a'][3] = 'Different wording.';
same( TBTPL_Format::same_shape( $base, $reworded ), true, 'reworded only' );

/* ---------- to_text() ---------- */

same( TBTPL_Format::parse( TBTPL_Format::to_text( $base ) ), $base, 'to_text( parse( text ) ) round-trips' );
same( strpos( TBTPL_Format::to_text( $base ), "D. That caring" ) !== false, true, 'to_text writes letters' );

/* ---------- percentages() ---------- */

$pc = TBTPL_Format::percentages( array( 9, 5, 6, 1 ) );
same( array_sum( $pc ), 100, '[9,5,6,1] sums to 100' );
same( $pc, array( 43, 24, 28, 5 ), '[9,5,6,1] as the spec shows' );
$pc = TBTPL_Format::percentages( array( 1, 1, 1 ) );
same( array_sum( $pc ), 100, '[1,1,1] sums to 100' );
same( $pc, array( 34, 33, 33 ), '[1,1,1] tie goes to the earlier answer' );
same( TBTPL_Format::percentages( array( 0, 0, 0, 0 ) ), array( 0, 0, 0, 0 ), 'all zero' );
same( TBTPL_Format::percentages( array( 0, 7, 0, 0 ) ), array( 0, 100, 0, 0 ), 'one answer with every vote' );
same( TBTPL_Format::percentages( array() ), array(), 'no answers' );

/* ---------- decode() ---------- */

same( TBTPL_Format::decode( json_encode( $base ) ), $base, 'decode the stored JSON' );
same( TBTPL_Format::decode( 'not json' ), array(), 'bad JSON is []' );
same( TBTPL_Format::decode( null ), array(), 'missing meta is []' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
