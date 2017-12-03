<?php

namespace Pes\Smtp;

class Exception extends \Exception
{
	public static $codes = [
		500 => 'Line too long.',
		501 => 'Path too long',
		552 => 'Too much mail data.'
	];

	public function __construct($code, $extra='', $cb=FALSE)
	{
		parent::__construct($extra, $code, NULL);
	}

	public static function sendFailure($code, $handler)
	{

	}
}
