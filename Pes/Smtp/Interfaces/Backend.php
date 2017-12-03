<?php

namespace Pes\Smtp\Interfaces;

interface Backend
{
	public function queueMail($from, $to, $body);
}