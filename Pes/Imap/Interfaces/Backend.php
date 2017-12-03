<?php

namespace Pes\Imap\Interfaces;

interface Backend
{
	public function login($username, $password);
	public function create($id, $mailbox);
	// return: [ [ reference, name, flags ], ... ]
	public function listMailboxes($id, $reference, $mailbox);

	public function queueMail($from, $to, $body);

	// admin tool helper functions
	public function _begin();
	public function _commit();
	public function _rollback();
	public function _addUserEmail($email, $linkEmail, $password, $name);
	public function _addDomain($name);
}
