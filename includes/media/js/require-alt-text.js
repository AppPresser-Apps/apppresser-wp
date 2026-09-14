/**
 * Forces alt text entry immediately after an image is uploaded.
 *
 * Every uploaded image without alt text opens a modal that cannot be
 * dismissed until alt text is saved. Uploads are detected through the
 * shared wp.Uploader queue (media grid, media modal, media-new.php),
 * which every uploader instance funnels finished files through, plus
 * an apiFetch middleware for REST media uploads (block editor).
 *
 * @package AppPresser
 */
(function ($) {
	'use strict';

	if (typeof AppPresserRequireAltText === 'undefined') {
		return;
	}

	var queue = [];
	var busy = false;
	var $overlay = null;

	function text( key, fallback ) {
		return AppPresserRequireAltText.strings && AppPresserRequireAltText.strings[ key ] ? AppPresserRequireAltText.strings[ key ] : fallback;
	}

	function buildModal() {
		if ( $overlay ) {
			return;
		}

		$overlay = $( '<div>', {
			'class':      'appr-alt-overlay',
			'role':       'dialog',
			'aria-modal': 'true',
			'aria-label': text( 'heading', 'Alt text is required' )
		} ).hide();

		var $box      = $( '<div>', { 'class': 'appr-alt-modal' } );
		var $thumb    = $( '<img>', { 'class': 'appr-alt-thumb', alt: '' } );
		var $filename = $( '<p>', { 'class': 'appr-alt-filename' } );
		var $input    = $( '<textarea>', { 'class': 'appr-alt-input widefat', rows: 3 } );
		var $error    = $( '<p>', { 'class': 'appr-alt-error' } ).hide();
		var $save     = $( '<button>', {
			'class': 'button button-primary appr-alt-save',
			type:    'button',
			text:    text( 'save', 'Save alt text' )
		} ).prop( 'disabled', true );

		$box
			.append( $( '<h2>', { text: text( 'heading', 'Alt text is required' ) } ) )
			.append( $( '<p>', { 'class': 'appr-alt-desc', text: text( 'description', 'Describe this image for screen readers and search engines before continuing.' ) } ) )
			.append( $thumb )
			.append( $filename )
			.append( $input )
			.append( $error )
			.append( $save );

		$overlay.append( $box ).appendTo( 'body' );

		$input.on( 'input', function () {
			$save.prop( 'disabled', '' === $.trim( $input.val() ) );
			$error.hide();
		} );

		$input.on( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				if ( ! $save.prop( 'disabled' ) ) {
					$save.trigger( 'click' );
				}
			}
		} );

		$save.on( 'click', function () {
			var item = queue[0];
			var alt  = $.trim( $input.val() );
			if ( ! item || ! alt ) {
				return;
			}

			$save.prop( 'disabled', true ).text( text( 'saving', 'Saving…' ) );

			$.post( ajaxurl, {
				action:        'apppresser_save_alt_text',
				attachment_id: item.id,
				alt_text:      alt,
				nonce:         AppPresserRequireAltText.nonce
			} ).done( function ( response ) {
				if ( response && response.success ) {
					// Keep any wp.media model in sync so the UI reflects the new alt text.
					if ( item.model && item.model.set ) {
						try {
							item.model.set( 'alt', alt );
						} catch ( e ) {}
					}
					done();
				} else {
					$error.text( text( 'error', 'Could not save the alt text. Please try again.' ) ).show();
				}
			} ).fail( function () {
				$error.text( text( 'error', 'Could not save the alt text. Please try again.' ) ).show();
			} ).always( function () {
				$save.prop( 'disabled', false ).text( text( 'save', 'Save alt text' ) );
			} );
		} );
	}

	function show( item ) {
		buildModal();
		$overlay.find( '.appr-alt-thumb' ).attr( 'src', item.thumb || '' ).toggle( !! item.thumb );
		$overlay.find( '.appr-alt-filename' ).text( item.title || '' );
		$overlay.find( '.appr-alt-input' ).val( '' ).trigger( 'input' );
		$overlay.find( '.appr-alt-error' ).hide();
		$overlay.show();
		window.setTimeout( function () {
			$overlay.find( '.appr-alt-input' ).trigger( 'focus' );
		}, 50 );
	}

	function done() {
		queue.shift();
		busy = false;
		if ( $overlay ) {
			$overlay.hide();
		}
		next();
	}

	function next() {
		if ( busy || ! queue.length ) {
			return;
		}
		busy = true;
		show( queue[0] );
	}

	function requireAltText( item ) {
		if ( ! item || ! item.id ) {
			return;
		}

		// Avoid stacking a second prompt for the same attachment.
		for ( var i = 0; i < queue.length; i++ ) {
			if ( queue[i].id === item.id ) {
				return;
			}
		}

		queue.push( item );
		next();
	}

	function attachmentThumb( model ) {
		var sizes = model.get( 'sizes' ) || {};
		var best  = sizes.thumbnail || sizes.medium || sizes.full;
		if ( best && best.url ) {
			return best.url;
		}
		return model.get( 'icon' ) || model.get( 'url' ) || '';
	}

	function handleUploadedAttachment( model ) {
		try {
			if ( ! model || ! model.get ) {
				return;
			}
			if ( 'image' !== model.get( 'type' ) ) {
				return;
			}
			if ( '' !== $.trim( model.get( 'alt' ) || '' ) ) {
				return;
			}
			requireAltText( {
				id:    model.get( 'id' ),
				title: model.get( 'title' ) || model.get( 'filename' ) || '',
				thumb: attachmentThumb( model ),
				model: model
			} );
		} catch ( e ) {}
	}

	var uploaderPatched = false;
	var restPatched     = false;

	/**
	 * wp.Uploader integration (media grid, media modal, media-new.php).
	 *
	 * Every wp.Uploader instance pushes completed uploads through the
	 * shared wp.Uploader.queue collection: the response data is merged
	 * into the attachment model and "uploading" flips from true to
	 * false. Listening for that change catches all upload flows no
	 * matter when the uploader instances were constructed.
	 */
	function patchQueue() {
		if ( uploaderPatched || typeof wp === 'undefined' || ! wp.Uploader || ! wp.Uploader.queue || ! wp.Uploader.queue.on ) {
			return;
		}
		uploaderPatched = true;

		wp.Uploader.queue.on( 'change:uploading', function ( model, uploading ) {
			if ( uploading ) {
				return;
			}
			handleUploadedAttachment( model );
		} );
	}

	/**
	 * REST upload integration (block editor uploads).
	 */
	function patchApiFetch() {
		if ( restPatched || typeof wp === 'undefined' || ! wp.apiFetch || ! wp.apiFetch.use ) {
			return;
		}
		restPatched = true;

		wp.apiFetch.use( function ( options, next ) {
			var result        = next( options );
			var isMediaCreate = options && options.path && 0 === options.path.indexOf( '/wp/v2/media' ) && 'POST' === options.method;
			if ( ! isMediaCreate ) {
				return result;
			}

			return result.then( function ( attachment ) {
				try {
					if ( attachment && attachment.id && 'image' === attachment.media_type && ( ! attachment.alt_text || '' === $.trim( attachment.alt_text ) ) ) {
						var thumb = attachment.source_url || '';
						if ( attachment.media_details && attachment.media_details.sizes ) {
							var s = attachment.media_details.sizes.thumbnail || attachment.media_details.sizes.medium || attachment.media_details.sizes.full;
							if ( s && s.source_url ) {
								thumb = s.source_url;
							}
						}
						requireAltText( {
							id:    attachment.id,
							title: attachment.title && attachment.title.rendered ? attachment.title.rendered : ( attachment.slug || '' ),
							thumb: thumb
						} );
					}
				} catch ( e ) {}
				return attachment;
			} );
		} );
	}

	// Patch right away if the media scripts already loaded...
	patchQueue();
	patchApiFetch();

	// ...and keep watching briefly in case they load late (footer/deferred).
	var attempts = 0;
	var watcher  = window.setInterval( function () {
		attempts++;
		patchQueue();
		patchApiFetch();
		if ( ( uploaderPatched && restPatched ) || attempts > 40 ) {
			window.clearInterval( watcher );
		}
	}, 250 );

})( jQuery );
