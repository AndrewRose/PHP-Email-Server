<?php

namespace Pes\Smtp\Backend;

class Pdo implements \Pes\Smtp\Interfaces\Backend
{
	use \Pes\Traits\Network;

	public $handler;
	private $settings = array();
	private $db = FALSE;
	private $dbMaxPacketSize = 0;
	public $log;

	public function __construct($settings, $handler)
	{
		$this->handler = $handler;
		$this->handler->log->write('Backend Pdo started');
		// PHP hostname lookups can take a substantial amount of time to timeout so use this->ping instead.
		if(!$this->ping($settings['hostname']))
		{
			throw new \Pes\Exception(100, 'Check your database hostname.');
		}

		$this->settings = $settings;
		//$this->db = new \PDO('mysql:host='.$settings['hostname'].';dbname='.$settings['database'], $settings['username'], $settings['password']);
		$this->db = new \PDO('mysql:dbname='.$settings['database'], $settings['username'], $settings['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

		/*
		An attempt was made to carefully size any query so it would not breach max_allowed_packet
		however this proved difficult as there seems to be protocol overhead that is not taken
		into account and would be a waste of cpu cycles trying to calculate.  So for now we will
		bail if the $this->handler->maxMailSize + 10% overhead is greater than max_allowed_packet
		which seems to work but watch out for 'server gone away' errors.
		*/
		$this->dbMaxPacketSize = ($this->dbMaxPacketSize = $this->db->query('select @@global.max_allowed_packet')->fetch(\PDO::FETCH_NUM)[0]/100)*90;

		if($this->db->query('select @@global.innodb_log_file_size')->fetch(\PDO::FETCH_NUM)[0]/10 < $this->handler->maxMailSize)
		{
			echo "MySQL innodb_log_file_size needs to be around 10x max_allowed_packet.  Increase innodb_log_file_size to make MySQL happy :)";
			exit();
		}

		if($this->handler->maxMailSize > $this->dbMaxPacketSize)
		{
			echo "MySQL max_allowed_packet is smaller than maxMailSize! Increase max_allowed_packet to maxMailSize+10% to make me happy :)";
			exit();
		}

		// Keep the DB connection alive by sending it a query every {keepalive} seconds..
		// We do it this way as unable to catch connect timeouts as an exception as you might have seen below...
		// no idea why we can't get a straight exception when a query fails .. and for why it failed :/
		$db = $this->db;
		$keepaliveEventTimer = \Event::timer($handler->base, function($keepalive) use (&$keepaliveEventTimer, $handler, $db) {
			$db->query('select 1');
			$handler->log->write('Ping DB', 3);
			$keepaliveEventTimer->add($keepalive);
		}, $settings['keepalive']);
		$keepaliveEventTimer->addTimer($settings['keepalive']);

		return TRUE;
	}

	public function queueMail($from, $to, $body)
	{
		if(strlen($body) > $this->handler->maxMailSize)
		{
			throw new \Pes\Smtp\Exception(552);
		}

		$tmp = explode('@', $to);
		$query = 'SELECT a.userId as userId, a.defaultMailboxId as mailboxId FROM user_email_address AS a, domain AS b WHERE a.domainId = b.id AND a.localPart = :localPart AND b.name = :domain';
		if(strlen($query) > $this->dbMaxPacketSize || strlen($tmp[0]) > 128 || strlen($tmp[1]) > 128)
		{
			throw new \Pes\Smtp\Exception(552);
		}

try
{
		$stmt = $this->db->prepare($query);
		if($stmt->execute([':localPart' => $tmp[0], ':domain' => $tmp[1]]))
		{
			$maildets = $stmt->fetch(\PDO::FETCH_ASSOC);

			$envelope = $this->createEnvelope($from, $to, $body);
			$bodystructure = $this->createBodyStructure($body);

			$this->db->beginTransaction();

			$query = 'INSERT INTO message_queue(mail_from, rcpt_to, body, envelope, bodystructure) VALUES(:mail_from, :rcpt_to, :body, :envelope, :bodystructure)';
			$packetSize = strlen($query) + strlen($from) + strlen($to) + strlen($envelope) + strlen($bodystructure);

			if((strlen($body)+$packetSize) > $this->handler->maxMailSize)
			{
				throw new \Pes\Smtp\Exception(552);
			}

			$stmt = $this->db->prepare($query);
			//$bodyChunk = substr($body, 0, ($this->dbMaxPacketSize-$packetSize));
			//$bodyRemaining = substr($body, ($this->dbMaxPacketSize-$packetSize));

			// change $body to $bodyChunk and uncomment below "if($bodyRemaining)" block if mysql/mariadb fix: https://bugs.mysql.com/bug.php?id=20458
			if(!$stmt->execute([':mail_from' => $from, ':rcpt_to' => $to, ':body' => $body, ':envelope' => $envelope, ':bodystructure' => $bodystructure]))
			{
				throw new \Pes\Smtp\Exception(451);
			}

			/*$mailId = $this->db->lastInsertId();
			if($bodyRemaining)
			{
				$query2 = 'UPDATE message_queue SET body = concat(body, :body) WHERE id = :mailId';
				$packetSize = strlen($query2);
				$stmt2 = $this->db->prepare($query2);

				$read = ($this->dbMaxPacketSize-$packetSize);
				while($bodyRemaining)
				{
					$stmt2->execute([':body' => substr($bodyRemaining, 0, $read), ':mailId' => $mailId]);
					$bodyRemaining = substr($bodyRemaining, $read);
					if(!$bodyRemaining) break;
				}
			}*/

			if($stmt->rowCount() == 1) // link message to any current email accounts
			{
				$stmt = $this->db->prepare('INSERT INTO user_messages(userId, mailboxId, messageId) VALUES(:userId, :mailboxId, :messageId)');
				if(!$stmt->execute([':userId' => $maildets['userId'], ':mailboxId' => $maildets['mailboxId'], ':messageId' => $this->db->lastInsertId()]))
				{
					$this->db->rollback();
					return FALSE;
				}
			}

			$this->db->commit();
			return TRUE;

		}
} catch(PDOException $e) {
$this->handler->log->write('Reconnecting PDO');
sleep(0.1);
$this->db = new \PDO('mysql:dbname='.$this->settings['database'], $this->settings['username'], $this->settings['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
$this->queueMail($from, $to, $body);
}

		return FALSE;
	}

	// only creates body not bodystructure with the exetended data .. is that even used?
	public function createBodyStructure($body)
	{
		$res = mailparse_msg_create();
		mailparse_msg_parse($res, $body);

		$structure = mailparse_msg_get_structure($res);
		$parts = array();
		foreach($structure as $id) {
			$part = mailparse_msg_get_part($res, $id);
			$parts[$id] = mailparse_msg_get_part_data($part);
		}

		if(sizeof($parts)>1) // pop the first section out if multipart
		{
			$head = array_shift($parts);
		}

		$structs = [];
		foreach($parts as $part)
		{
			if(!isset($part['start-pos-body']))
			{
				$part['start-pos-body'] = 0;
			}

			$struct = '(';
			$tmp = explode('/', $part['content-type']);
			$struct .= '"'.$tmp[0].'"'; // body type
			$struct .= ' "'.$tmp[1].'"'; // body subtype
			$struct .= ' ("CHARSET" "'.$part['charset'].'") NIL NIL'; // body param list
			$struct .= ' "'.$part['transfer-encoding'].'"'; // body transfer encoding
			$struct .= ' '.($part['ending-pos-body'] - $part['start-pos-body']); // body octets
			$struct .= ' '.$part['body-line-count']; // body lines
			$struct .= ')';

			$structs[] = $struct;
		}

		if(sizeof($structs)==1)
		{
			return array_pop($structs);
		}
		else if(sizeof($structs)>1)
		{
			$ret = '(';
			foreach($structs as $struct)
			{
				$ret.= $struct;
			}
			// boundary and nil nil that is comented out is part of the extended data
			return $ret.' '.(explode('/', $head['content-type'])[1]).')'; //.' ("BOUNDARY" "'.$head['content-boundary'].'") NIL NIL)';
		}
		else
		{
// throw fault
exit('fault');
		}		
	}

	public function createEnvelope($from, $to, $body)
	{
		$res = mailparse_msg_create();
		mailparse_msg_parse($res, $body);
		$s = mailparse_msg_get_part_data($res);

// TODO check date and subject are present?
		$ret = '("'.$s['headers']['date'].'" "'.$s['headers']['subject'].'"';
// TODO should rcpt be added here??
		foreach(array('from', 'sender', 'reply-to', 'to', 'cc', 'bcc') as $header)
		{
			if(isset($s['headers'][$header]))
			{
				$ret .= ' (';
				foreach(mailparse_rfc822_parse_addresses($s['headers'][$header]) as $parts)
				{
					$tmp = explode('@', $parts['address']);
					$ret .= '("'.$parts['display'].'" NIL "'.$tmp[0].'" "'.$tmp[1].'")';
				}
				$ret .= ')';
			}
			else
			{
				$ret .= ' NIL';
			}
		}

		if(isset($s['headers']['in-reply-to']))
		{
			$ret .= ' "'.$s['headers']['in-reply-to'].'"';
		}
		else
		{
			$ret .= ' NIL';
		}

		if(isset($s['headers']['message-id']))
		{
			$ret .= ' "'.$s['headers']['message-id'].'"';
		}
		else
		{
			$ret .= ' NIL';
		}

		return $ret . ')';
	}
}
