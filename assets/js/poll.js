/* TBT Poll — draws every state of [tbt_poll] from the data the server
 * embeds on the root element (data-tbtpl). One renderer: vote, results,
 * guest. The vote route answers with the same shape.
 *
 * Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var LETTERS = 'ABCDEF';
	var WORDS = [ 'zero', 'one', 'two', 'three', 'four', 'five', 'six' ];
	var instance = 0;

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			e.textContent = String( text );
		}
		return e;
	}

	function word( n ) {
		return WORDS[ n ] || String( n );
	}

	function reducedMotion() {
		return window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	/** "Answer all three questions to vote" and so on; '' when complete. */
	function hint( total, answered ) {
		var left = total - answered;
		if ( left <= 0 ) {
			return '';
		}
		if ( answered === 0 ) {
			if ( total === 1 ) {
				return 'Answer the question to vote';
			}
			if ( total === 2 ) {
				return 'Answer both questions to vote';
			}
			return 'Answer all ' + word( total ) + ' questions to vote';
		}
		if ( left === 1 ) {
			return 'Answer one more question to vote';
		}
		return 'Answer ' + word( left ) + ' more questions to vote';
	}

	function votersText( n ) {
		if ( ! n ) {
			return 'No votes yet';
		}
		return n === 1 ? '1 student has voted' : n + ' students have voted';
	}

	function thanksText( points ) {
		if ( typeof points === 'number' && points > 0 ) {
			return 'Thanks for voting. You earned ' + points + ( points === 1 ? ' point.' : ' points.' );
		}
		return 'Thanks for voting.';
	}

	function head( i, text, tag ) {
		var h = el( tag, 'tbtpl-head' );
		h.appendChild( el( 'span', 'tbtpl-n', i + 1 ) );
		h.appendChild( el( 'span', 'tbtpl-qt', text ) );
		return h;
	}

	function Poll( root, data ) {
		this.root = root;
		this.data = data;
		this.uid = 'tbtpl-' + data.id + '-' + ( ++instance );
		this.render( false, null );
	}

	Poll.prototype.render = function ( justVoted, points ) {
		if ( this.data.state === 'vote' ) {
			this.renderVote();
		} else {
			this.renderResults( justVoted, points );
		}
	};

	/* ---------- Vote ---------- */

	Poll.prototype.renderVote = function () {
		var self = this;
		var d = this.data;
		var total = d.questions.length;
		var form = el( 'form' );
		form.noValidate = true;

		d.questions.forEach( function ( q, i ) {
			var fs = el( 'fieldset', 'tbtpl-q' );
			fs.appendChild( head( i, q.q, 'legend' ) );
			var opts = el( 'div', 'tbtpl-opts' );
			q.a.forEach( function ( a, j ) {
				var lab = el( 'label', 'tbtpl-opt' );
				var inp = el( 'input' );
				inp.type = 'radio';
				inp.name = self.uid + '-q' + i;
				inp.value = String( j );
				lab.appendChild( inp );
				lab.appendChild( el( 'span', 'tbtpl-l', LETTERS.charAt( j ) ) );
				lab.appendChild( el( 'span', null, a ) );
				opts.appendChild( lab );
			} );
			fs.appendChild( opts );
			form.appendChild( fs );
		} );

		var foot = el( 'div', 'tbtpl-foot' );
		var btn = el( 'button', 'tbtpl-vote', 'Vote' );
		btn.type = 'submit';
		var left = el( 'span', 'tbtpl-left' );
		var err = el( 'p', 'tbtpl-error' );
		err.setAttribute( 'role', 'alert' );
		err.hidden = true;
		foot.appendChild( btn );
		foot.appendChild( left );
		foot.appendChild( err );
		form.appendChild( foot );

		var sending = false;

		function picked() {
			return d.questions.map( function ( _, i ) {
				var c = form.querySelector( 'input[name="' + self.uid + '-q' + i + '"]:checked' );
				return c ? parseInt( c.value, 10 ) : null;
			} );
		}

		function update() {
			var answered = picked().filter( function ( v ) {
				return v !== null;
			} ).length;
			btn.disabled = sending || answered < total;
			left.textContent = hint( total, answered );
		}

		form.addEventListener( 'change', update );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var answers = picked();
			if ( sending || answers.indexOf( null ) !== -1 ) {
				return;
			}
			sending = true;
			err.hidden = true;
			update();

			self.send( answers ).then( function ( res ) {
				if ( res.status === 200 && res.body && res.body.state ) {
					self.data = res.body;
					self.render( true, res.body.points );
					self.scrollTo();
					return;
				}
				if ( res.status === 409 && res.body && res.body.data && res.body.data.poll ) {
					self.data = res.body.data.poll;
					self.render( false, null );
					self.scrollTo();
					return;
				}
				throw new Error( 'vote' );
			} ).catch( function () {
				sending = false;
				err.textContent = 'Your vote wasn’t saved. Please try again.';
				err.hidden = false;
				update();
			} );
		} );

		update();
		this.root.textContent = '';
		this.root.appendChild( form );
	};

	/* ---------- Results and guest ---------- */

	Poll.prototype.renderResults = function ( justVoted, points ) {
		var d = this.data;
		var mine = d.state === 'results' && Array.isArray( d.mine ) ? d.mine : null;
		var frag = document.createDocumentFragment();

		d.questions.forEach( function ( q, i ) {
			var sec = el( 'section', 'tbtpl-q' );
			sec.setAttribute( 'aria-label', 'Question ' + ( i + 1 ) + ' results' );
			sec.appendChild( head( i, q.q, 'div' ) );
			var res = el( 'div', 'tbtpl-res' );
			var pct = ( d.percent && d.percent[ i ] ) || [];
			q.a.forEach( function ( a, j ) {
				var isMine = !! mine && mine[ i ] === j;
				var p = typeof pct[ j ] === 'number' ? pct[ j ] : 0;
				var r = el( 'div', 'tbtpl-r' + ( isMine ? ' is-mine' : '' ) );
				var line = el( 'div', 'tbtpl-rl' );
				var txt = el( 'span' );
				txt.appendChild( el( 'b', null, LETTERS.charAt( j ) + '.' ) );
				txt.appendChild( document.createTextNode( a ) );
				if ( isMine ) {
					txt.appendChild( el( 'span', 'tbtpl-mine', '✓ Your vote' ) );
				}
				line.appendChild( txt );
				line.appendChild( el( 'span', 'tbtpl-pct', p + '%' ) );
				var track = el( 'div', 'tbtpl-track' );
				track.setAttribute( 'role', 'img' );
				track.setAttribute( 'aria-label', p + ' percent' );
				var fill = el( 'div', 'tbtpl-fill' );
				fill.setAttribute( 'data-w', String( p ) );
				track.appendChild( fill );
				r.appendChild( line );
				r.appendChild( track );
				res.appendChild( r );
			} );
			sec.appendChild( res );
			frag.appendChild( sec );
		} );

		var sum = el( 'div', 'tbtpl-sum' );
		sum.appendChild( el( 'div', 'tbtpl-total', votersText( d.voters ) ) );
		if ( justVoted ) {
			sum.appendChild( el( 'span', 'tbtpl-pts', '✓ ' + thanksText( points ) ) );
		}
		if ( d.state === 'guest' && d.login_url ) {
			var login = el( 'a', 'tbtpl-login', 'Log in to vote' );
			login.href = d.login_url;
			sum.appendChild( login );
		}
		frag.appendChild( sum );

		this.root.textContent = '';
		this.root.appendChild( frag );

		var fills = this.root.querySelectorAll( '.tbtpl-fill' );
		function grow() {
			Array.prototype.forEach.call( fills, function ( f ) {
				f.style.width = f.getAttribute( 'data-w' ) + '%';
			} );
		}
		if ( reducedMotion() ) {
			grow();
		} else {
			requestAnimationFrame( function () {
				requestAnimationFrame( grow );
			} );
		}
	};

	Poll.prototype.scrollTo = function () {
		if ( this.root.scrollIntoView ) {
			this.root.scrollIntoView( { behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' } );
		}
	};

	/* ---------- Network ---------- */

	/** Resolves { status, body }; rejects only on a network failure. */
	Poll.prototype.send = function ( answers ) {
		var self = this;
		var d = this.data;

		function post( retried ) {
			return fetch( d.rest + 'polls/' + d.id + '/vote', {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': d.nonce || ''
				},
				body: JSON.stringify( { answers: answers, post_id: d.post_id || 0 } )
			} ).then( function ( r ) {
				return r.json().catch( function () {
					return null;
				} ).then( function ( body ) {
					if ( ! retried && r.status === 403 && body && body.code === 'rest_cookie_invalid_nonce' ) {
						return self.refreshNonce().then( function () {
							return post( true );
						} );
					}
					return { status: r.status, body: body };
				} );
			} );
		}

		return post( false );
	};

	/** A page left open until its nonce expired: fetch a fresh one. */
	Poll.prototype.refreshNonce = function () {
		var d = this.data;
		var url = d.ajax + ( d.ajax.indexOf( '?' ) < 0 ? '?' : '&' ) + 'action=tbtpl_nonce';
		return fetch( url, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( body ) {
				if ( ! body || ! body.success || ! body.data || ! body.data.nonce ) {
					throw new Error( 'nonce' );
				}
				d.nonce = body.data.nonce;
			} );
	};

	/* ---------- Boot ---------- */

	function boot() {
		var roots = document.querySelectorAll( '.tbtpl[data-tbtpl]' );
		Array.prototype.forEach.call( roots, function ( root ) {
			if ( root.getAttribute( 'data-tbtpl-ready' ) ) {
				return;
			}
			var data;
			try {
				data = JSON.parse( root.getAttribute( 'data-tbtpl' ) );
			} catch ( e ) {
				return;
			}
			if ( ! data || ! Array.isArray( data.questions ) || ! data.questions.length ) {
				return;
			}
			root.setAttribute( 'data-tbtpl-ready', '1' );
			new Poll( root, data ); // eslint-disable-line no-new
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
