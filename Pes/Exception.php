<?php

namespace Pes;

class Exception extends \Exception
{
	public static $codes = array(
		1 => 'Failed to load backend, check your settings',
		//backend
		100 => 'Failed to login',
		//
		200 => ''
	);

	public function __construct($code, $extra='', $db=FALSE)
	{
		if($code == 200)
		{
			if($db)
			{
				echo "Rolled back database changes.\n";
				$db->_rollback();
			}
		}
		echo self::$codes[$code].': '.$extra."\n";
		echo $this->getTraceAsString()."\n";
	}
}