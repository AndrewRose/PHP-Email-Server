<?php

namespace Pes\Traits;

trait Network
{
	// opendns just kinda shit all over ping, so this is not really much use these days.
	public function ping($hostname)
	{
		$socket  = socket_create(AF_INET, SOCK_RAW, 1);
		socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 1, 'usec' => 0));
		socket_connect($socket, $hostname, null);

		$packet = "\x08\x00\x7d\x4b\x00\x00\x00\x00PingHost";
		socket_send($socket, $packet, strLen($packet), 0);
		if(socket_read($socket, 255))
		{
			$res = TRUE;
		}
		else
		{
			$res = FALSE;
		}
		socket_close($socket);

		return $res;
	}
}