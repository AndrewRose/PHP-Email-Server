<?php

namespace Pes\Imap\Backend;

class Pdo implements \Pes\Imap\Interfaces\Backend
{
	use \Pes\Traits\Network;

	public $handler;
	private $settings = array();
	private $db = FALSE;
	public $dbMaxPacketSize;

	public function __construct($settings, $handler)
	{
		$this->handler = $handler;
		$this->handler->log->write('Backend Pdo started');

		// PHP hostname lookups can take a substantial amount of time to timeout.
		if(!$this->ping($settings['hostname']))
		{
			throw new \Pes\Exception(100, 'Check your database hostname.');
		}

		$this->settings = $settings;
		$this->db = new \PDO('mysql:host='.$settings['hostname'].';dbname='.$settings['database'], $settings['username'], $settings['password']);
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

		$this->dbMaxPacketSize = $this->db->query('select @@global.max_allowed_packet')->fetch(\PDO::FETCH_NUM)[0];

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
	}

	public function fetch($userId, $fetchParams, $uidMode=FALSE)
	{
//print_r($fetchParams);
		// C(1): 80 FETCH 1:6 (FLAGS UID ENVELOPE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (CONTENT-TYPE)])
		$query = 'SELECT ';
		$queryParts = [];
		//['ALL', 'FAST', 'FULL', 'ENVELOPE', 'FLAGS', 'INTERNALDATE', 'RFC822', 'RFC822.HEADER', 'RFC822.SIZE', 'RFC822.TEXT', 'UID']

		//print_r($fetchParams);

		if($uidMode)
		{
			$queryParts[] = 'a.id as UID';
		}
		else
		{
			$queryParts[] = 'a.id as UID';
		}
//$queryParts[] = 'length(b.body) as `RFC822.SIZE`';

		$ret = [];
		foreach($fetchParams as $item => $drop)
		{
			switch($item)
			{
				case 'UID':
				{
					if(!$uidMode)
					{
					//	$queryParts[] = 'a.id as UID';
					}
				}
				break;

				case 'ENVELOPE':
				{
					$queryParts[] = 'b.envelope as ENVELOPE';
				}
				break;

				case 'FLAGS':
				{
					$queryParts[] = "concat(
						if(a.seen=1,'\\\Seen ', ''),
						if(a.answered=1,'\\\Answered ',''),
						if(a.flagged=1,'\\\Flagged ',''),
						if(a.deleted=1,'\\\Deleted ',''),
						if(a.draft=1,'\\\Draft ',''),
						if(a.recent=1,'\\\Recent ','')
					) as FLAGS";
				}
				break;

				case 'RFC822':
				{
					$queryParts[] = 'b.body as RFC822';				
				}
				break;

				case 'RFC822.SIZE':
				{
					$queryParts[] = 'length(b.body) as `RFC822.SIZE`';
				}
				break;

				case 'BODY':
				{
					$queryParts[] = 'b.body as BODY';
				}
				break;

				case 'INTERNALDATE':
				{
					$queryParts[] =  '"Date: Sat, 10 Aug 2013 07:18:23 +0100" as INTERNALDATE';
				}
				break;
			}
		}

		$query .= implode(', ', $queryParts);

		$query .= '
		FROM
			user_messages AS a LEFT join
			message_queue AS b ON a.messageId = b.id
		WHERE
			a.userId = :userId
		';

		$query .= ' AND (';
		$rangeFilters = [];
		// handle range
		if(!empty($fetchParams['_RANGE']['ids']))
		{
			$rangeFilters[] = 'a.id IN ('.implode(',', $fetchParams['_RANGE']['ids']).')';
		}

		foreach($fetchParams['_RANGE']['ranges'] as $range)
		{
			$rangeFilters[] = 'a.id BETWEEN '.$range[0].' AND '.$range[1];
		}

		if($fetchParams['_RANGE']['openRange'] !== FALSE)
		{
			$rangeFilters[] = 'a.id > '.$fetchParams['_RANGE']['openRange'];
		}

		$query .= implode(' OR ', $rangeFilters).')';
//echo $query;
		$this->db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
		$params = [':userId' => $userId];
		/*if(isset($fetchParams['_RANGE'][1]))
		{
			if($uidMode)
			{
				$params[':rangeFrom'] = $fetchParams['_RANGE'][0];
				$params[':rangeTo'] = $fetchParams['_RANGE'][1];
				if($params[':rangeTo'] == '*')
				{
					unset($params[':rangeTo']);
					$query .= ' AND a.id >= :rangeFrom';
				}
				else
				{
					$query .= ' AND a.id >= :rangeFrom AND a.id <= :rangeTo';
				}
			}
			else
			{
				// rangeTo = rangeTo - rangeFrom
				$params[':rangeFrom'] = (int)$fetchParams['_RANGE'][0]-1;
				$params[':rangeTo'] = (int)($fetchParams['_RANGE'][1] - $fetchParams['_RANGE'][0])+1;
				$query .= ' ORDER BY a.id LIMIT :rangeTo OFFSET :rangeFrom';
			}
		}
		else
		{
			//$params[':rangeId'] = (int)$fetchParams['_RANGE'][0];
			if($uidMode)
			{
				$query .= ' AND a.id = :rangeId';
			}
			else
			{
				$query .= ' ORDER BY a.id LIMIT 1 OFFSET :rangeId-1';
			}
		}*/

		$stmt = $this->db->prepare($query);
//echo $query;
//print_r($params);
		if($stmt->execute($params))
		{

			if(!$stmt->rowCount())
			{
				return 0;
			}
$i = 1; //$fetchParams['_RANGE'][0];
			while($row = $stmt->fetch(\PDO::FETCH_ASSOC))
			{
				$tmp = '* '.$row['UID'].' FETCH (';
$i++;
$ii = 0;
				foreach($row as $item => $data)
				{
					switch($item)
					{
						case 'INTERNALDATE':
						{
							$tmp .= ($ii==0?'':' ').$item.' "'.$row[$item].'"';
						}
						break;

						case 'UID':
						case 'ENVELOPE':
						case 'RFC822.SIZE':
						{
							$tmp .= ($ii==0?'':' ').$item.' '.trim($row[$item]);
						}
						break;

						case 'FLAGS':
						{
							$tmp .= ($ii==0?'':' ').$item.' ('.trim($row[$item]).')';
						}
						break;

						case 'BODY':
						{
							if(isset($fetchParams['BODY']))
							{
								if(isset($fetchParams['BODY']['HEADER.FIELDS']))
								{
									$res = mailparse_msg_create();
									mailparse_msg_parse($res, $row['BODY']);
									$structure = mailparse_msg_get_structure($res);

									$parts = [];
									foreach($structure as $part_id)
									{
										$part = mailparse_msg_get_part($res, $part_id);
										$parts[$part_id] = mailparse_msg_get_part_data($part);
									}

									if(!isset($parts[1]))
									{
//echo "Fault\n";
exit();
									}
//print_r($parts);
									$headers = '';
									foreach($fetchParams['BODY']['HEADER.FIELDS'] as $header)
									{
										$tmpheader = strtolower($header);
										if(isset($parts[1]['headers'][$tmpheader]))
										{
											$headers .= $header.': '.$parts[1]['headers'][$tmpheader]."\r\n";
										}

									}
									$headers .= "\r\n";
									$tmp .= ($ii==0?'':' ').$fetchParams['BODY']['_CMD']. ' {'.strlen($headers)."}\r\n".$headers;
								}
								else if(isset($fetchParams['BODY']['ALL']))
								{
$tmp .= ($ii==0?'':' ').$fetchParams['BODY']['_CMD']. ' {'.$row['RFC822.SIZE']."}\r\n".$row['BODY'];
									//$tmp .= ($ii==0?'':' ').$fetchParams['BODY']['_CMD']. ' {'.strlen($row['BODY'])."}\r\n".$row['BODY'];
								}
							}
						}
						break;

						case 'RFC822':
						{
$tmp .= ($ii==0?'':' ').'RFC822 {'.$row['RFC822.SIZE']."}\r\n".$row['RFC822'];
							//$tmp .= ($ii==0?'':' ').'RFC822 {'.strlen($row['RFC822'])."}\r\n".$row['RFC822'];
						}
						break;
					}
$ii++;
				}
				$tmp .= ")\r\n";
				$ret[] = $tmp;
			}
		}
		return $ret;
	}

	public function hashPassword($password)
	{
		return hash('sha512', $this->settings['salt'].$password.$this->settings['salt']);
	}

	public function login($username, $password)
	{
		// check we have a valid email address as the username
		if(!filter_var($username, FILTER_VALIDATE_EMAIL))
		{
			return FALSE;
		}

		// convert password into a 512bit sha2 hash with a bit of salt.
		$password = $this->hashPassword($password);

		$stmt = $this->db->prepare("SELECT a.id as userId FROM user AS a LEFT JOIN user_email_address AS b ON a.id = b.userId LEFT JOIN domain AS c ON b.domainId = c.id WHERE concat(b.localPart, '@', c.name) = :username AND password = :password");
		if($stmt->execute([':username' => $username, ':password' => $password]))
		{
//print_r($stmt->fetchAll(\PDO::FETCH_ASSOC));
//$stmt->debugDumpParams();
			if($stmt->rowCount() == 1)
			{
				return $stmt->fetch(\PDO::FETCH_ASSOC);
			}
			else
			{
				return FALSE;
			}
		}
		else
		{
			throw new \Pes\Exception(100, $stmt->errorInfo()[2]);
		}
	}

	public function create($id, $mailbox)
	{
		$stmt = $this->db->prepare("INSERT INTO mailbox(userId, name) values(:userId, :mailbox)");
		if($stmt->execute([':userId' => $this->handler->connections[$id]['user']['userId'], ':mailbox' => $mailbox]))
		{
			return TRUE;
		}
		return FALSE;
	}

	public function listMailboxes($id, $reference, $mailbox)
	{
//if($mailbox == '*') $mailbox = '%';
//if($reference == '*') $reference = '%';
		$params = [':userId' => $this->handler->connections[$id]['user']['userId']];

		if($mailbox == '*')
		{
			$filter = " AND name LIKE :mailbox";
			$params[':mailbox'] = '%'; // find all mailboxes from reference
		}
		else if($mailbox == '%')
		{
			if(!empty($reference))
			{
				$filter = " AND name LIKE :mailbox0 and name not like :mailbox1"; // find all mailboxes on this reference level

				$params[':mailbox0'] = $reference.$mailbox.'%';
				$params[':mailbox1'] = $reference.$mailbox.'/%';
			}
			else
			{
				$filter = " AND name not LIKE '%/' and name not like '%/%'"; // find all mailboxes at first level
			}
		}
		else 
		{
			$params[':mailbox'] = $reference.$mailbox; // search for mailbox verbatim
			$filter = " AND name = :mailbox";
		}

		// select * from mailbox where name like '%test/%' and name not like '%test/%/%';
//echo "SELECT '' as reference, name as mailbox, '' as flags FROM mailbox WHERE userId = :userId".$filter."\n";
//print_r($params);
//echo $mailbox."\n";
//need to add \Nochildren flag
		$stmt = $this->db->prepare("SELECT '' as reference, name, '' as flags FROM mailbox WHERE userId = :userId ".$filter);
		if($stmt->execute($params))
		{

//print_r($stmt->fetchAll(\PDO::FETCH_ASSOC));
//$stmt->debugDumpParams();
//print_r($params);

			$data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			return $data;
		}

		return array();
	}

	public function selectMailBox($id, $mailbox)
	{
		// find the mailbox
		$stmt = $this->db->prepare('SELECT id FROM mailbox WHERE userId = :userId AND name = :mailbox');
		if(!$stmt->execute([':userId' => $this->handler->connections[$id]['user']['userId'], ':mailbox' => $mailbox]))
		{
			return FALSE;
		}

		$tmp = $stmt->fetch(\PDO::FETCH_ASSOC);
		$mailboxId = $tmp['id'];

		// gather some stats
		$stmt = $this->db->prepare('
		SELECT
			max(id)+1 as uidnext,
			count(id) as `exists`,
			sum(if(recent=1,1,0)) as recent,
			sum(if(seen=0,1,0)) as unseen
		FROM
			user_messages
		WHERE
			mailboxId = :mailboxId
		');

		if(!$stmt->execute([':mailboxId' => $mailboxId]))
		{
			return FALSE;
		}

		if($stmt->rowCount()!=1)
		{

			return FALSE;
		}

		$data = $stmt->fetch(\PDO::FETCH_ASSOC);

		return [
			'exists' => $data['exists'],
			'recent' => $data['recent'],
			'unseen' => $data['unseen'],
			'uidvalidity' => $mailboxId,
			'uidnext' => $data['uidnext']
		];
	}

	public function queueMail($from, $to, $body)
	{
		
	}

	// admin helper functions .. for now.
	public function _begin()
	{
		$this->db->beginTransaction();
	}

	public function _commit()
	{
		$this->db->commit();
	}

	public function _rollback()
	{
		$this->db->rollback();
	}

	public function _addUserEmail($email, $linkEmail, $password, $name)
	{
		// check the email address doesn't already exist.
		$stmt = $this->db->prepare("SELECT a.id as userId FROM user AS a LEFT JOIN user_email_address AS b ON a.id = b.userId LEFT JOIN domain AS c ON b.domainId = c.id WHERE concat(b.localPart, '@', c.name) = :email");
		if($stmt->execute([':email' => $email]))
		{
			if($stmt->rowCount() >= 1)
			{
				throw new \Pes\Exception(200, 'Email address already exists: '.$email, $this);
			}
		}

		if($linkEmail) // find the existing user record
		{
			$stmt = $this->db->prepare("SELECT a.id as userId FROM user AS a LEFT JOIN user_email_address AS b ON a.id = b.userId LEFT JOIN domain AS c ON b.domainId = c.id WHERE concat(b.localPart, '@', c.name) = :linkEmail");
			if($stmt->execute([':linkEmail' => $linkEmail]))
			{
				if($stmt->rowCount() == 1)
				{
					$row = $stmt->fetch(\PDO::FETCH_ASSOC);
					$userId = $row['userId'];
				}
				else
				{
					throw new \Pes\Exception(200, 'Unable to find existing user: '.$email, $this);
				}
			}
		}
		else // create a new user record
		{
			$stmt = $this->db->prepare("INSERT INTO user(realName, password) VALUES(:name, :password)");
			if($stmt->execute(['name' => $name, 'password' => $this->hashPassword($password)]))
			{
				$userId = $this->db->lastInsertId();
			}
			else
			{
				throw new \Pes\Exception(200, 'Unable to create user: '.$email, $this);
			}
		}

		$domainPart = explode('@', $email)[1];
		// now find the domain
		$stmt = $this->db->prepare("SELECT id FROM domain WHERE name = :domain");
		if($stmt->execute([':domain' => $domainPart]))
		{
			if($stmt->rowCount() == 1)
			{
				$row = $stmt->fetch(\PDO::FETCH_ASSOC);
				$domainId = $row['id'];
			}
			else
			{
				$domainId = $this->_addDomain($domainPart);
			}
		}

		// and add the localpart in user_email_address
		$stmt = $this->db->prepare("INSERT INTO user_email_address(userId, domainId, localPart) VALUES(:userId, :domainId, :localPart)");
		if($stmt->execute(['userId' => $userId, 'domainId' => $domainId, 'localPart' => explode('@', $email)[0]]))
		{
			return TRUE;
		}

		throw new \Pes\Exception(200, 'Failed to add email: '.$email, $this);
	}

	public function _addDomain($name)
	{
		$stmt = $this->db->prepare("INSERT INTO domain(name) VALUES(:name)");
		if($stmt->execute(['name' => $name]))
		{
			return $this->db->lastInsertId();
		}
		throw new \Pes\Exception(200, 'Failed to add domain: '.$name, $this);
	}
}
