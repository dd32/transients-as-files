<?php
namespace DionHulse\WordPress\TransientsAsFiles;
/**
 * Plugin Name: Transients as Files
 * Author: Dion Hulse
 * Description: A simple idea to cease complaints about the options table filling up, store transients in files!
 */

class Plugin {
	function __construct() {
		if ( wp_using_ext_object_cache() ) {
//			add_action( 'admin_init', array( $this, 'aborted_due_to_cache' ) );
			return;
		}

		// Oh I'm sorry.. I promise not to use Regex though ;)
		add_filter( 'all', array( $this, 'filter_all' ), 1 );

		// Catch transients being set, dodgy but it's what we're all about.
		add_action( 'setted_transient', array( $this, 'setted_transient' ), 10, 3 );
		add_action( 'setted_site_transient', array( $this, 'setted_site_transient' ), 10, 3 );
	}

	function filter_all() {
		$filter = current_filter();
		// TODO: Short-circuit on a few common transient names here.
		$fourteen = substr( $filter, 0, 14 );
		$nineteen = substr( $filter, 0, 19 );

		// Add a Pre-get transient filters.
		if ( 'pre_transient_' == $fourteen ) {
			add_filter( $filter, array( $this, 'get_transient_transient' ), 5, 2 );
			return;
		}
		if ( 'pre_site_transient_' == $nineteen ) {
			add_filter( $filter, array( $this, 'get_site_transient_transient' ), 5, 2 );
			return;
		}

		// Add transient delete handlers.
		if ( 'delete_transie' == $fourteen && 'delete_transient_' == substr( $filter, 0, 17 ) ) {
			add_action( $filter, array( $this, 'delete_transient' ), 5, 1 );
		}
		if ( 'delete_site_transie' == $nineteen && 'delete_site_transient_' == substr( $filter, 0, 21 ) ) {
			add_action( $filter, array( $this, 'delete_site_transient' ), 5, 1 );
		}
		// TODO: add handlers for the delete options too.

		// And then on the option variant of Pre-Get transient options. This is mostly to prevent queries
		if (
			'pre_option_transien' == $nineteen ||
			'pre_option_site_tra' == $nineteen
		) {
			add_filter( $filter, array( $this, 'get_transient_option' ), 5, 2 );
			return;
		}
	}

	function get_transient( $transient, $site = false ) {
		$file = $this->get_file( $transient, $site );
		var_dump( "looking for transient $transient for " . ($site ? 'Site' : 'local' ) . "$file " );

		if ( file_exists( $file ) ) {
			$value = include $file;
			return $value;
		}

		return false;
	}

	function set_transient( $transient, $value, $expiration, $site = false ) {
		$file = $this->get_file( $transient, $site );

		// TODO Implement ourselves.
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ) . '/', 0777, true );
		}

		// Actual expiration time.
		$expiration += time();

		$result = (bool) file_put_contents(
			$file,
			$this->get_file_payload( $value, $expiration )
		);
		if ( $result ) {
			// Set the file date to the expiration time.
			@touch( $file, $expiration );
		}

		return $result;
	}

	function delete_transient( $transient ) {
		$file = $this->get_file( $transient, false );
		if ( @unlink( $file ) ) {
			do_action( 'deleted_transient', $transient );
		}
	}

	function delete_site_transient( $transient ) {
		$file = $this->get_file( $transient, true );
		if ( @unlink( $file ) ) {
			do_action( 'deleted_site_transient', $transient );
		}
	}

	function get_file_payload( $value, $expiration = 0 ) {
		$random_bytes = base64_encode( random_bytes( 10 ) );
		return '<' . '?php ' .
			'// Transient storage for WordPress' . "\n" .
			( $expiration ?
				"if ( time() > $expiration ) { @unlink(__FILE__); return false; }"
				:
				''
			) .
			( is_scalar( $value ) ?
					'return ' . var_export( $value, true ) . ';'
					:
					"return unserialize( <<<EOTRANSIENT{$random_bytes}\n" . serialize( $value ) . "\nEOTRANSIENT{$random_bytes}\n );"
			);
	}

	function get_file( $transient, $site = false ) {
		$path = WP_CONTENT_DIR . '/transients-as-files/';
		if ( $site ) {
			$path .= 'global/';
		} elseif ( is_multisite() ) {
			$path .= 'site-' . get_current_blog_id() . '/';
		}

		if ( ! $site ) {
			// file in directories based on the $transient
			$path .= substr( $transient, 0, 1 ) . '/';
		}

		// Strip out any odd values from the transient name.
		$safe_transient = preg_replace( '![^a-z0-9-_.]!i', '', $transient );
		// Ensure the filename is unique even if the sanitized variant is different
		if ( $safe_transient !== $transient ) {
			$safe_transient .= '-' . md5( $transient );
		}

		$path .= $safe_transient . '.php';

		return $path;
	}

	/* Start Pre_get options */
	function get_site_transient_transient( $value, $transient ) {
		return $this->get_transient( $transient, true ) ?: $value;
	}

	function get_transient_transient( $value, $transient ) {
		return $this->get_transient( $transient ) ?: $value;
	}

	function get_transient_option( $value, $option ) {
		if ( '_transient_' === substr( $option, 0, 11 ) ) {
			return $this->get_transient( substr( $option, 11 ), $value ) ?: $value;
		} elseif ( '_site_transient_' === substr( $option, 0, 16 ) ) {
			return $this->get_transient( substr( $option, 11 ), $value, true ) ?: $value;
		} else {
			return $value;
		}
	}

	/* Start post-set */
	function setted_transient( $transient, $value, $expiration ) {
		if ( $this->set_transient( $transient, $value, $expiration, false ) ) {
			// LOL
			delete_option( '_transient_timeout_' . $transient );
			delete_option( '_transient_' . $transient );
		}
	}
	function setted_site_transient( $transient, $value, $expiration ) {
		if ( $this->set_transient( $transient, $value, $expiration, true ) ) {
			// LOL
			delete_site_option( '_site_transient_timeout_' . $transient );
			delete_site_option( '_site_transient_' . $transient );
		}
	}

}

new Plugin();