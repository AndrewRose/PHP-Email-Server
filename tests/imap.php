#!/usr/bin/php
<?php

class Pes_Test
{
	private $mailbox;
	private $con;

	public function __construct($mailbox)
	{
		$this->mailbox = $mailbox;
		$this->login('andrew@sds.ac', 'password');

		$boxes = $this->listMailboxes('test"');

		$this->create('INBOX/ \\test');

		//print_r($boxes);
		//foreach($boxes as $box)
		//{
		//	print_r(imap_status($this->con, $box, SA_ALL));

		//	$this->select($box);
			//print_r(imap_headerinfo($this->con, 1));
		//}

		$this->logout();
	}

	public function login($username, $password)
	{
		$this->con = imap_open($this->mailbox, $username, $password);
	}

	public function create($name)
	{
		imap_createmailbox($this->con, imap_utf7_encode($this->mailbox.$name));
	}

	public function select($mailbox)
	{
		imap_reopen($this->con, $mailbox);
	}

	public function listMailboxes($pattern)
	{
		return imap_list($this->con, $this->mailbox, $pattern);
	}

	public function logout()
	{
		imap_close($this->con);
	}
}

new Pes_Test('{localhost:143}');
