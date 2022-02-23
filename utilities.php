<?php

namespace Skynet\Filesystem;

use function Skynet\functions\strings\trimForwardSlash;
use function Skynet\functions\url\trimUriPrefix;
use const Skynet\URI_SKYNET_PREFIX;

function filesystem( $name = null, $instance = null, array $options = [] ): Filesystem {
	static $instances = [];

	if ( null === $name ) {
		$name = 'global';
	}

	if ( false === $instance ) {
		unset( $instances[ $name ] );
	}

	if ( $instance instanceof Filesystem ) {
		$instances[ $name ] = $instance;
	}

	if ( ! isset( $instances[ $name ] ) ) {
		$instances[ $name ] = new Filesystem( $options );
	}

	return $instances[ $name ];
}

function createContext( array $options ) {
	return stream_context_create( [
		LumeStreamWrapper::SCHEME =>
			$options,
	] );
}

function createStreamingContext( int $offset = 0 ) {
	return createContext( [ 'streaming' => true, 'streamOffset' => $offset ] );
}

/**
 * @return resource
 */
function createPublicContext( string $seed = null ) {
	return stream_context_create( [
		LumeStreamWrapper::SCHEME =>
			[
				'seed' => $seed,
				'mode' => Filesystem::MODE_PUBLIC,
			],
	] );
}



function getSkynetContextOptions(): array {
	return stream_context_get_options( stream_context_get_default() )[ LumeStreamWrapper::SCHEME ];
}

function setContextOption( string $option, $value, $context = null ) {
	if ( ! $context ) {
		$default = true;
		$context = stream_context_get_default();
	}
	$options                                         = stream_context_get_options( $context );
	$options[ LumeStreamWrapper::SCHEME ][ $option ] = $value;
	if ( ! $default ) {
		stream_context_set_default( $options );

		return;
	}
	stream_context_set_option( $context, $options );
}

function getContextOption( string $option, $context = null ) {
	if ( ! $context ) {
		$context = stream_context_get_default();
	}

	$options = getContextOptions( $context );
	if ( isset( $options[ $option ] ) ) {
		return $options[ $option ];
	}

	return null;
}

function getContextOptions( $context = null, bool $default = true ): array {
	if ( ! $context ) {
		if ( ! $default ) {
			return [];
		}
		$context = stream_context_get_default();
	}

	return stream_context_get_options( $context )[ LumeStreamWrapper::SCHEME ] ?? [];
}

function normalizePath( string $path ): string {
	return trimForwardSlash( preg_replace( '@\w+://@', '', $path ) );
}

function stripSkylinkPrefix( string $skylink ): string {
	return trimUriPrefix( $skylink, URI_SKYNET_PREFIX );
}
