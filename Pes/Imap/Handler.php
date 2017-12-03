<?php

namespace Pes\Imap;

class Handler
{
	public $connections = [];
	public $buffers = [];
	public $maxRead = 5;
	public $settings = [];
	private $backend = FALSE;
	private $lib;
	private $log;

	private $listener;
	private $base;
	private $ctx;

	public function __construct($settings)
	{
		$this->settings = $settings; 
		$backendClass = "\\Pes\\Imap\\Backend\\".$this->settings['backend']['driver'];
		$this->backend = new $backendClass($this->settings['backend']);
		$this->backend->handler = $this;
		$this->lib = new \Pes\Imap\Lib;

		$this->log = new \Pes\Log($settings['log']['file'],$settings['log']['level']);
		$this->log->write('Imapd started');

		$this->ctx = new \EventSslContext(\EventSslContext::TLS_SERVER_METHOD, [
			\EventSslContext::OPT_LOCAL_CERT  => ($settings['ssl']['root'].'/'.$settings['domain'].'/'.$settings['ssl']['cert']),
			\EventSslContext::OPT_LOCAL_PK    => ($settings['ssl']['root'].'/'.$settings['domain'].'/'.$settings['ssl']['key']),
			//\EventSslContext::OPT_PASSPHRASE  => '',
			\EventSslContext::OPT_VERIFY_PEER => false, // change to true with authentic cert
			\EventSslContext::OPT_ALLOW_SELF_SIGNED => true // change to false with authentic cert
		]);

		$this->base = new \EventBase();
		if(!$this->base) 
		{
			$this->log->write('Failed to create EventBase');
			exit("Couldn't open event base\n");
		}

		$socket = stream_socket_server('tcp://0.0.0.0:143', $errno, $errstr);
		stream_set_blocking($socket, 0);

		$this->listener = new \EventListener($this->base, [$this, 'ev_accept'], $this->ctx, \EventListener::OPT_REUSEABLE | \EventListener::OPT_LEAVE_SOCKETS_BLOCKING, -1, $socket);

		if(!$this->listener)
		{
			$this->log->write('Faled to create EventListener');
			exit("Couldn't create listener\n");
		}

//		$this->listener->setErrorCallback([$this, 'ev_event']);
		$this->base->dispatch();
	}

	public function ev_accept($listener, $fd, $address, $ctx)
	{
		if(!$fd)
		{
			$this->log->write('ev_accept fd false?!', 3);
			return;
		}

		static $id = 0;
		$id += 1;

		$this->log->write('ev_accept('.$id.')', 3);

		$this->connections[$id]['clientData'] = '';
		$this->connections[$id]['fd'] = $fd;
		$this->connections[$id]['fetchLiteralCount'] = FALSE;
		$this->connections[$id]['fetchLiteralResult'] = '';
		$this->connections[$id]['fetchLiteralResume'] = '';
		$this->connections[$id]['user'] = FALSE;
		$this->connections[$id]['selectedMailbox'] = FALSE;
		$this->connections[$id]['sslmode'] = FALSE;
		
		$this->connections[$id]['cnxSsl'] = FALSE;
		$this->connections[$id]['cnx'] = new \EventBufferEvent($this->base, $fd, \EventBufferEvent::OPT_CLOSE_ON_FREE);
		//$this->connections[$id]['cnx'] = \EventBufferEvent::sslSocket($this->base, $fd, $this->ctx, \EventBufferEvent::SSL_ACCEPTING, \EventBufferEvent::OPT_CLOSE_ON_FREE);

		if(!$this->connections[$id]['cnx'])
		{
			$this->log->write('Failed to create EventBufferEvent');
			$this->base->exit(NULL);
			exit(1);
		}

		//$this->connections[$id]['cnx']->setCallbacks([$this, 'ev_read'], [$this, 'ev_write'], [$this, 'ev_event'], $id);
		$this->connections[$id]['cnx']->setCallbacks([$this, 'ev_read'], [$this, 'ev_write'], FALSE, $id);
		$this->connections[$id]['cnx']->enable(\Event::READ | \Event::WRITE);

		$this->ev_write($id, "* OK IMAP4rev1 server ready\r\n");
	}

	function ev_event($listener, $ctx)
	{
		$errno = \EventUtil::getLastSocketErrno();
		$this->log->write('ev_event(): '.\EventUtil::getLastSocketError());

		if($errno!=0)
		{
			$this->log->write('ev_event(): closing');
			$listener->disable(\Event::READ | \Event::WRITE);
			$listener->close();
		}
	}

	public function ev_close($id)
	{
		$this->log->write('ev_close('.$id.')', 3);

		$i=0;
		while(($this->connections[$id]['cnx']->getOutput()->length > 0) && ($i < 64))
		{
			$i++;
			$this->connections[$id]['cnx']->getOutput()->write($this->connections[$id]['fd'], $this->maxRead);
		}

		$i=0;
		while(($this->connections[$id]['cnx']->getInput()->length > 0) && ($i < 64))
		{
			$i++;
			$this->connections[$id]['cnx']->getInput()->read($this->connections[$id]['fd'], $this->maxRead);
		}

		if($this->connections[$id]['cnxSsl']) $this->connections[$id]['cnx']->disable(\Event::READ | \Event::WRITE);
		$this->connections[$id]['cnx']->disable(\Event::READ | \Event::WRITE);

		if($this->connections[$id]['cnxSsl']) $this->connections[$id]['cnxSsl']->free();
		$this->connections[$id]['cnx']->free();

		// pretty sure this is the cause of some segfaults but not got to the root of it yet
		//unset($this->connections[$id]); 
	}

	protected function ev_write($id, $string)
	{
		$this->log->write('Server: '.substr($string, 0, 70), 3);

		if($this->connections[$id]['cnxSsl'])
		{
			$this->connections[$id]['cnxSsl']->write($string);
		}
		else
		{
			$this->connections[$id]['cnx']->write($string);
		}
	}

	// set's up the users connection so ev_read knows how to manage the data coming in from the client.
	protected function getLiteral($buffer, $id, $str, $tag, $cmd, $paramString)
	{
		$chCount = (int)substr($str, 1, strlen($str)-1);
		if(is_numeric($chCount))
		{
			$this->connections[$id]['fetchLiteralCount'] = $chCount;
			$this->connections[$id]['fetchLiteralResult'] = '';
			$this->connections[$id]['fetchLiteralResume'] = [$tag, $cmd, $paramString];
			$this->ev_write($id, '+ Ready for additional command text ('.$chCount.")\r\n");
		}
	}

	public function ev_read($buffer, $id)
	{
		while($buffer->input->length > 0)
		{
			if($this->connections[$id]['fetchLiteralCount']) // set to the number of characters to read in .. when we have enough we set this to false and refire the command that requested the literal.
			{
				//$data = event_buffer_read($buffer, $this->maxRead);
				$data = $buffer->input->read($this->maxRead);

				$dataLen = strlen($data);

				if(($dataLen + strlen($this->connections[$id]['fetchLiteralResult'])) < ($this->connections[$id]['fetchLiteralCount']+2)) // +2 for \r\n
				{
					$this->connections[$id]['fetchLiteralResult'] .= $data;
				}
				else if(($dataLen + strlen($this->connections[$id]['fetchLiteralResult'])) == ($this->connections[$id]['fetchLiteralCount']+2)) // +2 for \r\n
				{

					$this->connections[$id]['fetchLiteralResult'] .= $data;
					$this->connections[$id]['fetchLiteralCount'] = FALSE;
					$res = $this->connections[$id]['fetchLiteralResume'];
					$this->cmd($buffer, $id, $res[0], $res[1], $res[2], $this->connections[$id]['fetchLiteralResult']);
				}
			}
			else
			{
				//$this->connections[$id]['clientData'] .= event_buffer_read($buffer, $this->maxRead);
				$this->connections[$id]['clientData'] .= $buffer->input->read($this->maxRead);
				$this->processClientData($buffer, $id);
			}
		}
	}

	protected function processClientData($buffer, $id)
	{
		// make sure we have a complete client command line before processing.
		$clientDataLen = strlen($this->connections[$id]['clientData']);
		$cmds = explode("\r\n", $this->connections[$id]['clientData']);

		// was the last command partial?  if so store it in the clientData buffer ready for the rest of the data.
		if(!isset($this->connections[$id]['clientData'][$clientDataLen-2]) || (
			$this->connections[$id]['clientData'][$clientDataLen-2] != "\r" &&
			$this->connections[$id]['clientData'][$clientDataLen-1] != "\n"))
		{
			$this->connections[$id]['clientData'] = array_pop($cmds);
		}
		else
		{

			$this->connections[$id]['clientData'] = '';
		}

		if(sizeof($cmds)) foreach($cmds as $cmd)
		{
			$cmd = trim($cmd);
			if(!$cmd) continue;
			$cmdString = $cmd;

			$this->connections[$id]['uidMode'] = FALSE;

			$parts = explode(' ', $cmdString, 3); // We want the tag and command to begin with.  parts[2] contains the command parameters.
			if(!isset($parts[1]))
			{
				// ?
			}

			$tag = $parts[0];
			$cmd = strtoupper($parts[1]); // uppercase to make life easier in this->cmd();

			// check to see if the command is UID prefixed and set the cmd and cmdParams repsectivly.  Also set uidMode = TRUE.
			if(strtoupper($parts[1]) == 'UID')
			{
				$parts = explode(' ', $parts[2], 2); // Grab the command and params.
				$cmd = strtoupper(trim($parts[0]));
				$paramString = $parts[1];
				$this->connections[$id]['uidMode'] = TRUE;
			}
			else if(isset($parts[2]))
			{
				$paramString = $parts[2];
			}
			else // logout for example
			{
				$paramString = FALSE;
			}

			$this->cmd($buffer, $id, $tag, $cmd, $paramString);
		}
	}


	protected function cmd($buffer, $id, $tag, $cmd, $paramString, $literal=FALSE)
	{
		$cmd = strtoupper($cmd);
		$this->log->write('cmd('.$id.'): '.$cmd.' '.$paramString, 3);

		if(!$this->connections[$id]['user'])
		{
			if(!in_array($cmd, array('LOGIN', 'CAPABILITY', 'NOOP', 'LOGOUT', 'STARTTLS')))
			{
				$this->log->write('Client attempted to communicate without logging in.', 2);
				$this->ev_write($id, $tag." BAD Login first\r\n");
				return FALSE;
			}
		}

		switch($cmd)
		{
			case 'STARTTLS':
			{
				if($this->connections[$id]['sslmode'])
				{
					$this->ev_write($id, $tag." BAD STARTTLS already called!\r\n");
					return;
				}
				$this->ev_write($id, $tag." OK Begin TLS negotiation now\r\n");

				$this->connections[$id]['cnxSsl'] = $this->connections[$id]['cnx']->sslFilter($this->base, $this->connections[$id]['cnx'], $this->ctx, \EventBufferEvent::SSL_ACCEPTING, \EventBufferEvent::OPT_CLOSE_ON_FREE);
				$this->connections[$id]['cnxSsl']->setCallbacks([$this, "ev_read"], [$this, "ev_write"], FALSE, $id);
				$this->connections[$id]['cnxSsl']->enable(\Event::READ | \Event::WRITE);
				$this->connections[$id]['sslmode'] = TRUE;
			}
			break;

			case 'CAPABILITY':
			{
//$this->ev_write($id, "* CAPABILITY IMAP4 IMAP4rev1 UNSELECT LITERAL+ ID UIDPLUS ENABLE MOVE CONDSTORE IDLE AUTH=NTLM LOGIN STARTTLS\r\n");
				$this->ev_write($id, "* CAPABILITY IMAP4 IMAP4rev1 LOGIN STARTTLS\r\n"); //AUTH=PLAIN LOGINDISABLED STARTTLS
				$this->ev_write($id, $tag." OK CAPABILITY completed\r\n");
			}
			break;

			case 'NOOP':
			{
				$this->ev_write($id, $tag." OK NOOP completed\r\n");
			}
			break;

			// inital implementation -20120702
			case 'LOGIN':
			{
				$parts = explode(' ', $paramString);

				if(sizeof($parts)!=2)
				{
					$this->ev_write($id, $tag." BAD Invalid arguments\r\n");
				}

				if($parts[0][0] == '"') // username
				{
					$parts[0] = substr($parts[0], 1, strlen($parts[0])-2);
				}

				if($parts[1][0] == '"') // password
				{
					$parts[1] = substr($parts[1], 1, strlen($parts[1])-2);
				}

				if(!($user = $this->backend->login($parts[0], $parts[1])))
				{
					$this->ev_write($id, $tag." NO LOGIN Failed\r\n");
				}
				else
				{
					$this->connections[$id]['user'] = $user;
					$this->ev_write($id, $tag." OK LOGIN Successful\r\n");
				}
			}
			break;

			case 'LOGOUT':
			{
				$this->ev_write($id, "* BYE IMAP4rev1 Server logging out\r\n");
				$this->ev_write($id, $tag." OK LOGOUT completed\r\n");
				$this->ev_close($id);
			}
			break;

			case 'LSUB':
			case 'LIST':
			{
				$reference = '';
				$mailbox = '';
				$referenceActive = TRUE;
				for($len = strlen($paramString), $i = 0; $i<$len; $i++)
				{
					if((!isset($paramString[$i-1]) || $paramString[$i-1] != '\\') && (isset($paramString[$i+1]) && $paramString[$i+1] == ' ') && $paramString[$i] == '"')
					{
						$reference = substr($reference, 1, $i-1);
						$i++; // skip SP between reference and mailbox
						$referenceActive = FALSE; // move to mailbox param
					}
					else if(!($paramString[$i] == '\\' && (isset($paramString[$i+1]) && $paramString[$i+1] == '"'))) // ignore dquote escape character
					{
						if($referenceActive)
						{
							$reference .= $paramString[$i];
						}
						else
						{
							$mailbox .= $paramString[$i];
						}
					}
				}

				if($referenceActive)
				{
					$this->ev_write($id, $tag." BAD Failed to list mailbox: ".$paramString."\r\n");
					return FALSE;
				}

				if($mailbox[0] == '"') // remove dquotes unless literal
				{
					$mailbox = substr($mailbox, 1, strlen($mailbox)-2); 
				}

				if($mailbox == 'INBOX')
				{

					$this->ev_write($id, '* LIST () "'.$this->settings['backend']['mailboxDelimiter'].'" "'.$mailbox.'"'."\r\n");
					$this->ev_write($id, $tag." OK done\r\n");
					return TRUE;
				}

				//echo "reference: ".$reference."\n";
				//echo "mailbox: ".$mailbox."\n";

				if(!empty($mailbox) && !$literal && $mailbox[0] == '{') // check if mailbox is a literal
				{
					$data = $this->getLiteral($buffer, $id, $mailbox, $tag, $cmd, $paramString);
				}
				else
				{
					if($literal)
					{
						$mailbox = $literal;
						$literal = FALSE;
					}

					if(empty($mailbox)) // request for mailbox delimiter
					{
						$this->ev_write($id, '* LIST (\Noselect) "'.$this->settings['backend']['mailboxDelimiter'].'" "'.$this->settings['backend']['mailboxDelimiter'].'"'."\r\n");
					}
					else //if(empty($reference) && ($mailbox == '%' || $mailbox == '*'))
					{
						foreach($this->backend->listMailboxes($id, $reference, $mailbox) as $mailbox)
						{
							$this->ev_write($id, '* LIST ('.$mailbox['flags'].') "'.$this->settings['backend']['mailboxDelimiter'].'" "'.$mailbox['name'].'"'."\r\n");
						}
					}

					$this->ev_write($id, $tag." OK done\r\n");
				}
			}
			break;

			case 'CREATE':
			{
				if(!$literal && $paramString[0] == '{')
				{
					$data = $this->getLiteral($buffer, $id, $paramString, $tag, $cmd, $paramString);
				}
				else
				{
					if($literal)
					{
						$paramString = $literal;
						$literal = FALSE;
					}

					if($paramString[0] == '"') // remove DQUOTEs if needed.
					{
						$paramString = substr($paramString, 1, strlen($paramString)-2);
					}

					if(strtoupper($paramString) != 'INBOX' && $this->backend->create($id, $paramString))
					{
						$this->ev_write($id, $tag." OK CREATE completed: ".$paramString."\r\n");
					}
					else
					{
						$this->ev_write($id, $tag." NO Failed to create mailbox: ".$paramString."\r\n");
					}
				}
			}
			break;

// TODO: handle examine correctly
			case 'EXAMINE':
			case 'SELECT':
			{
				if(!$literal && $paramString[0] == '{')
				{
					$data = $this->getLiteral($buffer, $id, $paramString, $tag, $cmd, $paramString);
				}
				else
				{
					if($literal)
					{
						$paramString = $literal;
						$literal = FALSE;
					}

					if($paramString[0] == '"') // remove DQUOTEs if needed.
					{
						$paramString = substr($paramString, 1, strlen($paramString)-2);
					}

					$flags = $this->backend->selectMailBox($id, $paramString);

					if(is_array($flags))
					{
						$this->ev_write($id, '* '.$flags['exists']." EXISTS\r\n");
						$this->ev_write($id, '* '.$flags['recent']." RECENT\r\n");
						$this->ev_write($id, '* OK [UNSEEN '.$flags['unseen']."]\r\n");
						$this->ev_write($id, '* OK [UIDVALIDITY 1]'."\r\n"); //'.$flags['uidvalidity']."]\r\n");
						$this->ev_write($id, '* OK [UIDNEXT '.$flags['uidnext']."]\r\n");
						$this->ev_write($id, '* FLAGS (\Answered \Flagged \Deleted \Seen \Draft)'."\r\n");
						$this->ev_write($id, $tag." OK [READ-WRITE] SELECT completed\r\n");
					}
				}
			}
			break;

			case 'FETCH':
			{
				$fetch = $this->lib->parseFetch($paramString);

				if(!$fetch)
				{
					$this->ev_write($id, $tag." BAD Unknown command(0): ".$cmd."\r\n");
				}
				else
				{
					$data = $this->backend->fetch($this->connections[$id]['user']['userId'], $fetch, $this->connections[$id]['uidMode']);
					if($data === 0)
					{
						$this->ev_write($id, $tag." OK FETCH completed\r\n");
					}
					else if($data === FALSE)
					{
						$this->ev_write($id, $tag." BAD Unknown command(1): ".$cmd."\r\n");
					}
					else
					{
//$this->ev_write($id, implode('', $data).$tag." OK FETCH completed\r\n");
						foreach($data as $msg)
						{
							$this->ev_write($id, $msg);
						}
						$this->ev_write($id, $tag." OK FETCH completed\r\n");	
					}
				}
			}
			break;

			case 'APPEND':
			{
// TODO need to store args from before literal retrieval so we can push the message into the append mailbox
// might be a good idea to also store the message :D
				if($literal)
				{
					$this->ev_write($id, $tag." OK APPEND completed\r\n");
				}
				else
				{
					$args = $this->lib->parseAppend($paramString);

					if(is_numeric($args['literal']))
					{
						$data = $this->getLiteral($buffer, $id, '{'.$args['literal'].'}', $tag, $cmd, $paramString);
					}
				}

			}
			break;

			case 'CHECK':
			{
				$this->ev_write($id, $tag." OK CHECK Completed\r\n");
			}
			break;


			case 'CLOSE':
			{
//echo "responding to close\n";
				$this->ev_write($id, $tag." OK CLOSE completed\r\n");
			}
			break;

			case 'STATUS':
			{
//echo "responding to status\n";
$this->ev_write($id, '* STATUS blurdybloop (MESSAGES 0 UNSEEN 0 RECENT 0)'."\r\n");
$this->ev_write($id, $tag." OK STATUS completed\r\n");

			}
			break;

/*			case 'LSUB':
			{
//$this->ev_write($id, $tag." OK LSUB completed\r\n");
$this->ev_write($id, $tag." BAD LSUB not supported\r\n");
			}
			break;
*/
			case 'SUBSCRIBE':
			{
//print_r($parts);
$this->ev_write($id, $tag." OK SUBSCRIBE completed\r\n");
			}
			break;

			case 'AUTHENTICATE':
			{
$this->ev_write($id, $tag." NO AUTHENTICATE\r\n");
			}
			break;

			case 'SEARCH':
			{
$this->ev_write($id, "* SEARCH\r\n");
$this->ev_write($id, $tag." OK SEARCH completed\r\n");

			}
			break;

			case 'EXPUNGE':
			{
$this->ev_write($id, $tag." OK EXPUNGE completed\r\n");
			}
			break;

			default:
			{
//				echo 'unknown command: '.$cmd."\n";
$this->ev_write($id, $tag." BAD Unknown command: ".$cmd."\r\n");
			}
			break;
		}
	}
}
