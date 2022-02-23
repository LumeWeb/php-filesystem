<?php

use Skynet\Filesystem\Filesystem;
use Skynet\Filesystem\LumeStreamWrapper;

stream_wrapper_register( 'lume', LumeStreamWrapper::class );

/*stream_context_set_default( [
	SkynetStreamWrapper::SCHEME => Filesystem::getDefaultOptions(),
] );*/
